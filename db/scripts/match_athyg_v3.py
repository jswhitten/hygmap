"""
match_athyg_v3.py — Map AT-HYG v3.3 star ids onto current (v4.0) athyg ids.

The AT-HYG 4 migration renumbered every star: `athyg.id` is the source catalog's row id,
not ours, and v4 assigned new ones. Every link anyone saved before that migration now
points at a different star, silently. This builds the mapping that lets those links be
recognised, so the app can say "this used to mean X" instead of quietly showing Y.

Reads the two v3.3 CSVs, queries the live athyg table for current identifiers, and writes
db/data/athyg_v3_ids.csv for SQL import.

    v3_id,athyg_id,match_method

Source data (deleted from AT-HYG's main branch 2026-07-25; the parent commit still has it):

    https://codeberg.org/astronexus/athyg/media/commit/\
e2d25eb56726ddede7722d8885a49bb2b8583c7e/data/athyg_v33-1.csv.gz
    ...athyg_v33-2.csv.gz

Use the `media/` path, not `raw/`. These are Git LFS objects and `raw/` returns a 133-byte
pointer file, which looks exactly like a truncated download.

Usage:
    docker compose up -d hygmap-db
    cd db/scripts
    pip install -r requirements.txt
    python match_athyg_v3.py --v3 /path/to/athyg_v33-1.csv.gz /path/to/athyg_v33-2.csv.gz
"""

import argparse
import csv
import gzip
import os
import sys
from collections import Counter

try:
    import psycopg2
except ImportError:  # importable for unit tests without a database
    psycopg2 = None

OUTPUT_FILE = os.environ.get("ATHYG_V3_OUTPUT", "../data/athyg_v3_ids.csv")

DB_HOST = os.environ.get("DB_HOST", "localhost")
DB_PORT = int(os.environ.get("DB_PORT", "5432"))
DB_NAME = os.environ.get("DB_NAME", "hygmap")
DB_USER = os.environ.get("DB_USER", "hygmap_user")
DB_PASS = os.environ.get("DB_PASS", "hygmap_pass")

CSV_COLUMNS = ["v3_id", "athyg_id", "match_method"]

# AT-HYG v3.3 column order. Not identical to v4: v3.3 has x0,y0,z0 where v4 has
# x_eq,y_eq,z_eq. We only read identifier columns, so the difference is inert — but the
# header is positional for file 2, which has none, so this list is load-bearing.
V3_COLUMNS = [
    "id", "tyc", "gaia", "hyg", "hip", "hd", "hr", "gl", "bayer", "flam", "con",
    "proper", "ra", "dec", "pos_src", "dist", "x0", "y0", "z0", "dist_src", "mag",
    "absmag", "ci", "mag_src", "rv", "rv_src", "pm_ra", "pm_dec", "pm_src",
    "vx", "vy", "vz", "spect", "spect_src",
]

# Order matters: most specific identifier first. Gaia DR3 resolves 98.6% of the catalog on
# its own; the rest is mostly Tycho-2 bright stars Gaia saturates on.
MATCH_CASCADE = ["gaia", "tyc", "hip", "hd", "hr", "gj"]

# v3.3 calls the Gliese column `gl`; our table calls it `gj`.
V3_FIELD_FOR = {
    "gaia": "gaia", "tyc": "tyc", "hip": "hip",
    "hd": "hd", "hr": "hr", "gj": "gl",
}


def norm(value):
    """Normalise an identifier for comparison. Returns None for anything unusable.

    Numeric ids are compared as integers-rendered-as-strings so that '439', '439.0' and
    ' 439 ' are one key. Tycho ids ('6995-1264-1') are not numeric and pass through.

    **Integers are parsed as integers, never via float.** A Gaia DR3 source_id has 19
    digits and does not survive a float round-trip: float('2306965202564744064') comes
    back as 2306965202564744192. Gaia is the primary match key for 98.6% of this catalog,
    so routing it through float silently corrupts almost every match. Caught by
    test_regression_7301_maps_to_7323.
    """
    if value is None:
        return None
    s = str(value).strip()
    if not s:
        return None
    try:
        return str(int(s))
    except ValueError:
        pass
    try:
        f = float(s)
    except ValueError:
        return s
    # Only fold '439.0' -> '439' where the value is exactly representable; beyond 2^53 the
    # float has already lost digits and we must not invent replacements for them.
    if f == int(f) and abs(f) < 2 ** 53:
        return str(int(f))
    return s


