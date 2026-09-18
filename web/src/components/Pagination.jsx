export default function Pagination({ meta, onPageChange }) {
  if (!meta || meta.total === 0) {
    return null;
  }

  const { page, per_page: perPage, total, total_pages: totalPages } = meta;
  const first = (page - 1) * perPage + 1;
  const last = Math.min(page * perPage, total);

  return (
    <nav className="pagination" aria-label="Task list pages">
      <span className="pagination__summary">
        Showing {first}&ndash;{last} of {total}
      </span>

      <div className="pagination__controls">
        <button
          type="button"
          className="button button--ghost"
          disabled={page <= 1}
          onClick={() => onPageChange(page - 1)}
        >
          Previous
        </button>

        <span>
          Page {page} of {totalPages}
        </span>

        <button
          type="button"
          className="button button--ghost"
          disabled={page >= totalPages}
          onClick={() => onPageChange(page + 1)}
        >
          Next
        </button>
      </div>
    </nav>
  );
}
