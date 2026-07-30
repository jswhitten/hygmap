#!/usr/bin/env python3
"""
Report distance-quality problems in the built database.

Read-only. Run after an import to catch the failure modes that reached production in
July 2026 -- all of which looked completely normal in the app until someone searched for
a constellation and got a 503.

    python3 db/scripts/check_distance_quality.py            # exits 1 if anything is found
    DB_HOST=localhost DB_PORT=5432 python3 ... --quiet

The three checks correspond to three real defects:

1. SENTINEL. HYG floors an unusable parallax at 0.01 mas, which inverts to a distance of
   exactly 100000 pc. It is a placeholder, not a measurement, and it is documented
   nowhere upstream. Treated as data it produced absolute magnitudes down to -16.16 and,
   because /api/stars/search orders brightest-first, those stars came back first.

2. VOLUME CONTRADICTION. CNS5 is complete to 25 pc and GCNS to 100 pc, so a star either
   catalogue lists cannot be further away than that. Where AT-HYG disagrees by more than
   a factor of two, one source is wrong -- and it has been AT-HYG every time so far
   (15 cases, all bad Gaia DR3 parallaxes).

3. IMPLAUSIBLE LUMINOSITY. Nothing is brighter than about absmag -10. Values below that
   mean the distance is wrong, because absmag is derived from it.

4. COORDINATE DRIFT. x/y/z are derived from dist, so their length must equal it. When a
   supplement catalogue corrected a distance, the recompute was gated on the coordinates
   being NULL -- true only for newly inserted rows -- so 17 stars kept coordinates
   derived from the distance that had just been rejected. GJ 125 had dist 17.19 pc and
   coordinates 97,011 pc from Sol. Every other check here passed on those rows: dist was
   right, absmag was right, and only the position was wrong, which is exactly why this
   check had to exist separately.
"""
import argparse
import os
import sys

try:
    import psycopg2
except ImportError:
    sys.exit("psycopg2 is required: pip install -r db/scripts/requirements.txt")

# Brighter than any real star; below this the distance is the thing at fault.
ABSMAG_FLOOR = -10.0
# HYG's unusable-parallax placeholder, in parsecs.
SENTINEL_DIST = 100000

# Stars whose absolute magnitude is implausible because AT-HYG's parallax is bad and no
# source in this pipeline covers their distance. The check fails if this count grows.
#
# Was 53 before GAIA-DISTANCES; Bailer-Jones cleared 52 of them. The one holdout (id 364061)
# has a Gaia DR2-derived distance and no entry in the EDR3-based Bailer-Jones catalogue, so
# it needs a different source. Lower this if that is ever resolved.
KNOWN_IMPLAUSIBLE_BASELINE = 1

# How far a stored position may sit from the distance it was derived from, as a fraction
# of that distance. Coordinates are float32 in the database, so exact equality is not
# available; 0.1% is roughly four orders of magnitude looser than float32 round-trip
# error and four orders tighter than the smallest real defect this has caught.
COORD_TOLERANCE_FRACTION = 0.001

