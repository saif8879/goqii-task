import { PRIORITIES, SORT_OPTIONS, STATUSES } from '../constants.js';

export default function TaskFilters({
  filters,
  sort,
  users,
  isAdmin,
  onFilterChange,
  onSortChange,
  onReset,
}) {
  const handle = (event) => onFilterChange(event.target.name, event.target.value);

  return (
    <section className="filters" aria-label="Filter tasks">
      <label>
        Status
        <select name="status" value={filters.status} onChange={handle}>
          <option value="">All</option>
          {STATUSES.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>

      <label>
        Priority
        <select name="priority" value={filters.priority} onChange={handle}>
          <option value="">All</option>
          {PRIORITIES.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>

      {/* Only an admin sees more than one owner's tasks, so only an admin
          gets a filter for it. */}
      {isAdmin && (
        <label>
          Owner
          <select name="user_id" value={filters.user_id} onChange={handle}>
            <option value="">Anyone</option>
            {users.map((user) => (
              <option key={user.id} value={user.id}>
                {user.name}
              </option>
            ))}
          </select>
        </label>
      )}

      <label className="filters__search">
        Search
        <input
          type="search"
          name="search"
          value={filters.search}
          placeholder="Title or description"
          onChange={handle}
        />
      </label>

      <label>
        Sort by
        <select
          name="sort"
          value={sort.sort}
          onChange={(event) => onSortChange(event.target.value)}
        >
          {SORT_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>

      <button type="button" className="button button--ghost" onClick={onReset}>
        Clear
      </button>
    </section>
  );
}
