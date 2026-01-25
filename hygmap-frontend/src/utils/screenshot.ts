/**
 * Screenshot utility for capturing the canvas
 */

/**
 * Capture the WebGL canvas and download as PNG
 */
export function captureScreenshot(
  canvas: HTMLCanvasElement,
  filename: string = 'hygmap-screenshot.png'
): void {
  // Force a render to ensure the canvas is up to date
  // Note: WebGL preserveDrawingBuffer should be true for this to work reliably

  try {
    // Get the data URL from canvas
    const dataURL = canvas.toDataURL('image/png')

    // Create download link
    const link = document.createElement('a')
    link.download = filename
    link.href = dataURL
    link.style.display = 'none'

    // Trigger download
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
  } catch (error) {
    console.error('Screenshot failed:', error)
    // This can fail if the canvas has cross-origin content
    // or if preserveDrawingBuffer is false
  }
}

/**
 * Generate a timestamped filename
 */
export function generateScreenshotFilename(): string {
  const now = new Date()
  const timestamp = now.toISOString().replace(/[:.]/g, '-').slice(0, 19)
  return `hygmap-${timestamp}.png`
}
