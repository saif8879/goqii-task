import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { ApiError } from '../api/client.js';
import { useAuth } from '../auth/AuthContext.jsx';

export default function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const [values, setValues] = useState({ email: '', password: '' });
  const [fieldErrors, setFieldErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const handleChange = (event) =>
    setValues((current) => ({ ...current, [event.target.name]: event.target.value }));

  const handleSubmit = async (event) => {
    event.preventDefault();

    const errors = {};

    if (values.email.trim() === '') {
      errors.email = 'Email is required.';
    }

    if (values.password === '') {
      errors.password = 'Password is required.';
    }

    setFieldErrors(errors);
    setFormError(null);

    if (Object.keys(errors).length > 0) {
      return;
    }

    setSubmitting(true);

    try {
      await login(values.email.trim(), values.password);

      // Send them back to whatever they were trying to reach.
      navigate(location.state?.from || '/', { replace: true });
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 422) {
        setFieldErrors(failure.errors);
      } else {
        setFormError(failure.message);
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="auth-screen">
      <form className="panel auth-form" onSubmit={handleSubmit} noValidate>
        <img className="auth-form__logo" src="/goqii-logo.png" alt="GOQii" width="251" height="258" />
        <h1>Sign in</h1>
        <p className="auth-form__hint">Task Manager</p>

        {formError && (
          <div className="alert alert--error" role="alert">
            <span>{formError}</span>
          </div>
        )}

        <div className="field">
          <label htmlFor="email">Email</label>
          <input
            id="email"
            name="email"
            type="email"
            autoComplete="email"
            value={values.email}
            onChange={handleChange}
            aria-invalid={Boolean(fieldErrors.email)}
          />
          {fieldErrors.email && <p className="field__error">{fieldErrors.email}</p>}
        </div>

        <div className="field">
          <label htmlFor="password">Password</label>
          <input
            id="password"
            name="password"
            type="password"
            autoComplete="current-password"
            value={values.password}
            onChange={handleChange}
            aria-invalid={Boolean(fieldErrors.password)}
          />
          {fieldErrors.password && <p className="field__error">{fieldErrors.password}</p>}
        </div>

        <button type="submit" className="button" disabled={submitting}>
          {submitting ? 'Signing in…' : 'Sign in'}
        </button>

        <p className="auth-form__switch">
          No account yet? <Link to="/register">Create one</Link>
        </p>
      </form>
    </div>
  );
}
