/**
 * Vitest setup file
 *
 * Configures testing environment with jsdom and testing-library matchers.
 */

import '@testing-library/jest-dom'
import { vi } from 'vitest'

// Mock WebGL context for Three.js tests
class MockWebGLRenderingContext {
  canvas = document.createElement('canvas')
  getExtension() { return null }
  getParameter() { return null }
  createShader() { return {} }
  shaderSource() {}
  compileShader() {}
  getShaderParameter() { return true }
  createProgram() { return {} }
  attachShader() {}
  linkProgram() {}
  getProgramParameter() { return true }
  useProgram() {}
  createBuffer() { return {} }
  bindBuffer() {}
  bufferData() {}
  enableVertexAttribArray() {}
  vertexAttribPointer() {}
  getUniformLocation() { return {} }
  getAttribLocation() { return 0 }
  uniform1f() {}
  uniform2f() {}
  uniform3f() {}
  uniform4f() {}
  uniformMatrix4fv() {}
  drawArrays() {}
  viewport() {}
  clearColor() {}
  clear() {}
  enable() {}
  disable() {}
  blendFunc() {}
  depthFunc() {}
  createTexture() { return {} }
  bindTexture() {}
  texImage2D() {}
  texParameteri() {}
  generateMipmap() {}
  activeTexture() {}
  createFramebuffer() { return {} }
  bindFramebuffer() {}
  framebufferTexture2D() {}
  checkFramebufferStatus() { return 36053 } // FRAMEBUFFER_COMPLETE
  readPixels() {}
  deleteFramebuffer() {}
  deleteTexture() {}
  scissor() {}
  pixelStorei() {}
}

// Mock canvas getContext to return WebGL context
HTMLCanvasElement.prototype.getContext = vi.fn((contextType: string) => {
  if (contextType === 'webgl' || contextType === 'webgl2') {
    return new MockWebGLRenderingContext() as unknown as WebGLRenderingContext
  }
  return null
})

// Mock ResizeObserver
global.ResizeObserver = vi.fn().mockImplementation(() => ({
  observe: vi.fn(),
  unobserve: vi.fn(),
  disconnect: vi.fn(),
}))

// Mock matchMedia
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
})

// Mock requestAnimationFrame
global.requestAnimationFrame = vi.fn((callback) => {
  return setTimeout(callback, 16) as unknown as number
})

global.cancelAnimationFrame = vi.fn((id) => {
  clearTimeout(id)
})
