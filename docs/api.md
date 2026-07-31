# HYGMap API Reference

HYGMap provides two API backends:
- **PHP Backend** - Classic server-rendered interface at `http://localhost/`
- **FastAPI Backend** - Modern REST API at `http://localhost:8000/`

---

## FastAPI Backend (Port 8000)

The FastAPI backend provides a JSON REST API for the React frontend. Interactive API documentation is available at `http://localhost:8000/docs` (Swagger UI).

### Health Check

**GET** `/health`

Returns API health status.

**Response:**
```json
{
  "status": "healthy"
}
```

---

### Stars API

#### List Stars (`/api/stars/`)

**GET** `/api/stars/`

Get stars within specified 3D spatial bounds. Returns stars ordered by absolute magnitude (brightest first).

**Parameters:**
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `xmin` | float | -50 | Minimum X coordinate (parsecs) |
| `xmax` | float | 50 | Maximum X coordinate (parsecs) |
| `ymin` | float | -50 | Minimum Y coordinate (parsecs) |
| `ymax` | float | 50 | Maximum Y coordinate (parsecs) |
| `zmin` | float | -50 | Minimum Z coordinate (parsecs) |
| `zmax` | float | 50 | Maximum Z coordinate (parsecs) |
| `mag_max` | float | null | Maximum absolute magnitude (LOD filter) |
| `limit` | int | 10000 | Maximum stars to return (max: 50000) |
| `world_id` | int | 0 | Fictional universe for the `name` field; `0` means no fictional names |
| `order` | string | `absmag asc` | Sort order: `absmag`, `mag`, `proper` or `dist`, each `asc` or `desc`. Validated against an allowlist. |

**Constraints:**
- Coordinates must be within ±10,000 parsecs
- Spatial range must not exceed 3,000 parsecs per dimension
- `min` values must be less than `max` values

**Response:**
```json
{
  "result": "success",
  "data": [
    {
      "id": 12345,
      "proper": "Sirius",
      "bayer": "Alp",
      "flam": null,
      "con": "CMa",
      "spect": "A1V",
      "absmag": 1.42,
      "x": -1.82,
      "y": -1.87,
      "z": -1.13,
      "hip": "32349",
      "hd": "48915",
      "hr": "2491",
      "gj": "244A",
      "gaia": "5853498713190525696",
      "tyc": "5949-2777-1",
      "display_name": "Sirius"
    }
  ],
  "length": 1
}
```

**Example:**
```bash
curl "http://localhost:8000/api/stars/?xmin=-10&xmax=10&ymin=-10&ymax=10&zmin=-10&zmax=10"
```

---

### Star names (`display_name`)

Every star response carries a server-computed `display_name`. **It is the authoritative
name** — clients should display it rather than deriving their own, and both front ends do
(PHP keeps a parallel implementation only because its map labels are colour-coded by
which kind of designation matched).

Resolution order:

| # | Source | Example |
|---|---|---|
| 1 | Fictional name (only when `world_id` is set) | `Vulcan` |
| 2 | Proper name | `Sirius` |
| 3 | Bayer + constellation (both required) | `Alp Cen` |
| 4 | Flamsteed + constellation (both required) | `61 Cyg` |
| 5 | Gliese-Jahreiss | `GJ 1` |
| 6 | Henry Draper | `HD 225213` |
| 7 | Hipparcos | `HIP 439` |
| 8 | Yale Bright Star | `HR 2491` |
| 9 | CNS5 | `CNS5 19` |
| 10 | Tycho-2 | `TYC 6995-1264-1` |
| 11 | Gaia DR3 | `Gaia 2306965202564744064` |
| 12 | Spectral type | `M2V` |
| 13 | Database id | `ID 7323` |

**GJ leads the catalog designations deliberately.** HYGMap is a map of the solar
neighbourhood, so the nearby-star catalog is the most useful identifier to show. Until
2026-07-29 this endpoint led with HIP while the PHP UI led with GJ, so the same star was
named two different things depending on which interface you opened.

Bayer and Flamsteed values are trimmed (the source catalog carries padding), and a
designation is only used when its constellation is present — a bare Greek letter is not a
name. The name is never empty.

The canonical table of cases lives in `tests/fixtures/display-names.json` and is asserted
by all three test suites. **Change that file first** if this order ever needs to move.

---

#### Search Stars (`/api/stars/search`)

**GET** `/api/stars/search`

Search for stars by name or catalog ID.

