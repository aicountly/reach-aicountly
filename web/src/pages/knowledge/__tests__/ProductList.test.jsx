import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/knowledgeService', () => ({
  knowledgeService: {
    listProducts: vi.fn(),
    createProduct: vi.fn(),
    importProductTaxonomy: vi.fn(),
  },
}));

import { knowledgeService } from '../../../services/knowledgeService';
import { ProductListPage } from '../ProductListPage';

beforeEach(() => {
  knowledgeService.listProducts.mockReset();
  knowledgeService.createProduct.mockReset();
  knowledgeService.importProductTaxonomy.mockReset();
});

const authAsAdmin = {
  auth: {
    user: { id: 1, email: 'root@aicountly.org', role: 'super_admin' },
    permissions: ['*'],
  },
};

const authAsViewer = {
  auth: {
    user: { id: 2, email: 'viewer@aicountly.org', role: 'viewer' },
    permissions: ['product.view'],
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

  it('creates a product from the New Product form and reloads the list', async () => {
    const user = userEvent.setup();
    knowledgeService.listProducts.mockResolvedValue({ items: [], total: 0 });
    knowledgeService.createProduct.mockResolvedValueOnce({ id: 7, name: 'Reach AI', slug: 'reach_ai' });

    renderWithAuth(<ProductListPage />, authAsAdmin);
    await waitFor(() => expect(knowledgeService.listProducts).toHaveBeenCalled());

    await user.click(screen.getByRole('button', { name: /New Product/i }));
    await user.type(screen.getByLabelText('Name'), 'Reach AI');
    await user.type(screen.getByLabelText('Short description'), 'Marketing ops portal');
    await user.click(screen.getByRole('button', { name: /Save/i }));

    await waitFor(() => expect(knowledgeService.createProduct).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'Reach AI', short_description: 'Marketing ops portal' }),
    ));
    // Slug is omitted so the API derives a unique one.
    expect(knowledgeService.createProduct.mock.calls[0][0]).not.toHaveProperty('slug');
    await waitFor(() => expect(knowledgeService.listProducts).toHaveBeenCalledTimes(2));
  });

  it('refuses to submit the create form without a name', async () => {
    const user = userEvent.setup();
    knowledgeService.listProducts.mockResolvedValue({ items: [], total: 0 });

    renderWithAuth(<ProductListPage />, authAsAdmin);
    await user.click(screen.getByRole('button', { name: /New Product/i }));
    await user.click(screen.getByRole('button', { name: /Save/i }));

    expect(knowledgeService.createProduct).not.toHaveBeenCalled();
    await waitFor(() => expect(screen.getByText(/name is required/i)).toBeInTheDocument());
  });

  it('imports the taxonomy, reports the summary and reloads the list', async () => {
    const user = userEvent.setup();
    knowledgeService.listProducts.mockResolvedValue({ items: [], total: 0 });
    knowledgeService.importProductTaxonomy.mockResolvedValueOnce({
      created: 12, skipped: 0, aliases: 5, errors: 0,
    });

    renderWithAuth(<ProductListPage />, authAsAdmin);
    await waitFor(() => expect(knowledgeService.listProducts).toHaveBeenCalled());

    await user.click(screen.getByRole('button', { name: /Import taxonomy/i }));

    await waitFor(() => expect(screen.getByText(/12 created/)).toBeInTheDocument());
    await waitFor(() => expect(knowledgeService.listProducts).toHaveBeenCalledTimes(2));
  });

  it('surfaces an import failure instead of failing silently', async () => {
    const user = userEvent.setup();
    knowledgeService.listProducts.mockResolvedValue({ items: [], total: 0 });
    knowledgeService.importProductTaxonomy.mockRejectedValueOnce(new Error('Forbidden'));

    renderWithAuth(<ProductListPage />, authAsAdmin);
    await user.click(screen.getByRole('button', { name: /Import taxonomy/i }));

    await waitFor(() => expect(screen.getByText(/Forbidden/)).toBeInTheDocument());
  });

  it('hides create and import controls from users without product.manage', async () => {
    knowledgeService.listProducts.mockResolvedValue({ items: [], total: 0 });
    renderWithAuth(<ProductListPage />, authAsViewer);

    await waitFor(() => expect(knowledgeService.listProducts).toHaveBeenCalled());
    expect(screen.queryByRole('button', { name: /New Product/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Import taxonomy/i })).not.toBeInTheDocument();
  });
});
