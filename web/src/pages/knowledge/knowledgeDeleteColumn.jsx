import { DeleteButton } from '../../components/common/DeleteButton';

/**
 * Actions column shared by every knowledge list page.
 *
 * Spread into a `columns` array. Returns an empty array when the caller lacks
 * the manage permission for that entity, so the column disappears rather than
 * showing a button the API would reject.
 *
 * @param {object}   opts
 * @param {boolean}  opts.can          Result of usePermission().has(...)
 * @param {string}   opts.entityLabel  Singular noun for the confirm prompt
 * @param {Function} opts.nameOf       row => display name
 * @param {Function} opts.onDelete     row => Promise
 * @param {Function} opts.onDeleted    Reload callback
 * @param {Function} opts.onError      Error message setter
 */
export function knowledgeDeleteColumn({ can, entityLabel, nameOf, onDelete, onDeleted, onError }) {
  if (!can) return [];

  // Claims, citations and brand rules are identified by a paragraph of text —
  // a confirm dialog holding all of it is unreadable.
  const label = (row) => {
    const name = String(nameOf(row) ?? '').trim() || 'untitled';
    return name.length > 80 ? `${name.slice(0, 80)}…` : name;
  };

  return [{
    key: 'actions',
    label: 'Actions',
    width: 110,
    render: (row) => (
      <DeleteButton
        confirmMessage={`Permanently delete the ${entityLabel} “${label(row)}”? This cannot be undone.`}
        onDelete={() => onDelete(row)}
        onDeleted={onDeleted}
        onError={onError}
      />
    ),
  }];
}
