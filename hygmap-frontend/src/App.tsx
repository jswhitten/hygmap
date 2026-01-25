import { useRef, useCallback, useState, useEffect } from 'react'
import { Canvas, useThree } from '@react-three/fiber'
import { OrbitControls } from '@react-three/drei'
import type { OrbitControls as OrbitControlsImpl } from 'three-stdlib'
import * as THREE from 'three'
import StarField from './components/StarField'
import HUD from './components/HUD'
import Settings from './components/Settings'
import Toolbar from './components/Toolbar'
import StarInfoPanel from './components/StarInfoPanel'
import SignalInfoPanel from './components/SignalInfoPanel'
import PrintableOverlay from './components/PrintableOverlay'
import ErrorBoundary from './components/ErrorBoundary'
import CameraAnimator from './render/CameraAnimator'
import KeyboardNavigator from './components/KeyboardNavigator'
import { useAppStore } from './state/store'
import { fetchStarById } from './api/stars'
import type { Star } from './types/star'
import { projectStarToScene, isLockedViewMode } from './domain/viewMode'
import type { ViewMode } from './domain/viewMode'
import { decodeStateFromURL } from './utils/urlState'
import './App.css'

// Default distance (in parsecs) to place the camera from the target
const DEFAULT_CAMERA_OFFSET_PC = 8

// Camera controller component inside Canvas
function CameraController({
  controlsRef,
  cameraTarget,
  onAnimationComplete,
  onUserInteraction,
  viewMode,
}: {
  controlsRef: React.RefObject<OrbitControlsImpl | null>
  cameraTarget: { position: THREE.Vector3; lookAt: THREE.Vector3; key: number } | null
  onAnimationComplete: () => void
  onUserInteraction: () => void
  viewMode: ViewMode
}) {
  const { camera } = useThree()
  const isLocked = isLockedViewMode(viewMode)

  // Set up 2D mode camera when switching
  useEffect(() => {
    if (isLocked && controlsRef.current) {
      const controls = controlsRef.current
      const target = controls.target.clone()

      // Position camera directly above target looking down from +Z
      const distance = camera.position.distanceTo(target) || 40
      camera.position.set(target.x, target.y, Math.max(distance, 20))
      camera.up.set(0, 1, 0)
      camera.lookAt(target)

      // Force controls to update to the new camera position
      controls.update()
    }
  }, [isLocked, camera, controlsRef])

  // Stop animation when user starts interacting
  useEffect(() => {
    const controls = controlsRef.current
    if (!controls) return

    const handleStart = () => {
      onUserInteraction()
    }

    controls.addEventListener('start', handleStart)
    return () => controls.removeEventListener('start', handleStart)
  }, [controlsRef, onUserInteraction])

  return (
    <>
      <OrbitControls
        ref={controlsRef as React.RefObject<OrbitControlsImpl>}
        enableRotate={!isLocked}
        // In 2D mode: left and right click both pan
        mouseButtons={
          isLocked
            ? { LEFT: THREE.MOUSE.PAN, MIDDLE: THREE.MOUSE.DOLLY, RIGHT: THREE.MOUSE.PAN }
            : { LEFT: THREE.MOUSE.ROTATE, MIDDLE: THREE.MOUSE.DOLLY, RIGHT: THREE.MOUSE.PAN }
        }
        // Lock to top-down view in 2D mode (polar angle = 0 means looking down from +Z when properly set up)
        screenSpacePanning={true}
      />
      <CameraAnimator
        controlsRef={controlsRef}
        targetPosition={cameraTarget?.position ?? null}
        targetLookAt={cameraTarget?.lookAt ?? null}
        animationKey={cameraTarget?.key}
        onAnimationComplete={onAnimationComplete}
      />
    </>
  )
}

