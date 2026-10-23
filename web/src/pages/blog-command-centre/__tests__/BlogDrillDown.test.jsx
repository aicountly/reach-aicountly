import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/contentService', () => ({
  contentService: { listItems: vi.fn() },
}));
vi.mock('../../../services/jobService', () => ({
  jobService: { list: vi.fn(), retry: vi.fn(), cancel: vi.fn() },
}));
vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import { contentService } from '../../../services/contentService';
import { jobService } from '../../../services/jobService';
import api from '../../../services/api';
import { BlogContentListWrapper } from '../BlogContentListWrapper';
import { BlogSharedEmbed } from '../BlogSharedEmbed';

const ctx = (route) => ({
  auth: { user: { id: 1, role: 'super_admin' }, permissions: ['*'] },
  route,
});

beforeEach(() => {
  vi.clearAllMocks();
  contentService.listItems.mockResolvedValue({ items: [], pager: { total: 0, perPage: 25 } });
  jobService.list.mockResolvedValue({ items: [], total: 0 });
  api.get.mockResolvedValue({ items: [], last_page: 1, total: 0 });
});

describe('BlogContentListWrapper', () => {
  it('uses the page default when the URL sets no status', async () => {
    renderWithAuth(<BlogContentListWrapper title="Drafts" defaultWorkflowStatus="draft" />, ctx('/blog-command-centre/pipeline/drafts'));
    await waitFor(() => expect(contentService.listItems).toHaveBeenCalledWith({ content_type: 'blog', workflow_status: 'draft' }));
  });

  it('lets ?workflow_status from a dashboard count win over the default', async () => {
    renderWithAuth(
      <BlogContentListWrapper title="Drafts" defaultWorkflowStatus="draft" />,
      ctx('/blog-command-centre/pipeline/drafts?workflow_status=draft,fact_verifying'),
    );
    await waitFor(() => expect(contentService.listItems).toHaveBeenCalledWith({
      content_type: 'blog', workflow_status: 'draft,fact_verifying',
    }));
    // The combined stages show as the selected option.
    expect(screen.getByRole('option', { name: 'draft + fact verifying' }).selected).toBe(true);
  });

  it('"All statuses" really lists every status on a page with a default', async () => {
    renderWithAuth(<BlogContentListWrapper title="Drafts" defaultWorkflowStatus="draft" />, ctx('/x'));
    await waitFor(() => expect(contentService.listItems).toHaveBeenCalled());

    fireEvent.change(screen.getByRole('combobox'), { target: { value: '' } });

    await waitFor(() => expect(contentService.listItems).toHaveBeenLastCalledWith({ content_type: 'blog' }));
  });
});

describe('BlogSharedEmbed presets', () => {
  it('opens Failed Jobs on the failed jobs', async () => {
    renderWithAuth(<BlogSharedEmbed embed="failed-jobs" />, ctx('/blog-command-centre/operations/failed-jobs'));
    await waitFor(() => expect(jobService.list).toHaveBeenCalled());
    expect(jobService.list).toHaveBeenCalledWith(expect.objectContaining({ status: 'dead_letter,failed' }));
    // It never asks for the unfiltered list first.
    expect(jobService.list).not.toHaveBeenCalledWith(expect.objectContaining({ status: '' }));
  });

  it('keeps a status the link already carries', async () => {
    renderWithAuth(<BlogSharedEmbed embed="failed-jobs" />, ctx('/blog-command-centre/operations/failed-jobs?status=pending'));
    await waitFor(() => expect(jobService.list).toHaveBeenCalled());
    expect(jobService.list).toHaveBeenCalledWith(expect.objectContaining({ status: 'pending' }));
  });

  it('scopes deployments to blog content and rollback to what is live', async () => {
    renderWithAuth(<BlogSharedEmbed embed="deployments" />, ctx('/blog-command-centre/publishing/deployments?status=failed,blocked'));
    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(api.get).toHaveBeenCalledWith('v1/publishing/deployments', expect.objectContaining({ status: 'failed,blocked', content_type: 'blog' }));
  });

  it('opens rollback on live blog deployments', async () => {
    renderWithAuth(<BlogSharedEmbed embed="rollback" />, ctx('/blog-command-centre/publishing/rollback'));
    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(api.get).toHaveBeenCalledWith('v1/publishing/deployments', expect.objectContaining({ status: 'published,verified', content_type: 'blog' }));
  });
});
