"""
match_cns5.py — Cross-match CNS5 catalog against the live athyg database.

Reads cns5.dat (fixed-width), queries the hygmap-db PostgreSQL container,
and outputs db/data/cns5.csv for SQL import.

Usage:
    docker compose up -d hygmap-db
    cd db/scripts
    pip install -r requirements.txt
    python match_cns5.py
"""

import csv
import math
import os
from collections import Counter

import psycopg2

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

INPUT_FILE = os.environ.get("CNS5_INPUT", "cns5.dat")
OUTPUT_FILE = os.environ.get("CNS5_OUTPUT", "../data/cns5.csv")

DB_HOST = os.environ.get("DB_HOST", "localhost")
DB_PORT = int(os.environ.get("DB_PORT", "5432"))
DB_NAME = os.environ.get("DB_NAME", "hygmap")
DB_USER = os.environ.get("DB_USER", "hygmap_user")
DB_PASS = os.environ.get("DB_PASS", "hygmap_pass")

# First new-star ID (must not collide with existing athyg rows)
NEW_ID_START = 5_000_000

# Positional match radius in arcseconds
POS_RADIUS_ARCSEC = 2.0
POS_RADIUS_HIGH_PM_ARCSEC = 5.0  # for stars with PM > 500 mas/yr
HIGH_PM_THRESHOLD = 500.0  # mas/yr total proper motion

CSV_COLUMNS = [
    "athyg_id", "match_method", "cns5_id", "gj", "gj_comp",
    "gaia", "hip", "ra_j2000", "dec_j2000", "dist",
    "mag", "absmag", "spect", "rv", "pm_ra", "pm_dec",
    "x_eq", "y_eq", "z_eq", "bright_unmatched",
]


# ---------------------------------------------------------------------------
# Parsing
# ---------------------------------------------------------------------------

def safe_float(s):
    """Parse a float from a fixed-width field, returning None for blanks/dashes."""
    s = s.strip()
    if not s or s == "-":
        return None
    try:
        return float(s)
    except ValueError:
        return None


def safe_int(s):
    """Parse an int from a fixed-width field, returning None for blanks/dashes."""
    s = s.strip()
    if not s or s == "-":
        return None
    try:
        return int(s)
    except ValueError:
        return None


def safe_str(s):
    """Strip a fixed-width string field, returning None for blanks/dashes."""
    s = s.strip()
    if not s or s == "-":
        return None
    return s


def parse_line(line):
    """Parse one fixed-width record from cns5.dat.

    Returns a dict with parsed fields, or None if the line is unparseable.
    Byte positions are from the CNS5 ReadMe (1-indexed -> 0-indexed slicing).
    """
    # Pad short lines so slicing doesn't fail
    line = line.ljust(761)

    rec = {}
    rec["cns5_id"] = safe_int(line[0:4])
    rec["gj"] = safe_str(line[5:11])
    rec["comp"] = safe_str(line[12:16])
    rec["ncomp"] = safe_int(line[17:18])
    rec["primary_flag"] = safe_int(line[19:20])
    rec["gj_primary"] = safe_str(line[21:26])
    rec["gaia"] = safe_str(line[27:46])
    rec["hip"] = safe_str(line[47:53])
    rec["ra_deg"] = safe_float(line[54:74])
    rec["dec_deg"] = safe_float(line[75:98])
    rec["epoch"] = safe_float(line[99:108])
    rec["parallax"] = safe_float(line[129:148])
    rec["pmra"] = safe_float(line[183:206])
    rec["pmdec"] = safe_float(line[229:252])
    rec["rv"] = safe_float(line[296:319])
    rec["g_mag"] = safe_float(line[361:371])
    rec["bp_mag"] = safe_float(line[394:404])
    rec["rp_mag"] = safe_float(line[427:437])

    if rec["cns5_id"] is None:
        return None
    return rec


# ---------------------------------------------------------------------------
# Astrometric helpers
# ---------------------------------------------------------------------------

def propagate_to_j2000(ra_deg, dec_deg, epoch, pmra_masyr, pmdec_masyr):
    """Propagate a position from *epoch* to J2000.0 using proper motions.

    pmra is already mu_alpha* (i.e. includes cos(dec) factor) in mas/yr.
    Returns (ra_j2000_deg, dec_j2000_deg).
    """
    if epoch is None or pmra_masyr is None or pmdec_masyr is None:
        return ra_deg, dec_deg  # can't propagate, return as-is
    dt = 2000.0 - epoch  # negative for epochs after J2000
    dec_rad = math.radians(dec_deg)
    cos_dec = math.cos(dec_rad)
    if cos_dec == 0:
        return ra_deg, dec_deg
    # Convert mas to degrees: 1 mas = 1/3_600_000 deg
    ra_j2000 = ra_deg + (pmra_masyr * dt) / (3_600_000.0 * cos_dec)
    dec_j2000 = dec_deg + (pmdec_masyr * dt) / 3_600_000.0
    # Wrap RA to [0, 360)
    ra_j2000 = ra_j2000 % 360.0
    return ra_j2000, dec_j2000


