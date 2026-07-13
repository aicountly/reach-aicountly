import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: {
    get: vi.fn(),
  },
}));
import api from '../../../services/api';
import ReadinessPage from '../ReadinessPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['publishing.view'],
  },
};

beforeEach(() => {
  api.get.mockReset();
});

describe('ReadinessPage additional scenarios', () => {
  it('enables button when content ID is entered', async () => {
    renderWithAuth(<ReadinessPage />, ctx);
    const input = screen.getByPlaceholderText(/e\.g\. 123/i);
    const button = screen.getByRole('button', { name: /Check Readiness/i });
    expect(button).toBeDisabled();
    fireEvent.change(input, { target: { value: '10' } });
    expect(button).not.toBeDisabled();
  });

  it('shows READY status when checks pass', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: {
          content_item_id: 10,
          status: 'ready',
          blocking: [],
          warnings: ['Image alt text missing'],
        },
      },
    });
    renderWithAuth(<ReadinessPage />, ctx);
    const input = screen.getByPlaceholderText(/e\.g\. 123/i);
    fireEvent.change(input, { target: { value: '10' } });
    fireEvent.click(screen.getByRole('button', { name: /Check Readiness/i }));
    await waitFor(() => expect(screen.getByText(/READY/i)).toBeInTheDocument());
  });

  it('shows blockers when blocked', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: {
          content_item_id: 15,
          status: 'blocked',
          blocking: ['Missing meta title', 'Unapproved content', 'No slug defined'],
          warnings: [],
        },
      },
    });
    renderWithAuth(<ReadinessPage />, ctx);
    const input = screen.getByPlaceholderText(/e\.g\. 123/i);
    fireEvent.change(input, { target: { value: '15' } });
    fireEvent.click(screen.getByRole('button', { name: /Check Readiness/i }));
    await waitFor(() => expect(screen.getByText(/BLOCKED/i)).toBeInTheDocument());
    expect(screen.getByText(/Missing meta title/i)).toBeInTheDocument();
    expect(screen.getByText(/Unapproved content/i)).toBeInTheDocument();
  });

  it('shows Blocking Issues section header when blockers present', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: {
          status: 'blocked',
          blocking: ['No SEO profile defined'],
          warnings: [],
        },
      },
    });
    renderWithAuth(<ReadinessPage />, ctx);
    const input = screen.getByPlaceholderText(/e\.g\. 123/i);
    fireEvent.change(input, { target: { value: '5' } });
    fireEvent.click(screen.getByRole('button', { name: /Check Readiness/i }));
    await waitFor(() => expect(screen.getByText(/Blocking Issues/i)).toBeInTheDocument());
  });
});
