/**
 * Tests for API client
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { fetchStars, fetchStarById, searchStars, ApiError } from './stars'

describe('API client', () => {
  const mockFetch = vi.fn()
  const originalFetch = global.fetch

  beforeEach(() => {
    global.fetch = mockFetch
    mockFetch.mockReset()
  })

  afterEach(() => {
    global.fetch = originalFetch
  })

  describe('fetchStars', () => {
    it('should fetch stars within bounds', async () => {
      const mockResponse = {
        result: 'success',
        data: [{ id: 1, x: 0, y: 0, z: 0, display_name: 'Sol' }],
        length: 1,
      }

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve(mockResponse),
      })

      const result = await fetchStars({
        bounds: { xmin: -10, xmax: 10, ymin: -10, ymax: 10, zmin: -10, zmax: 10 },
      })

      expect(result).toEqual(mockResponse)
      expect(mockFetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/stars?'),
        expect.any(Object)
      )
    })

    it('should include magMax parameter when provided', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ result: 'success', data: [], length: 0 }),
      })

      await fetchStars({
        bounds: { xmin: -10, xmax: 10, ymin: -10, ymax: 10, zmin: -10, zmax: 10 },
        magMax: 6,
      })

      expect(mockFetch).toHaveBeenCalledWith(
        expect.stringContaining('mag_max=6'),
        expect.any(Object)
      )
    })

    it('should throw ApiError on 404', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 404,
        statusText: 'Not Found',
      })

      await expect(
        fetchStars({
          bounds: { xmin: -10, xmax: 10, ymin: -10, ymax: 10, zmin: -10, zmax: 10 },
        })
      ).rejects.toThrow(ApiError)
    })

    it('should retry on 500 error', async () => {
      // First call fails with 500
      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 500,
        statusText: 'Internal Server Error',
      })

      // Second call succeeds
      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ result: 'success', data: [], length: 0 }),
      })

      const result = await fetchStars({
        bounds: { xmin: -10, xmax: 10, ymin: -10, ymax: 10, zmin: -10, zmax: 10 },
      })

      expect(result.result).toBe('success')
      expect(mockFetch).toHaveBeenCalledTimes(2)
    })
  })

  describe('fetchStarById', () => {
    it('should fetch star by ID', async () => {
      const mockStar = {
        id: 12345,
        x: 5,
        y: 10,
        z: 15,
        display_name: 'Vega',
        absmag: 0.58,
      }

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ result: 'success', data: mockStar }),
      })

      const result = await fetchStarById(12345)

      expect(result.data).toEqual(mockStar)
      expect(mockFetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/stars/12345'),
        expect.any(Object)
      )
    })

    it('should throw ApiError when star not found', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: false,
        status: 404,
        statusText: 'Not Found',
      })

      await expect(fetchStarById(99999)).rejects.toThrow(ApiError)
    })
  })

  describe('searchStars', () => {
    it('should search stars by query', async () => {
      const mockResponse = {
        result: 'success',
        data: [
          { id: 1, x: 0, y: 0, z: 0, display_name: 'Vega' },
          { id: 2, x: 1, y: 1, z: 1, display_name: 'Vegans Star' },
        ],
        length: 2,
      }

      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve(mockResponse),
      })

      const result = await searchStars('Vega')

      expect(result).toEqual(mockResponse)
      expect(mockFetch).toHaveBeenCalledWith(
        expect.stringContaining('q=Vega'),
        expect.any(Object)
      )
    })

    it('should respect limit parameter', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ result: 'success', data: [], length: 0 }),
      })

      await searchStars('test', 50)

      expect(mockFetch).toHaveBeenCalledWith(
        expect.stringContaining('limit=50'),
        expect.any(Object)
      )
    })
  })

  describe('ApiError', () => {
    it('should include status code', () => {
      const error = new ApiError('Test error', 404)
      expect(error.status).toBe(404)
      expect(error.message).toBe('Test error')
      expect(error.name).toBe('ApiError')
    })

    it('should indicate network error', () => {
      const error = new ApiError('Network error', undefined, true)
      expect(error.isNetworkError).toBe(true)
    })

    it('should indicate timeout', () => {
      const error = new ApiError('Timeout', undefined, false, true)
      expect(error.isTimeout).toBe(true)
    })
  })
})
