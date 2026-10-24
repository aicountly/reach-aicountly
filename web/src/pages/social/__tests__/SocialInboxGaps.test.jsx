import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { Routes, Route, useLocation } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/socialInboxService', () => ({
  socialInboxService: { list: vi.fn(), summary: vi.fn(), retry: vi.fn() },
}));

import { socialInboxService } from '../../../services/socialInboxService';
import { SocialInboxPage } from '../SocialInboxPage';

const ADMIN = { user: { id: 1, role: 'super_admin' }, permissions: ['*'] };

function Where() {
  const { search } = useLocation();
  return <output data-testid="search">{search}</output>;
}

const at = (route) => renderWithAuth(
  <Routes><Route path="*" element={<><SocialInboxPage /><Where /></>} /></Routes>,
  { auth: ADMIN, route },
);

const summary = (day) => ({
  config: { enabled: true, auto_reply: true, social_api_key: true, flow: 'configured' },
  last_24h: { received: 5, replies_sent: 0, tickets: 0, leads: 0, by_classification: {}, ...day },
});

beforeEach(() => {
  vi.clearAllMocks();
  socialInboxService.list.mockResolvedValue({ items: [], total: 0 });
});

describe('Social inbox — reply failures and complaints waiting for Flow', () => {
  it('reply failures open the failed replies changed in the last 24 hours', async () => {
    socialInboxService.summary.mockResolvedValue(summary({ reply_failures: 2, waiting_for_flow: 0 }));
    at('/social/inbox');
    fireEvent.click(await screen.findByRole('button', { name: 'Show 2 reply failures from the last 24 hours' }));

    await waitFor(() => expect(screen.getByTestId('search')).toHaveTextContent('?reply_status=failed&updated_since_hours=24'));
    expect(socialInboxService.list).toHaveBeenLastCalledWith(expect.objectContaining({ reply_status: 'failed', updated_since_hours: '24', page: 1 }));
    expect(screen.getByRole('button', { name: /clear changed in the last 24 h filter/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /complaints waiting for flow/i })).not.toBeInTheDocument();
  });

  it('complaints waiting for Flow open with waiting=flow and show a removable chip', async () => {
    socialInboxService.summary.mockResolvedValue(summary({ reply_failures: 0, waiting_for_flow: 3 }));
    at('/social/inbox?network=x');
    fireEvent.click(await screen.findByRole('button', { name: 'Show 3 complaints waiting for Flow' }));

    await waitFor(() => expect(screen.getByTestId('search')).toHaveTextContent('?waiting=flow'));
    expect(socialInboxService.list).toHaveBeenLastCalledWith(expect.objectContaining({ waiting: 'flow', network: '' }));
    fireEvent.click(screen.getByRole('button', { name: /clear complaints waiting for flow filter/i }));
    await waitFor(() => expect(socialInboxService.list).toHaveBeenLastCalledWith(expect.not.objectContaining({ waiting: 'flow' })));
  });
});
