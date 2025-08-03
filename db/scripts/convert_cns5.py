import csv
import math

def convert_cns5_to_athyg_csv(input_filename='cns5.dat', output_filename='gliese_cns5.csv'):
    """
    Reads the Gliese CNS5 catalog data file (cns5.dat), converts it to the
    format of athyg_supplement.csv, and saves it as gliese_cns5.csv.

    The script performs the following key transformations:
    1.  Assigns new unique IDs starting from 6,000,000.
    2.  Converts parallax (mas) to distance (parsecs).
    3.  Converts RA from decimal degrees to decimal hours.
    4.  Calculates equatorial coordinates (x0, y0, z0).
    5.  Approximates Johnson V-band magnitude from Gaia G-band magnitude and BP-RP color index.
    6.  Calculates absolute magnitude from the new V-band magnitude and distance.
    7.  Maps relevant fields to the ATHYG CSV format.
    """
    # Header for the output CSV, matching the athyg_supplement format
    athyg_header = [
        'id', 'tyc', 'gaia', 'hyg', 'hip', 'hd', 'hr', 'gl', 'bayer', 'flam',
        'con', 'proper', 'ra', 'dec', 'pos_src', 'dist', 'x0', 'y0', 'z0',
        'dist_src', 'mag', 'absmag', 'ci', 'mag_src', 'rv', 'rv_src',
        'pm_ra', 'pm_dec', 'pm_src', 'vx', 'vy', 'vz', 'spect', 'spect_src'
    ]

    # Starting ID for new stars to avoid collision with existing data.
    current_id = 6000000

    print(f"Starting conversion of '{input_filename}'...")

    try:
        with open(input_filename, 'r') as infile, open(output_filename, 'w', newline='') as outfile:
            writer = csv.writer(outfile)
            writer.writerow(athyg_header)

            for line in infile:
                # --- 1. Parse fixed-width fields based on ReadMe ---
                # Using string slicing. Field positions are 1-based in the ReadMe.
                try:
                    cns5_id = line[0:10].strip()
                    ra_deg = float(line[11:24].strip())
                    dec_deg = float(line[25:38].strip())
                    plx_mas = float(line[39:46].strip()) if line[39:46].strip() else None
                    pm_ra = float(line[47:55].strip()) if line[47:55].strip() else None
                    pm_dec = float(line[56:64].strip()) if line[56:64].strip() else None
                    g_mag = float(line[65:71].strip()) if line[65:71].strip() else None
                    bp_rp = float(line[72:78].strip()) if line[72:78].strip() else None
                    sp_type = line[79:99].strip()
                    proper_name = line[100:110].strip()
                    constellation = line[111:114].strip()
                except (ValueError, IndexError):
                    print(f"Skipping malformed line: {line.strip()}")
                    continue

                # Initialize all output fields to empty strings
                row = {key: '' for key in athyg_header}

                # --- 2. Populate known fields and perform conversions ---
                row['id'] = current_id
                row['gl'] = cns5_id
                row['proper'] = proper_name
                row['con'] = constellation
                row['spect'] = sp_type
                row['pos_src'] = 'CNS5'
                row['dist_src'] = 'CNS5'
                row['mag_src'] = 'CNS5'
                row['spect_src'] = 'CNS5'
                row['pm_src'] = 'CNS5'
                
                # Convert RA from degrees to hours
                row['ra'] = ra_deg / 15.0
                row['dec'] = dec_deg
                
                # Add proper motion data if it exists
                if pm_ra is not None:
                    row['pm_ra'] = pm_ra
                if pm_dec is not None:
                    row['pm_dec'] = pm_dec


                # --- 3. Calculate Distance and Equatorial Coords ---
                dist_pc = None
                if plx_mas and plx_mas > 0:
                    dist_pc = 1000.0 / plx_mas
                    row['dist'] = dist_pc

                    # Calculate equatorial coordinates (x0, y0, z0)
                    ra_rad = math.radians(ra_deg)
                    dec_rad = math.radians(dec_deg)
                    row['x0'] = dist_pc * math.cos(dec_rad) * math.cos(ra_rad)
                    row['y0'] = dist_pc * math.cos(dec_rad) * math.sin(ra_rad)
                    row['z0'] = dist_pc * math.sin(dec_rad)

                # --- 4. Convert Magnitude ---
                if g_mag is not None and bp_rp is not None:
                    row['ci'] = bp_rp
                    # Approximate Vmag from Gmag and BP-RP color index
                    # V = G - (-0.01760 - 0.006860*(BP-RP) + 0.1732*(BP-RP)^2) -- corrected formula
                    v_mag = g_mag - (-0.01760 - 0.006860 * bp_rp + 0.1732 * (bp_rp**2))
                    row['mag'] = v_mag

                    # Calculate absolute magnitude if distance is known
                    if dist_pc:
                        # M = m - 5 * log10(d) + 5
                        abs_mag = v_mag - 5 * (math.log10(dist_pc)) + 5
                        row['absmag'] = abs_mag
                
                # --- 5. Write the final row to the CSV ---
                writer.writerow([row[key] for key in athyg_header])
                current_id += 1

        print(f"\nConversion complete. {current_id - 6000000} stars processed.")
        print(f"Output saved to '{output_filename}'")

    except FileNotFoundError:
        print(f"Error: Input file '{input_filename}' not found.")
        print("Please download 'cns5.dat' from the CDS archive and place it in the same directory.")

if __name__ == '__main__':
    convert_cns5_to_athyg_csv()