CHECKS = (
    (
        "unknown-distance sentinel treated as a measurement",
        f"SELECT count(*) FROM athyg WHERE dist = {SENTINEL_DIST}",
        "db/sql/03_import_data.sql should clear these to NULL",
    ),
    (
        "CNS5 stars placed beyond its 25 pc sphere by AT-HYG",
        # dist_src is excluded where we have already adopted the catalogue's own value,
        # so this counts unresolved contradictions rather than accepted overrides. A few
        # CNS5 entries genuinely sit just outside 25 pc, hence the generous bound.
        "SELECT count(*) FROM athyg"
        " WHERE cns5 IS NOT NULL AND dist > 50"
        "   AND coalesce(dist_src, '') NOT IN ('CNS5', 'GCNS')",
        "AT-HYG contradicts CNS5; 06_import_cns5.sql should adopt the CNS5 distance",
    ),
    (
        "positions inconsistent with a null distance",
        "SELECT count(*) FROM athyg WHERE dist IS NULL AND x IS NOT NULL",
        "a star with no distance must not carry a fabricated position",
    ),
    (
        "galactic positions whose length disagrees with dist",
        # x/y/z are dist projected through an orthonormal rotation, so their length IS
        # the distance. A relative tolerance rather than an absolute one because the
        # values span 8 orders of magnitude; 0.1% is far tighter than any real drift
        # (the 17 known cases were wrong by factors of 10 to 5,600) and far looser than
        # float32 storage noise.
        f"SELECT count(*) FROM athyg"
        f" WHERE dist IS NOT NULL AND dist > 0 AND x IS NOT NULL"
        f"   AND abs(sqrt(x*x + y*y + z*z) - dist) > {COORD_TOLERANCE_FRACTION} * dist",
        "a distance was changed without recomputing the position it derives;"
        " see 06_import_cns5.sql / 07_import_gcns.sql",
    ),
    (
        "equatorial positions whose length disagrees with dist",
        # Checked separately from the galactic triple: x/y/z are derived FROM x_eq/y_eq/z_eq,
        # so if only the galactic check ran, a stale equatorial triple with a correctly
        # recomputed galactic one would pass -- and the next import to re-derive x/y/z
        # would silently reintroduce the bad position.
        f"SELECT count(*) FROM athyg"
        f" WHERE dist IS NOT NULL AND dist > 0 AND x_eq IS NOT NULL"
        f"   AND abs(sqrt(x_eq*x_eq + y_eq*y_eq + z_eq*z_eq) - dist) >"
        f" {COORD_TOLERANCE_FRACTION} * dist",
        "the equatorial position is stale; it is the source x/y/z is computed from",
    ),
)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--quiet", action="store_true", help="only report problems")
    args = ap.parse_args()

    conn = psycopg2.connect(
        host=os.environ.get("DB_HOST", "localhost"),
        port=os.environ.get("DB_PORT", "5432"),
        dbname=os.environ.get("DB_NAME", "hygmap"),
        user=os.environ.get("DB_USER", "hygmap_user"),
        password=os.environ.get("DB_PASS", os.environ.get("POSTGRES_PASSWORD", "")),
    )

    problems = 0
    with conn, conn.cursor() as cur:
        for label, sql, hint in CHECKS:
            cur.execute(sql)
            count = cur.fetchone()[0]
            if count:
                problems += count
                print(f"FAIL  {count:>6,}  {label}")
                print(f"              -> {hint}")
            elif not args.quiet:
                print(f"ok         0  {label}")

        # The implausible-luminosity residue is tracked against a baseline rather than
        # required to be zero: nothing in this pipeline supplies distances beyond 100 pc,
        # so these cannot be fixed here. Failing on any growth still catches a regression.
        cur.execute(
            "SELECT count(*), count(gaia) FROM athyg WHERE absmag < %s", (ABSMAG_FLOOR,)
        )
        residue, with_gaia = cur.fetchone()
        if residue > KNOWN_IMPLAUSIBLE_BASELINE:
            problems += residue - KNOWN_IMPLAUSIBLE_BASELINE
            print(
                f"FAIL  {residue:>6,}  implausibly luminous stars"
                f" (baseline {KNOWN_IMPLAUSIBLE_BASELINE}, so {residue - KNOWN_IMPLAUSIBLE_BASELINE} new)"
            )
            print("              -> a broken parallax inflates absmag; check the import")
        elif not args.quiet:
            print(
                f"ok  {residue:>6,}  implausibly luminous stars (known residue,"
                f" baseline {KNOWN_IMPLAUSIBLE_BASELINE})"
            )
            print(
                f"              -> {with_gaia:,} carry a Gaia source_id but have no"
                " Bailer-Jones entry (no usable Gaia parallax), so they need a"
                " literature or association distance"
            )

    conn.close()
    if problems:
        print(f"\n{problems:,} row(s) need attention")
        return 1
    if not args.quiet:
        print("\nno distance-quality problems found")
    return 0


if __name__ == "__main__":
    sys.exit(main())
