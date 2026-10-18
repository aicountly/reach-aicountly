import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CornerDownLeft, ExternalLink, FileText, Search } from 'lucide-react';
import { buildPaletteEntries, searchPalette, settingEntries } from '../../constants/navManifest';
import { usePermission } from '../../hooks/usePermission';
import { adminService } from '../../services/adminService';
import { matchesQuery } from '../../pages/admin/automationSettings/model';

const MAX_RESULTS = 60;

/**
 * Ctrl/⌘-K "go to" palette: every page and setting the user may open.
 * A combobox + listbox (arrow keys, Enter, Escape); focus returns to where
 * it was on close.
 */
export function CommandPalette({ open, onClose }) {
  const { has } = usePermission();
  const navigate = useNavigate();
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const [settingsRows, setSettingsRows] = useState(null);
  const inputRef = useRef(null);
  const listRef = useRef(null);
  const restoreRef = useRef(null);
  const listId = useId();
  const optionId = (i) => `${listId}-opt-${i}`;

  const canSettings = has('settings.view');
  const base = useMemo(() => buildPaletteEntries(has), [has]);

  // Lazily fetch the individual settings the first time the palette opens.
  useEffect(() => {
    if (!open || !canSettings || settingsRows !== null) return;
    let cancelled = false;
    Promise.resolve()
      .then(() => adminService.automationSettings())
      .then((rows) => { if (!cancelled) setSettingsRows(Array.isArray(rows) ? rows : []); })
      .catch(() => { if (!cancelled) setSettingsRows([]); });
    return () => { cancelled = true; };
  }, [open, canSettings, settingsRows]);

  const results = useMemo(() => {
    const pages = searchPalette(base, query);
    if (!query.trim() || !settingsRows?.length) return pages.slice(0, MAX_RESULTS);
    const settings = settingEntries(settingsRows).filter((e) => matchesQuery(e.row, query));
    return [...pages, ...settings].slice(0, MAX_RESULTS);
  }, [base, query, settingsRows]);

  useEffect(() => {
    if (!open) return undefined;
    restoreRef.current = document.activeElement;
    setQuery('');
    setActive(0);
    const t = setTimeout(() => inputRef.current?.focus(), 0);
    return () => {
      clearTimeout(t);
      const el = restoreRef.current;
      if (el && typeof el.focus === 'function') el.focus();
    };
  }, [open]);

  useEffect(() => { setActive(0); }, [query]);

  useEffect(() => {
    const el = listRef.current?.querySelector(`[data-index="${active}"]`);
    if (el && typeof el.scrollIntoView === 'function') el.scrollIntoView({ block: 'nearest' });
  }, [active]);

  if (!open) return null;

  const go = (entry) => {
    if (!entry) return;
    onClose();
    if (entry.external) {
      window.open(entry.path, '_blank', 'noopener,noreferrer');
      return;
    }
    navigate(entry.path);
  };

  const onKeyDown = (e) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive((i) => (results.length ? (i + 1) % results.length : 0));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((i) => (results.length ? (i - 1 + results.length) % results.length : 0));
    } else if (e.key === 'Home') {
      e.preventDefault(); setActive(0);
    } else if (e.key === 'End') {
      e.preventDefault(); setActive(Math.max(0, results.length - 1));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      go(results[active]);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      onClose();
    } else if (e.key === 'Tab') {
      e.preventDefault(); // the input is the only stop inside the dialog
    }
  };

  // Group headers in result order.
  const rows = [];
  let lastGroup = null;
  results.forEach((entry, i) => {
    if (entry.group !== lastGroup) {
      rows.push({ header: entry.group, key: `h:${entry.group}:${i}` });
      lastGroup = entry.group;
    }
    rows.push({ entry, index: i, key: entry.id });
  });

  return (
    <div className="reach-palette__backdrop" onMouseDown={onClose} data-testid="command-palette">
      <div
        className="reach-palette"
        role="dialog"
        aria-modal="true"
        aria-label="Go to a page or setting"
        onMouseDown={(e) => e.stopPropagation()}
      >
        <div className="reach-palette__search">
          <Search size={17} aria-hidden="true" className="reach-palette__search-icon" />
          <input
            ref={inputRef}
            type="text"
            role="combobox"
            aria-expanded="true"
            aria-controls={listId}
            aria-autocomplete="list"
            aria-activedescendant={results.length ? optionId(active) : undefined}
            aria-label="Search pages and settings"
            placeholder="Search pages and settings…"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={onKeyDown}
            autoComplete="off"
            spellCheck="false"
          />
          <kbd className="reach-kbd">Esc</kbd>
        </div>
        <ul className="reach-palette__list" id={listId} role="listbox" ref={listRef} aria-label="Results">
          {rows.map((r) => (r.header ? (
            <li key={r.key} role="presentation" className="reach-palette__group">{r.header}</li>
          ) : (
            <li
              key={r.key}
              id={optionId(r.index)}
              role="option"
              aria-selected={r.index === active}
              data-index={r.index}
              className={`reach-palette__option${r.index === active ? ' reach-palette__option--active' : ''}`}
              onMouseMove={() => { if (r.index !== active) setActive(r.index); }}
              onClick={() => go(r.entry)}
            >
              {r.entry.icon ? <r.entry.icon size={15} aria-hidden="true" className="reach-palette__icon" /> : <FileText size={15} aria-hidden="true" className="reach-palette__icon" />}
              <span className="reach-palette__label">{r.entry.label}</span>
              {r.entry.hint && <span className="reach-palette__hint">{r.entry.hint}</span>}
              {r.entry.external
                ? <ExternalLink size={13} aria-hidden="true" className="reach-palette__enter" />
                : <CornerDownLeft size={13} aria-hidden="true" className="reach-palette__enter" />}
            </li>
          )))}
        </ul>
        {results.length === 0 && (
          <p className="reach-palette__empty" role="status">
            Nothing matches “{query}”. Try a page name (Social, Jobs, Leads) or a setting (API key, model).
          </p>
        )}
        <div className="reach-palette__footer" aria-hidden="true">
          <span><kbd className="reach-kbd">↑</kbd><kbd className="reach-kbd">↓</kbd> move</span>
          <span><kbd className="reach-kbd">Enter</kbd> open</span>
          <span><kbd className="reach-kbd">Esc</kbd> close</span>
        </div>
      </div>
    </div>
  );
}
