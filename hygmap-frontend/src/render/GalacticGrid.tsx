/**
 * GalacticGrid - Grid planes for spatial reference
 *
 * Shows the galactic plane (XY at Z=0) and optionally a full 3D grid.
 * Supports both Cartesian (rectangular) and Polar/Spherical coordinate systems.
 * Grid lines always pass through the origin (Sun's position).
 */

import { useMemo } from 'react'
import * as THREE from 'three'

interface GalacticGridProps {
  size?: number          // Total size of the grid (default 100 parsecs)
  spacing?: number       // Distance between grid lines in parsecs (default 10)
  color?: string         // Main grid line color (default bright blue)
  color3D?: string       // 3D grid line color (default blue-gray)
  opacity?: number       // Line opacity (default 0.8)
  show3D?: boolean       // Show full 3D grid (default false)
  coordinateSystem?: 'cartesian' | 'polar' // Grid type (default cartesian)
  center?: [number, number, number] // Center position of the grid (default [0,0,0])
}

// Create Cartesian XY plane geometry centered around a position
// Lines are at fixed world coordinates (multiples of spacing from origin)
function createCartesianXYPlaneWorld(
  size: number,
  spacing: number,
  z: number,
  centerX: number,
  centerY: number
): THREE.BufferGeometry {
  const geometry = new THREE.BufferGeometry()
  const vertices: number[] = []
  const halfSize = size / 2

  // Calculate world coordinate range
  const xMin = centerX - halfSize
  const xMax = centerX + halfSize
  const yMin = centerY - halfSize
  const yMax = centerY + halfSize

  // Find first grid line position (snap to spacing)
  const firstX = Math.ceil(xMin / spacing) * spacing
  const firstY = Math.ceil(yMin / spacing) * spacing

  // Create horizontal lines (constant Y, varying X) at fixed world Y positions
  for (let y = firstY; y <= yMax; y += spacing) {
    vertices.push(xMin, y, z, xMax, y, z)
  }

  // Create vertical lines (constant X, varying Y) at fixed world X positions
  for (let x = firstX; x <= xMax; x += spacing) {
    vertices.push(x, yMin, z, x, yMax, z)
  }

  geometry.setAttribute('position', new THREE.Float32BufferAttribute(vertices, 3))
  return geometry
}

// Create Polar XY plane geometry (circles in galactic plane)
function createPolarXYPlane(size: number, spacing: number, radialDivisions: number = 12): THREE.BufferGeometry {
  const geometry = new THREE.BufferGeometry()
  const vertices: number[] = []
  const halfSize = size / 2
  const maxRadius = halfSize * Math.sqrt(2)

  // Concentric circles at regular spacing from origin
  const circleSegments = 64
  for (let radius = spacing; radius <= maxRadius; radius += spacing) {
    for (let i = 0; i < circleSegments; i++) {
      const angle1 = (i / circleSegments) * Math.PI * 2
      const angle2 = ((i + 1) / circleSegments) * Math.PI * 2
      vertices.push(
        Math.cos(angle1) * radius, Math.sin(angle1) * radius, 0,
        Math.cos(angle2) * radius, Math.sin(angle2) * radius, 0
      )
    }
  }

  // Radial lines from origin in XY plane
  const angleStep = (Math.PI * 2) / radialDivisions
  for (let i = 0; i < radialDivisions; i++) {
    const angle = i * angleStep
    vertices.push(
      0, 0, 0,
      Math.cos(angle) * maxRadius, Math.sin(angle) * maxRadius, 0
    )
  }

  geometry.setAttribute('position', new THREE.Float32BufferAttribute(vertices, 3))
  return geometry
}

// Create vertical lines for 3D Cartesian grid at fixed world coordinates
function createCartesianVerticalLinesWorld(
  size: number,
  spacing: number,
  centerX: number,
  centerY: number,
  centerZ: number
): THREE.BufferGeometry {
  const geometry = new THREE.BufferGeometry()
  const vertices: number[] = []
  const halfSize = size / 2

  // Calculate world coordinate range
  const xMin = centerX - halfSize
  const xMax = centerX + halfSize
  const yMin = centerY - halfSize
  const yMax = centerY + halfSize
  const zMin = centerZ - halfSize
  const zMax = centerZ + halfSize

  // Find first grid line positions (snap to spacing)
  const firstX = Math.ceil(xMin / spacing) * spacing
  const firstY = Math.ceil(yMin / spacing) * spacing

  // Create vertical lines at grid intersections
  for (let x = firstX; x <= xMax; x += spacing) {
    for (let y = firstY; y <= yMax; y += spacing) {
      vertices.push(x, y, zMin, x, y, zMax)
    }
  }

  geometry.setAttribute('position', new THREE.Float32BufferAttribute(vertices, 3))
  return geometry
}

