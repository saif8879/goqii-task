import { useCallback, useEffect, useState } from 'react';
import { api } from '../api/client.js';

/**
 * Loads the task list for the given query. The query object must be memoised
 * by the caller, otherwise every render triggers another fetch.
 */
export function useTasks(query) {
  const [tasks, setTasks] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [nonce, setNonce] = useState(0);

  const reload = useCallback(() => setNonce((value) => value + 1), []);

  useEffect(() => {
    const controller = new AbortController();

    setLoading(true);
    setError(null);

    api
      .listTasks(query, controller.signal)
      .then((payload) => {
        setTasks(payload.data);
        setMeta(payload.meta);
        setLoading(false);
      })
      .catch((failure) => {
        // The effect cleanup aborted this request, so a newer one is in flight.
        if (failure.name === 'AbortError') {
          return;
        }

        setTasks([]);
        setMeta(null);
        setError(failure.message);
        setLoading(false);
      });

    return () => controller.abort();
  }, [query, nonce]);

  return { tasks, meta, loading, error, reload };
}