def estimate_vmag(g_mag, bp_mag, rp_mag):
    """Estimate Johnson V from Gaia G and BP-RP color index.

    Uses the polynomial: V = G - (-0.01760 - 0.006860*X + 0.1732*X^2)
    where X = BP - RP.
    Returns V magnitude or None.
    """
    if g_mag is None:
        return None
    if bp_mag is not None and rp_mag is not None:
        bp_rp = bp_mag - rp_mag
        correction = -0.01760 - 0.006860 * bp_rp + 0.1732 * bp_rp ** 2
        return g_mag - correction
    return None


def compute_equatorial_coords(ra_hours, dec_deg, dist_pc):
    """Compute equatorial Cartesian coordinates (x_eq, y_eq, z_eq).

    ra_hours: RA in decimal hours
    dec_deg:  Declination in degrees
    dist_pc:  Distance in parsecs
    """
    if ra_hours is None or dec_deg is None or dist_pc is None:
        return None, None, None
    ra_rad = math.radians(ra_hours * 15.0)
    dec_rad = math.radians(dec_deg)
    x = dist_pc * math.cos(dec_rad) * math.cos(ra_rad)
    y = dist_pc * math.cos(dec_rad) * math.sin(ra_rad)
    z = dist_pc * math.sin(dec_rad)
    return x, y, z


def estimate_spectral_type(bp_rp, absmag):
    """Estimate a rough spectral type from Gaia BP-RP color and absolute magnitude.

    Returns a string like "G V", "K III", "D" (white dwarf), "L" (brown dwarf),
    or None if classification is not possible.

    Uses absolute magnitude to determine luminosity class:
      III (giant), IV (subgiant), V (dwarf) by comparing against
      expected main-sequence Mv for the color.
    """
    if bp_rp is None:
        return None

    # Brown dwarf: very red
    if bp_rp >= 4.0:
        return "L"

    # White dwarf: faint with blue/moderate color
    if absmag is not None and absmag > 10 and bp_rp < 1.5:
        return "D"

    # Without absolute magnitude, moderate colors are ambiguous
    if absmag is None:
        if bp_rp > 2.5:
            return "M V"  # very red, virtually certain M dwarf within 25 pc
        if bp_rp >= 0.5:
            return None  # ambiguous without absmag

    # Spectral letter + expected main-sequence absolute magnitude
    # BP-RP boundaries based on Gaia EDR3 color-spectral type calibrations
    if bp_rp < -0.3:
        letter, ms_mv = "O", -4.0
    elif bp_rp < 0.0:
        letter, ms_mv = "B", 0.0
    elif bp_rp < 0.35:
        letter, ms_mv = "A", 1.5
    elif bp_rp < 0.65:
        letter, ms_mv = "F", 3.5
    elif bp_rp < 1.0:
        letter, ms_mv = "G", 5.0
    elif bp_rp < 1.85:
        letter, ms_mv = "K", 7.0
    else:
        letter, ms_mv = "M", 11.0

    # Determine luminosity class from absolute magnitude.
    # Require BOTH a relative offset from the MS AND an absolute brightness
    # threshold to prevent dim stars from being misclassified as evolved.
    if absmag is None:
        return f"{letter} V"  # default to dwarf for blue stars without absmag

    if absmag < ms_mv - 3.0 and absmag < 2.0:
        return f"{letter} III"  # giant
    if absmag < ms_mv - 1.5 and absmag < 4.0:
        return f"{letter} IV"  # subgiant
    return f"{letter} V"  # dwarf / main sequence


def extract_gj_component(gj_str):
    """Extract trailing component letter(s) from a GJ designation.

    Examples: '127A' -> 'A', '822.1C' -> 'C', '551' -> None, '822.1' -> None
    """
    if not gj_str:
        return None
    gj_str = gj_str.strip()
    if not gj_str:
        return None
    # Find where trailing alpha characters start
    i = len(gj_str)
    while i > 0 and gj_str[i - 1].isalpha():
        i -= 1
    if i == 0 or i == len(gj_str):
        return None  # all alpha (e.g. "Sun") or no trailing alpha
    return gj_str[i:]


