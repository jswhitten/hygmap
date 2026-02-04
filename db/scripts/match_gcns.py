"""
match_gcns.py — Cross-match GCNS (Gaia Catalogue of Nearby Stars) against the
live athyg database.

Reads gcns.csv (downloaded from VizieR), queries the hygmap-db PostgreSQL
container, and outputs db/data/gcns.csv for SQL import.

GCNS was published 2021 as part of Gaia EDR3 and contains 331,312 objects
within 100 pc of the Sun. EDR3/DR3 source_ids are identical, so we can match
directly against the athyg.gaia column.

Matching pipeline (short-circuiting, ordered):
  1. Gaia source_id exact match (primary path)
  2. Positional match with epoch propagation (J2016→J2000)
  3. Brightness sanity check for unmatched stars
  4. Insert as new star

Usage:
    docker compose up -d hygmap-db
    cd db/scripts
    pip install -r requirements.txt
    python match_gcns.py
"""

import csv
import math
import os
from collections import Counter

import psycopg2

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

INPUT_FILE = os.environ.get("GCNS_INPUT", "table1c.dat")
OUTPUT_FILE = os.environ.get("GCNS_OUTPUT", "../data/gcns.csv")

DB_HOST = os.environ.get("DB_HOST", "localhost")
DB_PORT = int(os.environ.get("DB_PORT", "5432"))
DB_NAME = os.environ.get("DB_NAME", "hygmap")
DB_USER = os.environ.get("DB_USER", "hygmap_user")
DB_PASS = os.environ.get("DB_PASS", "hygmap_pass")

# First new-star ID (must not collide with existing athyg rows or CNS5 new stars)
# CNS5 uses 5_000_000+, so GCNS uses 6_000_000+
NEW_ID_START = 6_000_000

# Positional match radius in arcseconds
POS_RADIUS_ARCSEC = 2.0
POS_RADIUS_HIGH_PM_ARCSEC = 5.0  # for stars with PM > 500 mas/yr
HIGH_PM_THRESHOLD = 500.0  # mas/yr total proper motion

# Magnitude tolerance for positional matching (reject if |delta_mag| > this)
# 3 mag allows for variability, color differences, and measurement errors
MAG_TOLERANCE = 3.0

# GCNS epoch (Gaia EDR3)
GCNS_EPOCH = 2016.0

# Minimum probability threshold for import (prob100 = probability within 100 pc)
MIN_PROB100 = 0.5

CSV_COLUMNS = [
    "athyg_id", "match_method", "source_id",
    "ra_j2000", "dec_j2000", "dist",
    "mag", "absmag", "spect", "pm_ra", "pm_dec",
    "x_eq", "y_eq", "z_eq",
    "prob100", "probastr",
    "bright_unmatched",
]


# ---------------------------------------------------------------------------
# Parsing
# ---------------------------------------------------------------------------

def safe_float(s):
    """Parse a float from a CSV field, returning None for blanks/empty."""
    if s is None:
        return None
    s = str(s).strip()
    if not s or s == "" or s.lower() == "nan":
        return None
    try:
        return float(s)
    except ValueError:
        return None


def safe_str(s):
    """Strip a string field, returning None for blanks."""
    if s is None:
        return None
    s = str(s).strip()
    if not s:
        return None
    return s


