import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { ProtectedRoute } from '../ProtectedRoute';
import { renderWithAuth } from '../../test/renderWithAuth';

const Guarded = () => <div>Protected content</div>;
const Home = () => <div>Home (dashboard fallback)</div>;

function renderAt(path, auth) {
  return renderWithAuth(
    <Routes>
      <Route path="/" element={<Home />} />
      <Route
        path="/blog"
        element={
          <ProtectedRoute permission="blog.view">
            <Guarded />
          </ProtectedRoute>
        }
      />
      <Route
        path="/settings"
        element={
          <ProtectedRoute permission="settings.manage">
            <Guarded />
          </ProtectedRoute>
        }
      />
    </Routes>,
    { route: path, auth },
  );
}

describe('ProtectedRoute', () => {
  it('redirects unauthenticated visitors away from a permission-guarded route', () => {
    renderAt('/blog', { user: null });
    expect(screen.getByText(/Home \(dashboard fallback\)/)).toBeInTheDocument();
    expect(screen.queryByText(/Protected content/)).not.toBeInTheDocument();
  });

  it('renders a forbidden state for authenticated users lacking the permission', () => {
    renderAt('/settings', {
      user: { id: 2, email: 'analyst@aicountly.org', role: 'analyst' },
      permissions: ['dashboard.view', 'analytics.view'],
    });
    expect(screen.getByText(/Access denied/i)).toBeInTheDocument();
    expect(screen.getByText(/settings\.manage/)).toBeInTheDocument();
    expect(screen.queryByText(/Protected content/)).not.toBeInTheDocument();
  });

  it('renders the guarded content when the user has the required permission', () => {
    renderAt('/blog', {
      user: { id: 5, email: 'writer@aicountly.org', role: 'marketing_manager' },
      permissions: ['blog.view', 'blog.create'],
    });
    expect(screen.getByText(/Protected content/)).toBeInTheDocument();
    expect(screen.queryByText(/Access denied/i)).not.toBeInTheDocument();
  });

  it('treats wildcard "*" as full access', () => {
    renderAt('/settings', {
      user: { id: 1, email: 'root@aicountly.org', role: 'super_admin' },
      permissions: ['*'],
    });
    expect(screen.getByText(/Protected content/)).toBeInTheDocument();
  });
});
