/**
 * Keyboard navigation for 3D scene
 * Provides WASD/arrow key movement, Q/E for up/down, +/- for zoom, R to reset
 */

import { useEffect } from 'react'
import { useThree } from '@react-three/fiber'
import * as THREE from 'three'
import type { OrbitControls as OrbitControlsImpl } from 'three-stdlib'
import type { ViewMode } from '../domain/viewMode'
import { isLockedViewMode } from '../domain/viewMode'

interface KeyboardNavigatorProps {
  controlsRef: React.MutableRefObject<OrbitControlsImpl | null>
  viewMode: ViewMode
}

export default function KeyboardNavigator({ controlsRef, viewMode }: KeyboardNavigatorProps) {
  const { camera } = useThree()

  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      const controls = controlsRef.current
      if (!controls) return

      // Ignore keyboard events when user is typing in input fields
      const target = event.target as HTMLElement
      if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT') {
        return
      }

      const moveSpeed = 5 // parsecs per key press
      const zoomSpeed = 5 // parsecs per key press

      let moved = false

      // Camera movement relative to current view direction
      const cameraForward = new THREE.Vector3()
      camera.getWorldDirection(cameraForward)
      cameraForward.normalize()

      const isLocked = isLockedViewMode(viewMode)

      const forward = isLocked ? new THREE.Vector3(0, 1, 0) : cameraForward.clone()
      const right = isLocked
        ? new THREE.Vector3(1, 0, 0)
        : new THREE.Vector3().crossVectors(camera.up, cameraForward).normalize()

      const up = new THREE.Vector3(0, 0, 1) // Always use galactic Z as up

      switch (event.key.toLowerCase()) {
        // WASD for camera movement
        case 'w':
        case 'arrowup':
          camera.position.addScaledVector(forward, moveSpeed)
          controls.target.addScaledVector(forward, moveSpeed)
          moved = true
          break
        case 's':
        case 'arrowdown':
          camera.position.addScaledVector(forward, -moveSpeed)
          controls.target.addScaledVector(forward, -moveSpeed)
          moved = true
          break
        case 'a':
        case 'arrowleft':
          camera.position.addScaledVector(right, moveSpeed)
          controls.target.addScaledVector(right, moveSpeed)
          moved = true
          break
        case 'd':
        case 'arrowright':
          camera.position.addScaledVector(right, -moveSpeed)
          controls.target.addScaledVector(right, -moveSpeed)
          moved = true
          break

        // Q/E for up/down movement
        case 'q':
          camera.position.addScaledVector(up, moveSpeed)
          controls.target.addScaledVector(up, moveSpeed)
          moved = true
          break
        case 'e':
          camera.position.addScaledVector(up, -moveSpeed)
          controls.target.addScaledVector(up, -moveSpeed)
          moved = true
          break

        // +/- or =/- for zoom
        case '=':
        case '+':
          camera.position.addScaledVector(cameraForward, zoomSpeed)
          moved = true
          break
        case '-':
        case '_':
          camera.position.addScaledVector(cameraForward, -zoomSpeed)
          moved = true
          break

        // R to reset to home
        case 'r':
          camera.position.set(0, 0, 8)
          controls.target.set(0, 0, 0)
          moved = true
          break
      }

      if (moved) {
        event.preventDefault()
        controls.update()
      }
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [camera, controlsRef, viewMode])

  return null
}