def build_index(rows, field):
    """Index current stars by one identifier.

    Any identifier held by more than one current star is REMOVED from the index rather
    than resolved to the first. Those are GAIA-DUPLICATES' real binary components — Tycho-2
    resolves both, Gaia records one source — so the identifier genuinely names two stars.
    1,166 gaia ids and 61 hip ids are in this state.

    **This is more conservative than it needs to be, per the maintainer (2026-07-31):**
    resolving to the brighter component is perfectly acceptable. The two components of a
    close binary are at the same place on the map, and landing on either one is a far better
    outcome for an old link than refusing it. The original rationale here — that picking
    would be "the exact silent-wrong-star failure this feature exists to end" — conflated
    two very different errors: pointing at *the other component of the same binary* is not
    the same as pointing at *an unrelated star in another constellation*, which is what the
    renumbering did. Changing this is tracked in the ROADMAP; the refusal stands until then
    because it is the safe direction to be wrong in, not because it is right.

    Returns (index, ambiguous_count).
    """
    index = {}
    ambiguous = set()
    for row in rows:
        key = norm(row.get(field))
        if key is None:
            continue
        if key in index and index[key] != row["id"]:
            ambiguous.add(key)
        else:
            index.setdefault(key, row["id"])
    for key in ambiguous:
        index.pop(key, None)
    return index, len(ambiguous)


def read_v3_rows(paths):
    """Yield dict rows from the v3.3 CSVs.

    The second file HAS NO HEADER: its first line is data (id 1276083). Treating every
    file the same way — skipping line 1 — silently drops a star, so the header is detected
    by content rather than by position.
    """
    for path in paths:
        opener = gzip.open if str(path).endswith(".gz") else open
        with opener(path, "rt", newline="") as fh:
            reader = csv.reader(fh)
            for parts in reader:
                if not parts or len(parts) < 8:
                    continue
                if parts[0].strip() == "id":  # header line, only present in file 1
                    continue
                yield dict(zip(V3_COLUMNS, parts))


def match_rows(v3_rows, indexes):
    """Resolve each v3 row to a current athyg id via the cascade.

    Returns (output_rows, stats).
    """
    out = []
    stats = Counter()
    for row in v3_rows:
        stats["v3_rows"] += 1
        v3_id = norm(row.get("id"))
        if v3_id is None:
            stats["bad_v3_id"] += 1
            continue
        for method in MATCH_CASCADE:
            key = norm(row.get(V3_FIELD_FOR[method]))
            if key is None:
                continue
            current = indexes.get(method, {}).get(key)
            if current is not None:
                out.append((int(v3_id), int(current), method))
                stats["matched"] += 1
                stats[f"via_{method}"] += 1
                break
        else:
            stats["unmatched"] += 1
    return out, stats


def assert_no_duplicate_v3_ids(rows):
    """A v3 id must appear at most once. Two rows claiming one legacy id would make the
    lookup nondeterministic, which is the failure this feature is supposed to remove.
    """
    seen = Counter(r[0] for r in rows)
    dupes = [i for i, n in seen.items() if n > 1]
    if dupes:
        raise ValueError(
            f"{len(dupes)} v3 id(s) mapped more than once, e.g. {sorted(dupes)[:5]}. "
            "The v3 input probably contains duplicate rows."
        )


def connect_db():
    if psycopg2 is None:
        raise RuntimeError("psycopg2 is not installed; pip install -r requirements.txt")
    return psycopg2.connect(
        host=DB_HOST, port=DB_PORT, dbname=DB_NAME, user=DB_USER, password=DB_PASS
    )


def load_current_stars(cur):
    cur.execute("SELECT id, gaia, tyc, hip, hd, hr, gj FROM athyg")
    cols = ["id", "gaia", "tyc", "hip", "hd", "hr", "gj"]
    return [dict(zip(cols, r)) for r in cur.fetchall()]


def run(v3_paths, output_file):
    print("Loading current stars from the database...")
    with connect_db() as conn, conn.cursor() as cur:
        current = load_current_stars(cur)
    print(f"  {len(current):,} current stars")

    indexes = {}
    for method in MATCH_CASCADE:
        idx, ambiguous = build_index(current, method)
        indexes[method] = idx
        note = f" ({ambiguous:,} ambiguous, dropped)" if ambiguous else ""
        print(f"  index {method}: {len(idx):,} keys{note}")

    print("Matching v3.3 rows...")
    rows, stats = match_rows(read_v3_rows(v3_paths), indexes)
    assert_no_duplicate_v3_ids(rows)

    total = stats["v3_rows"]
    pct = 100.0 * stats["matched"] / total if total else 0.0
    print(f"  v3.3 rows:  {total:,}")
    print(f"  matched:    {stats['matched']:,} ({pct:.1f}%)")
    print(f"  unmatched:  {stats['unmatched']:,}")
    for method in MATCH_CASCADE:
        if stats[f"via_{method}"]:
            print(f"    via {method}: {stats[f'via_{method}']:,}")

    unchanged = sum(1 for v3, cur_id, _ in rows if v3 == cur_id)
    print(f"  v3_id == athyg_id: {unchanged:,} (everything else was renumbered)")

    with open(output_file, "w", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(CSV_COLUMNS)
        w.writerows(sorted(rows))
    print(f"Wrote {output_file} ({len(rows):,} rows)")
    return stats


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--v3", nargs="+", required=True,
                   help="AT-HYG v3.3 CSVs (.csv or .csv.gz), both parts")
    p.add_argument("--output", default=OUTPUT_FILE)
    args = p.parse_args(argv)
    run(args.v3, args.output)
    return 0


if __name__ == "__main__":
    sys.exit(main())
