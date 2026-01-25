import { useEffect, useState } from 'react'

/**
 * Lightweight debounce hook to mirror the API we used from `use-debounce`.
 * Returns a tuple so it can replace `[debouncedValue] = useDebounce(...)` usage.
 */
export function useDebounce<T>(value: T, delay: number): [T] {
  const [debouncedValue, setDebouncedValue] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedValue(value)
    }, delay)

    return () => {
      clearTimeout(timer)
    }
  }, [value, delay])

  return [debouncedValue]
}
