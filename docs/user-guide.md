# HYGMap User Guide

Learn how to navigate and explore the star map with HYGMap.

## Available Interfaces

HYGMap offers two ways to explore the stars:

| Interface | URL | Description |
|-----------|-----|-------------|
| **PHP App** | http://localhost/ | Classic 2D map with server-rendered images |
| **React Frontend** | http://localhost:5173/ | Modern 3D interactive visualization |

Both interfaces connect to the same star database and show the same 2.5+ million stars.

---

## React Frontend (3D Interface)

The React frontend provides an interactive 3D star map built with Three.js.

### Features

- **3D Navigation** - Rotate, pan, and zoom through the star field
- **Real-time Rendering** - Stars render instantly as you navigate
- **Level of Detail** - Distant stars fade and nearby stars become more detailed
- **Star Selection** - Click stars to see detailed information
- **Search** - Find stars by name or catalog ID

### Controls

**Mouse:**
- **Left click + drag** - Rotate view
- **Right click + drag** - Pan view
- **Scroll wheel** - Zoom in/out
- **Click star** - Select and view details

**Keyboard:**
- **Arrow keys** - Pan view
- **+/-** - Zoom in/out

### SETI Signals

The 3D frontend displays SETI signal data:
- **Transmitted signals** - Historical messages sent from Earth (Arecibo Message, etc.)
- **Received signals** - Notable signals detected from space (Wow! Signal, etc.)

Signals are shown as expanding spheres representing how far radio waves have traveled through space.

---

## PHP App (Classic Interface)

The PHP interface provides a traditional 2D star map view with server-rendered images.

### Interface Overview

- **Control Panel** (left) - Navigation and filter controls
- **Star Map** (center) - Visual representation of stars
- **Star Table** (bottom) - List of visible stars

### Basic Navigation

**Center Coordinates (X, Y, Z):**
- Your position in galactic coordinates (parsecs or light years)
- Default (0,0,0) is our solar system
- Click any star name in the table to center on it

**Zoom Controls:**
- **X/Y Zoom** - Distance from center to edge (smaller = zoomed in)
- **Z Zoom** - Depth of view ("up" and "down" distance)

### Configuration

**Units and Grid Size:**
- Select between parsecs and light years
- Choose the spacing between grid lines in your selected units
- Optionally add lines between stars within a certain distance of each other

**Fictional Names:**
- Choose None to see only real star names, or a scifi universe to identify real stars with fictional planets

**Map Types:**
- **Normal:** 2D overhead view of the galactic plane
- **3D:** Stereoscopic left/right images for depth perception
- **Printable:** Black and white version

**Magnitude Filters:**
- **Show stars brighter than magnitude X:** Lower numbers = only brightest stars visible
- **Label stars brighter than magnitude X:** Controls which stars get name labels

### Sharing and Exporting

**Copy Link:**
Click the **Copy Link** button to copy a shareable URL preserving your current view settings.

**Export CSV:**
Click **Export CSV** to download a spreadsheet of visible stars including names, coordinates, magnitudes, and catalog IDs.

---

## Understanding Stars

### Star Colors

Colors indicate stellar temperature:
- **Blue** - Hot stars (O, B type)
- **White** - Medium-hot stars (A, F type)
- **Yellow** - Sun-like stars (G type)
- **Orange/Red** - Cool stars (K, M type)

### Star Sizes

In both interfaces, star size represents brightness:
- Larger = brighter stars
- Smaller = dimmer stars

### Star Names

Stars show the most prominent name available:
1. Proper names (Sirius, Vega)
2. Traditional designations (Alpha Centauri)
3. Catalog numbers (HD, Hipparcos)

**Fictional Names:** Enable fictional names to see sci-fi locations (Star Trek, Babylon 5) overlaid on real stars.

---

## Coordinate System

Both interfaces use galactic coordinates centered on Sol:

- **Origin (0, 0, 0):** Our Sun's position
- **X-axis:** Points toward galactic center
- **Y-axis:** Points toward Cygnus (90° galactic longitude)
- **Z-axis:** Points "up" perpendicular to galactic plane

**Units:**
- Default: parsecs
- 1 parsec = 3.26156 light years

---

## Tips

- **Lower magnitude numbers** = fewer, brighter stars = cleaner view
- **Center on bright stars** (Sirius, Vega) for good reference points
- **Use the search feature** to find specific stars by name or catalog ID
- **Try both interfaces** - the 3D view helps understand stellar distances, while the 2D view is better for precise navigation
- **Fictional names** add a fun layer for sci-fi fans to locate familiar star systems

---

## Mobile Support

**PHP App:** Works on tablets and phones with responsive design.

**React Frontend:** Best experienced on desktop with mouse controls. Touch navigation is supported but limited.
