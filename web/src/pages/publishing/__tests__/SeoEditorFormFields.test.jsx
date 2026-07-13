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
    useParams: () => ({ contentId: '99' }),
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

describe('SeoEditorPage form fields', () => {
  it('renders Primary Keyword field', async () => {
    api.get.mockResolvedValueOnce({ data: { data: {} } });
    renderWithAuth(<SeoEditorPage />, ctx);
    await waitFor(() => expect(screen.getByText('Primary Keyword')).toBeInTheDocument());
  });

  it('renders Meta Title field', async () => {
    api.get.mockResolvedValueOnce({ data: { data: {} } });
    renderWithAuth(<SeoEditorPage />, ctx);
    await waitFor(() => {
      const labels = screen.getAllByText(/Meta Title/i);
      expect(labels.length).toBeGreaterThan(0);
    });
  });

  it('renders Meta Description field', async () => {
    api.get.mockResolvedValueOnce({ data: { data: {} } });
    renderWithAuth(<SeoEditorPage />, ctx);
    await waitFor(() => {
      const labels = screen.getAllByText(/Meta Description/i);
      expect(labels.length).toBeGreaterThan(0);
    });
  });

  it('renders Slug field', async () => {
    api.get.mockResolvedValueOnce({ data: { data: {} } });
    renderWithAuth(<SeoEditorPage />, ctx);
    await waitFor(() => expect(screen.getByText('Slug')).toBeInTheDocument());
  });

  it('shows save error message on failure', async () => {
    api.get.mockResolvedValueOnce({ data: { data: {} } });
    api.put.mockRejectedValueOnce({ response: { data: { message: 'Validation failed' } } });

    renderWithAuth(<SeoEditorPage />, ctx);
    await waitFor(() => screen.getByRole('button', { name: /Save SEO Profile/i }));
    fireEvent.click(screen.getByRole('button', { name: /Save SEO Profile/i }));
    await waitFor(() => expect(screen.getByText(/Save failed/i)).toBeInTheDocument());
  });

  it('Run SEO Check button is present', async () => {
    api.get.mockResolvedValueOnce({ data: { data: {} } });
    renderWithAuth(<SeoEditorPage />, ctx);
    await waitFor(() => expect(screen.getByRole('button', { name: /Run SEO Check/i })).toBeInTheDocument());
  });
});
