#!/usr/bin/env python3
"""
Fill in missing constellations from RA/Dec.

Writes db/data/constellations.csv, which db/sql/10_import_constellations.sql applies.

WHY THIS IS NOT A CATALOGUE LOOKUP
----------------------------------
281,301 stars in athyg have no `con`, almost all of them inserted by the CNS5 and GCNS
imports, whose source data carries positions but no constellation. That makes them
unfindable by a constellation search — searching "Cyg" cannot return a star the database
does not know is in Cygnus.

The obvious fix is to ask SIMBAD. That would be wrong: a constellation is a pure function of
sky position, so it is computable offline and exactly, and querying a shared academic service
281,301 times for something derivable locally would be both slow and rude. This needs no
network at all.

HOW IT WORKS
------------
Constellation boundaries run along lines of constant right ascension and declination -- but in
the **B1875.0** equinox, which is what Delporte used when the IAU fixed them in 1930. A J2000
position cannot be compared against them directly; it has to be transformed to B1875 first.

astropy's `get_constellation` does that and is the reference implementation, so it is what runs
here. Two independent implementations are carried deliberately:

  * astropy (authoritative) -- correctly handles that B1875.0 is a *Besselian* epoch in the
    **FK4** frame, so the transform is not merely a precession rotation: it includes the
    FK4/FK5 equinox correction and the E-terms of aberration, both sub-arcsecond.
  * `constellation_independent()` below -- IAU 1976 precession angles plus a scan of
    db/data/constellation_boundaries.csv (Roman 1987, VizieR VI/42).

They agree on 99.993% of a 30,000-star sample. The hand-rolled version was written first, and
measuring it against astropy is what surfaced why the difference exists: every disagreement was
a star within about one arcsecond of a boundary, where the FK4 frame terms decide the answer.
One example precessed to dec -25.49998738 against a boundary at exactly -25.5000.

That check is kept as a test rather than deleted, because two implementations agreeing is
stronger evidence than either alone -- and it records the exact cost of dropping the astropy
dependency, should anyone want to.

Roman's table is ordered so a linear scan returns the right answer on the first hit: rows run
by descending declination, so the specific bands match before the catch-alls. **Do not sort or
reorder that file.**

USAGE
-----
    docker compose up -d hygmap-db
    DB_PASS=... python3 db/scripts/compute_constellations.py
    DB_PASS=... python3 db/scripts/compute_constellations.py --verify   # self-check only

Attribution: constellation boundary table from Roman, N.G. (1987), "Identification of a
Constellation from a Position", PASP 99, 695. VizieR catalogue VI/42.
"""
import argparse
import csv
import math
import os
import sys

BOUNDARIES_CSV = os.path.join(os.path.dirname(__file__), "..", "data", "constellation_boundaries.csv")
OUTPUT_CSV = os.path.join(os.path.dirname(__file__), "..", "data", "constellations.csv")

# Julian Date of B1875.0. Besselian epochs are not Julian epochs; B1875.0 is JD 2405889.25855,
# which is why this is a literal rather than a year arithmetic expression.
JD_B1875 = 2405889.25855
JD_J2000 = 2451545.0

# Known positions used by --verify. Chosen to cover a pole, both hemispheres, and a couple of
# stars close to a boundary where a missing precession step shows up.
VERIFY_CASES = [
    ("Polaris", 2.5301944, 89.264111, "UMi"),
    ("Sirius", 6.7524628, -16.716116, "CMa"),
    ("Vega", 18.6156500, 38.783689, "Lyr"),
    ("Betelgeuse", 5.9195297, 7.407064, "Ori"),
    ("Rigil Kentaurus", 14.6599681, -60.833975, "Cen"),
    ("Aldebaran", 4.5986939, 16.509300, "Tau"),
    ("Fomalhaut", 22.9608486, -29.622237, "PsA"),
    ("Deneb", 20.6905314, 45.280339, "Cyg"),
    ("Achernar", 1.6285706, -57.236753, "Eri"),
    ("Antares", 16.4901292, -26.432003, "Sco"),
]


def load_boundaries(path=BOUNDARIES_CSV):
    """Load the boundary table, preserving file order (which is load-bearing)."""
    with open(os.path.normpath(path), newline="") as fh:
        return [
            (float(r["ra_low_h"]), float(r["ra_up_h"]), float(r["dec_low_deg"]), r["con"])
            for r in csv.DictReader(fh)
        ]


def precess_to_b1875(ra_hours: float, dec_deg: float) -> tuple[float, float]:
    """
    Precess a J2000 position back to B1875.0.

    IAU 1976 precession angles. T is negative here because B1875 precedes J2000, which is what
    makes this run backwards without a separate inverse formulation.
    """
    t = (JD_B1875 - JD_J2000) / 36525.0

    # Arcseconds, then to radians.
    zeta = (2306.2181 * t + 0.30188 * t**2 + 0.017998 * t**3) * math.pi / 648000.0
    z = (2306.2181 * t + 1.09468 * t**2 + 0.018203 * t**3) * math.pi / 648000.0
    theta = (2004.3109 * t - 0.42665 * t**2 - 0.041833 * t**3) * math.pi / 648000.0

    ra = math.radians(ra_hours * 15.0)
    dec = math.radians(dec_deg)

    a = math.cos(dec) * math.sin(ra + zeta)
    b = math.cos(theta) * math.cos(dec) * math.cos(ra + zeta) - math.sin(theta) * math.sin(dec)
    c = math.sin(theta) * math.cos(dec) * math.cos(ra + zeta) + math.cos(theta) * math.sin(dec)

    ra_out = math.atan2(a, b) + z
    dec_out = math.asin(max(-1.0, min(1.0, c)))

    ra_hours_out = math.degrees(ra_out) / 15.0 % 24.0
    return ra_hours_out, math.degrees(dec_out)


