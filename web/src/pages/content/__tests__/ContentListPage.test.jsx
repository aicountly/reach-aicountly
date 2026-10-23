import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { Link } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { ContentListPage } from '../ContentListPage';

// Mock global fetch so contentService doesn't fail
beforeEach(() => {
  global.fetch = vi.fn().mockResolvedValue({
    ok: true,
    json: () => Promise.resolve({ ok: true, data: { items: [], total: 0 } }),
  });
});

const ctx = {
  auth: {
    user: { id: 1, email: 'manager@test.com', role: 'marketing_manager' },
    permissions: ['content.view', 'content.create'],
  },
};

describe('ContentListPage', () => {
  it('renders page heading', async () => {
    renderWithAuth(<ContentListPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Content Studio/i)).toBeInTheDocument());
  });

  it('shows New Content button for users with content.create', async () => {
    renderWithAuth(<ContentListPage />, ctx);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /new content/i })).toBeInTheDocument();
    });
  });

  // Dashboard tiles open the rows they counted.
  it('applies workflow_status and content_type from the URL', async () => {
    renderWithAuth(<ContentListPage />, { ...ctx, route: '/content?workflow_status=scheduled,ready_for_publication&content_type=blog' });
    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    const url = String(global.fetch.mock.calls[0][0]);
    expect(url).toContain('workflow_status=scheduled%2Cready_for_publication');
    expect(url).toContain('content_type=blog');
  });

  const fetchedUrls = () => global.fetch.mock.calls.map((c) => String(c[0]));

  it('passes the narrowing filters other pages link with', async () => {
    renderWithAuth(<ContentListPage />, { ...ctx, route: '/content?risk_level=high&approval_status=pending&product_id=3&market_id=4' });
    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    const url = fetchedUrls()[0];
    ['risk_level=high', 'approval_status=pending', 'product_id=3', 'market_id=4'].forEach((p) => expect(url).toContain(p));
    // No visible control for these, so each shows as a removable chip.
    expect(screen.getByRole('button', { name: /clear risk filter/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /clear product filter/i })).toBeInTheDocument();
  });

  it('reloads when the URL changes, not only on first render', async () => {
    renderWithAuth(
      <>
        <Link to="/content?content_type=blog&workflow_status=published">published blogs</Link>
        <ContentListPage />
      </>,
      { ...ctx, route: '/content?workflow_status=draft' },
    );
    await waitFor(() => expect(fetchedUrls()[0]).toContain('workflow_status=draft'));

    fireEvent.click(screen.getByText('published blogs'));

    await waitFor(() => expect(fetchedUrls().at(-1)).toContain('workflow_status=published'));
    expect(fetchedUrls().at(-1)).toContain('content_type=blog');
    expect(screen.getByLabelText('Workflow status')).toHaveValue('published');
  });

  it('dropping a chip drops that filter from the query', async () => {
    renderWithAuth(<ContentListPage />, { ...ctx, route: '/content?risk_level=high' });
    fireEvent.click(await screen.findByRole('button', { name: /clear risk filter/i }));
    await waitFor(() => expect(fetchedUrls().at(-1)).not.toContain('risk_level'));
  });
});
