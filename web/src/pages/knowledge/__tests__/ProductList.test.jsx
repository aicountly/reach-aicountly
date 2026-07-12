import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/knowledgeService', () => ({
  knowledgeService: {
    listProducts: vi.fn(),
  },
}));

import { knowledgeService } from '../../../services/knowledgeService';
import { ProductListPage } from '../ProductListPage';

beforeEach(() => { knowledgeService.listProducts.mockReset(); });

const authAsAdmin = {
  auth: {
    user: { id: 1, email: 'root@aicountly.org', role: 'super_admin' },
    permissions: ['*'],
  },
};

describe('ProductListPage', () => {
  it('renders products from the API', async () => {
    knowledgeService.listProducts.mockResolvedValueOnce({
      items: [
        { id: 1, name: 'Reach AI', slug: 'reach-ai', knowledge_status: 'approved', updated_at: '2026-07-01T00:00:00Z' },
      ],
      total: 1,
    });
    renderWithAuth(<ProductListPage />, authAsAdmin);
    await waitFor(() => expect(screen.getByText('Reach AI')).toBeInTheDocument());
  });

  it('shows error Alert on API failure', async () => {
    knowledgeService.listProducts.mockRejectedValueOnce(new Error('DB down'));
    renderWithAuth(<ProductListPage />, authAsAdmin);
    await waitFor(() => expect(screen.getByText(/DB down/)).toBeInTheDocument());
  });
});
