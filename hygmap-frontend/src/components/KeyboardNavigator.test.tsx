import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render } from '@testing-library/react'
import { act } from '@testing-library/react'
import KeyboardNavigator from './KeyboardNavigator'
import type { OrbitControls as OrbitControlsImpl } from 'three-stdlib'
import type { ViewMode } from '../domain/viewMode'
import * as THREE from 'three'

// Mock modules
let mockCamera: {
  position: THREE.Vector3
  up: THREE.Vector3
  getWorldDirection: (target: THREE.Vector3) => THREE.Vector3
}

vi.mock('@react-three/fiber', () => ({
  useThree: () => ({
    camera: mockCamera,
  }),
}))

vi.mock('../domain/viewMode', async () => {
  const actual = await vi.importActual<typeof import('../domain/viewMode')>('../domain/viewMode')
  return {
    ...actual,
    isLockedViewMode: (mode: string) => mode === '2d-flat' || mode === '3d-locked',
  }
})

describe('KeyboardNavigator', () => {
  let mockControls: {
    target: THREE.Vector3
    update: ReturnType<typeof vi.fn>
  }
  let controlsRef: React.RefObject<OrbitControlsImpl | null>

  beforeEach(() => {
    // Reset mock camera
    mockCamera = {
      position: new THREE.Vector3(0, 0, 8),
      up: new THREE.Vector3(0, 1, 0),
      getWorldDirection: (target: THREE.Vector3) => {
        target.set(0, 0, -1)
        target.normalize()
        return target
      },
    }

    // Reset mock controls
    mockControls = {
      target: new THREE.Vector3(0, 0, 0),
      update: vi.fn(),
    }

    // Create ref
    controlsRef = { current: mockControls as unknown as OrbitControlsImpl }
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  const renderNavigator = (viewMode: ViewMode = '3d-free') => {
    return render(<KeyboardNavigator controlsRef={controlsRef} viewMode={viewMode} />)
  }

  describe('Component Rendering', () => {
    it('should render without crashing', () => {
      const { container } = renderNavigator()
      expect(container).toBeTruthy()
    })

    it('should return null (no visual output)', () => {
      const { container } = renderNavigator()
      expect(container.firstChild).toBeNull()
    })
  })

  describe('WASD Movement', () => {
    it('should move forward on W key press', () => {
      renderNavigator()
      const initialZ = mockCamera.position.z

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'w' }))
      })

      expect(mockCamera.position.z).not.toBe(initialZ)
      expect(mockControls.update).toHaveBeenCalled()
    })

    it('should move backward on S key press', () => {
      renderNavigator()
      const initialZ = mockCamera.position.z

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 's' }))
      })

      expect(mockCamera.position.z).not.toBe(initialZ)
      expect(mockControls.update).toHaveBeenCalled()
    })

    it('should move left on A key press', () => {
      renderNavigator()
      const initialX = mockCamera.position.x

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }))
      })

      expect(mockCamera.position.x).not.toBe(initialX)
      expect(mockControls.update).toHaveBeenCalled()
    })

    it('should move right on D key press', () => {
      renderNavigator()
      const initialX = mockCamera.position.x

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'd' }))
      })

      expect(mockCamera.position.x).not.toBe(initialX)
      expect(mockControls.update).toHaveBeenCalled()
    })
  })

  describe('Arrow Key Movement', () => {
    it('should move forward on ArrowUp key press', () => {
      renderNavigator()
      const initialZ = mockCamera.position.z

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp' }))
      })

      expect(mockCamera.position.z).not.toBe(initialZ)
      expect(mockControls.update).toHaveBeenCalled()
    })

    it('should move right on ArrowRight key press', () => {
      renderNavigator()
      const initialX = mockCamera.position.x

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
      })

      expect(mockCamera.position.x).not.toBe(initialX)
      expect(mockControls.update).toHaveBeenCalled()
    })
  })

  describe('Vertical Movement (Q/E)', () => {
    it('should move up on Q key press', () => {
      renderNavigator()
      const initialZ = mockCamera.position.z

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'q' }))
      })

      expect(mockCamera.position.z).toBeGreaterThan(initialZ)
      expect(mockControls.update).toHaveBeenCalled()
    })

    it('should move down on E key press', () => {
      renderNavigator()
      const initialZ = mockCamera.position.z

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'e' }))
      })

      expect(mockCamera.position.z).toBeLessThan(initialZ)
      expect(mockControls.update).toHaveBeenCalled()
    })
  })

  describe('Zoom (+/-)', () => {
    it('should zoom in on + key press', () => {
      renderNavigator()
      const initialZ = mockCamera.position.z

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: '+' }))
      })

      expect(mockCamera.position.z).not.toBe(initialZ)
      expect(mockControls.update).toHaveBeenCalled()
    })

    it('should zoom out on - key press', () => {
      renderNavigator()
      const initialZ = mockCamera.position.z

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: '-' }))
      })

      expect(mockCamera.position.z).not.toBe(initialZ)
      expect(mockControls.update).toHaveBeenCalled()
    })
  })

  describe('Reset (R)', () => {
    it('should reset camera to home position on R key press', () => {
      renderNavigator()

      // Move camera away from home
      mockCamera.position.set(10, 10, 10)
      mockControls.target.set(5, 5, 5)

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'r' }))
      })

      expect(mockCamera.position.x).toBe(0)
      expect(mockCamera.position.y).toBe(0)
      expect(mockCamera.position.z).toBe(8)
      expect(mockControls.target.x).toBe(0)
      expect(mockControls.target.y).toBe(0)
      expect(mockControls.target.z).toBe(0)
      expect(mockControls.update).toHaveBeenCalled()
    })
  })

  describe('Input Field Focus', () => {
    it('should ignore keyboard events when input field is focused', () => {
      renderNavigator()

      const input = document.createElement('input')
      document.body.appendChild(input)

      act(() => {
        const event = new KeyboardEvent('keydown', { key: 'w', bubbles: true })
        Object.defineProperty(event, 'target', { value: input, enumerable: true })
        window.dispatchEvent(event)
      })

      expect(mockControls.update).not.toHaveBeenCalled()

      document.body.removeChild(input)
    })

    it('should ignore keyboard events when textarea is focused', () => {
      renderNavigator()

      const textarea = document.createElement('textarea')
      document.body.appendChild(textarea)

      act(() => {
        const event = new KeyboardEvent('keydown', { key: 'w', bubbles: true })
        Object.defineProperty(event, 'target', { value: textarea, enumerable: true })
        window.dispatchEvent(event)
      })

      expect(mockControls.update).not.toHaveBeenCalled()

      document.body.removeChild(textarea)
    })
  })

  describe('Controls Ref', () => {
    it('should handle null controls ref gracefully', () => {
      const nullControlsRef = { current: null }

      render(<KeyboardNavigator controlsRef={nullControlsRef} viewMode="3d-free" />)

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'w' }))
      })

      // Should not crash
      expect(mockControls.update).not.toHaveBeenCalled()
    })
  })

  describe('View Mode Behavior', () => {
    it('should use locked movement in 2d-flat mode', () => {
      renderNavigator('2d-flat')
      const initialY = mockCamera.position.y

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'w' }))
      })

      // In locked mode, forward should move along Y axis
      expect(mockCamera.position.y).not.toBe(initialY)
      expect(mockControls.update).toHaveBeenCalled()
    })

    it('should use camera-relative movement in 3d-free mode', () => {
      renderNavigator('3d-free')

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'w' }))
      })

      // In free mode, movement is relative to camera direction
      expect(mockControls.update).toHaveBeenCalled()
    })
  })

  describe('Case Insensitivity', () => {
    it('should handle uppercase keys', () => {
      renderNavigator()

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'W' }))
      })

      expect(mockControls.update).toHaveBeenCalled()
    })

    it('should handle uppercase R for reset', () => {
      renderNavigator()

      mockCamera.position.set(10, 10, 10)

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'R' }))
      })

      expect(mockCamera.position.z).toBe(8)
      expect(mockControls.update).toHaveBeenCalled()
    })
  })

  describe('Event Cleanup', () => {
    it('should remove event listener on unmount', () => {
      const removeEventListenerSpy = vi.spyOn(window, 'removeEventListener')

      const { unmount } = renderNavigator()

      unmount()

      expect(removeEventListenerSpy).toHaveBeenCalledWith('keydown', expect.any(Function))

      removeEventListenerSpy.mockRestore()
    })
  })

  describe('Unhandled Keys', () => {
    it('should not trigger updates for unhandled keys', () => {
      renderNavigator()

      act(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'x' }))
      })

      expect(mockControls.update).not.toHaveBeenCalled()
    })
  })
})
