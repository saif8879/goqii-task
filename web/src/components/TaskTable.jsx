import { PRIORITIES, STATUSES, labelFor } from '../constants.js';

function formatDueDate(dueDate) {
  if (!dueDate) {
    return '—';
  }

  const [year, month, day] = dueDate.split('-').map(Number);

  return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
  });
}

function isOverdue(task) {
  if (!task.due_date || task.status === 'done') {
    return false;
  }

  return task.due_date < new Date().toISOString().slice(0, 10);
}

export default function TaskTable({ tasks, busyIds, isAdmin, onStatusChange, onEdit, onDelete }) {
  return (
    <table className="tasks">
      <thead>
        <tr>
          <th>Task</th>
          {/* A regular user only ever sees their own rows, so the column is noise. */}
          {isAdmin && <th>Owner</th>}
          <th>Priority</th>
          <th>Due</th>
          <th>Status</th>
          <th aria-label="Actions" />
        </tr>
      </thead>
      <tbody>
        {tasks.map((task) => {
          const busy = busyIds.includes(task.id);

          return (
            <tr key={task.id} className={busy ? 'is-busy' : undefined}>
              <td>
                <div className="tasks__title">{task.title}</div>
                {task.description && <p className="tasks__description">{task.description}</p>}
              </td>
              {isAdmin && <td>{task.owner.name}</td>}
              <td>
                <span className={`badge badge--${task.priority}`}>
                  {labelFor(PRIORITIES, task.priority)}
                </span>
              </td>
              <td className={isOverdue(task) ? 'tasks__due is-overdue' : 'tasks__due'}>
                {formatDueDate(task.due_date)}
                {isOverdue(task) && <span className="tasks__overdue-flag">overdue</span>}
              </td>
              <td>
                <select
                  value={task.status}
                  disabled={busy}
                  aria-label={`Status for ${task.title}`}
                  onChange={(event) => onStatusChange(task, event.target.value)}
                >
                  {STATUSES.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </td>
              <td className="tasks__actions">
                <button type="button" className="button button--ghost" disabled={busy} onClick={() => onEdit(task)}>
                  Edit
                </button>
                <button type="button" className="button button--danger" disabled={busy} onClick={() => onDelete(task)}>
                  Delete
                </button>
              </td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}