**Parameters:**
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `q` | string | required | Search query (2-100 characters) |
| `limit` | int | 20 | Maximum results (max: 100) |
| `world_id` | int | 0 | Also search fictional names from this universe (0 = real names only) |

**Supported Search Formats:**
| Format | Example | Description |
|--------|---------|-------------|
| Proper name | `Sirius`, `Vega` | IAU-recognized star names |
| Fictional name | `Vulcan` (with `world_id=1`) | Requires a `world_id` — see below |
| Bayer designation | `Alpha Centauri`, `Alp Cen` | Greek letter + constellation |
| Flamsteed number | `61 Cygni`, `51 Peg` | Number + constellation |
| Henry Draper | `HD 48915`, `hd48915` | HD catalog number |
| Hipparcos | `HIP 32349`, `hip32349` | Hipparcos catalog number |
| Gaia DR3 | `Gaia 5853498713190525696` | Gaia catalog ID |
| Gliese | `GJ 581`, `Gl 876` | Gliese-Jahreiss catalog |
| Tycho-2 | `TYC 5949-2777-1` | Tycho catalog ID |
| Yale Bright Star | `HR 2491` | HR catalog number |

**Matching semantics:**

Name searches (everything that is not a catalog ID) match case-insensitively against
proper names, Bayer and Flamsteed designations, and constellation abbreviations.

- **Three characters or more** match anywhere in the name, so `elgeu` finds Betelgeuse.
- **One or two characters** match only the *start* of a name, so `ve` finds Vega but
  `ol` does not find Sol. Short terms are anchored because the trigram indexes backing
  this endpoint cannot filter on fewer than three characters; without anchoring a
  two-character search scans the whole 2.8M-row table.
- **Bayer designations accept either form** of the Greek letter — `Alpha Cen` and
  `Alp Cen` both find Alpha Centauri, including systems stored with a component suffix
  such as `Alp-1 Cen`.

**Example:**
```bash
curl "http://localhost:8000/api/stars/search?q=Sirius"
curl "http://localhost:8000/api/stars/search?q=HIP+32349"

# Fictional names are scoped to a universe
curl "http://localhost:8000/api/stars/search?q=vulcan&world_id=1"  # Keid / HD 26965
curl "http://localhost:8000/api/stars/search?q=vulcan&world_id=2"  # [] - wrong universe
curl "http://localhost:8000/api/stars/search?q=vulcan"             # [] - none selected
```

**Fictional name scoping:** a fictional name matches only when its universe is the one
selected by `world_id`. This is deliberate rather than a limitation — matching across all
universes would mean a star could be found under a name the map is not currently showing
for it, and every surface on a page load is supposed to agree on one name. When a match is
found this way, `name` and `display_name` both carry the fictional name.

A `world_id` never removes or duplicates a result that a plain search already returned; it
can only add matches. Note that it can add them via substring, exactly as proper-name
search does — with Star Trek selected, `q=ori` also matches a star named
`Alpha Canis Majoris`.

Stars with no usable position are excluded from search results whether they match on a real
or a fictional name, because selecting one cannot be rendered on the map.

---

#### Get Star by ID (`/api/stars/{star_id}`)

**GET** `/api/stars/{star_id}`

Get detailed information for a specific star by its database ID.

**Response:**
```json
{
  "result": "success",
  "data": {
    "id": 12345,
    "proper": "Sirius",
    "bayer": "Alp",
    "flam": null,
    "con": "CMa",
    "spect": "A1V",
    "absmag": 1.42,
    "x": -1.82,
    "y": -1.87,
    "z": -1.13,
    "hyg": 32349,
    "hip": "32349",
    "hd": "48915",
    "hr": "2491",
    "gj": "244A",
    "gaia": "5853498713190525696",
    "tyc": "5949-2777-1",
    "ra": 101.2875,
    "dec": -16.7161,
    "dist": 2.64,
    "mag": -1.46,
    "display_name": "Sirius"
  }
}
```

**Example:**
```bash
curl "http://localhost:8000/api/stars/12345"
```

---

#### List Proper Names (`/api/stars/proper-names`)

**GET** `/api/stars/proper-names`

Every star carrying a proper name, ordered alphabetically. Intended for populating a
name-picker; takes no parameters and returns the full list in one response.

**Response:**
```json
{
  "result": "success",
  "data": [
    { "id": 197326, "proper": "268 G. Cet" },
    { "id": 223454, "proper": "Acamar" }
  ],
  "length": 2
}
```

**Example:**
```bash
curl "http://localhost:8000/api/stars/proper-names"
```

---

#### List Fictional Worlds (`/api/stars/worlds`)

