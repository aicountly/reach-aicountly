import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { KnowledgeLayout } from '../KnowledgeLayout';

vi.mock('../../../hooks/usePermission', () => ({
  usePermission: () => ({ has: () => true }),
}));

const authAsAdmin = {
  auth: {
    user: { id: 1, email: 'root@aicountly.org', role: 'super_admin' },
    permissions: ['*'],
  },
};

describe('KnowledgeLayout nav', () => {
  it('renders nav links for super admin', () => {
    renderWithAuth(<KnowledgeLayout />, authAsAdmin);
    expect(screen.getByText('Products')).toBeInTheDocument();
    expect(screen.getByText('Personas')).toBeInTheDocument();
    expect(screen.getByText('Claims')).toBeInTheDocument();
    expect(screen.getByText('Sources')).toBeInTheDocument();
  });
});
