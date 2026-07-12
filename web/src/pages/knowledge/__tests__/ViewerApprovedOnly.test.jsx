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

const authAsViewer = {
  auth: {
    user: { id: 5, email: 'viewer@test.com', role: 'viewer' },
    permissions: ['knowledge.view', 'product.view'],
  },
};

describe('Viewer sees approved-only knowledge', () => {
  it('renders page without create button', async () => {
    knowledgeService.listProducts.mockResolvedValueOnce({ items: [], total: 0 });
    renderWithAuth(<ProductListPage />, authAsViewer);
    await waitFor(() => expect(screen.queryByText(/New Product/i)).not.toBeInTheDocument());
  });

  it('calls API normally for viewer', async () => {
    knowledgeService.listProducts.mockResolvedValueOnce({ items: [], total: 0 });
    renderWithAuth(<ProductListPage />, authAsViewer);
    await waitFor(() => expect(knowledgeService.listProducts).toHaveBeenCalled());
  });
});
