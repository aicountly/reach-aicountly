import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal();
  return { ...actual, useParams: () => ({ id: '42' }), useNavigate: () => vi.fn() };
});

vi.mock('../../../services/knowledgeService', () => ({
  knowledgeService: {
    getProduct:          vi.fn(),
    listModules:         vi.fn(),
    listClaims:          vi.fn(),
    completenessProduct: vi.fn(),
    submitProduct:       vi.fn(),
    approveProduct:      vi.fn(),
    rejectProduct:       vi.fn(),
  },
}));

import { knowledgeService } from '../../../services/knowledgeService';
import { ProductDetailPage } from '../ProductDetailPage';

beforeEach(() => {
  knowledgeService.getProduct.mockReset();
  knowledgeService.listModules.mockReset();
  knowledgeService.listClaims.mockReset();
  knowledgeService.completenessProduct.mockReset();
  knowledgeService.submitProduct.mockReset();
  knowledgeService.approveProduct.mockReset();
  knowledgeService.rejectProduct.mockReset();
});

/** Mount the page for a product in the given workflow status. */
function mountWith(status, auth) {
  knowledgeService.getProduct.mockResolvedValue({ id: 42, name: 'Reach AI', slug: 'reach-ai', status });
  knowledgeService.listModules.mockResolvedValue({ items: [] });
  knowledgeService.listClaims.mockResolvedValue({ items: [] });
  knowledgeService.completenessProduct.mockResolvedValue({ score: 72, missing: [] });
  return renderWithAuth(<ProductDetailPage />, auth);
}

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
    knowledgeService.completenessProduct.mockResolvedValueOnce({ score: 72, missing: [] });

    renderWithAuth(<ProductDetailPage />, authAsAdmin);
    await waitFor(() => expect(screen.getByText('Reach AI')).toBeInTheDocument());
  });

  it('shows error on fetch failure', async () => {
    knowledgeService.getProduct.mockRejectedValueOnce(new Error('Not found'));
    knowledgeService.listModules.mockResolvedValueOnce({ items: [] });
    knowledgeService.listClaims.mockResolvedValueOnce({ items: [] });
    knowledgeService.completenessProduct.mockResolvedValueOnce({ score: 0, missing: [] });

    renderWithAuth(<ProductDetailPage />, authAsAdmin);
    await waitFor(() => expect(screen.getByText(/Not found/)).toBeInTheDocument());
  });

  it('offers Submit for review on a draft, not Approve', async () => {
    mountWith('draft', authAsAdmin);
    await waitFor(() => expect(screen.getByText('Reach AI')).toBeInTheDocument());

    expect(screen.getByRole('button', { name: /Submit for review/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Approve/i })).not.toBeInTheDocument();
  });

  it('offers Approve once the product is awaiting review', async () => {
    const user = userEvent.setup();
    knowledgeService.approveProduct.mockResolvedValueOnce({ id: 42, status: 'approved' });
    mountWith('needs_review', authAsAdmin);
    await waitFor(() => expect(screen.getByText('Reach AI')).toBeInTheDocument());

    await user.click(screen.getByRole('button', { name: /Approve/i }));
    await waitFor(() => expect(knowledgeService.approveProduct).toHaveBeenCalledWith('42'));
  });

  it('sends the rejection reason as an object the API can read', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'prompt').mockReturnValue('Missing evidence');
    knowledgeService.rejectProduct.mockResolvedValueOnce({ id: 42, status: 'rejected' });
    mountWith('needs_review', authAsAdmin);
    await waitFor(() => expect(screen.getByText('Reach AI')).toBeInTheDocument());

    await user.click(screen.getByRole('button', { name: /Reject/i }));
    await waitFor(() => expect(knowledgeService.rejectProduct).toHaveBeenCalledWith('42', { reason: 'Missing evidence' }));
  });

  it('hides approval controls from a reviewer without knowledge.approve', async () => {
    mountWith('needs_review', {
      auth: {
        user: { id: 3, email: 'analyst@aicountly.org', role: 'analyst' },
        permissions: ['product.view', 'knowledge.view'],
      },
    });
    await waitFor(() => expect(screen.getByText('Reach AI')).toBeInTheDocument());

    expect(screen.queryByRole('button', { name: /Approve/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Reject/i })).not.toBeInTheDocument();
  });
});
