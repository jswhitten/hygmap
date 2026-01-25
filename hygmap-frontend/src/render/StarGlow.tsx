/**
 * StarGlow - Animated glow effect for stars near the camera
 *
 * Uses a custom shader to create colorful, animated ray patterns.
 * Glow colors are more saturated versions of star colors.
 * Dynamically updates which stars have glows based on camera position.
 */

import { useRef, useMemo, useState, useEffect } from 'react'
import { useFrame, extend } from '@react-three/fiber'
import * as THREE from 'three'
import type { Star } from '../types/star'
import { projectStarToScene } from '../domain/viewMode'
import { getStarColor, getStarSize } from '../domain/star'
import type { ViewMode } from '../domain/viewMode'

// Minimum star size to show glow (all stars with visible disk)
const MIN_GLOW_SIZE = 0.03

// Maximum distance from camera focus to show glow
const MAX_GLOW_DISTANCE = 25

interface StarGlowProps {
  stars: Star[]
  starsInitializedRef: React.RefObject<boolean>
  viewMode: ViewMode
}

// Custom shader material for glow effect
class GlowMaterial extends THREE.ShaderMaterial {
  constructor() {
    super({
      vertexShader: `
        varying vec2 vUv;
        varying vec3 vColor;

        void main() {
          vUv = uv * 2.0 - 1.0;

          // instanceColor is provided by Three.js for InstancedMesh
          // It's only available when USE_INSTANCING_COLOR is defined
          #ifdef USE_INSTANCING_COLOR
            vColor = instanceColor;
          #else
            vColor = vec3(1.0, 1.0, 1.0);
          #endif

          // Get instance position and scale from matrix
          vec4 mvPosition = modelViewMatrix * instanceMatrix * vec4(0.0, 0.0, 0.0, 1.0);

          // Billboard: offset in screen space
          vec2 scale = vec2(
            length(instanceMatrix[0].xyz),
            length(instanceMatrix[1].xyz)
          );
          mvPosition.xy += position.xy * scale;

          gl_Position = projectionMatrix * mvPosition;
        }
      `,
      fragmentShader: `
        uniform float uTime;

        varying vec2 vUv;
        varying vec3 vColor;

        void main() {
          float dist = length(vUv);

          // Discard pixels outside the circle
          if (dist > 1.0) discard;

          // No center clipping - let renderOrder handle star disk on top of glow
          // The glow renders first (renderOrder=-1), stars render on top (renderOrder=1)

          // Smooth spherical glow from center outward
          float glow = exp(-dist * 2.5) * 1.0;

          // Angular position for rays
          float angle = atan(vUv.y, vUv.x);

          // Create subtle uneven rays - ONLY additive, clamp negatives to 0
          float rays = 0.0;

          // Primary rays (6 rays, faster spin)
          rays += max(0.0, sin(angle * 6.0 + uTime * 0.85)) * 0.14;

          // Secondary rays (4 rays, opposite rotation, faster)
          rays += max(0.0, sin(angle * 4.0 - uTime * 0.65)) * 0.1;

          // Tertiary rays (8 rays, high-frequency shimmer)
          rays += max(0.0, sin(angle * 8.0 + uTime * 1.1)) * 0.06;

          // Swirling aurora-style halo (angle + radius interaction)
          float swirl = max(0.0, sin(angle * 10.0 + dist * 14.0 + uTime * 1.4)) * 0.07;

          // Add slight pulsing (faster beat)
          float pulse = 1.0 + sin(uTime * 2.0 + dist * 3.0) * 0.1;

          // Rays visible close to star surface - gradual fadeout
          float rayMask = smoothstep(0.06, 0.14, dist) * (1.0 - smoothstep(0.22, 0.45, dist));

          // Combine: round glow everywhere, rays near star surface, swirl extends outward
          float finalGlow = glow * pulse + (rays * rayMask + swirl * (1.0 - smoothstep(0.25, 0.6, dist))) * 0.9;

          // Chromatic halo: inner core stays star-colored, rim leans toward warmer tint
          vec3 coreColor = vColor * 1.8;
          vec3 rimColor = vColor * vec3(1.05, 0.9, 0.75) + vec3(0.08, 0.04, 0.0);
          float rimMix = smoothstep(0.2, 0.8, dist);
          vec3 glowColor = mix(coreColor, rimColor, rimMix);

          // Smooth fade at edges for round appearance
          float alpha = finalGlow * smoothstep(1.0, 0.0, dist * 0.78);

          gl_FragColor = vec4(glowColor, alpha);
        }
      `,
      uniforms: {
        uTime: { value: 0 },
      },
      transparent: true,
      blending: THREE.AdditiveBlending,
      depthWrite: false,
      depthTest: false, // Disable depth testing to prevent glitches with star disks
      side: THREE.DoubleSide,
    })
  }
}