# ---------------------------------------------------------------------------
# Database helpers
# ---------------------------------------------------------------------------

def connect_db():
    """Connect to the hygmap PostgreSQL database."""
    return psycopg2.connect(
        host=DB_HOST, port=DB_PORT,
        dbname=DB_NAME, user=DB_USER, password=DB_PASS,
    )


def load_gj_index(cur):
    """Load gj -> athyg.id mapping into memory for fast lookup."""
    print("  Loading GJ index ...")
    cur.execute("SELECT gj, id FROM athyg WHERE gj IS NOT NULL")
    index = {}
    for gj, aid in cur:
        index[gj] = aid
    print(f"    {len(index)} GJ IDs loaded.")
    return index


def load_gaia_index(cur):
    """Load gaia -> athyg.id mapping into memory for fast lookup."""
    print("  Loading Gaia index ...")
    cur.execute("SELECT gaia, id FROM athyg WHERE gaia IS NOT NULL")
    index = {}
    for gaia, aid in cur:
        index[gaia] = aid
    print(f"    {len(index)} Gaia IDs loaded.")
    return index


def load_hip_index(cur):
    """Load hip -> athyg.id mapping into memory for fast lookup."""
    print("  Loading HIP index ...")
    cur.execute("SELECT hip, id FROM athyg WHERE hip IS NOT NULL")
    index = {}
    for hip, aid in cur:
        index[hip] = aid
    print(f"    {len(index)} HIP IDs loaded.")
    return index


def load_id_to_gj_index(cur):
    """Load athyg.id -> gj reverse mapping for component validation."""
    print("  Loading ID->GJ reverse index ...")
    cur.execute("SELECT id, gj FROM athyg WHERE gj IS NOT NULL")
    index = {}
    for aid, gj in cur:
        index[aid] = gj
    print(f"    {len(index)} entries loaded.")
    return index


def match_by_position(cur, ra_j2000_deg, dec_j2000_deg, radius_arcsec):
    """Try to match by J2000 position within a box. Returns athyg.id or None.

    athyg stores RA in hours and Dec in degrees.
    """
    if ra_j2000_deg is None or dec_j2000_deg is None:
        return None

    ra_hours = ra_j2000_deg / 15.0
    dec = dec_j2000_deg

    # Convert radius from arcsec to degrees, then RA degrees to hours
    radius_deg = radius_arcsec / 3600.0
    dec_rad = math.radians(dec)
    cos_dec = math.cos(dec_rad)
    if cos_dec == 0:
        return None
    radius_ra_hours = radius_deg / (15.0 * cos_dec)
    radius_dec_deg = radius_deg

    cur.execute("""
        SELECT id, ra, dec,
               SQRT(POWER((ra - %s) * 15.0 * COS(RADIANS(dec)), 2)
                  + POWER(dec - %s, 2)) AS sep_deg
        FROM athyg
        WHERE ra BETWEEN %s AND %s
          AND dec BETWEEN %s AND %s
          AND ra IS NOT NULL AND dec IS NOT NULL
        ORDER BY sep_deg
        LIMIT 1
    """, (
        ra_hours, dec,
        ra_hours - radius_ra_hours, ra_hours + radius_ra_hours,
        dec - radius_dec_deg, dec + radius_dec_deg,
    ))
    row = cur.fetchone()
    if row is None:
        return None
    # Verify the match is actually within the angular radius
    sep_deg = row[3]
    if sep_deg <= radius_deg:
        return row[0]
    return None


# ---------------------------------------------------------------------------
# Output row builder
# ---------------------------------------------------------------------------

