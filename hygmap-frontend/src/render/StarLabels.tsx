/**
 * StarLabels - Renders text labels for bright stars
 *
 * Uses drei's Html component for labels that always face the camera.
 * Only shows labels for stars within a fixed distance from the camera.
 * Important stars (with names) get priority when in view.
 */

import { useState, useRef, useCallback } from 'react'
import { useFrame } from '@react-three/fiber'
import { Html } from '@react-three/drei'
import * as THREE from 'three'
import type { Star } from '../types/star'
import { projectStarToScene } from '../domain/viewMode'
import type { ViewMode } from '../domain/viewMode'
import './StarLabels.css'

// Maximum distance from camera to show labels
const MAX_LABEL_DISTANCE = 30
// Maximum number of labels to show (for performance)
const MAX_LABELS = 50

interface StarLabelsProps {
  stars: Star[]
  labelMagLimit: number
  selectedStar?: Star | null
  viewMode: ViewMode
}

/**
 * Check if a star is "important" (has proper name or Bayer/Flamsteed designation)
 */
function isImportantStar(star: Star): boolean {
  return !!(star.proper || star.bayer || star.flam)
}

// Reusable vector for distance calculations
const tempVec = new THREE.Vector3()

export default function StarLabels({ stars, labelMagLimit, selectedStar, viewMode }: StarLabelsProps) {
  const [labeledStars, setLabeledStars] = useState<Star[]>([])
  const lastUpdateRef = useRef(new THREE.Vector3())
  const lastStarsLengthRef = useRef(0)
  const lastViewModeRef = useRef<ViewMode>(viewMode)

  // Recalculate labels based on camera position
  const recalculateLabels = useCallback((cameraPos: THREE.Vector3) => {
    // Calculate distance from camera for each star
    const starsWithDistance = stars
      .filter((star) => star.display_name)
      .map((star) => {
  const [x, y, z] = projectStarToScene(star, viewMode)
        tempVec.set(x, y, z)
        const distance = tempVec.distanceTo(cameraPos)
        return { star, distance }
      })
      .filter(({ distance }) => distance < MAX_LABEL_DISTANCE)

    // Sort by importance then distance
    starsWithDistance.sort((a, b) => {
      const aImportant = isImportantStar(a.star)
      const bImportant = isImportantStar(b.star)
      if (aImportant && !bImportant) return -1
      if (!aImportant && bImportant) return 1
      return a.distance - b.distance
    })

    // Filter by magnitude for non-important stars, keep top N
    const filtered: Star[] = []
    for (const { star } of starsWithDistance) {
      if (filtered.length >= MAX_LABELS) break

      const isImportant = isImportantStar(star)
      const mag = star.absmag ?? 20

      if (isImportant || mag <= labelMagLimit) {
        filtered.push(star)
      }
    }

    // Always include selected star if it has a display name and is nearby
    if (selectedStar?.display_name) {
  const [sx, sy, sz] = projectStarToScene(selectedStar, viewMode)
      tempVec.set(sx, sy, sz)
      const selectedDist = tempVec.distanceTo(cameraPos)
      if (selectedDist < MAX_LABEL_DISTANCE * 2 && !filtered.some((s) => s.id === selectedStar.id)) {
        filtered.push(selectedStar)
      }
    }

    setLabeledStars(filtered)
  }, [stars, labelMagLimit, selectedStar, viewMode])

  // Update labeled stars when camera moves significantly or stars change
  useFrame(({ camera }) => {
    const viewModeChanged = viewMode !== lastViewModeRef.current
    if (viewModeChanged) {
      lastViewModeRef.current = viewMode
    }

    const starsChanged = stars.length !== lastStarsLengthRef.current
    const cameraMoved = camera.position.distanceTo(lastUpdateRef.current) > 2

    if (starsChanged || cameraMoved || viewModeChanged) {
      lastUpdateRef.current.copy(camera.position)
      lastStarsLengthRef.current = stars.length
      recalculateLabels(camera.position)
    }
  })

  return (
    <>
      {labeledStars.map((star) => {
  const [x, y, z] = projectStarToScene(star, viewMode)
        return (
          <Html
            key={star.id}
            position={[x, y, z]}
            center
            style={{ pointerEvents: 'none' }}
            zIndexRange={[0, 0]}
            occlude={false}
          >
            <div className="star-label">{star.display_name}</div>
          </Html>
        )
      })}
    </>
  )
}
