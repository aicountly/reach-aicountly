import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/growthIntelService.js', () => ({
  getVisibilitySummary: vi.fn(),
  listVisibilityAnswers: vi.fn(),
  runVisibilityBatch: vi.fn(),
  listVisibilityRunRecords: vi.fn(),
  getVisibilityRun: vi.fn(),
  listVisibilityObservationRecords: vi.fn(),
  getCompetitor: vi.fn(),
  listCompetitorObservations: vi.fn(),
}));
vi.mock('../../../services/intelligenceService.js', () => ({ listCompetitors: vi.fn(), createCompetitor: vi.fn() }));

import * as growth from '../../../services/growthIntelService.js';
import * as intel from '../../../services/intelligenceService.js';
import VisibilityOverviewPage from '../VisibilityOverviewPage';
import VisibilityRunHistoryPage from '../VisibilityRunHistoryPage';
import VisibilityRunDetailPage from '../VisibilityRunDetailPage';
import VisibilityObservationsPage from '../VisibilityObservationsPage';
import CompetitorListPage from '../CompetitorListPage';
import CompetitorDetailPage from '../CompetitorDetailPage';

const entities = { AICOUNTLY: { mentions: 2, mention_rate: 0.5, share_of_voice: 0.4, avg_rank: 2 }, Tally: { mentions: 3, mention_rate: 0.75, share_of_voice: 0.6, avg_rank: 1 } };
const summary = {
  brand: 'AICOUNTLY', providers: [], series: [], failures: [], top_competitors: ['Tally'],
  overall: { answers: 4, brand_mentions: 2, brand_mention_rate: 0.5, brand_avg_rank: 2, brand_cited: 1, entities },
  by_provider: [{ provider: 'gemini', answers: 4, brand_mentions: 2, brand_mention_rate: 0.5, brand_avg_rank: 2, entities }],
  by_market: [{ market: 'India', answers: 4, brand_mentions: 2, brand_mention_rate: 0.5, top_entities: ['Tally'] }],
  gaps: [{ prompt_id: 7, question: 'Best Tally alternatives?', market: 'India', answers_without_us: 2, providers: ['openai'], competitors: [{ name: 'Vyapar', answers: 2 }] }],
  top_cited_domains: [{ domain: 'vyaparapp.in', answers: 1 }],
};
const run = {
  run_id: 11, provider: 'gemini', model: 'gemini-test', status: 'completed', prompt_id: 7, prompt_name: 'Tally alternatives', prompt_version: 1,
  market: 'India', question: 'Best Tally alternatives?', asked_at: '2026-10-20T00:00:00Z', brand_mentioned: false, brand_counted: true,
  gap: true, competitors: ['Vyapar'], cited_domains: ['vyaparapp.in'],
};

beforeEach(() => {
  vi.clearAllMocks();
  growth.getVisibilitySummary.mockResolvedValue(summary);
  growth.listVisibilityAnswers.mockResolvedValue([]);
});

describe('AI visibility overview drills to the answers it counted', () => {
  it('every row links to the run list with the same period and facet', async () => {
    renderWithAuth(<VisibilityOverviewPage />, { route: '/intelligence/visibility?days=7' });
    await waitFor(() => expect(growth.getVisibilitySummary).toHaveBeenCalledWith(7));
    expect(await screen.findByRole('link', { name: 'Answers analysed: 4' })).toHaveAttribute('href', '/intelligence/visibility/runs?days=7&status=completed');
    expect(screen.getByRole('link', { name: 'AICOUNTLY mention rate: 50%' })).toHaveAttribute('href', '/intelligence/visibility/runs?days=7&brand=named');
    const byAssistant = screen.getByRole('button', { name: 'Gemini' }).closest('tr');
    expect(byAssistant.querySelector('a[href="/intelligence/visibility/runs?days=7&status=completed&provider=gemini"]')).not.toBeNull();
    expect(screen.getByRole('link', { name: '3' })).toHaveAttribute('href', '/intelligence/visibility/runs?days=7&entity=Tally');
    expect(screen.getByRole('link', { name: 'Vyapar ×2' })).toHaveAttribute('href', '/intelligence/visibility/runs?days=7&gap=1&prompt_id=7&entity=Vyapar');
    expect(screen.getByText('vyaparapp.in').closest('tr').querySelector('a[href="/intelligence/visibility/runs?days=7&cited_domain=vyaparapp.in"]')).not.toBeNull();
    expect(document.querySelector('a[href="/intelligence/visibility/runs?days=7&status=completed&market=India"]')).not.toBeNull();
    // The assistant name still narrows the latest answers in place.
    fireEvent.click(screen.getByRole('button', { name: 'Gemini' }));
    await waitFor(() => expect(growth.listVisibilityAnswers).toHaveBeenLastCalledWith({ limit: 30, provider: 'gemini' }));
  });
});

