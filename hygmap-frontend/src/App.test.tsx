import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, waitFor, act } from '@testing-library/react'
import App from './App'
import { useAppStore } from './state/store'
import * as starsApi from './api/stars'
import type { Star } from './types/star'

vi.mock('./api/stars', () => ({
  fetchStars: vi.fn(async () => ({ result: 'success', data: [], length: 0 })),
  fetchStarById: vi.fn(),
  fetchStarByLegacyId: vi.fn(async () => null),
  searchStars: vi.fn(async () => ({ result: 'success', data: [], length: 0 })),
  ApiError: class ApiError extends Error {},
}))

// Mock React Three Fiber Canvas
vi.mock('@react-three/fiber', () => ({
  Canvas: ({ children }: { children: React.ReactNode }) => (
    <div data-testid="r3f-canvas" role="img" aria-label="3D scene">{children}</div>
  ),
  useThree: () => ({
    camera: {
      position: { x: 0, y: 0, z: 0, set: vi.fn(), copy: vi.fn(), addScaledVector: vi.fn() },
      quaternion: { copy: vi.fn(), equals: vi.fn(() => false) },
      up: { x: 0, y: 1, z: 0 },
      getWorldDirection: vi.fn(() => ({ x: 0, y: 0, z: -1, normalize: vi.fn() })),
    },
    scene: {},
    gl: { domElement: document.createElement('canvas') },
  }),
  useFrame: vi.fn(),
}))

// Mock drei components
vi.mock('@react-three/drei', () => ({
  OrbitControls: () => null,
}))

// Mock the StarField component (complex 3D rendering)
vi.mock('./components/StarField', () => ({
  default: () => <div data-testid="star-field">StarField</div>,
}))

describe('App Component', () => {
  it('should render without crashing', () => {
    render(<App />)
    expect(screen.getByTestId('r3f-canvas')).toBeInTheDocument()
  })

  it('should render main UI components', () => {
    render(<App />)

    // Check for 3D canvas
    const canvas = screen.getByTestId('r3f-canvas')
    expect(canvas).toBeInTheDocument()

    // Check for Toolbar (contains search and buttons)
    expect(screen.getByRole('textbox', { name: /search/i })).toBeInTheDocument()

    // Check for Settings button
    expect(screen.getByRole('button', { name: /settings/i })).toBeInTheDocument()
  })

  it('should have proper canvas accessibility attributes', () => {
    render(<App />)

    const canvas = screen.getByTestId('r3f-canvas')
    expect(canvas).toHaveAttribute('role', 'img')
    expect(canvas).toHaveAttribute('aria-label')
  })

  it('should render StarField component', () => {
    render(<App />)

    expect(screen.getByTestId('star-field')).toBeInTheDocument()
  })

  it('should contain error boundary', () => {
    // The ErrorBoundary should be present but invisible during normal operation
    // This is tested by rendering without errors
    render(<App />)

    // If error boundary catches an error, it would show different content
    expect(screen.queryByText(/something went wrong/i)).not.toBeInTheDocument()
  })

  it('should have proper document structure', () => {
    const { container } = render(<App />)

    // Check that main container exists
    expect(container.firstChild).toBeInTheDocument()
  })
})

describe('Old-link notice', () => {
  const makeStar = (id: number, display_name: string): Star => ({
    id,
    x: 1,
    y: 2,
    z: 3,
    absmag: 5,
    display_name,
  })

  // The star ?star=7301 resolves to in the current catalog...
  const RESOLVED = makeStar(7301, 'TYC 1234-5-1')
  // ...and the star that id meant before the AT-HYG 4 renumbering.
  const LEGACY = makeStar(7323, 'GJ 1')
  // Anything the reader picks afterwards. Unrelated to either of the above.
  const UNRELATED = makeStar(99999, 'Betelgeuse')

  const originalSearch = window.location.search

  beforeEach(() => {
    vi.clearAllMocks()
    useAppStore.setState({ selectedStar: null })
    // An unmarked id: no `c=`, so its catalog is unknown and the notice applies.
    window.history.replaceState({}, '', '/?star=7301')
    vi.mocked(starsApi.fetchStarById).mockResolvedValue({
      result: 'success',
      data: RESOLVED,
    } as Awaited<ReturnType<typeof starsApi.fetchStarById>>)
    vi.mocked(starsApi.fetchStarByLegacyId).mockResolvedValue(LEGACY)
  })

  afterEach(() => {
    window.history.replaceState({}, '', `/${originalSearch}`)
  })

  it('reports the star the URL id resolves to', async () => {
    render(<App />)

    await waitFor(() => {
      expect(screen.getByRole('status', { name: /old link notice/i })).toHaveTextContent('TYC 1234-5-1')
    })
    expect(screen.getByRole('button', { name: 'GJ 1' })).toBeInTheDocument()
  })

  /**
   * The regression this pins (audit-frontend 2026-07-31-2014).
   *
   * The notice used to render `selectedStar` from the store rather than the star the URL
   * id resolved to. Nothing in the search or canvas selection paths clears the notice, so
   * choosing any unrelated star left it asserting "You are seeing <that star>" about an id
   * that never named it. The claim is about the id, so it must not move.
   */
  it('does not restate itself about a star the reader selects afterwards', async () => {
    render(<App />)

    await waitFor(() => {
      expect(screen.getByRole('status', { name: /old link notice/i })).toHaveTextContent('TYC 1234-5-1')
    })

    // Exactly what Toolbar.tsx:88 and StarField.tsx:348 do on a selection.
    act(() => {
      useAppStore.getState().setSelectedStar(UNRELATED)
    })

    const notice = screen.getByRole('status', { name: /old link notice/i })
    expect(notice).toHaveTextContent('TYC 1234-5-1')
    expect(notice).not.toHaveTextContent('Betelgeuse')
  })

  it('says the id names no current star when it resolves to nothing', async () => {
    vi.mocked(starsApi.fetchStarById).mockResolvedValue({
      result: 'success',
      data: null,
    } as unknown as Awaited<ReturnType<typeof starsApi.fetchStarById>>)

    render(<App />)

    await waitFor(() => {
      expect(screen.getByRole('status', { name: /old link notice/i })).toHaveTextContent(
        /names no star in the current catalog/i
      )
    })
  })

  it('stays silent when the id meant the same star all along', async () => {
    // 636 stars kept their id across the migration; a notice offering the same star twice
    // is noise.
    vi.mocked(starsApi.fetchStarByLegacyId).mockResolvedValue(makeStar(7301, 'TYC 1234-5-1'))

    render(<App />)

    await waitFor(() => {
      expect(starsApi.fetchStarByLegacyId).toHaveBeenCalledWith(7301)
    })
    expect(screen.queryByRole('status', { name: /old link notice/i })).not.toBeInTheDocument()
  })
})
