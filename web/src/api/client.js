const configuredApiUrl = import.meta.env.VITE_API_URL;

// Vite inlines this at build time, so a production bundle built without it
// would quietly point every request at localhost. Failing on load makes the
// misconfiguration obvious instead of producing an app that cannot reach its
// API for reasons that look like a network fault.
if (!configuredApiUrl && !import.meta.env.DEV) {
  throw new Error('VITE_API_URL was not set when this bundle was built.');
}

const BASE_URL = configuredApiUrl || 'http://localhost:8080/api';

export class ApiError extends Error {
  constructor(message, status, errors = {}, code = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
    this.code = code;
  }
}

/**
 * The access token is held here in memory and never in localStorage, so an XSS
 * payload cannot read it out of storage. The trade-off is that a page reload
 * loses it, which is why the app performs a silent refresh on boot using the
 * httpOnly cookie the browser keeps for us.
 */
let accessToken = null;

/** Called when the session is beyond saving, so the app can drop to /login. */
let onSessionLost = null;

/** Called whenever a refresh succeeds, so the app can pick up the fresh user. */
let onSessionRenewed = null;

/** In-flight refresh, shared so ten simultaneous 401s cause one refresh. */
let refreshInFlight = null;

/**
 * The most recent successful refresh, reused for a moment afterwards.
 *
 * Sharing the in-flight promise only helps callers that overlap. A component
 * that mounts twice in quick succession can refresh, finish, then refresh
 * again — spending the cookie twice and tripping the server's reuse detection.
 * An access token minted a moment ago is good for another 15 minutes, so
 * handing back the same one is both safe and correct.
 */
let lastRefresh = null;
let lastRefreshAt = 0;
const REFRESH_REUSE_WINDOW_MS = 2000;

export function setAccessToken(token) {
  accessToken = token;
}

export function setSessionCallbacks({ onLost, onRenewed }) {
  onSessionLost = onLost;
  onSessionRenewed = onRenewed;
}

function isAuthRoute(path) {
  return path.startsWith('/auth/');
}

/**
 * Drops the cached refresh. Signing in or out is an authoritative change of
 * identity, so the previous session must not be handed to a later caller.
 */
function resetRefreshCache() {
  lastRefresh = null;
  lastRefreshAt = 0;
}

async function send(path, { method = 'GET', body, signal } = {}) {
  const headers = { Accept: 'application/json' };

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  if (accessToken !== null) {
    headers.Authorization = `Bearer ${accessToken}`;
  }

  try {
    return await fetch(BASE_URL + path, {
      method,
      signal,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      // Needed for the browser to send and store the httpOnly refresh cookie.
      // The cookie is scoped to /api/auth, so it rides along only where useful.
      credentials: 'include',
    });
  } catch (error) {
    if (error.name === 'AbortError') {
      throw error;
    }

    throw new ApiError('Could not reach the API. Is the server running?', 0);
  }
}

async function parse(response) {
  if (response.status === 204) {
    return null;
  }

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    throw new ApiError(
      (payload && payload.message) || `Request failed with status ${response.status}.`,
      response.status,
      (payload && payload.errors) || {},
      (payload && payload.code) || null,
    );
  }

  return payload;
}

/**
 * Exchanges the refresh cookie for a new access token, resolving with the
 * session payload or null. This is the only place that calls /auth/refresh, so
 * every caller shares the deduplication below.
 *
 * Concurrent callers await one shared request rather than each firing their
 * own. That is a correctness requirement, not an optimisation: the server
 * rotates the refresh token on every use and treats a second presentation of
 * the same token as theft, revoking the whole family. Two parallel refreshes
 * would therefore log the user out. React's StrictMode double-invokes effects
 * in development, so the boot-time restore hits this path twice on every
 * reload — without the guard, refreshing the page ends the session.
 */
function refreshSession() {
  if (lastRefresh !== null && Date.now() - lastRefreshAt < REFRESH_REUSE_WINDOW_MS) {
    return Promise.resolve(lastRefresh);
  }

  if (refreshInFlight === null) {
    refreshInFlight = send('/auth/refresh', { method: 'POST' })
      .then(async (response) => {
        if (!response.ok) {
          return null;
        }

        const payload = await response.json().catch(() => null);

        if (!payload || !payload.data || !payload.data.access_token) {
          return null;
        }

        accessToken = payload.data.access_token;
        lastRefresh = payload.data;
        lastRefreshAt = Date.now();

        return payload.data;
      })
      .catch(() => null)
      .finally(() => {
        refreshInFlight = null;
      });
  }

  return refreshInFlight;
}

async function request(path, options = {}) {
  let response = await send(path, options);

  // A 401 on a normal call usually just means the 15-minute access token aged
  // out. Try exactly one silent refresh, replay the request, and only give up
  // if that fails. Auth routes are excluded to avoid recursing on themselves.
  if (response.status === 401 && !isAuthRoute(path)) {
    const session = await refreshSession();

    if (session !== null) {
      if (onSessionRenewed) {
        onSessionRenewed(session);
      }

      response = await send(path, options);
    } else if (onSessionLost) {
      onSessionLost();
    }
  }

  return parse(response);
}

function toQueryString(params) {
  const search = new URLSearchParams();

  Object.entries(params).forEach(([key, value]) => {
    if (value !== '' && value !== null && value !== undefined) {
      search.set(key, value);
    }
  });

  const query = search.toString();

  return query === '' ? '' : `?${query}`;
}

export const api = {
  // Auth
  register: (payload) => {
    resetRefreshCache();

    return request('/auth/register', { method: 'POST', body: payload });
  },
  login: (email, password) => {
    resetRefreshCache();

    return request('/auth/login', { method: 'POST', body: { email, password } });
  },
  logout: () => {
    resetRefreshCache();

    return request('/auth/logout', { method: 'POST' });
  },
  me: () => request('/auth/me'),

  /**
   * Boot-time session restore. Resolves with the session payload or null; it
   * never throws, because "not logged in" is an ordinary outcome here. Shares
   * the deduplicated refresh so a double mount cannot spend the cookie twice.
   */
  restoreSession: () => refreshSession(),

  // Tasks
  listTasks: (params, signal) => request(`/tasks${toQueryString(params)}`, { signal }),
  createTask: (task) => request('/tasks', { method: 'POST', body: task }),
  updateTask: (id, task) => request(`/tasks/${id}`, { method: 'PUT', body: task }),
  patchTask: (id, changes) => request(`/tasks/${id}`, { method: 'PATCH', body: changes }),
  deleteTask: (id) => request(`/tasks/${id}`, { method: 'DELETE' }),

  // Admin only
  listUsers: (signal) => request('/users', { signal }),
};
