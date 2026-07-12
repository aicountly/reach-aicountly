import { Outlet, NavLink } from 'react-router-dom';
import { ROUTES } from '../../constants/routes';
import { usePermission } from '../../hooks/usePermission';

const NAV_ITEMS = [
  { to: ROUTES.CONTENT, label: 'All Content', exact: true },
  { to: ROUTES.CONTENT_DAILY_PACK, label: 'Daily Pack' },
  { to: ROUTES.CONTENT_CALENDAR, label: 'Calendar' },
];

export function ContentLayout() {
  const { has } = usePermission();
  if (!has('content.view')) return null;

  return (
    <div>
      <nav style={{
        display: 'flex',
        gap: 4,
        borderBottom: '1px solid #e5e7eb',
        paddingBottom: 0,
        marginBottom: 20,
      }}>
        {NAV_ITEMS.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.exact}
            style={({ isActive }) => ({
              padding: '8px 16px',
              fontSize: 13,
              fontWeight: 600,
              color: isActive ? '#3b82f6' : '#6b7280',
              borderBottom: `2px solid ${isActive ? '#3b82f6' : 'transparent'}`,
              textDecoration: 'none',
            })}
          >
            {item.label}
          </NavLink>
        ))}
      </nav>
      <Outlet />
    </div>
  );
}
