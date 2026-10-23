import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/rankingsDrilldownService', () => ({
  rankingsDrilldownService: { keywords: vi.fn(), aiAnswers: vi.fn() },
}));

import { rankingsDrilldownService } from '../../../services/rankingsDrilldownService';
import { Rankings } from '../Rankings';

const now = new Date().toISOString();

const section = {
  status: 'ok', built_at: now,
  tracker: {
    available: true, latest_date: '2026-10-23', compared_with: '2026-10-19', depth: 100,
    tracked: 8, keywords_checked: 8, failed: 0, not_checked: 0, ranked: 1, top10: 1, top10_share: 0.125,
    avg_rank: 1, avg_rank_over: 1,
    top10_keywords: [{ keyword: 'aicountly login', rank: 1, url: 'https://aicountly.com/login' }],
    ranked_keywords: [{ keyword: 'aicountly login', rank: 1, url: 'https://aicountly.com/login' }],
    unranked_keywords: ['gst billing software'],
    compared: 8, unchanged: 8, no_previous: 0, movers_up: [], movers_down: [],
    competitors: [
      { name: 'Tally', domain: 'tallysolutions.com', keywords: 5, top10: 5, avg_rank: 8.4,
        top10_keywords: [{ keyword: 'gst billing software', rank: 4, our_rank: null }] },
    ],
  },
  visibility: {
    available: true, period: { days: 28 }, answers: 432, brand_answers_counted: 0, brand_mentions: 0, measured: false,
    mention_rate: null, avg_rank: null, by_provider: [{ provider: 'gemini', answers: 216, counted: 0, brand_mentions: 0 }],
    failed_runs: 0, top_competitors: ['Tally'], note: 'One API answer per prompt per assistant per day.',
  },
};

const keywords = {
  available: true, latest_date: '2026-10-23', compared_with: '2026-10-19', depth: 100,
  tracker_status: { status: 'ready' }, volume_note: 'Search volume is not stored.', search_console_window: null,
  summary: { tracked: 3, checked: 2, ranked: 1, top10: 1, failed: 0, not_checked: 1, avg_rank: 1 },
  keywords: [
    { id: 1, keyword: 'aicountly login', product: 'platform', priority: 'high', enabled: true, status: 'ok', our_rank: 1, our_url: 'https://aicountly.com/login',
      previous_rank: 1, previous_checked: true, change: 0, last_checked_at: '2026-10-23T06:05:00+05:30', competitors: [], best_competitor: null, search_console: { impressions: 40, clicks: 3, avg_position: 1.2 } },
    { id: 2, keyword: 'gst billing software', product: 'billing', priority: 'high', enabled: true, status: 'ok', our_rank: null, our_url: null,
      previous_rank: null, previous_checked: true, change: 0, last_checked_at: '2026-10-23T06:05:00+05:30',
      competitors: [{ name: 'Tally', domain: 'tallysolutions.com', rank: 4, url: 'https://tallysolutions.com/x' }],
      best_competitor: { name: 'Tally', domain: 'tallysolutions.com', rank: 4, url: 'https://tallysolutions.com/x' }, search_console: null },
    { id: 3, keyword: 'payroll software', product: 'payroll', priority: 'low', enabled: true, status: 'not_checked', our_rank: null, our_url: null,
      previous_rank: null, previous_checked: false, change: null, last_checked_at: null, competitors: [], best_competitor: null, search_console: null },
  ],
  competitors: [
    { id: 9, name: 'Tally', domain: 'tallysolutions.com', keywords: 2, top10: 1, avg_rank: 8.4,
      placements: [{ keyword: 'gst billing software', rank: 4, url: 'https://tallysolutions.com/x', our_rank: null }, { keyword: 'tally alternative', rank: 31, url: null, our_rank: 55 }] },
  ],
};

const aiAnswers = {
  available: true, measured: false, mention_rate: null, brand: 'Aicountly',
  period: { from: '2026-09-26', to: '2026-10-23', days: 28 },
  totals: { answers: 2, counted: 0, brand_mentions: 0, failed: 0 },
  providers: [{ key: 'perplexity', label: 'Perplexity', will_run: false, reason: 'No key.' }],
  prompts: [{
    prompt_id: 1, name: 'brand-what-is-aicountly', market: 'India', question: 'What is Aicountly and is it good for small businesses?',
    answers: 2, counted: 0, brand_mentions: 0, competitors: [],
    providers: [{ provider: 'gemini', model: 'gemini-2.5-flash', answers: 1, counted: 0, brand_mentions: 0, failed: 0, competitors: [{ name: 'Zoho Books', answers: 1 }], last_asked_at: '2026-10-23T06:10:00+05:30' }],
  }],
  sample_note: 'One API answer per prompt per assistant per day.',
};

const ctx = { auth: { user: { id: 1, email: 'o@x', role: 'super_admin' }, permissions: ['*'] } };

beforeEach(() => {
  vi.clearAllMocks();
  rankingsDrilldownService.keywords.mockResolvedValue(keywords);
  rankingsDrilldownService.aiAnswers.mockResolvedValue(aiAnswers);
});

