import { useState } from 'react';
import { Trash2 } from 'lucide-react';

/**
 * Confirm-then-delete button shared by the content, community and knowledge
 * lists so every permanent delete in the panel behaves the same way.
 *
 * `onDelete(force)` can be called twice: once normally, and again with
 * force=true when the API refused because a public takedown failed and the
 * operator confirms they will remove the public copy by hand.
 */
export function DeleteButton({
  onDelete,
  onDeleted,
  onError,
  confirmMessage = 'Delete this record permanently? This cannot be undone.',
  label = 'Delete',
  busyLabel = 'Deleting…',
  className = 'btn btn-danger btn-sm',
  title = 'Delete permanently',
  disabled = false,
  showIcon = true,
}) {
  const [busy, setBusy] = useState(false);

  const run = async (e) => {
    e.stopPropagation();
    if (!window.confirm(confirmMessage)) return;

    setBusy(true);
    onError?.(null);
    try {
      await onDelete(false);
      onDeleted?.();
    } catch (err) {
      const message = err?.message || 'Delete failed.';
      // The API says "…or delete with force…" when only the public takedown
      // failed; anything else is a plain error the operator cannot override.
      if (/force/i.test(message) && window.confirm(`${message}\n\nRemove it from Reach anyway?`)) {
        try {
          await onDelete(true);
          onDeleted?.();
        } catch (forced) {
          onError?.(forced?.message || 'Delete failed.');
        }
        return;
      }
      onError?.(message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <button
      type="button"
      className={className}
      onClick={run}
      disabled={disabled || busy}
      title={title}
    >
      {showIcon && <Trash2 size={12} />} {busy ? busyLabel : label}
    </button>
  );
}
