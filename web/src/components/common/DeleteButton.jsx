import { Trash2 } from 'lucide-react';

/**
 * Row/detail delete control with a confirm step.
 *
 * The confirm text is passed in so each surface can state exactly what the
 * delete does (archive vs permanent removal, cascading children, …).
 */
export function DeleteButton({
  onConfirm,
  confirmMessage = 'Delete this record? This cannot be undone.',
  label = 'Delete',
  busyLabel = 'Deleting…',
  busy = false,
  disabled = false,
  title,
  size = 12,
  className = 'btn btn-danger btn-sm',
}) {
  const handleClick = (e) => {
    e.stopPropagation();
    if (!window.confirm(confirmMessage)) return;
    onConfirm();
  };

  return (
    <button
      type="button"
      className={className}
      onClick={handleClick}
      disabled={busy || disabled}
      title={title || label}
    >
      <Trash2 size={size} /> {busy ? busyLabel : label}
    </button>
  );
}

/**
 * DataTable column carrying a DeleteButton, or nothing when the user lacks the
 * permission. Spread into a columns array:
 *   ...deleteColumn({ canDelete, isDeleting, remove, confirmMessage })
 *
 * `confirmMessage` may be a string or a function of the row.
 */
export function deleteColumn({ canDelete, isDeleting, remove, confirmMessage, title }) {
  if (!canDelete) return [];

  return [{
    key: 'actions',
    label: 'Actions',
    width: 110,
    render: (row) => (
      <DeleteButton
        busy={isDeleting(row)}
        title={title}
        confirmMessage={typeof confirmMessage === 'function' ? confirmMessage(row) : confirmMessage}
        onConfirm={() => remove(row)}
      />
    ),
  }];
}
