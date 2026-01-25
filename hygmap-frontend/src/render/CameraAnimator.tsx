/**
 * CameraAnimator - Smoothly animates camera to target positions
 */

import { useRef, useEffect } from 'react'
import { useFrame } from '@react-three/fiber'
import * as THREE from 'three'
import type { OrbitControls as OrbitControlsImpl } from 'three-stdlib'

interface CameraAnimatorProps {
  controlsRef: React.RefObject<OrbitControlsImpl | null>
  targetPosition: THREE.Vector3 | null
  targetLookAt: THREE.Vector3 | null
  animationKey?: number // Changes to trigger new animation
  onAnimationComplete?: () => void
}

const ANIMATION_SPEED = 4 // Higher = faster

export default function CameraAnimator({
  controlsRef,
  targetPosition,
  targetLookAt,
  animationKey,
  onAnimationComplete,
}: CameraAnimatorProps) {
  const isAnimating = useRef(false)

  // Start animation when key changes (new target requested)
  useEffect(() => {
    if (targetPosition && targetLookAt && animationKey) {
      isAnimating.current = true
    }
  }, [animationKey, targetPosition, targetLookAt])

  useFrame((_, delta) => {
    const controls = controlsRef.current
    if (!controls || !targetPosition || !targetLookAt || !isAnimating.current) return

    const camera = controls.object
    const lerpFactor = 1 - Math.exp(-ANIMATION_SPEED * delta)

    // Lerp camera position
    camera.position.lerp(targetPosition, lerpFactor)

    // Lerp orbit target
    controls.target.lerp(targetLookAt, lerpFactor)

    controls.update()

    // Check if animation is complete (close enough)
    const positionDist = camera.position.distanceTo(targetPosition)
    const targetDist = controls.target.distanceTo(targetLookAt)

    if (positionDist < 0.01 && targetDist < 0.01) {
      camera.position.copy(targetPosition)
      controls.target.copy(targetLookAt)
      controls.update()
      isAnimating.current = false
      onAnimationComplete?.()
    }
  })

  return null
}