def parse_fixed_width_line(line):
    """Parse one line from table1c.dat (fixed-width format).

    Byte positions from table1c.format (1-indexed, inclusive):
      3-21:   GaiaEDR3 source_id (I19)
      23-36:  RAdeg (F14.7)
      46-59:  DEdeg (F14.7)
      69-77:  Plx parallax (F9.3)
      87-95:  pmRA (F9.3)
      105-113: pmDE (F9.3)
      123-130: Gmag (F8.4)
      142-149: BPmag (F8.4)
      161-168: RPmag (F8.4)
      240-244: GCNSprob (F5.3) - probability of good astrometry
      246-250: WDprob (F5.3) - white dwarf probability
    
    Note: Python slicing is 0-indexed and end-exclusive, so bytes N-M become [N-1:M]
    """
    rec = {}

    # Extract fields using byte positions (convert 1-indexed to 0-indexed slicing)
    rec["source_id"] = safe_str(line[2:21])      # bytes 3-21
    rec["ra_deg"] = safe_float(line[22:36])      # bytes 23-36
    rec["dec_deg"] = safe_float(line[45:59])     # bytes 46-59
    rec["parallax"] = safe_float(line[68:77])    # bytes 69-77
    rec["pmra"] = safe_float(line[86:95])        # bytes 87-95
    rec["pmdec"] = safe_float(line[104:113])     # bytes 105-113
    rec["g_mag"] = safe_float(line[122:130])     # bytes 123-130
    rec["bp_mag"] = safe_float(line[141:149])    # bytes 142-149
    rec["rp_mag"] = safe_float(line[160:168])    # bytes 161-168
    rec["probastr"] = safe_float(line[239:244])  # bytes 240-244 (GCNSprob)
    
    # For prob100, GCNS table1c uses GCNSprob as the quality indicator.
    # All stars in table1c are within 100 pc by definition, so we use GCNSprob
    # as our probability threshold (probability of good astrometry).
    rec["prob100"] = rec["probastr"]

    if rec["source_id"] is None:
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
            return "M V"  # very red, virtually certain M dwarf within 100 pc
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
    if absmag is None:
        return f"{letter} V"  # default to dwarf for blue stars without absmag

    if absmag < ms_mv - 3.0 and absmag < 2.0:
        return f"{letter} III"  # giant
    if absmag < ms_mv - 1.5 and absmag < 4.0:
        return f"{letter} IV"  # subgiant
    return f"{letter} V"  # dwarf / main sequence


# ---------------------------------------------------------------------------
# Database helpers
# ---------------------------------------------------------------------------

def connect_db():
    """Connect to the hygmap PostgreSQL database."""
    return psycopg2.connect(
        host=DB_HOST, port=DB_PORT,
        dbname=DB_NAME, user=DB_USER, password=DB_PASS,
    )


def load_gaia_index(cur):
    """Load gaia -> athyg.id mapping into memory for fast lookup.
    
    Keys are normalized: stripped of whitespace and stored as strings.
    Empty strings are excluded.
    """
    print("  Loading Gaia index ...")
    cur.execute("SELECT gaia, id FROM athyg WHERE gaia IS NOT NULL AND gaia != ''")
    index = {}
    duplicates = 0
    for gaia, aid in cur:
        # Normalize the key: strip whitespace, ensure string
        key = str(gaia).strip()
        if not key:
            continue
        if key in index:
            duplicates += 1
            # Keep the first (lower ID) entry - more likely to be canonical
            continue
        index[key] = aid
    print(f"    {len(index)} Gaia IDs loaded ({duplicates} duplicates skipped).")
    return index


def load_spatial_index(cur):
    """Load all non-Gaia stars into an in-memory spatial index."""
    print("  Loading spatial index for positional matching ...")
    # Only load stars that don't have a Gaia ID (candidates for positional match)
    cur.execute("SELECT id, ra, dec, mag FROM athyg WHERE (gaia IS NULL OR gaia = '') AND ra IS NOT NULL AND dec IS NOT NULL")
    
    index = SpatialIndex()
    count = 0
    for row in cur:
        index.add(*row)
        count += 1
    
    print(f"    {count} stars loaded into spatial index.")
    return index


