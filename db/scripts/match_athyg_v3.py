"""
match_athyg_v3.py — Map AT-HYG v3.3 star ids onto current (v4.0) athyg ids.

The AT-HYG 4 migration renumbered every star: `athyg.id` is the source catalog's row id,
not ours, and v4 assigned new ones. Every link anyone saved before that migration now
points at a different star, silently. This builds the mapping that lets those links be
recognised, so the app can say "this used to mean X" instead of quietly showing Y.

Reads the two v3.3 CSVs, queries the live athyg table for current identifiers, and writes
db/data/athyg_v3_ids.csv for SQL import, as ranges:

    v3_start,v3_end,offset,match_method

meaning: for every v3 id from v3_start to v3_end inclusive, athyg_id = v3_id + offset.
11_import_athyg_v3_ids.sql expands that back to one row per id with generate_series; the
athyg_v3_ids table and the API are unchanged by this, it is purely how the mapping is
stored on disk.

Ranges rather than 2.5M individual rows because the renumbering moved the catalog in
blocks rather than shuffling it, so the offset is constant over long runs — 78,733 ranges
instead of 2,552,145 rows, 51 MB down to 2 MB. See to_ranges().

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

CSV_COLUMNS = ["v3_start", "v3_end", "offset", "match_method"]

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


def brightness_rank(row):
    """Sort key that puts the brighter star first. Lower magnitude is brighter.

    A star with no magnitude sorts last, not brightest — `None` is "we don't know", and
    treating an unknown as -inf would hand every tie to the row with the least data. `id`
    breaks exact ties so the choice is deterministic across runs and machines; without it
    the index would depend on the order Postgres happened to return rows in, and the
    committed CSV would churn for no reason.
    """
    mag = row.get("mag")
    try:
        mag = float(mag)
    except (TypeError, ValueError):
        mag = None
    return (mag is None, mag if mag is not None else 0.0, row["id"])


def build_index(rows, field):
    """Index current stars by one identifier, resolving collisions to the brighter star.

    An identifier held by more than one current star is GAIA-DUPLICATES' real binary
    components — Tycho-2 resolves both, Gaia records one source — so the identifier
    genuinely names two stars. 1,166 gaia ids and 61 hip ids are in this state.

    **Those resolve to the brighter component (maintainer decision, 2026-07-31).** The two
    components of a close binary sit at the same point on the map, so landing on either is
    a far better outcome for an old link than refusing it. The earlier code dropped these
    keys, on the rationale that picking would be "the exact silent-wrong-star failure this
    feature exists to end" — which conflated two different errors: pointing at *the other
    component of the same binary* is not pointing at *an unrelated star in another
    constellation*, which is what the renumbering did.

    The count is still returned and still reported, because "1,166 of these were a judgement
    call" is worth printing even when the judgement is settled.

    Returns (index, ambiguous_count).
    """
    index = {}
    ambiguous = set()
    for row in rows:
        key = norm(row.get(field))
        if key is None:
            continue
        incumbent = index.get(key)
        if incumbent is None:
            index[key] = row
            continue
        if incumbent["id"] == row["id"]:
            continue
        ambiguous.add(key)
        if brightness_rank(row) < brightness_rank(incumbent):
            index[key] = row
    return {k: r["id"] for k, r in index.items()}, len(ambiguous)


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


def to_ranges(rows):
    """Collapse (v3_id, athyg_id, method) triples into contiguous ranges.

    The AT-HYG 4 renumbering did not shuffle the catalog — it moved it in blocks, so
    `athyg_id - v3_id` stays constant across long runs of consecutive v3 ids. Measured on
    the real mapping: 2,552,145 rows collapse to 6,582 distinct offset runs, and to 78,733
    ranges once `match_method` (which alternates between gaia and tyc) is allowed to break
    them. That is the difference between a 51 MB file and a 2 MB one, holding exactly the
    same information.

    The one-row-per-id form was the obvious way to write this and it hid the structure: a
    2.5M-row file tells you nothing, whereas 78,733 ranges make the block-structured
    renumbering visible on inspection.

    Emits (v3_start, v3_end, offset, match_method). A range breaks when the offset
    changes, when the method changes, or when a v3 id is missing (the 22 stars that did
    not survive to v4 leave real gaps, and they must stay gaps — expanding across one
    would invent a mapping for a dead link).
    """
    ordered = sorted(rows)
    out = []
    start = prev = None
    key = None

    for v3_id, athyg_id, method in ordered:
        this_key = (athyg_id - v3_id, method)
        contiguous = prev is not None and v3_id == prev + 1
        if key != this_key or not contiguous:
            if key is not None:
                out.append((start, prev, key[0], key[1]))
            start = v3_id
            key = this_key
        prev = v3_id

    if key is not None:
        out.append((start, prev, key[0], key[1]))
    return out


def from_ranges(ranges):
    """Expand ranges back into (v3_id, athyg_id, method) triples.

    The inverse of to_ranges(). Not used by the import — Postgres does the expansion in
    11_import_athyg_v3_ids.sql — but it is what makes the encoding testable as a
    round-trip property rather than by eyeballing a sample, and it documents the decode
    rule in the same file as the encode rule.
    """
    out = []
    for v3_start, v3_end, offset, method in ranges:
        for v3_id in range(v3_start, v3_end + 1):
            out.append((v3_id, v3_id + offset, method))
    return out


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
    # `mag` is not an identifier — build_index() uses it to pick the brighter component
    # when two current stars share one legacy identifier.
    cur.execute("SELECT id, gaia, tyc, hip, hd, hr, gj, mag FROM athyg")
    cols = ["id", "gaia", "tyc", "hip", "hd", "hr", "gj", "mag"]
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
        note = f" ({ambiguous:,} shared by two stars, resolved to the brighter)" if ambiguous else ""
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

    ranges = to_ranges(rows)
    if from_ranges(ranges) != sorted(rows):
        raise ValueError(
            "Range encoding did not round-trip. Refusing to write a mapping that does not "
            "expand back to what was matched."
        )

    with open(output_file, "w", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(CSV_COLUMNS)
        w.writerows(ranges)
    print(
        f"Wrote {output_file} ({len(ranges):,} ranges covering {len(rows):,} ids)"
    )
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
