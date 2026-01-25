/**
 * MeasureLine - Renders a measurement line between two points
 *
 * Shows a dashed line connecting the measurement points with
 * a distance label at the midpoint.
 */

import { useMemo } from 'react'
import { Line, Html } from '@react-three/drei'
import * as THREE from 'three'
import { useAppStore } from '../state/store'
import { parsecsToLightYears } from '../domain/coordinates'

export default function MeasureLine() {
  const { measure, unit } = useAppStore()

  // Calculate line points and distance
  const measureData = useMemo(() => {
    if (!measure.point1 || !measure.point2) {
      return null
    }

    const p1 = new THREE.Vector3(...measure.point1.position)
    const p2 = new THREE.Vector3(...measure.point2.position)
    const dist = p1.distanceTo(p2)
    const mid = p1.clone().add(p2).multiplyScalar(0.5)

    return {
      points: [p1, p2] as [THREE.Vector3, THREE.Vector3],
      distance: dist,
      midpoint: mid,
      pos1: measure.point1.position,
      pos2: measure.point2.position,
      name1: measure.point1.star?.display_name || 'Point 1',
      name2: measure.point2.star?.display_name || 'Point 2',
    }
  }, [measure.point1, measure.point2])

  if (!measureData) return null

  const { points, distance, midpoint, pos1, pos2, name1, name2 } = measureData

  // Format distance based on unit
  const displayDistance = unit === 'ly' ? parsecsToLightYears(distance) : distance
  const unitLabel = unit === 'ly' ? 'ly' : 'pc'
  const distanceText = displayDistance < 1
    ? `${displayDistance.toFixed(3)} ${unitLabel}`
    : displayDistance < 10
    ? `${displayDistance.toFixed(2)} ${unitLabel}`
    : `${displayDistance.toFixed(1)} ${unitLabel}`

  return (
    <group>
      {/* Main measurement line */}
      <Line
        points={points}
        color="#ffcc00"
        lineWidth={2}
        dashed
        dashSize={0.5}
        gapSize={0.3}
      />

      {/* Distance label at midpoint */}
      <Html
        position={midpoint}
        center
        style={{
          pointerEvents: 'none',
          userSelect: 'none',
        }}
      >
        <div
          style={{
            background: 'rgba(0, 0, 0, 0.85)',
            color: '#ffcc00',
            padding: '4px 8px',
            borderRadius: '4px',
            fontSize: '12px',
            fontFamily: 'monospace',
            fontWeight: 'bold',
            whiteSpace: 'nowrap',
            border: '1px solid #ffcc00',
          }}
        >
          {distanceText}
        </div>
      </Html>

      {/* Point markers */}
      <mesh position={pos1}>
        <sphereGeometry args={[0.15, 16, 16]} />
        <meshBasicMaterial color="#ffcc00" />
      </mesh>
      <mesh position={pos2}>
        <sphereGeometry args={[0.15, 16, 16]} />
        <meshBasicMaterial color="#ffcc00" />
      </mesh>

      {/* Point labels */}
      <Html
        position={pos1}
        style={{
          pointerEvents: 'none',
          userSelect: 'none',
          transform: 'translate(10px, -20px)',
        }}
      >
        <div
          style={{
            background: 'rgba(0, 0, 0, 0.75)',
            color: '#fff',
            padding: '2px 6px',
            borderRadius: '3px',
            fontSize: '10px',
            fontFamily: 'sans-serif',
            whiteSpace: 'nowrap',
          }}
        >
          {name1}
        </div>
      </Html>
      <Html
        position={pos2}
        style={{
          pointerEvents: 'none',
          userSelect: 'none',
          transform: 'translate(10px, -20px)',
        }}
      >
        <div
          style={{
            background: 'rgba(0, 0, 0, 0.75)',
            color: '#fff',
            padding: '2px 6px',
            borderRadius: '3px',
            fontSize: '10px',
            fontFamily: 'sans-serif',
            whiteSpace: 'nowrap',
          }}
        >
          {name2}
        </div>
      </Html>
    </group>
  )
}
