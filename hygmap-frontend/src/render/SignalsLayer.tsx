/**
 * SignalsLayer - renders SETI signal glyphs as animated wavefronts moving away from Sol
 */

import { useMemo, useEffect, useRef, useCallback } from 'react'
import { Line, Html } from '@react-three/drei'
import { useFrame } from '@react-three/fiber'
import * as THREE from 'three'
import type { Signal } from '../types/signal'
import { galacticToScene, lightYearsToParsecs } from '../domain/coordinates'
import ErrorBoundary from '../components/ErrorBoundary'

const Z_AXIS = new THREE.Vector3(0, 0, 1)
const WAVE_COUNT = 4
const WAVE_RANGE = 0.5 // keep propagation extremely tight
const WAVE_MIN_OFFSET = -0.08 // start just sun-ward of the signal
const WAVE_SPEED = 1.8 // waves per second
const WAVE_BASE_SIZE = 0.55
const WAVE_SIZE_VARIATION = 0.12
const WAVE_THICKNESS = 0.15

const TIMELINE_BASE_YEAR = 2000
const TIMELINE_YEAR_STEP = 50
const TIMELINE_MARGIN_LY = 20
const TIMELINE_MIN_FORWARD_LY = 150
const SUN_LABEL_FORMATTER = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' })

const SIGNAL_COLORS: Record<Signal['type'], { wave: string }> = {
  transmit: {
    wave: '#ff7043',
  },
  receive: {
    wave: '#64b5f6',
  },
}

type SignalGeometries = {
  wave: THREE.BoxGeometry
}

type FrameState = {
  clock: THREE.Clock
}

interface SignalsLayerProps {
  signals: Signal[]
  selectedSignalId?: number | null
  hoveredSignalId?: number | null
  onSelect: (signal: Signal | null) => void
  onHover: (signal: Signal | null) => void
}

function SignalsLayerContent({ signals, selectedSignalId, hoveredSignalId, onSelect, onHover }: SignalsLayerProps) {
  const geometries = useMemo<SignalGeometries>(() => ({
    wave: new THREE.BoxGeometry(0.22, 0.22, 0.08),
  }), [])

  useEffect(() => () => {
    geometries.wave.dispose()
  }, [geometries])

  const selectedSignal = useMemo(() => {
    if (!selectedSignalId) {
      return null
    }
    return signals.find((signal) => signal.id === selectedSignalId) ?? null
  }, [selectedSignalId, signals])

  if (signals.length === 0) {
    return null
  }

  return (
    <group>
      {signals.map((signal) => (
        <SignalWave
          key={signal.id}
          signal={signal}
          geometries={geometries}
          isSelected={signal.id === selectedSignalId}
          isHovered={signal.id === hoveredSignalId}
          onSelect={onSelect}
          onHover={onHover}
        />
      ))}
      {selectedSignal && (
        <SignalTimeline
          key={`timeline-${selectedSignal.id}`}
          signal={selectedSignal}
          color={SIGNAL_COLORS[selectedSignal.type].wave}
        />
      )}
    </group>
  )
}

export default function SignalsLayer(props: SignalsLayerProps) {
  return (
    <ErrorBoundary fallback={<group />}>
      <SignalsLayerContent {...props} />
    </ErrorBoundary>
  )
}

interface SignalWaveProps {
  signal: Signal
  geometries: SignalGeometries
  isSelected: boolean
  isHovered: boolean
  onSelect: (signal: Signal | null) => void
  onHover: (signal: Signal | null) => void
}

