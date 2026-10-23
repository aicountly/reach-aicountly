import { Link } from 'react-router-dom';

/**
 * A value you can drill into: a router link when `to` is given, otherwise a
 * button calling `onClick`. Renders plain text when neither is set, so a
 * caller can pass a target conditionally (e.g. never drill into a zero).
 *
 * @param {{ to?: string, onClick?: () => void, label?: string, children: any, className?: string, title?: string }} props
 */
export function DrillLink({ to, onClick, label, children, className = '', title }) {
  const cls = `drill-link ${className}`.trim();
  if (to) {
    return <Link to={to} className={cls} aria-label={label} title={title}>{children}</Link>;
  }
  if (onClick) {
    return (
      <button
        type="button"
        className={cls}
        aria-label={label}
        title={title}
        onClick={(e) => { e.stopPropagation(); onClick(); }}
      >
        {children}
      </button>
    );
  }
  return <>{children}</>;
}
