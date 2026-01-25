import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import App from './App'

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