function App() {
  const controlsRef = useRef<OrbitControlsImpl | null>(null)
  const [cameraTarget, setCameraTarget] = useState<{
    position: THREE.Vector3
    lookAt: THREE.Vector3
    key: number // Unique key to force animation restart
  } | null>(null)

  const viewMode = useAppStore((state) => state.viewMode)
  const printableView = useAppStore((state) => state.printableView)
  const setPrintableView = useAppStore((state) => state.setPrintableView)
  const { setSelectedStar, setUnit, setViewMode, setCoordinateSystem } = useAppStore((state) => ({
    setSelectedStar: state.setSelectedStar,
    setUnit: state.setUnit,
    setViewMode: state.setViewMode,
    setCoordinateSystem: state.setCoordinateSystem,
  }))

  useEffect(() => {
    if (typeof document === 'undefined') return
    document.body.classList.toggle('printable-mode', printableView)
    return () => {
      document.body.classList.remove('printable-mode')
    }
  }, [printableView])

  // Load state from URL on mount
  useEffect(() => {
    const urlState = decodeStateFromURL(window.location.search)

    // Apply view settings
    if (urlState.unit) setUnit(urlState.unit)
    if (urlState.view) setViewMode(urlState.view)
    if (urlState.coords) setCoordinateSystem(urlState.coords)

    // Set camera target from URL
    if (urlState.cx !== undefined && urlState.cy !== undefined && urlState.cz !== undefined) {
      const position = new THREE.Vector3(urlState.cx, urlState.cy, urlState.cz)
      const lookAt = new THREE.Vector3(
        urlState.tx ?? 0,
        urlState.ty ?? 0,
        urlState.tz ?? 0
      )
      setCameraTarget({ position, lookAt, key: Date.now() })
    }

    // Fetch and select star from URL
    if (urlState.star) {
      fetchStarById(urlState.star)
        .then((response) => {
          if (response.data) {
            setSelectedStar(response.data)
          }
        })
        .catch(console.error)
    }

    // Clear URL params after loading (optional - keeps URL clean)
    // window.history.replaceState({}, '', window.location.pathname)
  }, [setSelectedStar, setUnit, setViewMode, setCoordinateSystem])

  // Get current camera state for sharing
  const getCameraState = useCallback(() => {
    const controls = controlsRef.current
    if (!controls) return null

    return {
      position: [
        controls.object.position.x,
        controls.object.position.y,
        controls.object.position.z,
      ] as [number, number, number],
      target: [
        controls.target.x,
        controls.target.y,
        controls.target.z,
      ] as [number, number, number],
    }
  }, [])

  // Get canvas element for screenshot
  const getCanvas = useCallback(() => {
    return document.querySelector('canvas') as HTMLCanvasElement | null
  }, [])

  // Clear camera target after animation or user interaction
  const handleAnimationComplete = useCallback(() => {
    setCameraTarget(null)
  }, [])

  // Stop animation when user interacts with controls
  const handleUserInteraction = useCallback(() => {
    setCameraTarget(null)
  }, [])

  // Reset camera to home position (Sol at origin)
  const handleHome = useCallback(() => {
    setCameraTarget({
      position: new THREE.Vector3(0, 0, DEFAULT_CAMERA_OFFSET_PC),
      lookAt: new THREE.Vector3(0, 0, 0),
      key: Date.now(),
    })
  }, [])

  // Center camera on a star
  const handleCenter = useCallback((star: Star) => {
    const [x, y, z] = projectStarToScene(star, viewMode)
    const lookAt = new THREE.Vector3(x, y, z)

    // Position camera at a distance from the star
    const position = lookAt.clone().add(new THREE.Vector3(0, 0, DEFAULT_CAMERA_OFFSET_PC))

    setCameraTarget({ position, lookAt, key: Date.now() })
  }, [viewMode])

  return (
    <div className={`app${printableView ? ' printable-active' : ''}`}>
      <ErrorBoundary>
        <Canvas
          camera={{ position: [0, 0, DEFAULT_CAMERA_OFFSET_PC], fov: 45, near: 0.01, far: 5000 }}
          gl={{ antialias: true, preserveDrawingBuffer: true }}
          role="img"
          aria-label="Interactive 3D star map showing stars from the HYG database. Use WASD or arrow keys to move, Q/E to move up/down, +/- to zoom, R to reset to home. Mouse drag to rotate view."
        >
          <color attach="background" args={[printableView ? '#ffffff' : '#000000']} />
          <KeyboardNavigator controlsRef={controlsRef} viewMode={viewMode} />
          <CameraController
            controlsRef={controlsRef}
            cameraTarget={cameraTarget}
            onAnimationComplete={handleAnimationComplete}
            onUserInteraction={handleUserInteraction}
            viewMode={viewMode}
          />
          <StarField />
        </Canvas>
      </ErrorBoundary>
      {!printableView && <HUD />}
      {!printableView && <StarInfoPanel />}
  {!printableView && <SignalInfoPanel />}
      {!printableView && <Settings />}
      {!printableView && (
        <Toolbar onHome={handleHome} onCenter={handleCenter} getCameraState={getCameraState} getCanvas={getCanvas} />
      )}
      {printableView && (
        <PrintableOverlay onExit={() => setPrintableView(false)} />
      )}
    </div>
  )
}

export default App
