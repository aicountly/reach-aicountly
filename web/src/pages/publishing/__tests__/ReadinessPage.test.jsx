import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import ReadinessPage from '../ReadinessPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['publishing.view', 'seo.view'],
  },
};

beforeEach(() => {
  api.get.mockReset();
});

describe('ReadinessPage', () => {
  it('renders page header', () => {
    renderWithAuth(<ReadinessPage />, ctx);
    expect(screen.getByText('Publication Readiness Check')).toBeInTheDocument();
  });

  it('renders content ID input', () => {
    renderWithAuth(<ReadinessPage />, ctx);
    expect(screen.getByPlaceholderText(/e\.g\. 123/i)).toBeInTheDocument();
  });

  it('has disabled button when no content ID', () => {
    renderWithAuth(<ReadinessPage />, ctx);
    const btn = screen.getByRole('button', { name: /Check Readiness/i });
    expect(btn).toBeDisabled();
  });

  it('shows result with BLOCKED status', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: {
          status: 'blocked',
          content_type: 'blog',
          blocking: ['SEO profile not ready', 'Missing author'],
          warnings: [],
          domain_check: { status: 'blocked' },
          seo_check: { status: 'blocked' },
          aeo_check: { status: 'warning' },
        },
      },
    });

    renderWithAuth(<ReadinessPage />, ctx);
    fireEvent.change(screen.getByPlaceholderText(/e\.g\. 123/i), { target: { value: '42' } });
    fireEvent.click(screen.getByRole('button', { name: /Check Readiness/i }));

    await waitFor(() => expect(screen.getByText('BLOCKED')).toBeInTheDocument());
    expect(screen.getByText('SEO profile not ready')).toBeInTheDocument();
  });

  it('shows warnings section when warnings present', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: {
          status: 'warning',
          content_type: 'blog',
          blocking: [],
          warnings: ['No internal links found'],
          domain_check: { status: 'ready' },
          seo_check: { status: 'warning' },
          aeo_check: { status: 'ready' },
        },
      },
    });

    renderWithAuth(<ReadinessPage />, ctx);
    fireEvent.change(screen.getByPlaceholderText(/e\.g\. 123/i), { target: { value: '10' } });
    fireEvent.click(screen.getByRole('button', { name: /Check Readiness/i }));

    await waitFor(() => expect(screen.getByText('Warnings')).toBeInTheDocument());
    expect(screen.getByText('No internal links found')).toBeInTheDocument();
  });

  it('shows error message when API fails', async () => {
    api.get.mockRejectedValueOnce(new Error('Content not found'));
    renderWithAuth(<ReadinessPage />, ctx);
    fireEvent.change(screen.getByPlaceholderText(/e\.g\. 123/i), { target: { value: '999' } });
    fireEvent.click(screen.getByRole('button', { name: /Check Readiness/i }));
    await waitFor(() => expect(screen.getByText(/Content not found/i)).toBeInTheDocument());
  });
});
