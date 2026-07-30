#!/usr/bin/env python3
"""
Fetch Bailer-Jones geometric distances for stars AT-HYG cannot place.

Writes a reviewable CSV to db/data/gaia_distances.csv, which db/sql/08_import_gaia_distances.sql
then applies. Same two-stage shape as match_cns5.py: a script that queries and produces a
committed artifact, then a deterministic SQL import.

WHY THIS EXISTS
---------------
Every distance source in this pipeline stops at 100 pc. CNS5 is volume-complete to 25 pc,
GCNS to 100 pc, and beyond that AT-HYG is the only authority — so where its chosen parallax
is unusable there is no fallback. That left 196 stars with either no distance at all or an
absolute magnitude brighter than any real star, and because search orders brightest-first
those artifacts came back first. See docs/database.md.

WHY BAILER-JONES RATHER THAN 1000/parallax
------------------------------------------
Inverting a parallax that is small, noisy or negative is exactly what produced the mess
this script is cleaning up: HYG floors an unusable parallax at 0.01 mas, which inverts to
100000 pc, and that placeholder was being read as a measurement. Bailer-Jones et al. (2021)
apply a direction-dependent distance prior and publish a posterior, which is defined even
when the parallax is not. Do not replace this with parallax inversion.

We take `rgeo` (geometric) rather than `rpgeo` (photogeometric). Photogeometric is generally
more precise, but it assumes the star sits where the colour-magnitude diagram expects — and
this target list is made of exactly the stars that do not: bright supergiants, binaries and
astrometrically troubled objects. `rpgeo` is recorded in the CSV for reference.

COVERAGE, measured 2026-07-30
-----------------------------
    residue (implausible absmag)   52 of  53   (98%)
    no distance at all (sentinel)  25 of 111   (23%)

The sentinel group is mostly bright Hipparcos stars that Gaia has no parallax for at all,
so Bailer-Jones has no entry either. Polis (mu Sgr, G=3.76) is the type case: Gaia DR3 lists
it with photometry and an empty parallax, and SIMBAD carries only the Hipparcos value of
0.09 +/- 0.28 mas — a parallax smaller than its own error. Those stars need a literature or
association distance, which is not what this script does.

USAGE
-----
    docker compose up -d hygmap-db
    pip install -r db/scripts/requirements.txt
    DB_PASS=... python3 db/scripts/fetch_gaia_distances.py
"""
import argparse
import csv
import io
import os
import sys
import urllib.parse
import urllib.request

try:
    import psycopg2
except ImportError:
    sys.exit("psycopg2 is required: pip install -r db/scripts/requirements.txt")

TAP_URL = "https://tapvizier.cds.unistra.fr/TAPVizieR/tap/sync"
CATALOGUE = "I/352/gedr3dis"  # Bailer-Jones+ 2021, distances from Gaia EDR3 parallaxes
OUTPUT = os.path.join(os.path.dirname(__file__), "..", "data", "gaia_distances.csv")

# EDR3 and DR3 share source_ids, so athyg.gaia (DR3) matches this catalogue directly.
CHUNK = 60

# Brighter than any real star: below this the distance is what is wrong.
ABSMAG_FLOOR = -10.0

# Past this the API cannot express a bounding box containing the star
# (MAX_COORDINATE_VALUE in hygmap-api/app/api/stars.py), so a corrected distance will not
# make it appear on the map. We import it anyway and only count them here.
#
# An earlier version of this script skipped those rows, which was wrong: it optimised for
# visible effect over correctness. A star sitting at a fabricated 479,769 pc with an
# impossible absolute magnitude is worse data than one at an honest 14,456 pc, whether or
# not this app can currently draw it — and the honest value is what stops it sorting to the
# top of a brightest-first search.
BEYOND_DISPLAYABLE_PC = 10000.0