function SignalWave({ signal, geometries, isSelected, isHovered, onSelect, onHover }: SignalWaveProps) {
  const waveRefs = useRef<Array<THREE.Mesh | null>>(new Array(WAVE_COUNT).fill(null))
  const materialRefs = useRef<Array<THREE.MeshBasicMaterial | null>>(new Array(WAVE_COUNT).fill(null))
  const basePhase = useMemo(() => Math.random(), [])
  const [sx, sy, sz] = useMemo(() => galacticToScene(signal.x, signal.y, signal.z), [signal.x, signal.y, signal.z])
  const position = useMemo<[number, number, number]>(() => [sx, sy, sz], [sx, sy, sz])

  const orientation = useMemo(() => {
    const direction = new THREE.Vector3(sx, sy, sz)
    if (direction.lengthSq() === 0) {
      direction.set(0, 0, 1)
    } else {
      direction.normalize()
    }
    return new THREE.Quaternion().setFromUnitVectors(Z_AXIS, direction)
  }, [sx, sy, sz])

  const colors = SIGNAL_COLORS[signal.type]

  const registerWave = useCallback((mesh: THREE.Mesh | null, idx: number) => {
    waveRefs.current[idx] = mesh
  }, [])

  const registerMaterial = useCallback((material: THREE.MeshBasicMaterial | null, idx: number) => {
    materialRefs.current[idx] = material
  }, [])

  useFrame((state: FrameState) => {
    const elapsed = state.clock.getElapsedTime()
    waveRefs.current.forEach((mesh, idx) => {
      const material = materialRefs.current[idx]
      if (!mesh || !material) return

      const phase = (elapsed * WAVE_SPEED + basePhase + idx / WAVE_COUNT) % 1
      const offset = WAVE_MIN_OFFSET + phase * WAVE_RANGE
      mesh.position.set(0, 0, offset)

      const emphasis = isSelected ? 1.25 : isHovered ? 1.1 : 1
      const scale = (WAVE_BASE_SIZE + phase * WAVE_SIZE_VARIATION) * emphasis
      mesh.scale.set(scale, scale, WAVE_THICKNESS)

      const baseOpacity = isSelected ? 0.95 : isHovered ? 0.8 : 0.6
      material.opacity = Math.max(0.1, (1 - phase) * baseOpacity)
    })
  })

  return (
    <group
      position={position}
      quaternion={orientation}
      scale={isSelected ? 1.15 : isHovered ? 1.08 : 1}
      onPointerDown={(event) => {
        event.stopPropagation()
        onSelect(signal)
      }}
      onPointerUp={(event) => {
        event.stopPropagation()
      }}
      onClick={(event) => {
        event.stopPropagation()
        onSelect(signal)
      }}
      onPointerEnter={(event) => {
        event.stopPropagation()
        onHover(signal)
        if (typeof document !== 'undefined') {
          document.body.style.cursor = 'pointer'
        }
      }}
      onPointerLeave={(event) => {
        event.stopPropagation()
        onHover(null)
        if (typeof document !== 'undefined') {
          document.body.style.cursor = 'auto'
        }
      }}
    >
      {/* Traveling waves */}
      {Array.from({ length: WAVE_COUNT }).map((_, idx) => (
        <mesh
          key={idx}
          geometry={geometries.wave}
          ref={(mesh) => registerWave(mesh, idx)}
        >
          <meshBasicMaterial
            ref={(material) => registerMaterial(material, idx)}
            color={colors.wave}
            transparent
            opacity={0.65}
            depthWrite={false}
            blending={THREE.AdditiveBlending}
          />
        </mesh>
      ))}
    </group>
  )
}

interface SignalTimelineProps {
  signal: Signal
  color: string
}

