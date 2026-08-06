import { useCallback, useState } from 'react';

/**
 * Shared delete state for list rows and detail pages.
 *
 * `onDelete` performs the API call for one record, `onDone` reloads the
 * surface afterwards. The row's id (or uuid) is tracked so only the row being
 * deleted shows a busy state.
 */
export function useRowDelete({ onDelete, onDone }) {
  const [deletingId, setDeletingId] = useState(null);
  const [error, setError] = useState(null);

  const remove = useCallback(async (row) => {
    const key = row?.id ?? row?.uuid ?? row?.external_id ?? 'record';
    setDeletingId(key);
    setError(null);
    try {
      await onDelete(row);
      await onDone?.();
      return true;
    } catch (e) {
      setError(e?.message || 'Delete failed.');
      return false;
    } finally {
      setDeletingId(null);
    }
  }, [onDelete, onDone]);

  const isDeleting = useCallback(
    (row) => deletingId !== null && deletingId === (row?.id ?? row?.uuid ?? row?.external_id ?? 'record'),
    [deletingId],
  );

  return { deletingId, isDeleting, error, setError, remove };
}
