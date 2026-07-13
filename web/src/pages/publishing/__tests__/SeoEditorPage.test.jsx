import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: {
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
  },
}));
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual('react-router-dom');
  return {
    ...actual,
    useParams: () => ({ contentId: '42' }),
  };
});
import api from '../../../services/api';
import SeoEditorPage from '../SeoEditorPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['seo.manage'],
  },
};

beforeEach(() => {
  api.get.mockReset();
  api.put.mockReset();
  api.post.mockReset();
});

describe('SeoEditorPage', () => {
  it('shows loading state', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<SeoEditorPage />, ctx);
    expect(screen.getByText(/Loading/i)).toBeInTheDocument();
  });

  it('renders SEO form after loading', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: {
          primary_keyword: 'bank reconciliation',
          meta_title: 'Bank Reconciliation in AICOUNTLY',
          meta_description: 'Learn how to reconcile bank statements.',
          slug: 'bank-reconciliation-guide',
          canonical_preference: 'self_canonical',
          robots_directive: 'index,follow',
          focus_language: 'en',
        },
      },
    });
    renderWithAuth(<SeoEditorPage />, ctx);
    await waitFor(() => expect(screen.queryByText(/Loading/i)).not.toBeInTheDocument());
    expect(screen.getByDisplayValue('bank reconciliation')).toBeInTheDocument();
  });

  it('shows canonical preference dropdown', async () => {
    api.get.mockResolvedValueOnce({ data: { data: {} } });
    renderWithAuth(<SeoEditorPage />, ctx);
    await waitFor(() => expect(screen.queryByText(/Loading/i)).not.toBeInTheDocument());
    expect(screen.getByText(/Self canonical/i)).toBeInTheDocument();
  });

  it('shows Save SEO Profile button', async () => {
    api.get.mockResolvedValueOnce({ data: { data: {} } });
    renderWithAuth(<SeoEditorPage />, ctx);
    await waitFor(() => expect(screen.getByRole('button', { name: /Save SEO Profile/i })).toBeInTheDocument());
  });

  it('shows success message after save', async () => {
    api.get.mockResolvedValueOnce({ data: { data: {} } });
    api.put.mockResolvedValueOnce({ data: {} });

    renderWithAuth(<SeoEditorPage />, ctx);
    await waitFor(() => expect(screen.getByRole('button', { name: /Save SEO Profile/i })).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /Save SEO Profile/i }));
    await waitFor(() => expect(screen.getByText(/SEO profile saved/i)).toBeInTheDocument());
  });
});