TARGET_SQL = """
    SELECT id, gaia, dist, absmag, mag, dist_src
    FROM   athyg
    WHERE  gaia IS NOT NULL
      AND  (absmag < %s OR (dist IS NULL AND dist_src = 'H'))
    ORDER BY id
"""


def fetch_distances(source_ids):
    """Query the TAP service in chunks. Returns {source_id: (rgeo, rpgeo)}."""
    out = {}
    for i in range(0, len(source_ids), CHUNK):
        chunk = source_ids[i : i + CHUNK]
        query = (
            f'SELECT "Source", rgeo, rpgeo FROM "{CATALOGUE}" '
            f'WHERE "Source" IN ({",".join(chunk)})'
        )
        payload = urllib.parse.urlencode(
            {"REQUEST": "doQuery", "LANG": "ADQL", "FORMAT": "csv", "QUERY": query}
        ).encode()
        with urllib.request.urlopen(TAP_URL, data=payload, timeout=180) as resp:
            body = resp.read().decode()
        for row in csv.DictReader(io.StringIO(body)):
            if row.get("rgeo"):
                out[row["Source"]] = (
                    float(row["rgeo"]),
                    float(row["rpgeo"]) if row.get("rpgeo") else None,
                )
        print(f"  queried {min(i + CHUNK, len(source_ids))}/{len(source_ids)}", flush=True)
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description="Fetch Bailer-Jones distances")
    ap.add_argument("--dry-run", action="store_true", help="report, write nothing")
    args = ap.parse_args()

    conn = psycopg2.connect(
        host=os.environ.get("DB_HOST", "localhost"),
        port=os.environ.get("DB_PORT", "5432"),
        dbname=os.environ.get("DB_NAME", "hygmap"),
        user=os.environ.get("DB_USER", "hygmap_user"),
        password=os.environ.get("DB_PASS", os.environ.get("POSTGRES_PASSWORD", "")),
    )
    with conn, conn.cursor() as cur:
        cur.execute(TARGET_SQL, (ABSMAG_FLOOR,))
        targets = cur.fetchall()
    conn.close()

    print(f"targets needing a distance: {len(targets)}")
    if not targets:
        print("nothing to do")
        return 0

    found = fetch_distances([str(t[1]) for t in targets])
    print(f"\nBailer-Jones has a distance for {len(found)}/{len(targets)}")

    rows, beyond_display, missing = [], 0, 0
    for star_id, gaia, old_dist, old_absmag, mag, old_src in targets:
        hit = found.get(str(gaia))
        if hit is None:
            missing += 1
            continue
        rgeo, rpgeo = hit
        if rgeo > BEYOND_DISPLAYABLE_PC:
            beyond_display += 1
        rows.append(
            {
                "athyg_id": star_id,
                "gaia": gaia,
                "dist": f"{rgeo:.6f}",
                "rpgeo": f"{rpgeo:.6f}" if rpgeo is not None else "",
                "old_dist": "" if old_dist is None else f"{old_dist:.3f}",
                "old_absmag": "" if old_absmag is None else f"{old_absmag:.3f}",
                "old_dist_src": old_src or "",
                "mag": "" if mag is None else f"{mag:.4f}",
            }
        )

    print(f"  to be corrected: {len(rows)}")
    print(f"    of which still beyond the displayable {BEYOND_DISPLAYABLE_PC:.0f} pc: {beyond_display}")
    print(f"  no Bailer-Jones entry (no Gaia parallax): {missing}")

    if args.dry_run:
        print("\n--dry-run: not writing")
        return 0

    path = os.path.normpath(OUTPUT)
    with open(path, "w", newline="") as fh:
        writer = csv.DictWriter(
            fh,
            fieldnames=[
                "athyg_id", "gaia", "dist", "rpgeo",
                "old_dist", "old_absmag", "old_dist_src", "mag",
            ],
        )
        writer.writeheader()
        writer.writerows(rows)
    print(f"\nwrote {len(rows)} rows to {path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
