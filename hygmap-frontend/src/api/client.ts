/**
 * Shared API client utilities
 */

export const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000'

// Retry configuration
const MAX_RETRIES = 3
const INITIAL_DELAY_MS = 500
const MAX_DELAY_MS = 5000
const TIMEOUT_MS = 10000

export class ApiError extends Error {
  constructor(
    message: string,
    public status?: number,
    public isNetworkError: boolean = false,
    public isTimeout: boolean = false
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function fetchWithTimeout(
  url: string,
  options: RequestInit = {},
  timeoutMs: number = TIMEOUT_MS
): Promise<Response> {
  const controller = new AbortController()
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs)

  try {
    const response = await fetch(url, {
      ...options,
      signal: controller.signal,
    })
    return response
  } finally {
    clearTimeout(timeoutId)
  }
}

export async function fetchWithRetry(
  url: string,
  options: RequestInit = {},
  retries: number = MAX_RETRIES
): Promise<Response> {
  let lastError: Error | null = null
  let delay = INITIAL_DELAY_MS

  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const response = await fetchWithTimeout(url, options)

      if (response.status >= 400 && response.status < 500) {
        throw new ApiError(`Request failed: ${response.statusText}`, response.status)
      }

      if (response.status >= 500) {
        throw new ApiError(`Server error: ${response.statusText}`, response.status)
      }

      return response
    } catch (error) {
      lastError = error as Error

      if (error instanceof Error && error.name === 'AbortError') {
        lastError = new ApiError('Request timed out', undefined, false, true)
      }

      if (error instanceof TypeError && error.message.includes('fetch')) {
        lastError = new ApiError('Network error - please check your connection', undefined, true)
      }

      if (error instanceof ApiError && error.status && error.status < 500) {
        throw error
      }

      if (attempt < retries) {
        if (import.meta.env.DEV) {
          console.warn(
            `API request failed (attempt ${attempt + 1}/${retries + 1}), retrying in ${delay}ms:`,
            lastError.message
          )
        }
        await sleep(delay)
        delay = Math.min(delay * 2, MAX_DELAY_MS)
      }
    }
  }

  throw lastError || new ApiError('Request failed after retries')
}
