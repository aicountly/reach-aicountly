import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));
import api from '../../../services/api';
import ReleaseNotesPublishingPage from '../ReleaseNotesPublishingPage';
import WorkflowGuidesPublishingPage from '../WorkflowGuidesPublishingPage';
import PublishingLayout from '../PublishingLayout';

const publisher = { auth: { user: { id: 1, role: 'super_admin' }, permissions: ['publishing.view', 'publishing.publish'] } };
const viewer = { auth: { user: { id: 2, role: 'viewer' }, permissions: ['publishing.view'] } };

const published = {
  id: 7, product_slug: 'billing', product_name: 'Billing', version: '1.1', slug: 'billing-v1-1',
  title: 'Release Notes for AICOUNTLY Billing Version 1.1', status: 'published', vetting_decision: 'approve',
  vetting_run_id: 41, approved_by: 'Reach System Bot', reasons: ['Simplified two bullets.'],
  public_url: 'https://aicountly.com/releases/billing-v1-1', retryable: false, updated_at: '2026-10-17T15:30:00Z',
};
const rejected = {
  id: 8, product_slug: 'books', product_name: 'Smart Books', version: '2.1', slug: 'smart-books-v2-1',
  title: 'Smart Books 2.1', status: 'rejected', vetting_decision: 'reject', vetting_run_id: 42,
  reasons: ['Describes a security fix in detail.'], public_url: null, retryable: false, updated_at: '2026-10-17T15:31:00Z',
};
const failed = {
  id: 9, product_slug: 'notes', product_name: 'Notes', version: '1.1', slug: 'notes-v1-1', title: 'Notes 1.1',
  status: 'failed', vetting_decision: 'approve', reasons: [], error_category: 'not_live',
  last_error: 'The public site accepted the publish but does not serve the page yet.', public_url: null,
  retryable: true, next_attempt_at: '2026-10-17T16:00:00Z', updated_at: '2026-10-17T15:32:00Z',
};

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
});

describe('Release notes publishing page', () => {
  it('lists submissions with status, vetting reasons and the live link', async () => {
    api.get.mockResolvedValueOnce({ items: [published, rejected, failed], total: 3 });
    renderWithAuth(<ReleaseNotesPublishingPage />, publisher);

    await waitFor(() => expect(screen.getByText('Release Notes for AICOUNTLY Billing Version 1.1')).toBeInTheDocument());
    expect(api.get).toHaveBeenCalledWith('v1/publishing/release-notes', undefined);
    expect(screen.getByText('billing-v1-1').closest('a')).toHaveAttribute('href', 'https://aicountly.com/releases/billing-v1-1');
    expect(screen.getByText('Describes a security fix in detail.')).toBeInTheDocument();
    expect(screen.getByText('Rejected', { selector: '.badge' })).toBeInTheDocument();
    expect(screen.getByText('not_live')).toBeInTheDocument();
    expect(screen.getByText('run #41')).toBeInTheDocument();
    expect(screen.getByText('3 notes')).toBeInTheDocument();
  });

  it('offers Retry only on submissions that are not live yet, and retries them', async () => {
    api.get.mockResolvedValue({ items: [published, rejected, failed], total: 3 });
    api.post.mockResolvedValueOnce({ ...failed, status: 'published', public_url: 'https://aicountly.com/releases/notes-v1-1' });
    renderWithAuth(<ReleaseNotesPublishingPage />, publisher);

    const buttons = await screen.findAllByRole('button', { name: 'Retry' });
    expect(buttons).toHaveLength(1);
    fireEvent.click(buttons[0]);
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('v1/publishing/release-notes/9/retry'));
    await waitFor(() => expect(screen.getByText(/#9: Published — https:\/\/aicountly.com\/releases\/notes-v1-1/)).toBeInTheDocument());
  });

  it('hides Retry from users who cannot publish', async () => {
    api.get.mockResolvedValueOnce({ items: [failed], total: 1 });
    renderWithAuth(<ReleaseNotesPublishingPage />, viewer);
    await waitFor(() => expect(screen.getByText('Notes 1.1')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Retry' })).not.toBeInTheDocument();
  });

  it('filters by status', async () => {
    api.get.mockResolvedValue({ items: [], total: 0 });
    renderWithAuth(<ReleaseNotesPublishingPage />, publisher);
    await screen.findByText(/No release notes submitted yet/);
    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'rejected' } });
    await waitFor(() => expect(api.get).toHaveBeenLastCalledWith('v1/publishing/release-notes', { status: 'rejected' }));
    await screen.findByText('No notes with this status.');
  });

  it('shows the API error', async () => {
    api.get.mockRejectedValueOnce(new Error('Release notes API down'));
    renderWithAuth(<ReleaseNotesPublishingPage />, publisher);
    await waitFor(() => expect(screen.getByText('Release notes API down')).toBeInTheDocument());
  });
});

describe('Workflow guides publishing page', () => {
  it('reads the workflow-guides list and has no version column', async () => {
    api.get.mockResolvedValueOnce({
      items: [{ id: 3, product_slug: 'books', product_name: 'Smart Books', slug: 'record-a-sales-invoice', title: 'Record a sales invoice', status: 'pending_vetting', reasons: [], retryable: true, updated_at: '2026-10-17T00:00:00Z' }],
      total: 1,
    });
    renderWithAuth(<WorkflowGuidesPublishingPage />, publisher);
    await waitFor(() => expect(screen.getByText('Record a sales invoice')).toBeInTheDocument());
    expect(api.get).toHaveBeenCalledWith('v1/publishing/workflow-guides', undefined);
    expect(screen.getByText('Pending vetting', { selector: '.badge' })).toBeInTheDocument();
    expect(screen.queryByText('Version')).not.toBeInTheDocument();
    expect(screen.getByText('1 guide')).toBeInTheDocument();
  });
});

describe('Publishing navigation', () => {
  it('links to release notes and workflow guides', () => {
    renderWithAuth(<PublishingLayout />, publisher);
    expect(screen.getByText('Release notes').closest('a')).toHaveAttribute('href', '/publishing/release-notes');
    expect(screen.getByText('Workflow guides').closest('a')).toHaveAttribute('href', '/publishing/workflow-guides');
  });
});
