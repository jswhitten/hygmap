/**
 * SelectionIndicator - Renders a ring around the selected star
 *
 * Uses a RingGeometry that billboards toward the camera.
 * Pulses with a subtle animation for visibility.
 */

import { useRef, useMemo } from 'react'
import { useFrame } from '@react-three/fiber'
import * as THREE from 'three'
import type { Star } from '../types/star'
import { projectStarToScene } from '../domain/viewMode'
import type { ViewMode } from '../domain/viewMode'
import { getStarSize } from '../domain/star'

interface SelectionIndicatorProps {
  star: Star | null
  viewMode: ViewMode
}

// Reusable objects to avoid GC pressure
const tempLookAt = new THREE.Vector3()

export default function SelectionIndicator({ star, viewMode }: SelectionIndicatorProps) {
  const meshRef = useRef<THREE.Mesh>(null)
  const timeRef = useRef(0)

  // Create ring geometry - inner/outer radius will be scaled
  const geometry = useMemo(() => {
    return new THREE.RingGeometry(0.8, 1, 32)
  }, [])

  // Create material with cyan/teal color for visibility
  const material = useMemo(() => {
    return new THREE.MeshBasicMaterial({
      color: 0x00ccff,
      side: THREE.DoubleSide,
      transparent: true,
      opacity: 0.8,
      toneMapped: false,
    })
  }, [])

  // Get star position and size
  const starData = useMemo(() => {
    if (!star) return null
    const [x, y, z] = projectStarToScene(star, viewMode)
    const size = getStarSize(star.absmag)
    return {
      position: new THREE.Vector3(x, y, z),
      size,
    }
  }, [star, viewMode])

  // Billboard and pulse animation
  useFrame(({ camera }, delta) => {
    const mesh = meshRef.current
    if (!mesh || !starData) {
      if (mesh) mesh.visible = false
      return
    }

    mesh.visible = true

    // Update time for pulsing
    timeRef.current += delta

    // Pulse scale between 3x and 4x star size (outside glow)
    const pulse = 3 + 1 * Math.sin(timeRef.current * 3)
    const scale = starData.size * pulse

    // Billboard: face camera
    tempLookAt.copy(camera.position)
    mesh.lookAt(tempLookAt)

    // Set position
    mesh.position.copy(starData.position)

    // Set scale
    mesh.scale.set(scale, scale, scale)

    // Pulse opacity
    if (material) {
      material.opacity = 0.5 + 0.3 * Math.sin(timeRef.current * 3)
    }
  })

  if (!star) return null

  return (
    <mesh ref={meshRef} geometry={geometry} material={material} />
  )
}
