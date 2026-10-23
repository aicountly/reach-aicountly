import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Route, Routes, useLocation } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/botService', () => ({
  botService: { queue: vi.fn(), dispatch: vi.fn() },
}));

import { botService } from '../../../services/botService';
import { BotQueuePage } from '../BotQueuePage';

function Where() {
  const loc = useLocation();
  return <div data-testid="where">{loc.pathname}{loc.search}</div>;
}

const rows = [
  { id: 3, action: 'generate_blog_draft', status: 'completed', generation_job_id: 41, request_id: 'req-abc', created_at: '2026-10-22T10:00:00Z' },
  { id: 2, action: 'generate_seo_brief', status: 'queued', generation_job_id: null, request_id: null, created_at: '2026-10-21T10:00:00Z' },
];

beforeEach(() => {
  vi.clearAllMocks();
  botService.queue.mockResolvedValue({ items: rows });
});

describe('BotQueuePage', () => {
  it('links each row to its job run in the Job Monitor', async () => {
    const user = userEvent.setup();
    renderWithAuth(
      <Routes>
        <Route path="/bot/queue" element={<BotQueuePage />} />
        <Route path="/admin/jobs" element={<Where />} />
      </Routes>,
      { auth: { user: { id: 1 }, permissions: ['bot.view'] }, route: '/bot/queue' },
    );

    expect(await screen.findByRole('link', { name: '#41' })).toHaveAttribute('href', '/admin/jobs?request_id=req-abc');
    // A row with no job yet: no Job link, the row falls back to every bot dispatch job.
    await user.click(screen.getByRole('row', { name: /queue item #2/ }));
    expect(screen.getByTestId('where')).toHaveTextContent('/admin/jobs?job_type=reach.marketing_bot_dispatch');
  });

  it('opens the run behind a row on click', async () => {
    const user = userEvent.setup();
    renderWithAuth(
      <Routes>
        <Route path="/bot/queue" element={<BotQueuePage />} />
        <Route path="/admin/jobs" element={<Where />} />
      </Routes>,
      { auth: { user: { id: 1 }, permissions: ['bot.view'] }, route: '/bot/queue' },
    );
    await user.click(await screen.findByRole('row', { name: /queue item #3/ }));
    expect(screen.getByTestId('where')).toHaveTextContent('/admin/jobs?request_id=req-abc');
  });
});