class SpatialIndex:
    """Simple grid-based spatial index for fast positional lookups."""
    def __init__(self):
        # Dictionary mapping (ra_bin, dec_bin) -> list of (id, ra, dec, mag)
        # Bins are 1 hour (RA) x 1 degree (Dec)
        self.bins = {}

    def _get_bin(self, ra, dec):
        # RA is 0-24h, Dec is -90 to +90 deg
        ra_bin = int(ra) % 24
        dec_bin = int(dec)
        return ra_bin, dec_bin

    def add(self, aid, ra, dec, mag):
        b = self._get_bin(ra, dec)
        if b not in self.bins:
            self.bins[b] = []
        self.bins[b].append((aid, ra, dec, mag))

    def query_closest(self, ra, dec, radius_arcsec, gcns_mag=None):
        """Find closest star within radius_arcsec."""
        radius_deg = radius_arcsec / 3600.0
        
        # Check central bin and neighbors (3x3 grid covers >1 degree radius)
        # Since radius is small (~5 arcsec), checking adjacent bins is sufficient
        center_ra_bin = int(ra) % 24
        center_dec_bin = int(dec)
        
        candidates = []
        for d_off in [-1, 0, 1]:
            d_bin = center_dec_bin + d_off
            # Skip invalid declination bins
            if d_bin < -90 or d_bin > 90:
                continue
                
            for r_off in [-1, 0, 1]:
                r_bin = (center_ra_bin + r_off) % 24
                
                if (r_bin, d_bin) in self.bins:
                    candidates.extend(self.bins[(r_bin, d_bin)])
        
        best_match = None
        best_sep_sq = (radius_deg) ** 2  # Compare squared distances
        
        for aid, c_ra, c_dec, c_mag in candidates:
            # Magnitude check
            if gcns_mag is not None and c_mag is not None:
                if abs(gcns_mag - c_mag) > MAG_TOLERANCE:
                    continue
            
            # Fast distance check (Euclidean approximation sufficient for small angles?)
            # Or use full spherical distance if needed. 
            # The original SQL used a slightly approximated formula:
            # d^2 = (delta_ra * cos(dec))^2 + delta_dec^2
            
            ra_diff = abs(ra - c_ra)
            ra_diff = min(ra_diff, 24.0 - ra_diff)
            
            # If RA diff is huge, skip early (optimization)
            # 1 hour at equator is 15 degrees.
            if ra_diff > 1.0: 
                continue

            dec_diff = abs(dec - c_dec)
            if dec_diff > radius_deg:
                continue

            # Calculate separation
            # Use star's declination for cosine factor, as in original SQL
            dec_rad = math.radians(c_dec)
            sep_sq = (ra_diff * 15.0 * math.cos(dec_rad))**2 + (dec_diff)**2
            
            if sep_sq <= best_sep_sq:
                best_sep_sq = sep_sq
                best_match = (aid, math.sqrt(sep_sq) * 3600.0)
                
        return best_match if best_match else (None, None)



# ---------------------------------------------------------------------------
# Output row builder
# ---------------------------------------------------------------------------

def build_output_row(rec, athyg_id, match_method, bright_unmatched):
    """Build one CSV output row dict from a parsed GCNS record."""
    # Propagate position to J2000
    ra_j2000_deg = rec["ra_deg"]
    dec_j2000_deg = rec["dec_deg"]
    if rec["ra_deg"] is not None and rec["dec_deg"] is not None:
        ra_j2000_deg, dec_j2000_deg = propagate_to_j2000(
            rec["ra_deg"], rec["dec_deg"], GCNS_EPOCH,
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
        "source_id": rec["source_id"] or "",
        "ra_j2000": f"{ra_j2000_hours:.10f}" if ra_j2000_hours is not None else "",
        "dec_j2000": f"{dec_j2000_deg:.10f}" if dec_j2000_deg is not None else "",
        "dist": f"{dist_pc:.6f}" if dist_pc is not None else "",
        "mag": f"{v_mag:.4f}" if v_mag is not None else "",
        "absmag": f"{absmag:.4f}" if absmag is not None else "",
        "spect": spect or "",
        "pm_ra": f"{rec['pmra']:.4f}" if rec["pmra"] is not None else "",
        "pm_dec": f"{rec['pmdec']:.4f}" if rec["pmdec"] is not None else "",
        "x_eq": f"{x_eq:.6f}" if x_eq is not None else "",
        "y_eq": f"{y_eq:.6f}" if y_eq is not None else "",
        "z_eq": f"{z_eq:.6f}" if z_eq is not None else "",
        "prob100": f"{rec['prob100']:.4f}" if rec["prob100"] is not None else "",
        "probastr": f"{rec['probastr']:.4f}" if rec["probastr"] is not None else "",
        "bright_unmatched": bright_unmatched,
    }


