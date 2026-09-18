import { DESCRIPTION_MAX, PRIORITIES, STATUSES, TITLE_MAX, TITLE_MIN } from './constants.js';

function isCalendarDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return false;
  }

  const [year, month, day] = value.split('-').map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));

  // Rejects rolled-over dates such as 2026-02-31.
  return (
    date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day
  );
}

/**
 * Mirrors the server rules so the user gets feedback without a round trip.
 * The API still validates everything again - this is convenience, not security.
 */
export function validateTask(values, isAdmin = false) {
  const errors = {};
  const title = values.title.trim();

  if (title === '') {
    errors.title = 'Title is required.';
  } else if (title.length < TITLE_MIN) {
    errors.title = `Title must be at least ${TITLE_MIN} characters.`;
  } else if (title.length > TITLE_MAX) {
    errors.title = `Title cannot be longer than ${TITLE_MAX} characters.`;
  }

  if (values.description.trim().length > DESCRIPTION_MAX) {
    errors.description = `Description cannot be longer than ${DESCRIPTION_MAX} characters.`;
  }

  if (!STATUSES.some((option) => option.value === values.status)) {
    errors.status = 'Pick a valid status.';
  }

  if (!PRIORITIES.some((option) => option.value === values.priority)) {
    errors.priority = 'Pick a valid priority.';
  }

  if (values.due_date !== '' && !isCalendarDate(values.due_date)) {
    errors.due_date = 'Due date must be a real date.';
  }

  // Regular users never pick an owner: the server assigns it from their token.
  if (isAdmin && values.user_id === '') {
    errors.user_id = 'Choose an owner for this task.';
  }

  return errors;
}