def constellation_independent(ra_hours: float, dec_deg: float, boundaries) -> str | None:
    """
    Constellation for a J2000 position, computed without astropy.

    Kept as a cross-check on the authoritative astropy result, not used for the import. See the
    module docstring for why both exist and where they differ.
    """
    ra_1875, dec_1875 = precess_to_b1875(ra_hours, dec_deg)

    for ra_low, ra_up, dec_low, con in boundaries:
        if dec_1875 < dec_low:
            continue
        if ra_low <= ra_1875 < ra_up:
            return con
    return None


def constellations_astropy(positions):
    """
    Authoritative constellation lookup, vectorised.

    @param positions list of (ra_hours, dec_deg)
    @return list of three-letter abbreviations, aligned with the input
    """
    from astropy.coordinates import SkyCoord, get_constellation
    import astropy.units as u

    coords = SkyCoord(
        ra=[p[0] * 15.0 for p in positions] * u.deg,
        dec=[p[1] for p in positions] * u.deg,
        frame="icrs",
    )
    result = get_constellation(coords, short_name=True)
    # A single-element SkyCoord returns a bare string rather than an array.
    return [result] if isinstance(result, str) else list(result)


def verify(boundaries) -> int:
    """Check the algorithm against known stars. Returns the number of failures."""
    failures = 0
    astro = constellations_astropy([(ra, dec) for _n, ra, dec, _e in VERIFY_CASES])
    print(f"{'star':<18} {'expected':<9} {'astropy':<9} {'no-deps':<9} result")
    for (name, ra, dec, expected), got_a in zip(VERIFY_CASES, astro):
        got_i = constellation_independent(ra, dec, boundaries)
        ok = got_a == expected and got_i == expected
        failures += 0 if ok else 1
        print(
            f"{name:<18} {expected:<9} {got_a or '(none)':<9} {got_i or '(none)':<9} "
            f"{'ok' if ok else 'MISMATCH'}"
        )
    return failures


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--verify", action="store_true", help="run the self-check and exit")
    ap.add_argument("--dry-run", action="store_true", help="report, write nothing")
    args = ap.parse_args()

    boundaries = load_boundaries()
    print(f"loaded {len(boundaries)} boundary rows\n")

    failures = verify(boundaries)
    if failures:
        return f"self-check failed for {failures} known star(s); not writing anything"
    print("\nself-check passed for all known stars")
    if args.verify:
        return 0

    try:
        import psycopg2
    except ImportError:
        return "psycopg2 is required: pip install -r db/scripts/requirements.txt"

    conn = psycopg2.connect(
        host=os.environ.get("DB_HOST", "localhost"),
        port=os.environ.get("DB_PORT", "5432"),
        dbname=os.environ.get("DB_NAME", "hygmap"),
        user=os.environ.get("DB_USER", "hygmap_user"),
        password=os.environ.get("DB_PASS", os.environ.get("POSTGRES_PASSWORD", "")),
    )
    with conn, conn.cursor() as cur:
        # Sol is excluded deliberately. It sits at the coordinate origin because it *is* the
        # origin, not because it lies at RA 0h Dec 0 -- so the lookup would place the Sun in
        # Pisces, which is meaningless: you cannot be in a constellation you are inside of.
        # It is the only row at the exact origin (verified), and its con is correctly NULL.
        cur.execute(
            "SELECT id, ra, dec FROM athyg "
            "WHERE con IS NULL AND ra IS NOT NULL AND dec IS NOT NULL "
            "  AND NOT (ra = 0 AND dec = 0) "
            "ORDER BY id"
        )
        targets = cur.fetchall()
    conn.close()

    print(f"\nstars missing a constellation: {len(targets):,}")
    if not targets:
        print("nothing to do")
        return 0

    rows, unresolved = [], 0
    BATCH = 20000  # keeps peak memory sane on 280k+ rows without slowing things down
    for start in range(0, len(targets), BATCH):
        chunk = targets[start : start + BATCH]
        cons = constellations_astropy([(float(r[1]), float(r[2])) for r in chunk])
        for (star_id, _ra, _dec), con in zip(chunk, cons):
            if not con:
                unresolved += 1
                continue
            rows.append({"athyg_id": star_id, "con": con})
        print(f"  {min(start + BATCH, len(targets)):,}/{len(targets):,}", flush=True)

    print(f"\n  resolved:   {len(rows):,}")
    print(f"  unresolved: {unresolved:,}")

    if args.dry_run:
        print("\n--dry-run: not writing")
        return 0

    path = os.path.normpath(OUTPUT_CSV)
    with open(path, "w", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=["athyg_id", "con"])
        writer.writeheader()
        writer.writerows(rows)
    print(f"\nwrote {len(rows):,} rows to {path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
