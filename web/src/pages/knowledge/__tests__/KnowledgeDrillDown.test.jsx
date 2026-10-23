import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/knowledgeService', () => ({
  knowledgeService: {
    getProduct:          vi.fn(),
    listModules:         vi.fn(),
    listClaims:          vi.fn(),
    listBrandRules:      vi.fn(),
    listCitations:       vi.fn(),
    listSources:         vi.fn(),
    completenessProduct: vi.fn(),
  },
}));

import { knowledgeService } from '../../../services/knowledgeService';
import { ProductDetailPage } from '../ProductDetailPage';
import { ClaimListPage } from '../ClaimListPage';
import { BrandRulesPage } from '../BrandRulesPage';
import { CitationListPage } from '../CitationListPage';

/**
 * A product's claim count opens its claims, and the claim / brand-rule /
 * citation lists apply product_id / source_id from the URL with a chip to
 * drop it.
 */
const auth = { user: { id: 1, role: 'super_admin' }, permissions: ['*'] };

beforeEach(() => {
  vi.clearAllMocks();
  knowledgeService.getProduct.mockResolvedValue({ id: 42, name: 'Reach AI', slug: 'reach-ai', status: 'draft' });
  knowledgeService.listModules.mockResolvedValue({ items: [] });
  knowledgeService.listClaims.mockResolvedValue({ items: [], total: 0 });
  knowledgeService.listBrandRules.mockResolvedValue({ items: [], total: 0 });
  knowledgeService.listCitations.mockResolvedValue({ items: [], total: 0 });
  knowledgeService.completenessProduct.mockResolvedValue({ score: 1, missing: [] });
});

describe('Product detail', () => {
  it('"Claims (N)" and "+N more" open the claim list for this product', async () => {
    const claims = Array.from({ length: 7 }, (_, i) => ({ id: i + 1, claim_text: `Claim ${i + 1}`, risk_level: 'low' }));
    knowledgeService.listClaims.mockResolvedValue({ items: claims, total: 7 });
    renderWithAuth(
      <Routes><Route path="/knowledge/products/:id" element={<ProductDetailPage />} /></Routes>,
      { auth, route: '/knowledge/products/42' },
    );

    expect(await screen.findByRole('link', { name: 'Claims (7)' })).toHaveAttribute('href', '/knowledge/claims?product_id=42');
    expect(screen.getByRole('link', { name: '+2 more…' })).toHaveAttribute('href', '/knowledge/claims?product_id=42');
  });

  it('does not link a zero claim count', async () => {
    renderWithAuth(
      <Routes><Route path="/knowledge/products/:id" element={<ProductDetailPage />} /></Routes>,
      { auth, route: '/knowledge/products/42' },
    );

    expect(await screen.findByText('Claims (0)')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Claims (0)' })).not.toBeInTheDocument();
  });
});

describe('Claim list deep links', () => {
  it('applies product_id, status and risk from the URL and shows a removable product chip', async () => {
    renderWithAuth(<ClaimListPage />, { auth, route: '/knowledge/claims?product_id=42&status=approved&risk_level=high' });

    await waitFor(() => expect(knowledgeService.listClaims).toHaveBeenCalledWith(expect.objectContaining({
      product_id: '42', status: 'approved', risk_level: 'high',
    })));
    const chip = await screen.findByRole('button', { name: 'Clear product filter' });
    await waitFor(() => expect(chip).toHaveTextContent('Product: Reach AI'));

    fireEvent.click(chip);
    await waitFor(() => expect(knowledgeService.listClaims).toHaveBeenLastCalledWith(expect.objectContaining({ product_id: undefined })));
    expect(screen.queryByRole('button', { name: 'Clear product filter' })).not.toBeInTheDocument();
  });

  it('a claim links to its product', async () => {
    knowledgeService.listClaims.mockResolvedValue({ items: [{ id: 1, product_id: 42, claim_text: 'Fast', risk_level: 'low' }], total: 1 });
    renderWithAuth(<ClaimListPage />, { auth, route: '/knowledge/claims' });

    expect(await screen.findByRole('link', { name: 'Product #42' })).toHaveAttribute('href', '/knowledge/products/42');
  });
});

describe('Brand rules deep links', () => {
  it('applies product_id from the URL and links the product cell', async () => {
    knowledgeService.listBrandRules.mockResolvedValue({ items: [{ id: 1, product_id: 42, rule_type: 'tone', rule_text: 'Plain' }], total: 1 });
    renderWithAuth(<BrandRulesPage />, { auth, route: '/knowledge/brand-rules?product_id=42&rule_type=tone' });

    await waitFor(() => expect(knowledgeService.listBrandRules).toHaveBeenCalledWith(expect.objectContaining({
      product_id: '42', rule_type: 'tone',
    })));
    expect(await screen.findByRole('link', { name: '#42' })).toHaveAttribute('href', '/knowledge/products/42');
    expect(screen.getByRole('button', { name: 'Clear product filter' })).toBeInTheDocument();
  });
});

describe('Citation list deep links', () => {
  it('applies source_id and search from the URL', async () => {
    renderWithAuth(<CitationListPage />, { auth, route: '/knowledge/citations?source_id=9&search=gst' });

    await waitFor(() => expect(knowledgeService.listCitations).toHaveBeenCalledWith(expect.objectContaining({
      source_id: '9', search: 'gst',
    })));
    expect(screen.getByRole('button', { name: 'Clear source filter' })).toHaveTextContent('Source: #9');
  });

  it('a source cell narrows the list to that source', async () => {
    knowledgeService.listCitations.mockResolvedValue({ items: [{ id: 1, source_id: 9, citation_text: 'Section 16' }], total: 1 });
    renderWithAuth(<CitationListPage />, { auth, route: '/knowledge/citations?status=approved' });

    expect(await screen.findByRole('link', { name: '9' })).toHaveAttribute('href', '/knowledge/citations?status=approved&source_id=9');
  });
});
