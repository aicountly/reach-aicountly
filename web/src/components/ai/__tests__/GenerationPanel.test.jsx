import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import GenerationPanel from '../GenerationPanel.jsx';

vi.mock('../../../services/aiService.js', () => ({
  requestGeneration: vi.fn(),
  getGeneration: vi.fn(),
}));

import { requestGeneration, getGeneration } from '../../../services/aiService.js';

describe('GenerationPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders generate button', () => {
    render(<GenerationPanel contentItemId={1} contentType="blog_post" />);
    expect(screen.getByRole('button', { name: 'Generate AI draft' })).toBeTruthy();
  });

  it('shows human approval notice', () => {
    render(<GenerationPanel contentItemId={1} contentType="blog_post" />);
    expect(screen.getByText(/never auto-published/i)).toBeTruthy();
  });

  it('disables button when disabled prop is true', () => {
    render(<GenerationPanel contentItemId={1} contentType="blog_post" disabled />);
    expect(screen.getByRole('button', { name: 'Generate AI draft' }).disabled).toBe(true);
  });

  it('calls requestGeneration on button click', async () => {
    requestGeneration.mockResolvedValueOnce({ request: { uuid: 'test-uuid', status: 'pending' } });
    getGeneration.mockResolvedValueOnce({ request: { uuid: 'test-uuid', status: 'completed' } });

    render(<GenerationPanel contentItemId={1} contentType="blog_post" />);
    fireEvent.click(screen.getByRole('button', { name: 'Generate AI draft' }));

    await waitFor(() => {
      expect(requestGeneration).toHaveBeenCalledWith(
        expect.objectContaining({ task_type: 'draft_generation', content_type: 'blog_post', content_item_id: 1 })
      );
    });
  });

  it('shows error when requestGeneration fails', async () => {
    requestGeneration.mockRejectedValueOnce(new Error('Permission denied'));

    render(<GenerationPanel contentItemId={1} contentType="blog_post" />);
    fireEvent.click(screen.getByRole('button', { name: 'Generate AI draft' }));

    await waitFor(() => {
      expect(screen.getByText(/Permission denied/)).toBeTruthy();
    });
  });

  it('shows completion message when generation succeeds', async () => {
    requestGeneration.mockResolvedValueOnce({ request: { uuid: 'test-uuid', status: 'pending' } });
    getGeneration.mockResolvedValueOnce({ request: { uuid: 'test-uuid', status: 'completed' } });

    render(<GenerationPanel contentItemId={1} contentType="blog_post" />);
    fireEvent.click(screen.getByRole('button', { name: 'Generate AI draft' }));

    await waitFor(() => {
      expect(screen.getByText(/A human approver must review/i)).toBeTruthy();
    });
  });
});
