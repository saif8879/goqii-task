import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ApiError } from '../api/client.js';
import { useAuth } from '../auth/AuthContext.jsx';

const PASSWORD_MIN = 8;

/** Mirrors AuthValidator on the server; the server still re-checks everything. */
function validate(values) {
  const errors = {};

  if (values.name.trim().length < 2) {
    errors.name = 'Name must be at least 2 characters.';
  }

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email.trim())) {
    errors.email = 'Enter a valid email address.';
  }

  if (values.password.length < PASSWORD_MIN) {
    errors.password = `Password must be at least ${PASSWORD_MIN} characters.`;
  } else if (!/[A-Za-z]/.test(values.password) || !/\d/.test(values.password)) {
    errors.password = 'Password must contain at least one letter and one number.';
  }

  if (values.confirmation !== values.password) {
    errors.confirmation = 'Passwords do not match.';
  }

  return errors;
}

export default function RegisterPage() {
  const { register } = useAuth();
  const navigate = useNavigate();

  const [values, setValues] = useState({ name: '', email: '', password: '', confirmation: '' });
  const [fieldErrors, setFieldErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [submitted, setSubmitted] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const handleChange = (event) => {
    const next = { ...values, [event.target.name]: event.target.value };

    setValues(next);

    if (submitted) {
      setFieldErrors(validate(next));
    }
  };

  const handleSubmit = async (event) => {
    event.preventDefault();
    setSubmitted(true);
    setFormError(null);

    const errors = validate(values);
    setFieldErrors(errors);

    if (Object.keys(errors).length > 0) {
      return;
    }

    setSubmitting(true);

    try {
      await register({
        name: values.name.trim(),
        email: values.email.trim(),
        password: values.password,
      });

      navigate('/', { replace: true });
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
        <h1>Create an account</h1>
        <p className="auth-form__hint">New accounts start with the standard user role.</p>

        {formError && (
          <div className="alert alert--error" role="alert">
            <span>{formError}</span>
          </div>
        )}

        <div className="field">
          <label htmlFor="name">Name</label>
          <input
            id="name"
            name="name"
            value={values.name}
            onChange={handleChange}
            aria-invalid={Boolean(fieldErrors.name)}
          />
          {fieldErrors.name && <p className="field__error">{fieldErrors.name}</p>}
        </div>

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
            autoComplete="new-password"
            value={values.password}
            onChange={handleChange}
            aria-invalid={Boolean(fieldErrors.password)}
          />
          {fieldErrors.password && <p className="field__error">{fieldErrors.password}</p>}
        </div>

        <div className="field">
          <label htmlFor="confirmation">Confirm password</label>
          <input
            id="confirmation"
            name="confirmation"
            type="password"
            autoComplete="new-password"
            value={values.confirmation}
            onChange={handleChange}
            aria-invalid={Boolean(fieldErrors.confirmation)}
          />
          {fieldErrors.confirmation && <p className="field__error">{fieldErrors.confirmation}</p>}
        </div>

        <button type="submit" className="button" disabled={submitting}>
          {submitting ? 'Creating account…' : 'Create account'}
        </button>

        <p className="auth-form__switch">
          Already registered? <Link to="/login">Sign in</Link>
        </p>
      </form>
    </div>
  );
}
