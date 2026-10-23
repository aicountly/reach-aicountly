import { useEffect, useState } from 'react';

/**
 * A removable chip for a drill filter that has no control of its own on the
 * list (product_id, source_id), so a list opened from a product or source
 * shows why it is short and how to widen it. `resolve(id)` may look up a name
 * for the label; the id is shown until (or unless) it answers.
 *
 * @param {{ label: string, value: string, onClear: () => void, resolve?: (id: string) => Promise<string|undefined> }} props
 */
export function KnowledgeFilterChip({ label, value, onClear, resolve }) {
  const [resolved, setResolved] = useState({ value: '', name: '' });

  useEffect(() => {
    if (!value || !resolve) return undefined;
    let live = true;
    Promise.resolve(resolve(value))
      .then((name) => { if (live && name) setResolved({ value, name }); })
      .catch(() => {});
    return () => { live = false; };
  }, [value, resolve]);

  if (!value) return null;
  const name = resolved.value === value && resolved.name ? resolved.name : `#${value}`;
  return (
    <div className="flex items-center gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
      <button type="button" className="badge badge-secondary" onClick={onClear} aria-label={`Clear ${label.toLowerCase()} filter`}>
        {label}: {name} ✕
      </button>
    </div>
  );
}
