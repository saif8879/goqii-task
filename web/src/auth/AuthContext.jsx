import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, setAccessToken, setSessionCallbacks } from '../api/client.js';

const AuthContext = createContext(null);

/** 'checking' until the boot-time session restore settles, then one of the others. */
const CHECKING = 'checking';
const SIGNED_IN = 'signed-in';
const SIGNED_OUT = 'signed-out';

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [status, setStatus] = useState(CHECKING);

  const clearSession = useCallback(() => {
    setAccessToken(null);
    setUser(null);
    setStatus(SIGNED_OUT);
  }, []);

  const applySession = useCallback((session) => {
    setAccessToken(session.access_token);
    setUser(session.user);
    setStatus(SIGNED_IN);
  }, []);

  // Let the API client tell us when a background refresh renewed or lost the
  // session, so state stays in step with whatever the interceptor did.
  useEffect(() => {
    setSessionCallbacks({
      onLost: clearSession,
      onRenewed: (session) => {
        setAccessToken(session.access_token);
        setUser(session.user);
      },
    });
  }, [clearSession]);

  // The access token only lives in memory, so a reload starts with nothing.
  // The refresh cookie survives, so we trade it for a new token on boot.
  useEffect(() => {
    let cancelled = false;

    api
      .restoreSession()
      .then((session) => {
        if (cancelled) {
          return;
        }

        if (session) {
          applySession(session);
        } else {
          clearSession();
        }
      })
      .catch(() => {
        if (!cancelled) {
          clearSession();
        }
      });

    return () => {
      cancelled = true;
    };
  }, [applySession, clearSession]);

  const login = useCallback(
    async (email, password) => {
      const payload = await api.login(email, password);
      applySession(payload.data);
    },
    [applySession],
  );

  const register = useCallback(
    async (details) => {
      const payload = await api.register(details);
      applySession(payload.data);
    },
    [applySession],
  );

  const logout = useCallback(async () => {
    try {
      await api.logout();
    } finally {
      // Even if the call fails, drop local state: staying "signed in" with a
      // dead session is worse than signing out optimistically.
      clearSession();
    }
  }, [clearSession]);

  const value = useMemo(
    () => ({
      user,
      status,
      isChecking: status === CHECKING,
      isSignedIn: status === SIGNED_IN,
      isAdmin: user !== null && user.role === 'admin',
      login,
      register,
      logout,
    }),
    [user, status, login, register, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);

  if (context === null) {
    throw new Error('useAuth must be used inside an AuthProvider.');
  }

  return context;
}