**GET** `/api/stars/worlds`

The fictional universes available as naming layers, ordered by id. The `id` values are
what `world_id` and the PHP `fic_names` parameter refer to.

**Response:**
```json
{
  "result": "success",
  "data": [
    { "id": 1, "name": "Star Trek" },
    { "id": 2, "name": "Babylon 5" }
  ],
  "length": 2
}
```

**Example:**
```bash
curl "http://localhost:8000/api/stars/worlds"
```

---

#### List Fictional Names (`/api/stars/fictional-names`)

**GET** `/api/stars/fictional-names`

Every fictional star name belonging to one universe, ordered alphabetically.

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `world_id` | int | yes | Fictional world ID (≥ 1); see `/api/stars/worlds` |

**Response:**
```json
{
  "result": "success",
  "data": [
    { "star_id": 1561134, "name": "Aaamazzarra" },
    { "star_id": 223454, "name": "Acamar" }
  ],
  "length": 2
}
```

**Example:**
```bash
curl "http://localhost:8000/api/stars/fictional-names?world_id=1"
```

---

### Signals API

#### List Signals (`/api/signals/`)

**GET** `/api/signals/`

Get SETI signals within specified 3D spatial bounds.

**Parameters:**
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `xmin` | float | -150 | Minimum X coordinate (parsecs) |
| `xmax` | float | 150 | Maximum X coordinate (parsecs) |
| `ymin` | float | -150 | Minimum Y coordinate (parsecs) |
| `ymax` | float | 150 | Maximum Y coordinate (parsecs) |
| `zmin` | float | -150 | Minimum Z coordinate (parsecs) |
| `zmax` | float | 150 | Maximum Z coordinate (parsecs) |
| `order` | string | "time desc" | Sort order (time/name/frequency asc/desc) |
| `limit` | int | 1000 | Maximum signals to return (max: 5000) |
| `signal_type` | string | null | Filter by type: "transmit" or "receive" |

**Constraints:**
- Coordinates must be within ±20,000 parsecs
- Spatial range must not exceed 6,000 parsecs per dimension

**Response:**
```json
{
  "result": "success",
  "data": [
    {
      "id": 1,
      "name": "Arecibo Message",
      "type": "transmit",
      "time": "1974-11-16T17:30:00",
      "ra": 270.0,
      "dec": 21.6,
      "frequency": 2380.0,
      "notes": "Binary message sent to M13 globular cluster",
      "x": -4.25,
      "y": 3.12,
      "z": 1.05,
      "last_updated": "2026-01-01T00:00:00",
      "display_name": "Arecibo Message"
    }
  ],
  "length": 1
}
```

**Example:**
```bash
curl "http://localhost:8000/api/signals/"
curl "http://localhost:8000/api/signals/?signal_type=transmit"
```

---

### Rate Limits

- Scope: per-client IP across all FastAPI endpoints
- Default allowance: 1000 requests per minute (soft reset every 60s), set by
  `RATE_LIMIT` in `hygmap-api/app/config.py`
- Disable entirely with `RATE_LIMIT_ENABLED=false` (environment variable)
- Exceeding the limit returns HTTP 429 with a structured error body

**429 Response Example:**
```json
{
  "detail": "Rate limit exceeded. Try again soon."
}
```

---

## PHP Backend (Port 80)

The classic PHP interface provides server-rendered star maps and web pages.

### Search (`search.php`)

Search for a star by name or catalog ID.

**URL:** `/search.php?q={query}`

**Method:** GET

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `q` | string | Search query (required) |

**Response:** Redirects to `index.php` with the star selected and centered, or displays "No match" message.

**Supported Search Formats:**

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

**CSV Columns:**

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

---

## Coordinate System

All coordinates use galactic coordinates in parsecs:
- **Origin (0, 0, 0):** Sol (our Sun)
- **X-axis:** Points toward galactic center
- **Y-axis:** Points toward 90° galactic longitude (Cygnus)
- **Z-axis:** Points perpendicular to galactic plane (north)

To convert parsecs to light-years, multiply by 3.26156.

## Error Responses

### FastAPI Errors

| Status Code | Description |
|-------------|-------------|
| 400 | Bad Request - Invalid parameters |
| 404 | Not Found - Star ID does not exist |
| 429 | Too Many Requests - Rate limit exceeded |
| 500 | Internal Server Error |

**Error Response Format:**
```json
{
  "detail": "Error description"
}
```

### PHP Errors

The PHP backend returns HTML error pages or displays error messages inline.
