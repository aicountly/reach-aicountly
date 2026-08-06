import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/contentService', () => ({
  contentService: { listItems: vi.fn(), deleteItem: vi.fn() },
}));
import { contentService } from '../../../services/contentService';
import { ContentListPage } from '../ContentListPage';

const item = (overrides = {}) => ({
  id: 12,
  content_type: 'blog',
  title: 'Schedule III financial statements explained',
  slug: 'schedule-iii-financial-statements',
  workflow_status: 'published',
  risk_level: 'low',
  created_at: '2026-08-05T10:00:00Z',
  ...overrides,
});

const withPerms = (permissions) => ({
  auth: {
    user: { id: 1, email: 'manager@test.com', role: 'marketing_manager' },
    permissions,
  },
});

beforeEach(() => {
  contentService.listItems.mockReset();
  contentService.deleteItem.mockReset();
  vi.spyOn(window, 'confirm').mockReturnValue(true);
});

afterEach(() => { vi.restoreAllMocks(); });

describe('ContentListPage delete', () => {
  it('deletes an item and reloads the list', async () => {
    contentService.listItems.mockResolvedValue({ items: [item()] });
    contentService.deleteItem.mockResolvedValue({ deleted: true, permanent: false });

    renderWithAuth(<ContentListPage />, withPerms(['content.view', 'content.edit']));
    await waitFor(() => expect(screen.getByText(/Schedule III/i)).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /delete/i }));

    await waitFor(() => expect(contentService.deleteItem).toHaveBeenCalledWith(12, expect.any(String)));
    await waitFor(() => expect(contentService.listItems).toHaveBeenCalledTimes(2));
  });

  it('warns that deleting an archived item is permanent', async () => {
    contentService.listItems.mockResolvedValue({ items: [item({ workflow_status: 'archived' })] });
    contentService.deleteItem.mockResolvedValue({ deleted: true, permanent: true });

    renderWithAuth(<ContentListPage />, withPerms(['content.view', 'content.edit']));
    await waitFor(() => expect(screen.getByText(/Schedule III/i)).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /delete/i }));
    expect(window.confirm).toHaveBeenCalledWith(expect.stringMatching(/permanently delete/i));
  });

  it('hides the delete action without content.edit', async () => {
    contentService.listItems.mockResolvedValue({ items: [item()] });

    renderWithAuth(<ContentListPage />, withPerms(['content.view']));
    await waitFor(() => expect(screen.getByText(/Schedule III/i)).toBeInTheDocument());

    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
  });

  it('shows the API error when the delete fails', async () => {
    contentService.listItems.mockResolvedValue({ items: [item({ workflow_status: 'archived' })] });
    contentService.deleteItem.mockRejectedValue(new Error('Unpublish the content item before deleting it permanently.'));

    renderWithAuth(<ContentListPage />, withPerms(['content.view', 'content.edit']));
    await waitFor(() => expect(screen.getByText(/Schedule III/i)).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /delete/i }));
    await waitFor(() => expect(screen.getByText(/Unpublish the content item/i)).toBeInTheDocument());
  });
});
