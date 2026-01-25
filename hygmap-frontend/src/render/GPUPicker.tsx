/**
 * GPUPicker - GPU-based star selection using color ID encoding
 *
 * Renders stars to an offscreen buffer with unique colors encoding their index.
 * On mouse interaction, reads the pixel to determine which star is under cursor.
 */

import { useRef, useCallback, useEffect, useMemo } from 'react'
import { useThree, useFrame } from '@react-three/fiber'
import * as THREE from 'three'
import type { Star } from '../types/star'
import { projectStarToScene } from '../domain/viewMode'
import type { ViewMode } from '../domain/viewMode'
import { getStarSize } from '../domain/star'
import { MAX_RENDERED_STARS } from '../constants/rendering'

interface GPUPickerProps {
  stars: Star[]
  onHover: (star: Star | null) => void
  onClick: (star: Star | null) => void
  enabled?: boolean
  viewMode: ViewMode
}

// Reusable objects
const tempMatrix = new THREE.Matrix4()
const tempScale = new THREE.Vector3()
const tempUp = new THREE.Vector3(0, 1, 0)
const tempLookAt = new THREE.Vector3()
const pixelBuffer = new Uint8Array(4)

// Store computed star data for billboarding
interface StarData {
  position: THREE.Vector3
  size: number
}

/**
 * Encode an index as an RGB color
 * Supports up to 16,777,215 unique IDs (24-bit)
 */
function indexToColor(index: number): THREE.Color {
  const r = ((index >> 16) & 255) / 255
  const g = ((index >> 8) & 255) / 255
  const b = (index & 255) / 255
  return new THREE.Color(r, g, b)
}

/**
 * Decode RGB values back to index
 */
function colorToIndex(r: number, g: number, b: number): number {
  return (r << 16) | (g << 8) | b
}

