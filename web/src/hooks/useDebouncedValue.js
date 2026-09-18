import { useEffect, useState } from 'react';

/**
 * Delays propagating a fast-changing value, so typing in the search box does
 * not fire a request per keystroke.
 */
export function useDebouncedValue(value, delay = 300) {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);

    return () => clearTimeout(timer);
  }, [value, delay]);

  return debounced;
}
