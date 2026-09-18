import { useMemo, useState } from 'react';
import { DESCRIPTION_MAX, PRIORITIES, STATUSES, TITLE_MAX } from '../constants.js';
import { validateTask } from '../validation.js';

const BLANK_TASK = {
  title: '',
  description: '',
  status: 'todo',
  priority: 'medium',
  due_date: '',
  user_id: '',
};

function toFormValues(task, users, currentUserId) {
  if (!task) {
    // Default the owner to the person filling the form in; an admin can change it.
    const fallback = users.length > 0 ? users[0].id : currentUserId;

    return { ...BLANK_TASK, user_id: fallback === undefined ? '' : String(fallback) };
  }

  return {
    title: task.title,
    description: task.description ?? '',
    status: task.status,
    priority: task.priority,
    due_date: task.due_date ?? '',
    user_id: String(task.user_id),
  };
}

export default function TaskForm({
  task,
  users,
  isAdmin,
  currentUserId,
  submitting,
  serverErrors,
  onSubmit,
  onCancel,
}) {
  const [values, setValues] = useState(() => toFormValues(task, users, currentUserId));
  const [clientErrors, setClientErrors] = useState({});
  const [submitted, setSubmitted] = useState(false);

  // Server errors win, since they are the authoritative answer for this payload.
  const errors = useMemo(() => ({ ...clientErrors, ...serverErrors }), [clientErrors, serverErrors]);

  const handleChange = (event) => {
    const next = { ...values, [event.target.name]: event.target.value };

    setValues(next);

    // Only re-validate live once the user has tried to submit at least once.
    if (submitted) {
      setClientErrors(validateTask(next, isAdmin));
    }
  };

  const handleSubmit = (event) => {
    event.preventDefault();
    setSubmitted(true);

    const found = validateTask(values, isAdmin);
    setClientErrors(found);

    if (Object.keys(found).length > 0) {
      return;
    }

    const description = values.description.trim();

    const payload = {
      title: values.title.trim(),
      description: description === '' ? null : description,
      status: values.status,
      priority: values.priority,
      due_date: values.due_date === '' ? null : values.due_date,
    };

    // Only an admin may nominate an owner. Sending it as a regular user would
    // be rejected with a 403 on update, and ignored on create.
    if (isAdmin) {
      payload.user_id = Number(values.user_id);
    }

    onSubmit(payload);
  };

  return (
    <form className="panel task-form" onSubmit={handleSubmit} noValidate>
      <h2>{task ? `Edit task #${task.id}` : 'New task'}</h2>

      <div className="field">
        <label htmlFor="title">Title</label>
        <input
          id="title"
          name="title"
          value={values.title}
          maxLength={TITLE_MAX}
          onChange={handleChange}
          aria-invalid={Boolean(errors.title)}
        />
        {errors.title && <p className="field__error">{errors.title}</p>}
      </div>

      <div className="field">
        <label htmlFor="description">Description</label>
        <textarea
          id="description"
          name="description"
          rows={3}
          value={values.description}
          maxLength={DESCRIPTION_MAX}
          onChange={handleChange}
          aria-invalid={Boolean(errors.description)}
        />
        {errors.description && <p className="field__error">{errors.description}</p>}
      </div>

      <div className="field-row">
        <div className="field">
          <label htmlFor="status">Status</label>
          <select id="status" name="status" value={values.status} onChange={handleChange}>
            {STATUSES.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
          {errors.status && <p className="field__error">{errors.status}</p>}
        </div>

        <div className="field">
          <label htmlFor="priority">Priority</label>
          <select id="priority" name="priority" value={values.priority} onChange={handleChange}>
            {PRIORITIES.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
          {errors.priority && <p className="field__error">{errors.priority}</p>}
        </div>
      </div>

      <div className="field-row">
        <div className="field">
          <label htmlFor="due_date">Due date</label>
          <input
            id="due_date"
            name="due_date"
            type="date"
            value={values.due_date}
            onChange={handleChange}
            aria-invalid={Boolean(errors.due_date)}
          />
          {errors.due_date && <p className="field__error">{errors.due_date}</p>}
        </div>

        {isAdmin && (
          <div className="field">
            <label htmlFor="user_id">Owner</label>
            <select
              id="user_id"
              name="user_id"
              value={values.user_id}
              onChange={handleChange}
              aria-invalid={Boolean(errors.user_id)}
            >
              <option value="">Select an owner</option>
              {users.map((user) => (
                <option key={user.id} value={user.id}>
                  {user.name}
                </option>
              ))}
            </select>
            {errors.user_id && <p className="field__error">{errors.user_id}</p>}
          </div>
        )}
      </div>

      <div className="task-form__actions">
        <button type="submit" className="button" disabled={submitting}>
          {submitting ? 'Saving…' : task ? 'Save changes' : 'Create task'}
        </button>
        <button type="button" className="button button--ghost" onClick={onCancel} disabled={submitting}>
          Cancel
        </button>
      </div>
    </form>
  );
}
