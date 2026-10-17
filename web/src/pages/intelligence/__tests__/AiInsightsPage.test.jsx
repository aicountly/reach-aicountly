import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';
import AiInsightsPage from '../AiInsightsPage';
import * as svc from '../../../services/growthIntelService.js';

const report = {
  id: 7,
  status: 'completed',
  model: 'gemini-2.5-flash',
  period_start: '2026-09-20',
  period_end: '2026-10-17',
  created_at: '2026-10-17T10:30:00Z',
  unverified_numbers: ['4,321'],
  report: {
    summary: 'Search impressions are small; AI assistants rarely name us.',
    whats_working: [{ finding: 'Vetting approves most blogs', evidence: 'vetting.decisions_by_type' }],
    gaps: [{ area: 'AI visibility', finding: 'Not named for GST billing', evidence: 'ai_visibility.gaps' }],
    recommendations: [],
    ai_readiness_checklist: [
      { key: 'llms_txt', item: 'llms.txt published at the site root', status: 'missing', evidence: 'GET /llms.txt returned HTTP 404.', next_step: 'Publish it', source: 'observed' },
    ],
    internationalisation: { markets_to_test: [{ market: 'UAE', rationale: 'Assumption: VAT', first_step: 'Landing page' }], localisation_needs: ['Arabic invoices'] },
    investor_readiness: { metrics_investors_ask: [{ metric: 'MRR', have_data: false, evidence_or_gap: 'Not tracked in Reach' }], narrative_points: [], data_room_gaps: ['Retention cohorts'] },
    assumptions: ['UAE SMEs need VAT invoicing.'],
  },
  actions: [
    { id: 11, position: 1, title: 'Publish llms.txt', detail: 'List products', expected_impact: 'high', effort: 'S', owner_type: 'engineering', horizon_days: 3, status: 'open' },
  ],
};

vi.mock('../../../services/growthIntelService.js', () => ({
  getLatestInsights: vi.fn(async () => ({ report, last_run: { id: 7, status: 'completed' }, run_pending: false, configured: true, model: 'gemini-2.5-flash' })),
  listInsights: vi.fn(async () => [{ id: 7, status: 'completed', created_at: '2026-10-17T10:30:00Z' }]),
  getInsight: vi.fn(async () => report),
  runInsights: vi.fn(async () => ({ job_id: 3, message: 'Queued. The worker picks it up within a minute.' })),
  updateInsightAction: vi.fn(async (id, status) => ({ ...report.actions[0], status })),
  listStrategyMemos: vi.fn(async () => [{ id: 2, title: 'Growth strategy — week 42', created_at: '2026-10-18T12:30:00Z' }]),
  getStrategyMemo: vi.fn(async () => ({ id: 2, title: 'Growth strategy — week 42', body_markdown: '# Memo body' })),
}));

const ctx = (perms) => ({ auth: { user: { id: 1, email: 'a@aicountly.com', role: 'super_admin' }, permissions: perms } });

describe('AiInsightsPage', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows the report, the unverified-number warning, checklist, investor gaps and memos', async () => {
    renderWithAuth(<AiInsightsPage />, ctx(['intelligence.read']));
    await waitFor(() => expect(screen.getByText(/rarely name us/)).toBeInTheDocument());
    expect(screen.getByText('4,321')).toBeInTheDocument();
    expect(screen.getByText('llms.txt published at the site root')).toBeInTheDocument();
    expect(screen.getByText('Missing')).toBeInTheDocument();
    expect(screen.getByText('Retention cohorts')).toBeInTheDocument();
    expect(screen.getByText('UAE SMEs need VAT invoicing.')).toBeInTheDocument();
    expect(screen.getByText('Growth strategy — week 42')).toBeInTheDocument();
    // Read-only users get no run / mark-done controls.
    expect(screen.queryByRole('button', { name: /Run now/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Mark done' })).not.toBeInTheDocument();
  });

  it('marks an action done and queues a run for managers', async () => {
    const user = userEvent.setup();
    renderWithAuth(<AiInsightsPage />, ctx(['intelligence.read', 'intelligence.manage']));
    await waitFor(() => expect(screen.getByText('Publish llms.txt')).toBeInTheDocument());

    await user.click(screen.getByRole('button', { name: 'Mark done' }));
    expect(svc.updateInsightAction).toHaveBeenCalledWith(11, 'done');
    await waitFor(() => expect(screen.getByRole('button', { name: 'Reopen' })).toBeInTheDocument());

    await user.click(screen.getByRole('button', { name: /Run now/ }));
    await waitFor(() => expect(screen.getByText(/Queued. The worker/)).toBeInTheDocument());
  });
});
