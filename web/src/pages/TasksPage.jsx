import { useEffect, useMemo, useState } from 'react';
import { ApiError, api } from '../api/client.js';
import { useAuth } from '../auth/AuthContext.jsx';
import Pagination from '../components/Pagination.jsx';
import TaskFilters from '../components/TaskFilters.jsx';
import TaskForm from '../components/TaskForm.jsx';
import TaskTable from '../components/TaskTable.jsx';
import { DEFAULT_SORT, sortFor } from '../constants.js';
import { useDebouncedValue } from '../hooks/useDebouncedValue.js';
import { useTasks } from '../hooks/useTasks.js';

const PER_PAGE = 10;
const NO_FILTERS = { status: '', priority: '', user_id: '' };

export default function TasksPage() {
  const { user, isAdmin, logout } = useAuth();

  const [filters, setFilters] = useState(NO_FILTERS);
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState(DEFAULT_SORT);
  const [page, setPage] = useState(1);

  const [users, setUsers] = useState([]);
  const [isFormOpen, setIsFormOpen] = useState(false);
  const [formTask, setFormTask] = useState(null);
  const [formErrors, setFormErrors] = useState({});
  const [saving, setSaving] = useState(false);

  // Errors from row-level actions, kept apart from the list's own error state.
  const [actionError, setActionError] = useState(null);
  const [busyIds, setBusyIds] = useState([]);

  const debouncedSearch = useDebouncedValue(search, 350);

  const query = useMemo(
    () => ({ ...filters, search: debouncedSearch, ...sort, page, per_page: PER_PAGE }),
    [filters, debouncedSearch, sort, page],
  );

  const { tasks, meta, loading, error, reload } = useTasks(query);

  // Only an admin can list accounts, and only an admin needs to: regular users
  // never choose an owner, because the server assigns it from their token.
  useEffect(() => {
    if (!isAdmin) {
      setUsers([]);
      return undefined;
    }

    const controller = new AbortController();

    api
      .listUsers(controller.signal)
      .then((payload) => setUsers(payload.data))
      .catch((failure) => {
        if (failure.name !== 'AbortError') {
          setActionError(`Could not load the account list: ${failure.message}`);
        }
      });

    return () => controller.abort();
  }, [isAdmin]);

  // A new search term should drop the user back to the first page of results.
  useEffect(() => {
    setPage(1);
  }, [debouncedSearch]);

  const markBusy = (id) => setBusyIds((current) => [...current, id]);
  const clearBusy = (id) => setBusyIds((current) => current.filter((value) => value !== id));

  const handleFilterChange = (name, value) => {
    if (name === 'search') {
      setSearch(value);
      return;
    }

    setFilters((current) => ({ ...current, [name]: value }));
    setPage(1);
  };

  const handleSortChange = (column) => {
    setSort(sortFor(column));
    setPage(1);
  };

  const handleReset = () => {
    setFilters(NO_FILTERS);
    setSearch('');
    setSort(DEFAULT_SORT);
    setPage(1);
  };

  const openCreateForm = () => {
    setFormTask(null);
    setFormErrors({});
    setIsFormOpen(true);
  };

  const openEditForm = (task) => {
    setFormTask(task);
    setFormErrors({});
    setIsFormOpen(true);
  };

  const closeForm = () => {
    setIsFormOpen(false);
    setFormTask(null);
    setFormErrors({});
  };

  const handleSubmit = async (payload) => {
    setSaving(true);
    setFormErrors({});
    setActionError(null);

    try {
      if (formTask) {
        await api.updateTask(formTask.id, payload);
        closeForm();
        reload();
      } else {
        await api.createTask(payload);
        closeForm();

        // Newest first is the default sort, so jump to page 1 to reveal it.
        if (page === 1) {
          reload();
        } else {
          setPage(1);
        }
      }
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 422) {
        setFormErrors(failure.errors);
      } else {
        setActionError(failure.message);
      }
    } finally {
      setSaving(false);
    }
  };

  const handleStatusChange = async (task, status) => {
    if (status === task.status) {
      return;
    }

    markBusy(task.id);
    setActionError(null);

    try {
      await api.patchTask(task.id, { status });
      reload();
    } catch (failure) {
      setActionError(`Could not update "${task.title}": ${failure.message}`);
    } finally {
      clearBusy(task.id);
    }
  };

  const handleDelete = async (task) => {
    if (!window.confirm(`Delete "${task.title}"? This cannot be undone.`)) {
      return;
    }

    markBusy(task.id);
    setActionError(null);

    try {
      await api.deleteTask(task.id);

      // Removing the last row of a page would otherwise leave an empty list.
      if (tasks.length === 1 && page > 1) {
        setPage(page - 1);
      } else {
        reload();
      }
    } catch (failure) {
      setActionError(`Could not delete "${task.title}": ${failure.message}`);
    } finally {
      clearBusy(task.id);
    }
  };

  return (
    <div className="app">
      <header className="app__header">
        <div className="app__identity">
          <img className="app__logo" src="/goqii-logo.png" alt="GOQii" width="251" height="258" />
          <div>
            <h1>Task Manager</h1>
            <p className="app__subtitle">
              {meta
                ? `${meta.total} task${meta.total === 1 ? '' : 's'} ${isAdmin ? 'across all accounts' : 'assigned to you'}`
                : 'Loading tasks…'}
            </p>
          </div>
        </div>

        <div className="app__account">
          <span className="app__user">
            {user.name}
            <span className={`badge badge--role-${user.role}`}>{user.role}</span>
          </span>
          <button type="button" className="button" onClick={openCreateForm} disabled={isFormOpen}>
            New task
          </button>
          <button type="button" className="button button--ghost" onClick={logout}>
            Sign out
          </button>
        </div>
      </header>

      <TaskFilters
        filters={{ ...filters, search }}
        sort={sort}
        users={users}
        isAdmin={isAdmin}
        onFilterChange={handleFilterChange}
        onSortChange={handleSortChange}
        onReset={handleReset}
      />

      {isFormOpen && (
        <TaskForm
          key={formTask ? formTask.id : 'new'}
          task={formTask}
          users={users}
          isAdmin={isAdmin}
          currentUserId={user.id}
          submitting={saving}
          serverErrors={formErrors}
          onSubmit={handleSubmit}
          onCancel={closeForm}
        />
      )}

      {actionError && (
        <div className="alert alert--error" role="alert">
          <span>{actionError}</span>
          <button type="button" className="button button--ghost" onClick={() => setActionError(null)}>
            Dismiss
          </button>
        </div>
      )}

      <main className="panel">
        {loading && <p className="state">Loading tasks…</p>}

        {!loading && error && (
          <div className="alert alert--error" role="alert">
            <span>{error}</span>
            <button type="button" className="button button--ghost" onClick={reload}>
              Try again
            </button>
          </div>
        )}

        {!loading && !error && tasks.length === 0 && (
          <p className="state">No tasks match these filters yet.</p>
        )}

        {!loading && !error && tasks.length > 0 && (
          <>
            <TaskTable
              tasks={tasks}
              busyIds={busyIds}
              isAdmin={isAdmin}
              onStatusChange={handleStatusChange}
              onEdit={openEditForm}
              onDelete={handleDelete}
            />
            <Pagination meta={meta} onPageChange={setPage} />
          </>
        )}
      </main>
    </div>
  );
}