// Register the custom material
extend({ GlowMaterial })

// Add type declaration for custom JSX element
/* eslint-disable @typescript-eslint/no-namespace */
declare global {
  namespace JSX {
    interface IntrinsicElements {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      glowMaterial: any
    }
  }
}
/* eslint-enable @typescript-eslint/no-namespace */

// Reusable objects
const tempMatrix = new THREE.Matrix4()
const tempScale = new THREE.Vector3()
const tempUp = new THREE.Vector3(0, 1, 0)
const tempLookAt = new THREE.Vector3()
const tempColor = new THREE.Color()

interface StarGlowData {
  star: Star
  position: THREE.Vector3
  size: number
  color: string
}

export default function StarGlow({ stars, starsInitializedRef, viewMode }: StarGlowProps) {
  const meshRef = useRef<THREE.InstancedMesh>(null)
  const materialRef = useRef<GlowMaterial>(null)
  const lastFocusRef = useRef<THREE.Vector3 | null>(null) // null = force recalc on first frame
  const lastStarsLengthRef = useRef(0) // Track when stars change
  const lastViewModeRef = useRef<ViewMode>(viewMode)

  const maxInstances = 2000

  // Create geometry - simple quad
  const geometry = useMemo(() => {
    return new THREE.PlaneGeometry(1, 1)
  }, [])

  // Pre-compute all potential glow star data
  const allGlowStars = useMemo(() => {
    const result: StarGlowData[] = []

    stars.forEach((star) => {
      const size = getStarSize(star.absmag)
      if (size < MIN_GLOW_SIZE) return

      const [x, y, z] = projectStarToScene(star, viewMode)
      result.push({
        star,
        position: new THREE.Vector3(x, y, z),
        size: size * 10, // Glow extends to 10x star size
        color: getStarColor(star.spect),
      })
    })

    return result
  }, [stars, viewMode])

  // Track current glow count for logging
  const [glowCount, setGlowCount] = useState(0)
  const lastCameraQuaternionRef = useRef(new THREE.Quaternion())

  // Initialize mesh - set count to 0 and create instanceColor buffer
  useEffect(() => {
    const mesh = meshRef.current
    if (!mesh) return

    // Set count to 0 to prevent rendering uninitialized instances
    mesh.count = 0

    // Create instanceColor buffer by setting at least one color
    // This ensures USE_INSTANCING_COLOR is defined when shader recompiles
    mesh.setColorAt(0, tempColor.set('#ffffff'))
    mesh.setMatrixAt(0, tempMatrix.identity())
    if (mesh.instanceColor) {
      mesh.instanceColor.needsUpdate = true
    }
    mesh.instanceMatrix.needsUpdate = true
  }, [])

  // Handle empty stars - clear mesh immediately to match InstancedStars timing
  useEffect(() => {
    const mesh = meshRef.current
    if (!mesh) return

    if (allGlowStars.length === 0) {
      mesh.count = 0
      setGlowCount(0)
    }
  }, [allGlowStars.length])

  /**
   * Update billboard matrices to face camera.
   * Called when camera rotates without moving.
   */
  const updateBillboardOrientations = (mesh: THREE.InstancedMesh, cameraPosition: THREE.Vector3) => {
    for (let i = 0; i < mesh.count; i++) {
      mesh.getMatrixAt(i, tempMatrix)
      const pos = new THREE.Vector3().setFromMatrixPosition(tempMatrix)
      const scale = tempMatrix.getMaxScaleOnAxis()

      tempLookAt.copy(cameraPosition)
      tempMatrix.identity()
      tempMatrix.lookAt(pos, tempLookAt, tempUp)
      tempMatrix.setPosition(pos)
      tempScale.set(scale, scale, scale)
      tempMatrix.scale(tempScale)
      mesh.setMatrixAt(i, tempMatrix)
    }
    mesh.instanceMatrix.needsUpdate = true
  }

  /**
   * Create billboard matrix for a star glow at given position.
   */
  const createBillboardMatrix = (position: THREE.Vector3, size: number, cameraPosition: THREE.Vector3) => {
    tempLookAt.copy(cameraPosition)
    tempMatrix.identity()
    tempMatrix.lookAt(position, tempLookAt, tempUp)
    tempMatrix.setPosition(position)
    tempScale.set(size, size, size)
    tempMatrix.scale(tempScale)
    return tempMatrix.clone()
  }

  /**
   * Filter stars to those within MAX_GLOW_DISTANCE of focus point.
   * Returns sorted by distance, limited to maxInstances.
   */
  const filterNearbyStars = (focusPoint: THREE.Vector3) => {
    return allGlowStars
      .map((data) => ({
        ...data,
        distance: data.position.distanceTo(focusPoint),
      }))
      .filter((data) => data.distance < MAX_GLOW_DISTANCE)
      .sort((a, b) => a.distance - b.distance)
      .slice(0, maxInstances)
  }

  /**
   * Check if full recalculation is needed.
   * Returns true if first frame, stars changed, or view mode changed.
   */
  const checkNeedsFullRecalc = (): boolean => {
    const isFirstFrame = lastFocusRef.current === null
    const starsChanged = stars.length !== lastStarsLengthRef.current
    const viewModeChanged = viewMode !== lastViewModeRef.current

    if (starsChanged) {
      lastStarsLengthRef.current = stars.length
    }
    if (viewModeChanged) {
      lastViewModeRef.current = viewMode
    }

    return isFirstFrame || starsChanged || viewModeChanged
  }

  /**
   * Get camera focus point (point 10 units in front of camera).
   */
  const getCameraFocusPoint = (camera: THREE.Camera): THREE.Vector3 => {
    const cameraDir = new THREE.Vector3(0, 0, -1).applyQuaternion(camera.quaternion)
    return camera.position.clone().add(cameraDir.multiplyScalar(10))
  }

  // Update glows based on camera position each frame
  useFrame(({ camera, clock }: { camera: THREE.Camera; clock: THREE.Clock }) => {
    const mesh = meshRef.current
    const material = materialRef.current

    // Update shader time uniform for animation
    if (material) {
      material.uniforms.uTime.value = clock.getElapsedTime()
    }

    // Early exit conditions
    if (!mesh) return
    if (!starsInitializedRef.current) return
    if (allGlowStars.length === 0) return

    const focusPoint = getCameraFocusPoint(camera)
    const needsFullRecalc = checkNeedsFullRecalc()

    // Optimization: Skip if camera hasn't moved or rotated significantly
    const focusMoved = lastFocusRef.current === null || focusPoint.distanceTo(lastFocusRef.current) >= 1
    if (!needsFullRecalc && !focusMoved) {
      const quatChanged = !camera.quaternion.equals(lastCameraQuaternionRef.current)

      if (!quatChanged) {
        // Nothing changed - skip update entirely
        return
      }

      // Camera rotated but didn't move - just update billboard orientations
      lastCameraQuaternionRef.current.copy(camera.quaternion)
      updateBillboardOrientations(mesh, camera.position)
      return
    }

    // Update tracking refs
    if (lastFocusRef.current === null) {
      lastFocusRef.current = new THREE.Vector3()
    }
    lastFocusRef.current.copy(focusPoint)
    lastCameraQuaternionRef.current.copy(camera.quaternion)

    // Full recalculation: filter, sort, and update all instances
    const nearbyStars = filterNearbyStars(focusPoint)
    mesh.count = nearbyStars.length

    if (nearbyStars.length !== glowCount) {
      setGlowCount(nearbyStars.length)
    }

    // Update each instance's matrix and color
    nearbyStars.forEach((data, index) => {
      const matrix = createBillboardMatrix(data.position, data.size, camera.position)
      mesh.setMatrixAt(index, matrix)

      tempColor.set(data.color)
      mesh.setColorAt(index, tempColor)
    })

    mesh.instanceMatrix.needsUpdate = true
    if (mesh.instanceColor) {
      mesh.instanceColor.needsUpdate = true
    }
  })

  return (
    <instancedMesh
      ref={meshRef}
      args={[geometry, undefined, maxInstances]}
      frustumCulled={false}
      renderOrder={-1}
    >
      <glowMaterial ref={materialRef} />
    </instancedMesh>
  )
}
