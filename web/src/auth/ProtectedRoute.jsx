import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from './AuthContext.jsx';

/**
 * Gate for signed-in routes. While the boot-time session restore is still in
 * flight we render nothing conclusive — redirecting during that window would
 * bounce a signed-in user to the login screen on every refresh.
 */
export function ProtectedRoute({ children, role }) {
  const { isChecking, isSignedIn, user } = useAuth();
  const location = useLocation();

  if (isChecking) {
    return <p className="state">Checking your session…</p>;
  }

  if (!isSignedIn) {
    // Remember where they were headed so login can send them back.
    return <Navigate to="/login" state={{ from: location.pathname }} replace />;
  }

  if (role && user.role !== role) {
    return (
      <div className="alert alert--error" role="alert">
        <span>You do not have permission to view this page.</span>
      </div>
    );
  }

  return children;
}

/** Keeps signed-in users away from the login and register screens. */
export function GuestRoute({ children }) {
  const { isChecking, isSignedIn } = useAuth();

  if (isChecking) {
    return <p className="state">Checking your session…</p>;
  }

  if (isSignedIn) {
    return <Navigate to="/" replace />;
  }

  return children;
}