describe('run history, answer detail, observations', () => {
  it('the run list applies URL filters and a row opens the answer', async () => {
    growth.listVisibilityRunRecords.mockResolvedValue({ data: [run], meta: { total: 1 } });
    growth.getVisibilityRun.mockResolvedValue({
      ...run, responses: [{ id: 1, text: '- Tally\n- Vyapar', parser_version: '2', parse_status: 'parsed', response_timestamp: '2026-10-20T00:00:00Z' }],
      observations: [{ id: 1, entity_mentioned: 'Vyapar', mention_type: 'competitor', coverage_state: 'mentioned', mention_order: 2, competitor_id: 4, in_question: false }],
      citations: [{ id: 1, cited_url: 'https://vyaparapp.in', cited_domain: 'vyaparapp.in', citation_type: 'link', citation_order: 1 }],
    });
    renderWithAuth(
      <Routes>
        <Route path="/intelligence/visibility/runs" element={<VisibilityRunHistoryPage />} />
        <Route path="/intelligence/visibility/runs/:id" element={<VisibilityRunDetailPage />} />
      </Routes>,
      { route: '/intelligence/visibility/runs?days=7&gap=1&prompt_id=7&entity=Vyapar' },
    );
    await waitFor(() => expect(growth.listVisibilityRunRecords).toHaveBeenCalledWith({ days: '7', gap: '1', prompt_id: '7', entity: 'Vyapar', page: 1, per_page: 50 }));
    expect(screen.getByRole('button', { name: /clear names filter/i })).toBeInTheDocument();
    fireEvent.click(await screen.findByRole('row', { name: /Open answer 11/ }));
    expect(await screen.findByText(/- Tally/)).toBeInTheDocument();
    expect(growth.getVisibilityRun).toHaveBeenCalledWith('11');
    expect(screen.getByRole('link', { name: 'Vyapar' })).toHaveAttribute('href', '/intelligence/competitors/4');
    expect(screen.getByRole('link', { name: 'vyaparapp.in' })).toHaveAttribute('href', '/intelligence/visibility/runs?cited_domain=vyaparapp.in');
    expect(screen.getByText(/competitors were \(a gap\)/)).toBeInTheDocument();
  });

  it('observations read their filters from the URL', async () => {
    growth.listVisibilityObservationRecords.mockResolvedValue({ data: [
      { id: 3, run_id: 11, entity_mentioned: 'Tally', mention_type: 'competitor', coverage_state: 'mentioned', competitor_id: 4, ai_provider: 'openai', prompt_id: 7, prompt_name: 'Tally alternatives' },
    ], meta: { total: 1 } });
    renderWithAuth(<VisibilityObservationsPage />, { route: '/intelligence/visibility/observations?entity=Tally&counted=1&days=7' });
    expect(await screen.findByRole('link', { name: 'Tally' })).toHaveAttribute('href', '/intelligence/competitors/4');
    expect(growth.listVisibilityObservationRecords).toHaveBeenCalledWith({ entity: 'Tally', days: '7', counted: '1', page: 1, per_page: 50 });
  });
});

describe('competitors', () => {
  it('rows open the competitor; the rate opens the answers behind it', async () => {
    intel.listCompetitors.mockResolvedValue([{ id: 4, name: 'Tally', mention_rate: 0.75, mentions: 3, answers: 4, rate_days: 28, monitoring_status: 'active' }]);
    renderWithAuth(
      <Routes>
        <Route path="/intelligence/competitors" element={<CompetitorListPage />} />
        <Route path="/intelligence/competitors/:id" element={<p>competitor detail</p>} />
      </Routes>,
      { route: '/intelligence/competitors' },
    );
    expect(await screen.findByRole('link', { name: '75%' })).toHaveAttribute('href', '/intelligence/competitors/4?days=28');
    fireEvent.click(screen.getByRole('row', { name: 'Open competitor Tally' }));
    expect(await screen.findByText('competitor detail')).toBeInTheDocument();
  });

  it('the detail lists the observations behind the rate', async () => {
    growth.getCompetitor.mockResolvedValue({ id: 4, name: 'Tally', mention_rate: 0.75, mentions: 3, answers: 4, aliases: [{ alias_value: 'TallyPrime' }] });
    growth.listCompetitorObservations.mockResolvedValue({ data: [
      { id: 3, run_id: 11, prompt_name: 'Tally alternatives', ai_provider: 'openai', mention_order: 1, evidence_excerpt: 'Tally: the default' },
    ], meta: { total: 3 } });
    renderWithAuth(
      <Routes><Route path="/intelligence/competitors/:id" element={<CompetitorDetailPage />} /></Routes>,
      { route: '/intelligence/competitors/4?days=7' },
    );
    expect(await screen.findByText('Tally: the default')).toBeInTheDocument();
    expect(growth.getCompetitor).toHaveBeenCalledWith('4', { days: 7 });
    expect(growth.listCompetitorObservations).toHaveBeenCalledWith('4', { days: 7, page: 1, per_page: 50 });
    expect(screen.getByRole('link', { name: 'Answers analysed: 4' })).toHaveAttribute('href', '/intelligence/visibility/runs?days=7&status=completed');
    expect(screen.getByRole('link', { name: 'As answer list ›' })).toHaveAttribute('href', '/intelligence/visibility/runs?days=7&competitor_id=4');
    expect(screen.getByText(/also matched as TallyPrime/)).toBeInTheDocument();
  });
});
