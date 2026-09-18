export const STATUSES = [
  { value: 'todo', label: 'To do' },
  { value: 'in-progress', label: 'In progress' },
  { value: 'done', label: 'Done' },
];

export const PRIORITIES = [
  { value: 'low', label: 'Low' },
  { value: 'medium', label: 'Medium' },
  { value: 'high', label: 'High' },
];

// There is no direction control in the UI, so each column carries the
// direction that actually reads well for it. Newest created first, but the
// soonest deadline, titles from A to Z and the work still to do before the
// work already finished.
export const SORT_OPTIONS = [
  { value: 'created_at', label: 'Created', order: 'desc' },
  { value: 'due_date', label: 'Due date', order: 'asc' },
  { value: 'title', label: 'Title', order: 'asc' },
  { value: 'priority', label: 'Priority', order: 'desc' },
  { value: 'status', label: 'Status', order: 'asc' },
];

export function sortFor(column) {
  const match = SORT_OPTIONS.find((option) => option.value === column);

  return { sort: column, order: match ? match.order : 'desc' };
}

export const DEFAULT_SORT = sortFor('created_at');

// Kept in step with the server-side limits in TaskValidator.
export const TITLE_MIN = 3;
export const TITLE_MAX = 160;
export const DESCRIPTION_MAX = 2000;

export function labelFor(options, value) {
  const match = options.find((option) => option.value === value);

  return match ? match.label : value;
}