function SignalTimeline({ signal, color }: SignalTimelineProps) {
  const [sx, sy, sz] = useMemo(() => galacticToScene(signal.x, signal.y, signal.z), [signal.x, signal.y, signal.z])

  const timelineData = useMemo(() => {
    const dir = new THREE.Vector3(sx, sy, sz)
    if (dir.lengthSq() === 0) {
      dir.set(0, 0, 1)
    }
    dir.normalize()

    const baseDate = signal.time ? new Date(signal.time) : null
    const hasValidDate = baseDate && !Number.isNaN(baseDate.getTime())
    const baseYear = hasValidDate && baseDate ? baseDate.getUTCFullYear() : TIMELINE_BASE_YEAR
    const baseLabel = hasValidDate && baseDate
      ? SUN_LABEL_FORMATTER.format(baseDate)
      : `${baseYear}`

    const distancePc = Math.sqrt(signal.x * signal.x + signal.y * signal.y + signal.z * signal.z)
    const extraPc = lightYearsToParsecs(TIMELINE_MARGIN_LY)
    const minForwardPc = lightYearsToParsecs(TIMELINE_MIN_FORWARD_LY)
    const forwardLengthPc = Math.max(distancePc + extraPc, minForwardPc)
    const extendOpposite = signal.type === 'receive'
    const backwardLengthPc = extendOpposite ? forwardLengthPc : 0

    const startPoint = dir.clone().multiplyScalar(-backwardLengthPc)
    const endPoint = dir.clone().multiplyScalar(forwardLengthPc)

    const ticks: Array<{ year: number; label: string; position: THREE.Vector3; isSun: boolean }> = []
    ticks.push({ year: baseYear, label: baseLabel, position: new THREE.Vector3(0, 0, 0), isSun: true })

    let forwardYear = baseYear + TIMELINE_YEAR_STEP
    while (lightYearsToParsecs(forwardYear - baseYear) <= forwardLengthPc + 1e-3) {
      const yearsFromBase = forwardYear - baseYear
      const offsetPc = lightYearsToParsecs(yearsFromBase)
      ticks.push({
        year: forwardYear,
        label: `${forwardYear}`,
        position: dir.clone().multiplyScalar(offsetPc),
        isSun: false,
      })
      forwardYear += TIMELINE_YEAR_STEP
    }

    if (extendOpposite) {
      let backwardYear = baseYear - TIMELINE_YEAR_STEP
      while (lightYearsToParsecs(baseYear - backwardYear) <= backwardLengthPc + 1e-3) {
        const yearsFromBase = baseYear - backwardYear
        const offsetPc = lightYearsToParsecs(yearsFromBase)
        ticks.push({
          year: backwardYear,
          label: `${backwardYear}`,
          position: dir.clone().multiplyScalar(-offsetPc),
          isSun: false,
        })
        backwardYear -= TIMELINE_YEAR_STEP
      }
    }

    return {
      points: [startPoint, endPoint] as [THREE.Vector3, THREE.Vector3],
      ticks,
    }
  }, [sx, sy, sz, signal.x, signal.y, signal.z, signal.type, signal.time])

  return (
    <group>
      <Line
        points={timelineData.points}
        color={color}
        lineWidth={1.2}
        transparent
        opacity={0.85}
        raycast={() => null}
      />
      {timelineData.ticks.map((tick, index) => (
        <TimelineTick
          key={`${signal.id}-${tick.year}-${index}`}
          label={tick.label}
          position={tick.position}
          color={color}
          isSun={tick.isSun}
        />
      ))}
    </group>
  )
}

interface TimelineTickProps {
  label: string
  position: THREE.Vector3
  color: string
  isSun: boolean
}

function TimelineTick({ label, position, color, isSun }: TimelineTickProps) {
  const sphereSize = isSun ? 0.1 : 0.07
  return (
    <group position={position}>
      <mesh>
        <sphereGeometry args={[sphereSize, 16, 16]} />
        <meshBasicMaterial color={color} transparent opacity={isSun ? 1 : 0.9} />
      </mesh>
      <Html
        position={[0, 0.15, 0]}
        style={{
          pointerEvents: 'none',
          userSelect: 'none',
        }}
      >
        <div
          style={{
            background: 'rgba(0, 0, 0, 0.7)',
            color,
            padding: '2px 6px',
            borderRadius: '3px',
            fontSize: '11px',
            fontFamily: 'monospace',
            border: `1px solid ${color}`,
            fontWeight: isSun ? 700 : 500,
            boxShadow: '0 2px 6px rgba(0, 0, 0, 0.45)',
          }}
        >
          {label}
        </div>
      </Html>
    </group>
  )
}
