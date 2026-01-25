/**
 * InstancedStars - GPU-efficient batched star renderer
 *
 * Uses THREE.InstancedMesh for single-draw-call rendering of thousands of stars.
 * Each star's position, size, and color are encoded in instance attributes.
 * Stars billboard (always face the camera) for consistent visibility.
 */

import { useRef, useMemo, useLayoutEffect } from 'react'
import { useFrame } from '@react-three/fiber'
import * as THREE from 'three'
import type { Star } from '../types/star'
import { projectStarToScene } from '../domain/viewMode'
import { getStarColorThree, getStarSize } from '../domain/star'
import { MAX_RENDERED_STARS } from '../constants/rendering'
import type { ViewMode } from '../domain/viewMode'

interface InstancedStarsProps {
  stars: Star[]
  viewMode: ViewMode
  printableView: boolean
  onInitialized?: () => void
}

// Geometry detail for star disks; higher values smooth edges but cost vertices
const STAR_DISK_SEGMENTS = 16

// Reusable objects to avoid GC pressure
const tempMatrix = new THREE.Matrix4()
const tempScale = new THREE.Vector3()
const tempUp = new THREE.Vector3(0, 1, 0)
const tempLookAt = new THREE.Vector3()
const tempFrustum = new THREE.Frustum()
const tempProjectionMatrix = new THREE.Matrix4()

// Store computed star data for billboarding
interface StarData {
  position: THREE.Vector3
  size: number
}

export default function InstancedStars({ stars, viewMode, printableView, onInitialized }: InstancedStarsProps) {
  const meshRef = useRef<THREE.InstancedMesh>(null)
  const starDataRef = useRef<StarData[]>([])

  // Maximum number of instances (pre-allocate for performance)
  const maxInstances = MAX_RENDERED_STARS

  // Create geometry once - simple circle for star appearance
  const geometry = useMemo(() => {
    return new THREE.CircleGeometry(1, STAR_DISK_SEGMENTS)
  }, [])

  // Create material once - white base color, instance colors multiply with it
  const material = useMemo(() => {
    return new THREE.MeshBasicMaterial({
      color: 0xffffff,
      side: THREE.DoubleSide,
      toneMapped: false, // Keep colors bright
    })
  }, [])

  const printableColor = useMemo(() => new THREE.Color('#000000'), [])

  // Compute and store star positions/sizes when stars change
  // useLayoutEffect runs before paint, preventing flash of old data
  useLayoutEffect(() => {
    const mesh = meshRef.current
    if (!mesh) return

    if (stars.length === 0) {
      mesh.count = 0
      starDataRef.current = []
      if (import.meta.env.DEV) {
        console.log('InstancedStars: cleared (0 stars)')
      }
      return
    }

    const renderCount = Math.min(stars.length, maxInstances)
    if (stars.length > maxInstances && import.meta.env.DEV) {
      console.warn(
        `InstancedStars: received ${stars.length} stars but capacity is ${maxInstances}; truncating to nearest stars`
      )
    }

    // Compute star data and set colors (colors don't change with camera)
    const newStarData: StarData[] = []
    for (let index = 0; index < renderCount; index++) {
      const star = stars[index]
      const [x, y, z] = projectStarToScene(star, viewMode)
      const size = getStarSize(star.absmag)

      newStarData.push({
        position: new THREE.Vector3(x, y, z),
        size,
      })

      // Set color (doesn't change per frame)
      const color = printableView ? printableColor : getStarColorThree(star.spect)
      mesh.setColorAt(index, color)
    }

    starDataRef.current = newStarData
    mesh.count = renderCount

    if (mesh.instanceColor) {
      mesh.instanceColor.needsUpdate = true
    }

    // Set initial matrices so stars render immediately (before first useFrame)
    newStarData.forEach((data, index) => {
      tempMatrix.identity()
      tempMatrix.setPosition(data.position)
      tempScale.set(data.size, data.size, data.size)
      tempMatrix.scale(tempScale)
      mesh.setMatrixAt(index, tempMatrix)
    })
    mesh.instanceMatrix.needsUpdate = true

    // Notify parent that stars are initialized
    onInitialized?.()

    // Debug: log first star position and count
    if (import.meta.env.DEV && newStarData.length > 0) {
      const first = newStarData[0]
      console.log(`InstancedStars: updated ${stars.length} stars, first at (${first.position.x.toFixed(1)}, ${first.position.y.toFixed(1)}, ${first.position.z.toFixed(1)})`)
    }
  }, [stars, onInitialized, maxInstances, viewMode, printableView, printableColor])

  // Billboard: update instance matrices every frame to face camera
  // Uses frustum culling to skip stars outside the view
  useFrame(({ camera }: { camera: THREE.Camera }) => {
    const mesh = meshRef.current
    const starData = starDataRef.current
    if (!mesh || starData.length === 0) return

    // Update frustum from camera
    tempProjectionMatrix.multiplyMatrices(camera.projectionMatrix, camera.matrixWorldInverse)
    tempFrustum.setFromProjectionMatrix(tempProjectionMatrix)

    let visibleCount = 0
    let culledCount = 0

    starData.forEach((data, index) => {
      // Frustum culling: skip stars outside the view
      // Use a generous margin (star size * 2) to avoid popping at edges
      if (!tempFrustum.containsPoint(data.position)) {
        culledCount++
        // Set scale to 0 to hide the star (cheaper than re-ordering instances)
        tempMatrix.identity()
        tempMatrix.setPosition(data.position)
        tempScale.set(0, 0, 0)
        tempMatrix.scale(tempScale)
        mesh.setMatrixAt(index, tempMatrix)
        return
      }

      visibleCount++

      // Calculate direction from star to camera for billboarding
      tempLookAt.copy(camera.position)

      // Create a matrix that faces the camera
      tempMatrix.identity()
      tempMatrix.lookAt(data.position, tempLookAt, tempUp)

      // Apply position
      tempMatrix.setPosition(data.position)

      // Apply scale
      tempScale.set(data.size, data.size, data.size)
      tempMatrix.scale(tempScale)

      mesh.setMatrixAt(index, tempMatrix)
    })

    mesh.instanceMatrix.needsUpdate = true

    // Log culling stats occasionally in dev mode
    if (import.meta.env.DEV && Math.random() < 0.01) {
      console.log(`Frustum culling: ${visibleCount} visible, ${culledCount} culled (${((culledCount / starData.length) * 100).toFixed(0)}% culled)`)
    }
  })

  return (
    <instancedMesh
      ref={meshRef}
      args={[geometry, material, maxInstances]}
      frustumCulled={false}
      renderOrder={1} // Render on top of glow
    />
  )
}
