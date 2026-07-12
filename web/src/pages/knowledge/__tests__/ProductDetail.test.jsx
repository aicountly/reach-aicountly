import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal();
  return { ...actual, useParams: () => ({ id: '42' }), useNavigate: () => vi.fn() };
});

vi.mock('../../../services/knowledgeService', () => ({
  knowledgeService: {
    getProduct: vi.fn(),
    listModules: vi.fn(),
    listClaims: vi.fn(),
  },
}));

import { knowledgeService } from '../../../services/knowledgeService';
import { ProductDetailPage } from '../ProductDetailPage';

beforeEach(() => {
  knowledgeService.getProduct.mockReset();
  knowledgeService.listModules.mockReset();
  knowledgeService.listClaims.mockReset();
});

const authAsAdmin = {
  auth: {
    user: { id: 1, email: 'root@aicountly.org', role: 'super_admin' },
    permissions: ['*'],
  },
};

describe('ProductDetailPage', () => {
  it('renders product name', async () => {
    knowledgeService.getProduct.mockResolvedValueOnce({ id: 42, name: 'Reach AI', slug: 'reach-ai', knowledge_status: 'approved' });
    knowledgeService.listModules.mockResolvedValueOnce({ items: [] });
    knowledgeService.listClaims.mockResolvedValueOnce({ items: [] });

    renderWithAuth(<ProductDetailPage />, authAsAdmin);
    await waitFor(() => expect(screen.getByText('Reach AI')).toBeInTheDocument());
  });

  it('shows error on fetch failure', async () => {
    knowledgeService.getProduct.mockRejectedValueOnce(new Error('Not found'));
    knowledgeService.listModules.mockResolvedValueOnce({ items: [] });
    knowledgeService.listClaims.mockResolvedValueOnce({ items: [] });

    renderWithAuth(<ProductDetailPage />, authAsAdmin);
    await waitFor(() => expect(screen.getByText(/Not found/)).toBeInTheDocument());
  });
});