# ---------------------------------------------------------------------------
# Main pipeline
# ---------------------------------------------------------------------------

def run():
    print(f"Reading {INPUT_FILE} (fixed-width format) ...")
    with open(INPUT_FILE, "r", encoding="utf-8") as f:
        raw_lines = f.readlines()
    print(f"  {len(raw_lines)} lines read.")

    # Parse all records, filtering by probability threshold
    records = []
    parse_errors = 0
    low_prob_skipped = 0
    for i, line in enumerate(raw_lines):
        # Skip empty lines
        if not line.strip():
            continue
        rec = parse_fixed_width_line(line)
        if rec is None:
            parse_errors += 1
            print(f"  WARNING: could not parse line {i + 1}")
            continue
        # Filter by probability of good astrometry (GCNSprob)
        if rec["prob100"] is not None and rec["prob100"] < MIN_PROB100:
            low_prob_skipped += 1
            continue
        records.append(rec)

    print(f"  {len(records)} records to match "
          f"({parse_errors} parse errors, {low_prob_skipped} low-prob skipped).")

    # Connect to database and load lookup indexes into memory
    print(f"\nConnecting to {DB_HOST}:{DB_PORT}/{DB_NAME} ...")
    conn = connect_db()
    cur = conn.cursor()
    print("  Connected.")

    gaia_index = load_gaia_index(cur)
    spatial_index = load_spatial_index(cur)

    # Track which athyg IDs have already been claimed (one per star)
    claimed_ids = {}  # athyg_id -> (source_id, match_method) of claimer
    next_new_id = NEW_ID_START
    method_counts = Counter()
    bright_unmatched_count = 0
    duplicate_count = 0
    duplicate_conflicts = []  # cases where Gaia ID match overrode positional match
    missing_pm_count = 0  # stars with missing proper motion for positional match
    output_rows = []

    print(f"\nMatching {len(records)} GCNS records ...")

    for i, rec in enumerate(records):
        if (i + 1) % 10000 == 0:
            print(f"  {i+1}/{len(records)} ...")

        athyg_id = None
        match_method = None

        # --- Step 1: Gaia source_id exact match (primary path) ---
        # source_id from parse_fixed_width_line is already stripped via safe_str()
        # gaia_index keys are also stripped in load_gaia_index()
        if rec["source_id"] is not None:
            lookup_key = rec["source_id"]  # already normalized
            athyg_id = gaia_index.get(lookup_key)
            if athyg_id is not None:
                match_method = "gaia_source_id"

        # --- Step 2: Positional match (epoch-corrected, DB query) ---
        if athyg_id is None and rec["ra_deg"] is not None and rec["dec_deg"] is not None:
            # Check if we can properly propagate to J2000
            has_pm = rec["pmra"] is not None and rec["pmdec"] is not None
            
            if has_pm:
                ra_j2000, dec_j2000 = propagate_to_j2000(
                    rec["ra_deg"], rec["dec_deg"], GCNS_EPOCH,
                    rec["pmra"], rec["pmdec"],
                )
                total_pm = math.sqrt(rec["pmra"] ** 2 + rec["pmdec"] ** 2)
                radius = POS_RADIUS_HIGH_PM_ARCSEC if total_pm > HIGH_PM_THRESHOLD else POS_RADIUS_ARCSEC
            else:
                # Without proper motion, we can't accurately propagate J2016 -> J2000.
                # The 16-year baseline means even a modest PM of 100 mas/yr = 1.6" offset.
                # Use J2016 position with a larger radius to account for uncertainty,
                # but this risks false matches.
                ra_j2000, dec_j2000 = rec["ra_deg"], rec["dec_deg"]
                radius = POS_RADIUS_HIGH_PM_ARCSEC  # Use larger radius due to uncertainty
                missing_pm_count += 1

            # Estimate V magnitude for magnitude sanity check
            gcns_vmag = estimate_vmag(rec["g_mag"], rec["bp_mag"], rec["rp_mag"])
            # Fall back to G magnitude if V can't be estimated
            gcns_mag_for_match = gcns_vmag if gcns_vmag is not None else rec["g_mag"]
            
            # Convert RA J2000 (degrees) to hours for spatial index query
            ra_j2000_hours = ra_j2000 / 15.0 if ra_j2000 is not None else None
            
            athyg_id, sep_arcsec = spatial_index.query_closest(
                ra_j2000_hours, dec_j2000, radius, gcns_mag=gcns_mag_for_match
            )
            if athyg_id is not None:
                match_method = "position_j2000" if has_pm else "position_j2016_no_pm"

        # --- Duplicate check: each athyg_id can only be claimed once ---
        # Priority: gaia_source_id > position_j2000
        # If a later record has a better match method, it wins
        if athyg_id is not None and athyg_id in claimed_ids:
            prev_source, prev_method = claimed_ids[athyg_id]
            
            # Gaia ID match always beats positional match
            if match_method == "gaia_source_id" and prev_method == "position_j2000":
                # This Gaia ID match is better - the previous positional match was wrong
                # The previous match will have already been added to output_rows
                # We'll need to remove it later or mark it (for simplicity, just log it)
                duplicate_conflicts.append({
                    "athyg_id": athyg_id,
                    "winner": rec["source_id"],
                    "winner_method": match_method,
                    "loser": prev_source,
                    "loser_method": prev_method,
                })
                # Update the claim to this better match
                claimed_ids[athyg_id] = (rec["source_id"], match_method)
                # Note: this creates a potential duplicate in output, but the SQL import
                # uses athyg_id as key so only the last one wins anyway
            else:
                # Previous claim stands (same priority or previous was better)
                duplicate_count += 1
                continue

        if athyg_id is not None:
            claimed_ids[athyg_id] = (rec["source_id"], match_method)

        # --- Brightness check for unmatched stars ---
        bright_unmatched = 0
        if athyg_id is None:
            v_est = estimate_vmag(rec["g_mag"], rec["bp_mag"], rec["rp_mag"])
            # G <= 10.9 without BP-RP is suspicious (Tycho-2 is 99%+ complete to V~11)
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
    print("\n=== GCNS Match Audit Report ===")
    print(f"Total records processed: {len(records)}")
    print(f"Total output rows:       {len(output_rows)}")
    print(f"Duplicate matches:       {duplicate_count}")
    print(f"Conflict overrides:      {len(duplicate_conflicts)}")
    print(f"Missing PM (pos match):  {missing_pm_count}")
    print()
    print("Match method breakdown:")
    for method in ["gaia_source_id", "position_j2000", "position_j2016_no_pm", "new"]:
        count = method_counts.get(method, 0)
        pct = 100.0 * count / len(output_rows) if output_rows else 0
        print(f"  {method:25s}: {count:5d} ({pct:5.1f}%)")
    print()
    new_count = method_counts.get("new", 0)
    print(f"Bright unmatched (V<=11): {bright_unmatched_count}")
    print(f"New star IDs assigned:    {NEW_ID_START} - {next_new_id - 1} ({new_count} total)")
    
    # Report conflict details if any
    if duplicate_conflicts:
        print(f"\nConflict details (Gaia ID match overrode positional match):")
        for conflict in duplicate_conflicts[:10]:  # Show first 10
            print(f"  athyg {conflict['athyg_id']}: "
                  f"Gaia {conflict['winner']} beat positional {conflict['loser']}")
        if len(duplicate_conflicts) > 10:
            print(f"  ... and {len(duplicate_conflicts) - 10} more")


if __name__ == "__main__":
    run()
