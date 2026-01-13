# HYGMap API Reference

HYGMap provides several endpoints for searching stars and exporting data.

## Endpoints

### Search (`search.php`)

Search for a star by name or catalog ID.

**URL:** `/search.php?q={query}`

**Method:** GET

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `q` | string | Search query (required) |

**Response:** Redirects to `index.php` with the star selected and centered, or displays "No match" message.

#### Supported Search Formats

| Format | Example | Description |
|--------|---------|-------------|
| Proper name | `Sirius`, `Vega` | IAU-recognized star names |
| Bayer designation | `Alpha Centauri`, `α Cen` | Greek letter + constellation |
| Flamsteed number | `61 Cygni`, `70 Ophiuchi` | Number + constellation |
| Henry Draper | `HD 48915`, `hd48915` | HD catalog number |
| Hipparcos | `HIP 32349`, `hip32349` | Hipparcos catalog number |
| Gaia DR3 | `Gaia 5853498713190525696` | Gaia catalog ID |
| Gliese | `Gliese 581`, `GJ 667C`, `Gl 876` | Gliese-Jahreiss catalog |
| Tycho-2 | `TYC 9007-5848-1` | Tycho catalog ID |

**Examples:**
```
/search.php?q=Sirius
/search.php?q=Alpha+Centauri
/search.php?q=61+Cygni
/search.php?q=HD+48915
/search.php?q=HIP+32349
/search.php?q=GJ+581
```

---

### CSV Export (`export.php`)

Download stars in the current view as a CSV file.

**URL:** `/export.php?x_c={x}&y_c={y}&z_c={z}&xy_zoom={xy}&z_zoom={z}`

**Method:** GET

**Parameters:**
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `x_c` | float | 0.0 | Center X coordinate (parsecs) |
| `y_c` | float | 0.0 | Center Y coordinate (parsecs) |
| `z_c` | float | 0.0 | Center Z coordinate (parsecs) |
| `xy_zoom` | float | 25.0 | X/Y zoom level (distance from center to edge) |
| `z_zoom` | float | 25.0 | Z zoom level |

**Response:** CSV file download with filename `hygmap_stars_YYYY-MM-DD_HHMMSS.csv`

#### CSV Columns

| Column | Description |
|--------|-------------|
| Name | Display name (proper name, designation, or catalog ID) |
| Constellation | 3-letter IAU constellation abbreviation |
| Spectral Type | Stellar classification (e.g., G2V, M4.5V) |
| Absolute Magnitude | Intrinsic brightness |
| Distance from Sol | Distance in configured units |
| X, Y, Z | Galactic coordinates in configured units |
| Apparent Magnitude | Brightness as seen from Earth |
| RA (hours) | Right ascension |
| Dec (degrees) | Declination |
| Proper Name | IAU proper name (if any) |
| Bayer Designation | Greek letter designation (e.g., "Alp Cen") |
| Flamsteed Number | Flamsteed designation (e.g., "61 Cyg") |
| Henry Draper ID | HD catalog number |
| Hipparcos ID | HIP catalog number |
| Gliese ID | GJ/Gl catalog number |

**Example:**
```
/export.php?x_c=0&y_c=0&z_c=0&xy_zoom=10&z_zoom=10
```

---

### Map Image (`map.php`)

Generate a star map image.

**URL:** `/map.php?x_c={x}&y_c={y}&z_c={z}&xy_zoom={xy}&z_zoom={z}&...`

**Method:** GET

**Parameters:**
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `x_c` | float | 0.0 | Center X coordinate |
| `y_c` | float | 0.0 | Center Y coordinate |
| `z_c` | float | 0.0 | Center Z coordinate |
| `xy_zoom` | float | 25.0 | X/Y zoom level |
| `z_zoom` | float | 25.0 | Z zoom level |
| `image_size` | int | 500 | Image dimensions in pixels |
| `m_limit` | float | 10 | Magnitude limit for stars |
| `select_star` | int | - | Highlight this star ID |

**Response:** JPEG image

---

### Main Interface (`index.php`)

The main web interface. Accepts URL parameters for deep linking.

**URL:** `/index.php?x_c={x}&y_c={y}&z_c={z}&...`

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `x_c` | float | Center X coordinate |
| `y_c` | float | Center Y coordinate |
| `z_c` | float | Center Z coordinate |
| `xy_zoom` | float | X/Y zoom level |
| `z_zoom` | float | Z zoom level |
| `select_star` | int | Star ID to select |
| `select_center` | int | If 1, center on selected star |

**Example (deep link):**
```
/index.php?x_c=5&y_c=-3&z_c=0&xy_zoom=15&z_zoom=15&select_star=12345
```

## Rate Limits

- Query results are limited to 10,000 rows maximum
- No authentication required
- No formal rate limiting (be reasonable)

## Coordinate System

All coordinates use galactic coordinates in parsecs:
- **Origin (0, 0, 0):** Sol (our Sun)
- **X-axis:** Points toward galactic center
- **Y-axis:** Points toward 90° galactic longitude (Cygnus)
- **Z-axis:** Points perpendicular to galactic plane (north)

To convert parsecs to light-years, multiply by 3.26156.