// Create a sphere wireframe at a given radius
function createSphereWireframe(radius: number, latDivisions: number = 8, lonDivisions: number = 12): THREE.BufferGeometry {
  const geometry = new THREE.BufferGeometry()
  const vertices: number[] = []
  const segments = 32

  // Latitude circles (horizontal slices)
  for (let i = 1; i < latDivisions; i++) {
    const phi = (i / latDivisions) * Math.PI // 0 to PI (pole to pole)
    const y = radius * Math.cos(phi)
    const ringRadius = radius * Math.sin(phi)

    for (let j = 0; j < segments; j++) {
      const theta1 = (j / segments) * Math.PI * 2
      const theta2 = ((j + 1) / segments) * Math.PI * 2
      vertices.push(
        ringRadius * Math.cos(theta1), ringRadius * Math.sin(theta1), y,
        ringRadius * Math.cos(theta2), ringRadius * Math.sin(theta2), y
      )
    }
  }

  // Longitude lines (vertical great circles)
  for (let i = 0; i < lonDivisions; i++) {
    const theta = (i / lonDivisions) * Math.PI * 2

    for (let j = 0; j < segments; j++) {
      const phi1 = (j / segments) * Math.PI
      const phi2 = ((j + 1) / segments) * Math.PI

      const x1 = radius * Math.sin(phi1) * Math.cos(theta)
      const y1 = radius * Math.sin(phi1) * Math.sin(theta)
      const z1 = radius * Math.cos(phi1)

      const x2 = radius * Math.sin(phi2) * Math.cos(theta)
      const y2 = radius * Math.sin(phi2) * Math.sin(theta)
      const z2 = radius * Math.cos(phi2)

      vertices.push(x1, y1, z1, x2, y2, z2)
    }
  }

  geometry.setAttribute('position', new THREE.Float32BufferAttribute(vertices, 3))
  return geometry
}

// Create radial lines from origin outward in all directions (3D)
function createRadialLines3D(maxRadius: number, divisions: number = 12): THREE.BufferGeometry {
  const geometry = new THREE.BufferGeometry()
  const vertices: number[] = []

  // Create radial lines in a spherical pattern
  const latDivisions = 6 // Including poles

  for (let i = 0; i <= latDivisions; i++) {
    const phi = (i / latDivisions) * Math.PI // 0 to PI

    // Fewer longitude divisions near poles
    const lonDivisions = i === 0 || i === latDivisions ? 1 : divisions

    for (let j = 0; j < lonDivisions; j++) {
      const theta = (j / lonDivisions) * Math.PI * 2

      const x = Math.sin(phi) * Math.cos(theta) * maxRadius
      const y = Math.sin(phi) * Math.sin(theta) * maxRadius
      const z = Math.cos(phi) * maxRadius

      vertices.push(0, 0, 0, x, y, z)
    }
  }

  geometry.setAttribute('position', new THREE.Float32BufferAttribute(vertices, 3))
  return geometry
}

export default function GalacticGrid({
  size = 100,
  spacing = 10,
  color = '#88aaff',
  color3D = '#6688bb',
  opacity = 0.8,
  show3D = false,
  coordinateSystem = 'cartesian',
  center = [0, 0, 0],
}: GalacticGridProps) {
  const isPolar = coordinateSystem === 'polar'
  const halfSize = size / 2
  const maxRadius = halfSize * Math.sqrt(2)
  const [cx, cy, cz] = center

  // Create main XY plane geometry (galactic plane at Z=0)
  // Lines are at fixed world coordinates (multiples of spacing from origin)
  const mainPlaneGeometry = useMemo(
    () => isPolar
      ? createPolarXYPlane(size, spacing)
      : createCartesianXYPlaneWorld(size, spacing, 0, cx, cy),
    [size, spacing, isPolar, cx, cy]
  )

  // Create 3D grid geometries
  const grid3DGeometries = useMemo(() => {
    if (!show3D) return []

    if (isPolar) {
      // Spherical: concentric sphere wireframes (always centered at origin)
      const spheres: THREE.BufferGeometry[] = []
      for (let r = spacing; r <= maxRadius; r += spacing) {
        spheres.push(createSphereWireframe(r, 6, 12))
      }
      return spheres
    } else {
      // Cartesian: horizontal planes at different Z levels around camera
      const planes: THREE.BufferGeometry[] = []
      // Find Z levels that are multiples of spacing within our range
      const zMin = cz - halfSize
      const zMax = cz + halfSize
      const firstZ = Math.ceil(zMin / spacing) * spacing
      for (let z = firstZ; z <= zMax; z += spacing) {
        if (z !== 0) { // Skip z=0 as it's the main plane
          planes.push(createCartesianXYPlaneWorld(size, spacing, z, cx, cy))
        }
      }
      return planes
    }
  }, [size, spacing, halfSize, maxRadius, show3D, isPolar, cx, cy, cz])

  // Create lines for 3D grid (vertical for Cartesian, radial for Polar)
  const linesGeometry = useMemo(
    () => {
      if (!show3D) return null
      return isPolar
        ? createRadialLines3D(maxRadius, 12)
        : createCartesianVerticalLinesWorld(size, spacing, cx, cy, cz)
    },
    [size, spacing, maxRadius, show3D, isPolar, cx, cy, cz]
  )

  // Main plane material
  const mainMaterial = useMemo(
    () =>
      new THREE.LineBasicMaterial({
        color,
        transparent: true,
        opacity,
        depthWrite: false,
      }),
    [color, opacity]
  )

  // 3D grid material
  const material3D = useMemo(
    () =>
      new THREE.LineBasicMaterial({
        color: color3D,
        transparent: true,
        opacity: opacity * 0.6,
        depthWrite: false,
      }),
    [color3D, opacity]
  )

  return (
    <group>
      {/* Main galactic plane (XY at Z=0) - geometry at world coordinates */}
      <lineSegments args={[mainPlaneGeometry, mainMaterial]} />

      {/* 3D grid */}
      {show3D && (
        <>
          {/* Spheres or horizontal planes */}
          {grid3DGeometries.map((geometry, index) => (
            <lineSegments key={`grid-${index}`} args={[geometry, material3D]} />
          ))}
          {/* Radial or vertical lines */}
          {linesGeometry && (
            <lineSegments args={[linesGeometry, material3D]} />
          )}
        </>
      )}
    </group>
  )
}
