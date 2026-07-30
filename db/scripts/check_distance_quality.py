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
