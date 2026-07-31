import { Link } from 'react-router-dom';

/**
 * @param {{ items: Array<{ label: string, to?: string }> }} props
 */
export function Breadcrumbs({ items = [] }) {
  if (!items.length) return null;

  return (
    <nav className="breadcrumb" aria-label="Breadcrumb">
      {items.map((item, index) => {
        const isLast = index === items.length - 1;
        return (
          <span key={`${item.label}-${index}`}>
            {index > 0 && <span className="breadcrumb__sep" aria-hidden="true"> / </span>}
            {isLast || !item.to ? (
              <span className="breadcrumb__current" aria-current="page">{item.label}</span>
            ) : (
              <Link to={item.to} className="breadcrumb-link">{item.label}</Link>
            )}
          </span>
        );
      })}
    </nav>
  );
}