def build_output_row(rec, athyg_id, match_method, bright_unmatched):
    """Build one CSV output row dict from a parsed CNS5 record."""
    # Propagate position to J2000
    ra_j2000_deg = rec["ra_deg"]
    dec_j2000_deg = rec["dec_deg"]
    if rec["ra_deg"] is not None and rec["dec_deg"] is not None:
        ra_j2000_deg, dec_j2000_deg = propagate_to_j2000(
            rec["ra_deg"], rec["dec_deg"], rec["epoch"],
            rec["pmra"], rec["pmdec"],
        )

    ra_j2000_hours = ra_j2000_deg / 15.0 if ra_j2000_deg is not None else None
    dist_pc = None
    if rec["parallax"] is not None and rec["parallax"] > 0:
        dist_pc = 1000.0 / rec["parallax"]

    v_mag = estimate_vmag(rec["g_mag"], rec["bp_mag"], rec["rp_mag"])
    absmag = None
    if v_mag is not None and dist_pc is not None and dist_pc > 0:
        absmag = v_mag - 5.0 * math.log10(dist_pc) + 5.0

    x_eq, y_eq, z_eq = compute_equatorial_coords(
        ra_j2000_hours, dec_j2000_deg, dist_pc
    )

    # Estimate spectral type from color index
    bp_rp = None
    if rec["bp_mag"] is not None and rec["rp_mag"] is not None:
        bp_rp = rec["bp_mag"] - rec["rp_mag"]
    spect = estimate_spectral_type(bp_rp, absmag)

    return {
        "athyg_id": athyg_id,
        "match_method": match_method,
        "cns5_id": rec["cns5_id"],
        "gj": rec["gj"] or "",
        "gj_comp": rec["comp"] or "",
        "gaia": rec["gaia"] or "",
        "hip": rec["hip"] or "",
        "ra_j2000": f"{ra_j2000_hours:.10f}" if ra_j2000_hours is not None else "",
        "dec_j2000": f"{dec_j2000_deg:.10f}" if dec_j2000_deg is not None else "",
        "dist": f"{dist_pc:.6f}" if dist_pc is not None else "",
        "mag": f"{v_mag:.4f}" if v_mag is not None else "",
        "absmag": f"{absmag:.4f}" if absmag is not None else "",
        "spect": spect or "",
        "rv": f"{rec['rv']:.4f}" if rec["rv"] is not None else "",
        "pm_ra": f"{rec['pmra']:.4f}" if rec["pmra"] is not None else "",
        "pm_dec": f"{rec['pmdec']:.4f}" if rec["pmdec"] is not None else "",
        "x_eq": f"{x_eq:.6f}" if x_eq is not None else "",
        "y_eq": f"{y_eq:.6f}" if y_eq is not None else "",
        "z_eq": f"{z_eq:.6f}" if z_eq is not None else "",
        "bright_unmatched": bright_unmatched,
    }


# ---------------------------------------------------------------------------
# Main pipeline
# ---------------------------------------------------------------------------