describe('Rankings', () => {
  it('says what the average is over, and names the keywords on hover and on keyboard focus', async () => {
    const user = userEvent.setup();
    renderWithAuth(<Rankings section={section} />, ctx);

    expect(screen.getByText('avg position 1 over 1 ranked keyword')).toBeInTheDocument();
    const tile = screen.getByRole('button', { name: /Keywords in top 10/ });
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();

    await user.hover(tile);
    const tip = screen.getByRole('tooltip');
    expect(tip).toHaveTextContent('aicountly login — #1');
    expect(tile).toHaveAttribute('aria-describedby', tip.id);
    await user.unhover(tile);
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();

    await user.tab();
    expect(tile).toHaveFocus();
    expect(screen.getByRole('tooltip')).toHaveTextContent('1 of 8 checked keywords');
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();

    await user.hover(screen.getByRole('button', { name: /Ranked \(top 100\)/ }));
    expect(screen.getByRole('tooltip')).toHaveTextContent('the 7 checked keywords outside the top 100 are not in it');
  });

  it('expands the full keyword table from the drill-down endpoint', async () => {
    const user = userEvent.setup();
    renderWithAuth(<Rankings section={section} />, ctx);
    const tile = screen.getByRole('button', { name: /Ranked \(top 100\)/ });
    expect(tile).toHaveAttribute('aria-expanded', 'false');

    await user.click(tile);
    expect(tile).toHaveAttribute('aria-expanded', 'true');
    const panel = await screen.findByRole('region', { name: 'Every tracked keyword' });
    await waitFor(() => expect(within(panel).getByText('gst billing software')).toBeInTheDocument());
    expect(rankingsDrilldownService.keywords).toHaveBeenCalledTimes(1);

    const gst = within(panel).getByText('gst billing software').closest('tr');
    expect(within(gst).getAllByText('not in top 100')).toHaveLength(2); // now and on the previous check
    expect(within(gst).getByText('#4')).toBeInTheDocument();
    expect(within(gst).getByText(/23 Oct 2026, 06:05 IST/)).toBeInTheDocument();
    const login = within(panel).getByText('aicountly login').closest('tr');
    expect(within(login).getByRole('link', { name: /\/login/ })).toHaveAttribute('href', 'https://aicountly.com/login');
    expect(within(login).getByText('no change')).toBeInTheDocument();
    const payroll = within(panel).getByText('payroll software').closest('tr');
    expect(within(payroll).getByText('not checked on 2026-10-23')).toBeInTheDocument();
    expect(within(panel).getByText('Search volume is not stored.', { exact: false })).toBeInTheDocument();
    expect(within(panel).getByRole('link', { name: 'Open SEO tracker →' })).toHaveAttribute('href', '/seo-centre/tracker');

    await user.click(within(panel).getByRole('button', { name: /Not checked \/ failed/ }));
    expect(within(panel).queryByText('aicountly login')).not.toBeInTheDocument();
    expect(within(panel).getByText('payroll software')).toBeInTheDocument();

    await user.click(tile);
    expect(screen.queryByRole('region', { name: 'Every tracked keyword' })).not.toBeInTheDocument();
  });

  it('shows which keywords a competitor holds, on hover and on click', async () => {
    const user = userEvent.setup();
    renderWithAuth(<Rankings section={section} />, ctx);
    const bar = screen.getByRole('button', { name: /Tally/ });
    expect(bar).toHaveTextContent('5 · avg #8.4 over 5 kw');

    await user.hover(bar);
    expect(screen.getByRole('tooltip')).toHaveTextContent('gst billing software — #4 (us: not in top 100)');

    await user.click(bar);
    expect(bar).toHaveAttribute('aria-expanded', 'true');
    const panel = await screen.findByRole('region', { name: 'Tally: keywords and positions' });
    await waitFor(() => expect(within(panel).getByText('tally alternative')).toBeInTheDocument());
    const row = within(panel).getByText('tally alternative').closest('tr');
    expect(within(row).getByText('#31')).toBeInTheDocument();
    expect(within(row).getByText('#55')).toBeInTheDocument();
  });

  it('never shows 0% when nothing was measured, and lists the questions asked', async () => {
    const user = userEvent.setup();
    renderWithAuth(<Rankings section={section} />, ctx);
    const tile = screen.getByRole('button', { name: /AI assistants mention us/ });
    expect(within(tile).getByText('Not measured')).toBeInTheDocument();
    expect(screen.queryByText('0%')).not.toBeInTheDocument();

    await user.click(tile);
    const panel = await screen.findByRole('region', { name: 'AI-visibility questions and answers' });
    await waitFor(() => expect(within(panel).getByText(/What is Aicountly and is it good/)).toBeInTheDocument());
    expect(within(panel).getByText(/Not measured: every answer was to a question that already names Aicountly/)).toBeInTheDocument();
    expect(within(panel).getByText('gemini (gemini-2.5-flash)')).toBeInTheDocument();
    expect(within(panel).getByText(/named: Zoho Books 1×/)).toBeInTheDocument();
    expect(within(panel).getByText(/Not asked: Perplexity \(No key\.\)/)).toBeInTheDocument();
    expect(within(panel).getByRole('link', { name: 'Open AI visibility →' })).toHaveAttribute('href', '/intelligence/visibility');
  });

  it('reports a failed drill-down instead of an empty table', async () => {
    rankingsDrilldownService.keywords.mockRejectedValue(new Error('HTTP 500'));
    const user = userEvent.setup();
    renderWithAuth(<Rankings section={section} />, ctx);
    await user.click(screen.getByRole('button', { name: /Keywords in top 10/ }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load the detail: HTTP 500');
  });
});
