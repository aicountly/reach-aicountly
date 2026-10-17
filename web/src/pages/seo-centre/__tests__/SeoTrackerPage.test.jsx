import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

const getTracker = vi.fn();
vi.mock('../../../services/seoService', () => ({
  seoService: {
    getTracker: (...a) => getTracker(...a),
    runTracker: vi.fn(async () => ({ message: 'queued' })),
    createTrackedKeyword: vi.fn(async () => ({ id: 9 })),
    updateTrackedKeyword: vi.fn(async () => ({})),
  },
}));

import { SeoTrackerPage } from '../SeoTrackerPage';

const status = (s) => ({ status: s, detail: `detail ${s}`, location_code: 2356, language_code: 'en', depth: 100, max_keywords_per_day: 35, our_domain: 'aicountly.com' });
const manager = { auth: { user: { id: 1, role: 'reach_admin' }, permissions: ['seo.view', 'seo.manage'] } };

describe('SeoTrackerPage', () => {
  beforeEach(() => getTracker.mockReset());

  it('says not configured and shows no positions without DataForSEO', async () => {
    getTracker.mockResolvedValue({
      status: status('not_configured'), date: null, competitors: [{ id: 3, name: 'Vyapar', domain: 'vyaparapp.in' }],
      keywords: [{ id: 1, keyword: 'gst billing software', product: 'billing', enabled: true, our_rank: null, change: null, competitor_ranks: {}, winner: null }],
      summary: {}, top10_share: [],
    });
    renderWithAuth(<SeoTrackerPage />, manager);

    expect(await screen.findByTestId('tracker-status')).toHaveTextContent('NOT CONFIGURED');
    expect(screen.getByTestId('tracker-empty')).toHaveTextContent('nothing here is estimated');
    expect(screen.getByText('gst billing software')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Run now/ })).toBeDisabled();
  });

  it('renders our position, change, competitor columns and winner', async () => {
    getTracker.mockResolvedValue({
      status: status('ready'), date: '2026-10-17',
      competitors: [{ id: 3, name: 'Vyapar', domain: 'vyaparapp.in' }, { id: 4, name: 'Zoho Books', domain: 'zoho.com' }],
      keywords: [{
        id: 1, keyword: 'gst billing software', product: 'billing', enabled: true, check_status: 'ok',
        our_rank: 7, our_url: 'https://aicountly.com/billing', previous_rank: 15, change: 8,
        competitor_ranks: { 3: 2, 4: null }, winner: { name: 'Vyapar', rank: 2, ours: false },
      }],
      summary: { checked: 1, we_rank: 1, top10: 1, up: 1, down: 0, competitor_wins: 1 },
      top10_share: [{ id: null, name: 'aicountly', domain: 'aicountly.com', ours: true, top10: 1, share: 1 }],
    });
    renderWithAuth(<SeoTrackerPage />, manager);

    await waitFor(() => expect(screen.getByText('#7')).toBeInTheDocument());
    expect(screen.getByText('▲ 8')).toBeInTheDocument();
    expect(screen.getByText('#2')).toBeInTheDocument();
    expect(screen.getByText('Vyapar (#2)')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Run now/ })).not.toBeDisabled();
  });

  it('hides management controls without seo.manage', async () => {
    getTracker.mockResolvedValue({ status: status('ready'), date: null, competitors: [], keywords: [], summary: {}, top10_share: [] });
    renderWithAuth(<SeoTrackerPage />, { auth: { user: { id: 2, role: 'analyst' }, permissions: ['seo.view'] } });

    await screen.findByTestId('tracker-status');
    expect(screen.queryByRole('button', { name: /Run now/ })).not.toBeInTheDocument();
    expect(screen.queryByText('Track a keyword')).not.toBeInTheDocument();
  });
});