def run():
    print(f"Reading {INPUT_FILE} ...")
    with open(INPUT_FILE, "r", encoding="latin-1") as f:
        raw_lines = f.readlines()
    print(f"  {len(raw_lines)} lines read.")

    # Parse all records
    records = []
    parse_errors = 0
    for lineno, line in enumerate(raw_lines, 1):
        rec = parse_line(line)
        if rec is None:
            parse_errors += 1
            print(f"  WARNING: could not parse line {lineno}")
            continue
        # Skip the Sun record
        if rec["gj"] == "Sun" or rec["cns5_id"] == 0:
            print(f"  Skipping Sun record (line {lineno})")
            continue
        records.append(rec)

    print(f"  {len(records)} records to match ({parse_errors} parse errors).")

    # Connect to database and load lookup indexes into memory
    print(f"\nConnecting to {DB_HOST}:{DB_PORT}/{DB_NAME} ...")
    conn = connect_db()
    cur = conn.cursor()
    print("  Connected.")

    gj_index = load_gj_index(cur)
    gaia_index = load_gaia_index(cur)
    hip_index = load_hip_index(cur)
    id_to_gj_index = load_id_to_gj_index(cur)

    # Track which athyg IDs have already been claimed (one per star)
    claimed_ids = {}  # athyg_id -> cns5_id of first claimer
    next_new_id = NEW_ID_START
    method_counts = Counter()
    bright_unmatched_count = 0
    duplicate_as_new_count = 0
    comp_mismatch_count = 0
    output_rows = []

    print(f"\nMatching {len(records)} CNS5 records ...")

    for i, rec in enumerate(records):
        if (i + 1) % 1000 == 0:
            print(f"  {i+1}/{len(records)} ...")

        athyg_id = None
        match_method = None

        # --- Step 1: GJ number (in-memory lookup) ---
        if rec["gj"] is not None:
            athyg_id = gj_index.get(rec["gj"])
            # Try GJ + component (e.g., "150.1" + "B" -> "150.1B")
            if athyg_id is None and rec["comp"] is not None:
                athyg_id = gj_index.get(rec["gj"] + rec["comp"])
            if athyg_id is not None:
                match_method = "gj_id"

        # --- Step 2: Gaia source_id (in-memory lookup) ---
        if athyg_id is None and rec["gaia"] is not None:
            athyg_id = gaia_index.get(rec["gaia"])
            if athyg_id is not None:
                match_method = "gaia_source_id"

        # --- Step 3: HIP ID (in-memory lookup) ---
        if athyg_id is None and rec["hip"] is not None:
            athyg_id = hip_index.get(rec["hip"])
            if athyg_id is not None:
                match_method = "hip_id"

        # --- Step 4: Positional match (epoch-corrected, DB query) ---
        if athyg_id is None and rec["ra_deg"] is not None and rec["dec_deg"] is not None:
            ra_j2000, dec_j2000 = propagate_to_j2000(
                rec["ra_deg"], rec["dec_deg"], rec["epoch"],
                rec["pmra"], rec["pmdec"],
            )
            total_pm = 0.0
            if rec["pmra"] is not None and rec["pmdec"] is not None:
                total_pm = math.sqrt(rec["pmra"] ** 2 + rec["pmdec"] ** 2)
            radius = POS_RADIUS_HIGH_PM_ARCSEC if total_pm > HIGH_PM_THRESHOLD else POS_RADIUS_ARCSEC

            athyg_id = match_by_position(cur, ra_j2000, dec_j2000, radius)
            if athyg_id is not None:
                match_method = "position_j2000"

        # --- Component validation for non-GJ matches ---
        if athyg_id is not None and match_method != "gj_id":
            athyg_gj = id_to_gj_index.get(athyg_id)
            athyg_comp = extract_gj_component(athyg_gj)
            cns5_comp = rec["comp"]
            if athyg_comp is not None and cns5_comp is not None:
                if athyg_comp not in cns5_comp:
                    print(f"  NOTE: CNS5 {rec['cns5_id']} (GJ {rec['gj']} {cns5_comp}) "
                          f"matched athyg {athyg_id} (GJ {athyg_gj}) via {match_method} "
                          f"but component {cns5_comp} != {athyg_comp} — cancelled")
                    athyg_id = None
                    match_method = None
                    comp_mismatch_count += 1

        # --- Duplicate check: each athyg_id can only be claimed once ---
        if athyg_id is not None and athyg_id in claimed_ids:
            prev_cns5 = claimed_ids[athyg_id]
            comp_label = rec["comp"] or ""
            print(f"  NOTE: athyg {athyg_id} already claimed by CNS5 {prev_cns5}; "
                  f"CNS5 {rec['cns5_id']} (GJ {rec['gj']}{comp_label}) "
                  f"-> treating as new star")
            athyg_id = None
            duplicate_as_new_count += 1

        if athyg_id is not None:
            claimed_ids[athyg_id] = rec["cns5_id"]

        # --- Brightness check for unmatched ---
        bright_unmatched = 0
        if athyg_id is None:
            v_est = estimate_vmag(rec["g_mag"], rec["bp_mag"], rec["rp_mag"])
            if v_est is not None and v_est <= 11.0:
                bright_unmatched = 1
                bright_unmatched_count += 1
            elif v_est is None and rec["g_mag"] is not None and rec["g_mag"] <= 10.9:
                bright_unmatched = 1
                bright_unmatched_count += 1

        # --- Assign new ID if unmatched ---
        if athyg_id is None:
            match_method = "new"
            athyg_id = next_new_id
            next_new_id += 1

        method_counts[match_method] += 1
        output_rows.append(build_output_row(rec, athyg_id, match_method, bright_unmatched))

    cur.close()
    conn.close()

    # --- Write CSV ---
    print(f"\nWriting {len(output_rows)} rows to {OUTPUT_FILE} ...")
    os.makedirs(os.path.dirname(os.path.abspath(OUTPUT_FILE)), exist_ok=True)
    with open(OUTPUT_FILE, "w", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=CSV_COLUMNS)
        writer.writeheader()
        writer.writerows(output_rows)
    print("  Done.")

    # --- Audit report ---
    print("\n=== CNS5 Match Audit Report ===")
    print(f"Total records processed: {len(records)}")
    print(f"Total output rows:       {len(output_rows)}")
    print()
    print("Match method breakdown:")
    for method in ["gj_id", "gaia_source_id", "hip_id", "position_j2000", "new"]:
        count = method_counts.get(method, 0)
        pct = 100.0 * count / len(output_rows) if output_rows else 0
        print(f"  {method:20s}: {count:5d} ({pct:5.1f}%)")
    print()
    new_count = method_counts.get("new", 0)
    print(f"Bright unmatched (V<=11): {bright_unmatched_count}")
    print(f"Component mismatches:     {comp_mismatch_count}")
    print(f"Duplicate->new redirects: {duplicate_as_new_count}")
    print(f"New star IDs assigned:    {NEW_ID_START} - {next_new_id - 1} ({new_count} total)")


if __name__ == "__main__":
    run()