export default function GPUPicker({
  stars,
  onHover,
  onClick,
  enabled = true,
  viewMode,
}: GPUPickerProps) {
  const { gl, camera, size } = useThree()

  // Refs for picking infrastructure
  const pickingTarget = useRef<THREE.WebGLRenderTarget | null>(null)
  const pickingScene = useRef<THREE.Scene | null>(null)
  const pickingMesh = useRef<THREE.InstancedMesh | null>(null)
  const starDataRef = useRef<StarData[]>([])
  const lastHoveredIndex = useRef<number>(-1)
  const mousePosition = useRef({ x: -1, y: -1 })
  const pendingPick = useRef(false)

  // Create picking render target (use actual pixel dimensions, not CSS pixels)
  useEffect(() => {
    const dpr = gl.getPixelRatio()
    const width = Math.floor(size.width * dpr)
    const height = Math.floor(size.height * dpr)
    if (import.meta.env.DEV) {
      console.log(`GPUPicker: creating render target ${width}x${height} (dpr=${dpr})`)
    }

    pickingTarget.current = new THREE.WebGLRenderTarget(width, height)
    pickingTarget.current.texture.minFilter = THREE.NearestFilter
    pickingTarget.current.texture.magFilter = THREE.NearestFilter

    return () => {
      pickingTarget.current?.dispose()
    }
  }, [size.width, size.height, gl])

  // Create picking scene and mesh
  const pickingMaterial = useMemo(() => {
    return new THREE.MeshBasicMaterial({
      color: 0xffffff,
      side: THREE.DoubleSide,
      toneMapped: false,
    })
  }, [])

  const pickingGeometry = useMemo(() => {
    return new THREE.CircleGeometry(1, 16)
  }, [])

  // Update picking mesh when stars change
  useEffect(() => {
    if (stars.length === 0) {
      starDataRef.current = []
      return
    }

    // Create or recreate picking scene
    pickingScene.current = new THREE.Scene()
    pickingScene.current.background = new THREE.Color(0x000000)

    // Create instanced mesh for picking
  const maxInstances = Math.max(stars.length, MAX_RENDERED_STARS)
    pickingMesh.current = new THREE.InstancedMesh(
      pickingGeometry,
      pickingMaterial,
      maxInstances
    )

    // Store star data and set ID-encoded colors
    const newStarData: StarData[] = []
    stars.forEach((star, index) => {
  const [x, y, z] = projectStarToScene(star, viewMode)
      const starSize = getStarSize(star.absmag)

      newStarData.push({
        position: new THREE.Vector3(x, y, z),
        size: starSize,
      })

      // Encode index as color (index + 1 so 0 means "no star")
      const color = indexToColor(index + 1)
      pickingMesh.current!.setColorAt(index, color)
    })

    starDataRef.current = newStarData
    pickingMesh.current.count = stars.length
    if (pickingMesh.current.instanceColor) {
      pickingMesh.current.instanceColor.needsUpdate = true
    }

    pickingScene.current.add(pickingMesh.current)

    return () => {
      pickingMesh.current?.dispose()
    }
  }, [stars, pickingGeometry, pickingMaterial, viewMode])

  // Update picking mesh matrices to billboard toward camera
  const updatePickingMatrices = useCallback(() => {
    const mesh = pickingMesh.current
    const starData = starDataRef.current
    if (!mesh || starData.length === 0) return

    starData.forEach((data, index) => {
      tempLookAt.copy(camera.position)
      tempMatrix.identity()
      tempMatrix.lookAt(data.position, tempLookAt, tempUp)
      tempMatrix.setPosition(data.position)
      tempScale.set(data.size, data.size, data.size)
      tempMatrix.scale(tempScale)
      mesh.setMatrixAt(index, tempMatrix)
    })

    mesh.instanceMatrix.needsUpdate = true
  }, [camera])

  // Perform the pick operation
  const performPick = useCallback(() => {
    if (!enabled || !pickingTarget.current || !pickingScene.current) return
    if (mousePosition.current.x < 0 || mousePosition.current.y < 0) return

    // Update picking mesh to billboard toward camera
    updatePickingMatrices()

    // Render picking scene to offscreen target
    const currentRenderTarget = gl.getRenderTarget()
    gl.setRenderTarget(pickingTarget.current)
    gl.render(pickingScene.current, camera)

    // Read pixel at mouse position
    // Note: WebGL Y is flipped compared to DOM Y
    const x = mousePosition.current.x
    const y = size.height - mousePosition.current.y

    gl.readRenderTargetPixels(pickingTarget.current, x, y, 1, 1, pixelBuffer)
    gl.setRenderTarget(currentRenderTarget)

    // Decode color to index
    const [r, g, b] = pixelBuffer
    const encodedIndex = colorToIndex(r, g, b)

    // Index 0 means background (no star), otherwise subtract 1
    const starIndex = encodedIndex > 0 ? encodedIndex - 1 : -1

    // Only fire callback if hover state changed
    if (starIndex !== lastHoveredIndex.current) {
      lastHoveredIndex.current = starIndex
      if (starIndex >= 0 && starIndex < stars.length) {
        onHover(stars[starIndex])
      } else {
        onHover(null)
      }
    }

    pendingPick.current = false
  }, [enabled, gl, camera, size.height, stars, onHover, updatePickingMatrices])

  // Handle mouse move - debounced via requestAnimationFrame
  const handlePointerMove = useCallback((event: PointerEvent) => {
    if (!enabled) return

    // Get mouse position relative to canvas
    const canvas = gl.domElement
    const rect = canvas.getBoundingClientRect()
    mousePosition.current.x = event.clientX - rect.left
    mousePosition.current.y = event.clientY - rect.top
    pendingPick.current = true
  }, [enabled, gl.domElement])

  // Handle click
  const handleClick = useCallback((event: MouseEvent) => {
    if (!enabled) return

    const canvas = gl.domElement
    const rect = canvas.getBoundingClientRect()
    const cssX = event.clientX - rect.left
    const cssY = event.clientY - rect.top

    // Convert CSS pixels to device pixels
    const dpr = gl.getPixelRatio()
    mousePosition.current.x = cssX * dpr
    mousePosition.current.y = cssY * dpr

    if (import.meta.env.DEV) {
      console.log('GPUPicker click at CSS:', cssX, cssY, '-> device:', mousePosition.current.x, mousePosition.current.y)
    }

    // Perform immediate pick on click
    if (pickingTarget.current && pickingScene.current) {
      // Update picking mesh to billboard toward camera
      updatePickingMatrices()

      const currentRenderTarget = gl.getRenderTarget()
      gl.setRenderTarget(pickingTarget.current)
      gl.render(pickingScene.current, camera)

      const x = Math.floor(mousePosition.current.x)
      const y = Math.floor(size.height * dpr - mousePosition.current.y)

      if (import.meta.env.DEV) {
        console.log('Reading pixel at:', x, y, 'render target size:', pickingTarget.current.width, pickingTarget.current.height)
      }

      gl.readRenderTargetPixels(pickingTarget.current, x, y, 1, 1, pixelBuffer)
      gl.setRenderTarget(currentRenderTarget)

      const [r, g, b] = pixelBuffer
      const encodedIndex = colorToIndex(r, g, b)
      const starIndex = encodedIndex > 0 ? encodedIndex - 1 : -1

      if (import.meta.env.DEV) {
        console.log('Pixel values:', r, g, b, '-> encodedIndex:', encodedIndex, '-> starIndex:', starIndex)
      }

      if (starIndex >= 0 && starIndex < stars.length) {
        if (import.meta.env.DEV) {
          console.log('Selected star:', stars[starIndex])
        }
        onClick(stars[starIndex])
      } else {
        onClick(null)
      }
    } else if (import.meta.env.DEV) {
      console.log('GPUPicker: missing pickingTarget or pickingScene')
    }
  }, [enabled, gl, camera, size.height, stars, onClick, updatePickingMatrices])

  // Set up event listeners
  useEffect(() => {
    const canvas = gl.domElement
    canvas.addEventListener('pointermove', handlePointerMove)
    canvas.addEventListener('click', handleClick)

    return () => {
      canvas.removeEventListener('pointermove', handlePointerMove)
      canvas.removeEventListener('click', handleClick)
    }
  }, [gl.domElement, handlePointerMove, handleClick])

  // Perform picking on each frame if needed (debounced)
  useFrame(() => {
    if (pendingPick.current) {
      performPick()
    }
  })

  // This component doesn't render anything visible
  return null
}
