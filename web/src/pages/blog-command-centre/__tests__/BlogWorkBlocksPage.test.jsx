import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { Route, Routes } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import { BlogWorkBlocksPage } from '../BlogWorkBlocksPage';

const ctx = { auth: { user: { id: 1 }, permissions: ['*'] } };
const blocks = [
  { id: 3, block_type: 'brief', eligibility_status: 'queued', content_item_id: 12, content_title: 'Queued post', decision_id: 9, decision: 'queue_brief', optimizer_run_id: 4, job_id: 55, attempt_count: 0, max_attempts: 5 },
  { id: 4, block_type: 'cover', eligibility_status: 'queued', content_item_id: null, decision_id: 10, decision: 'queue_brief', optimizer_run_id: 4 },
];

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockResolvedValue({ items: blocks, total: 2 });
});

describe('work block list', () => {
  it('applies ?status= and ?run_id= from the AI Ops / optimiser links', async () => {
    renderWithAuth(<BlogWorkBlocksPage />, { ...ctx, route: '/blog-command-centre/operations/work-blocks?status=queued&run_id=4' });
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('v1/blog-command-centre/work-blocks', expect.objectContaining({ status: 'queued', run_id: '4', page: 1 })));
    expect(screen.getByLabelText('Status')).toHaveValue('queued');
    expect(screen.getByRole('button', { name: 'Clear Optimiser run #4 filter' })).toBeInTheDocument();
  });

  it('cells link to the content, the decision run and the job', async () => {
    renderWithAuth(<BlogWorkBlocksPage />, ctx);
    expect(await screen.findByRole('link', { name: 'Queued post' })).toHaveAttribute('href', '/content/12');
    expect(screen.getAllByRole('link', { name: /queue brief · run #4/ })[0]).toHaveAttribute('href', '/blog-command-centre/roadmap/optimizer?run_id=4&has_work_block=1');
    expect(screen.getByRole('link', { name: '#55' })).toHaveAttribute('href', '/admin/jobs/55');
  });

  it('a row opens its content, or its decision when it has none', async () => {
    renderWithAuth(
      <Routes>
        <Route path="/blog-command-centre/operations/work-blocks" element={<BlogWorkBlocksPage />} />
        <Route path="/content/:id" element={<p>content page</p>} />
        <Route path="/blog-command-centre/roadmap/optimizer" element={<p>decisions page</p>} />
      </Routes>,
      { ...ctx, route: '/blog-command-centre/operations/work-blocks' },
    );
    fireEvent.click(await screen.findByRole('row', { name: 'Open work block 4' }));
    expect(await screen.findByText('decisions page')).toBeInTheDocument();
  });
});
