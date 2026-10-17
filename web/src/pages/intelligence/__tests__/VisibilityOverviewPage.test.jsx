import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';
import VisibilityOverviewPage from '../VisibilityOverviewPage';

const entities = {
  'Zoho Books': { mentions: 3, mention_rate: 1, share_of_voice: 0.3333, avg_rank: 1 },
  AICOUNTLY: { mentions: 3, mention_rate: 1, share_of_voice: 0.3333, avg_rank: 2 },
};
const summary = {
  period: { from: '2026-10-11', to: '2026-10-17', days: 7 },
  brand: 'AICOUNTLY',
  providers: [
    { key: 'gemini', label: 'Gemini', model: 'gemini-2.5-flash', will_run: true, reason: null },
    { key: 'perplexity', label: 'Perplexity', model: 'sonar', will_run: false, reason: 'No Perplexity API key (optional; Settings → AI & Automation).' },
  ],
  overall: { answers: 3, brand_mention_rate: 1, brand_avg_rank: 2, brand_cited: 3, entities },
  by_provider: [{ provider: 'gemini', answers: 3, brand_mention_rate: 1, brand_avg_rank: 2, entities }],
  by_market: [{ market: 'India', answers: 3, brand_mention_rate: 1, top_entities: ['Zoho Books'] }],
  top_competitors: ['Zoho Books'],
  series: [
    { date: '2026-10-16', provider: 'gemini', answers: 1, sov: { AICOUNTLY: 0.25, 'Zoho Books': 0.5 } },
    { date: '2026-10-17', provider: 'gemini', answers: 2, sov: { AICOUNTLY: 0.3333, 'Zoho Books': 0.3333 } },
  ],
  gaps: [{ prompt_id: 4, question: 'What are the best Tally alternatives?', market: 'India', answers_without_us: 2, providers: ['openai'], competitors: [{ name: 'Vyapar', answers: 2 }] }],
  top_cited_domains: [{ domain: 'aicountly.com', answers: 3 }],
  failures: [],
  latest_batch: { batch_date: '2026-10-17', trigger_type: 'scheduled', status: 'completed' },
  sample_note: 'One API answer per prompt per assistant per day.',
};

vi.mock('../../../services/growthIntelService.js', () => ({
  getVisibilitySummary: vi.fn(async () => summary),
  listVisibilityAnswers: vi.fn(async () => [
    { run_id: 1, provider: 'gemini', status: 'completed', question: 'Best GST billing software?', brand_mentioned: true, brand_rank: 2, competitors: [{ name: 'Zoho Books', rank: 1 }], citations: [], answer_text: '1. Zoho Books\n2. AICOUNTLY', model: 'gemini-2.5-flash', market: 'India' },
  ]),
  runVisibilityBatch: vi.fn(async () => { throw Object.assign(new Error("Today's cap of 25 prompts is used."), { status: 429 }); }),
}));

const ctx = { auth: { user: { id: 1, email: 'a@aicountly.com', role: 'super_admin' }, permissions: ['visibility.read', 'visibility.execute'] } };

describe('VisibilityOverviewPage', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders measured mention rate, skipped assistants, gaps and a share-of-voice chart', async () => {
    renderWithAuth(<VisibilityOverviewPage />, ctx);
    await waitFor(() => expect(screen.getByText('AICOUNTLY mention rate')).toBeInTheDocument());
    expect(screen.getAllByText('100%').length).toBeGreaterThan(0);
    expect(screen.getByText(/Perplexity skipped — No Perplexity API key/)).toBeInTheDocument();
    expect(screen.getByText('What are the best Tally alternatives?')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Share of voice by day' })).toBeInTheDocument();
    expect(screen.getByText('Best GST billing software?', { exact: false })).toBeInTheDocument();
  });

  it('shows the server reason when a run cannot start', async () => {
    const user = userEvent.setup();
    renderWithAuth(<VisibilityOverviewPage />, ctx);
    await waitFor(() => expect(screen.getByRole('button', { name: /Run now/ })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /Run now/ }));
    await waitFor(() => expect(screen.getByText("Today's cap of 25 prompts is used.")).toBeInTheDocument());
  });
});
