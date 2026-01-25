/**
 * StarInfoPanel - Detailed info panel for selected star
 *
 * Shows catalog IDs, distance, spectral type, and links to external databases.
 */

import { useState, useEffect } from 'react'
import { useAppStore } from '../state/store'
import { fetchStarById } from '../api/stars'
import type { StarDetail } from '../types/star'
import './StarInfoPanel.css'

// Conversion: 1 parsec = 3.26156 light-years
const PC_TO_LY = 3.26156

export default function StarInfoPanel() {
  const { selectedStar, setSelectedStar, unit } = useAppStore()
  const [starDetail, setStarDetail] = useState<StarDetail | null>(null)
  const [loading, setLoading] = useState(false)

  // Fetch detailed star info when selection changes
  useEffect(() => {
    if (!selectedStar) {
      setStarDetail(null)
      return
    }

    setLoading(true)
    fetchStarById(selectedStar.id)
      .then((response) => {
        setStarDetail(response.data)
      })
      .catch((error) => {
        console.error('Failed to fetch star details:', error)
        setStarDetail(null)
      })
      .finally(() => {
        setLoading(false)
      })
  }, [selectedStar])

  if (!selectedStar) return null

  const star = starDetail || selectedStar

  // Calculate distance from coordinates
  const distPc = Math.sqrt(star.x * star.x + star.y * star.y + star.z * star.z)
  const distLy = distPc * PC_TO_LY
  const displayDist = unit === 'ly' ? distLy : distPc
  const distUnit = unit === 'ly' ? 'ly' : 'pc'

  // Build external links
  const getWikipediaUrl = () => {
    if (star.proper) {
      // Try proper name (e.g., "Vega" -> "Vega")
      return `https://en.wikipedia.org/wiki/${encodeURIComponent(star.proper)}`
    }
    return null
  }

  const getSimbadUrl = () => {
    const detail = star as StarDetail
    if (detail.hip) {
      return `https://simbad.cds.unistra.fr/simbad/sim-id?Ident=HIP+${detail.hip}`
    }
    if (detail.hd) {
      return `https://simbad.cds.unistra.fr/simbad/sim-id?Ident=HD+${detail.hd}`
    }
    if (star.proper) {
      return `https://simbad.cds.unistra.fr/simbad/sim-id?Ident=${encodeURIComponent(star.proper)}`
    }
    return null
  }

  const getIsdUrl = () => {
    // Internet Stellar Database uses proper names or specific formats
    if (star.proper) {
      return `http://www.stellar-database.com/Scripts/search_star.exe?Name=${encodeURIComponent(star.proper)}`
    }
    return null
  }

  const wikipediaUrl = getWikipediaUrl()
  const simbadUrl = getSimbadUrl()
  const isdUrl = getIsdUrl()

  return (
    <div className="star-info-panel">
      <div className="star-info-header">
        <h3>{star.display_name}</h3>
        <button
          className="star-info-close"
          onClick={() => setSelectedStar(null)}
          title="Close"
        >
          &times;
        </button>
      </div>

      {loading && <div className="star-info-loading">Loading details...</div>}

      <div className="star-info-content">
        {/* Basic info */}
        <div className="star-info-section">
          <h4>Properties</h4>
          {star.spect && (
            <div className="star-info-row">
              <span className="star-info-label">Spectral Type:</span>
              <span className="star-info-value">{star.spect}</span>
            </div>
          )}
          {star.absmag !== null && star.absmag !== undefined && (
            <div className="star-info-row">
              <span className="star-info-label">Absolute Mag:</span>
              <span className="star-info-value">{star.absmag.toFixed(2)}</span>
            </div>
          )}
          {starDetail?.mag !== null && starDetail?.mag !== undefined && (
            <div className="star-info-row">
              <span className="star-info-label">Apparent Mag:</span>
              <span className="star-info-value">{starDetail.mag.toFixed(2)}</span>
            </div>
          )}
          <div className="star-info-row">
            <span className="star-info-label">Distance:</span>
            <span className="star-info-value">{displayDist.toFixed(2)} {distUnit}</span>
          </div>
          {starDetail?.ra !== null && starDetail?.ra !== undefined && (
            <div className="star-info-row">
              <span className="star-info-label">RA:</span>
              <span className="star-info-value">{starDetail.ra.toFixed(4)}°</span>
            </div>
          )}
          {starDetail?.dec !== null && starDetail?.dec !== undefined && (
            <div className="star-info-row">
              <span className="star-info-label">Dec:</span>
              <span className="star-info-value">{starDetail.dec.toFixed(4)}°</span>
            </div>
          )}
        </div>

        {/* Designations */}
        <div className="star-info-section">
          <h4>Designations</h4>
          {star.proper && (
            <div className="star-info-row">
              <span className="star-info-label">Name:</span>
              <span className="star-info-value">{star.proper}</span>
            </div>
          )}
          {star.bayer && star.con && (
            <div className="star-info-row">
              <span className="star-info-label">Bayer:</span>
              <span className="star-info-value">{star.bayer} {star.con}</span>
            </div>
          )}
          {star.flam && star.con && (
            <div className="star-info-row">
              <span className="star-info-label">Flamsteed:</span>
              <span className="star-info-value">{star.flam} {star.con}</span>
            </div>
          )}
        </div>

        {/* Catalog IDs */}
        {starDetail && (
          <div className="star-info-section">
            <h4>Catalog IDs</h4>
            {starDetail.hip && (
              <div className="star-info-row">
                <span className="star-info-label">HIP:</span>
                <span className="star-info-value">{starDetail.hip}</span>
              </div>
            )}
            {starDetail.hd && (
              <div className="star-info-row">
                <span className="star-info-label">HD:</span>
                <span className="star-info-value">{starDetail.hd}</span>
              </div>
            )}
            {starDetail.hr && (
              <div className="star-info-row">
                <span className="star-info-label">HR:</span>
                <span className="star-info-value">{starDetail.hr}</span>
              </div>
            )}
            {starDetail.gj && (
              <div className="star-info-row">
                <span className="star-info-label">GJ:</span>
                <span className="star-info-value">{starDetail.gj}</span>
              </div>
            )}
            {starDetail.gaia && (
              <div className="star-info-row">
                <span className="star-info-label">Gaia:</span>
                <span className="star-info-value">{starDetail.gaia}</span>
              </div>
            )}
            {starDetail.tyc && (
              <div className="star-info-row">
                <span className="star-info-label">TYC:</span>
                <span className="star-info-value">{starDetail.tyc}</span>
              </div>
            )}
          </div>
        )}

        {/* External links */}
        <div className="star-info-section">
          <h4>External Links</h4>
          <div className="star-info-links">
            {wikipediaUrl && (
              <a
                href={wikipediaUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="star-info-link"
              >
                Wikipedia
              </a>
            )}
            {simbadUrl && (
              <a
                href={simbadUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="star-info-link"
              >
                SIMBAD
              </a>
            )}
            {isdUrl && (
              <a
                href={isdUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="star-info-link"
              >
                Internet Stellar Database
              </a>
            )}
            {!wikipediaUrl && !simbadUrl && !isdUrl && (
              <span className="star-info-no-links">No external links available</span>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
