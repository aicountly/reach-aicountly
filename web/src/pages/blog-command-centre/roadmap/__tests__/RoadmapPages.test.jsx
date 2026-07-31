import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../../test/renderWithAuth';

const mocks = {
  getRoadmapCandidates: vi.fn(),
  getRoadmapScored: vi.fn(),
  getRoadmapDecisions: vi.fn(),
  getOptimizerRuns: vi.fn(),
  getScoringWeights: vi.fn(),
  saveScoringWeights: vi.fn(),
};

vi.mock('../../../../services/blogCommandCentreService', () => ({
  blogCommandCentreService: mocks,
}));

const { RoadmapCandidatesPage } = await import('../RoadmapCandidatesPage.jsx');
const { RoadmapScoredPage } = await import('../RoadmapScoredPage.jsx');
const { RoadmapOptimizerPage } = await import('../RoadmapOptimizerPage.jsx');

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['blog.view', 'blog.edit'],
  },
  route: '/blog-command-centre/roadmap',
};

beforeEach(() => {
  Object.values(mocks).forEach((fn) => fn.mockReset());
});

describe('RoadmapCandidatesPage', () => {
  it('shows an explicit empty state instead of fabricated rows', async () => {
    mocks.getRoadmapCandidates.mockResolvedValue({ items: [], total: 0 });

    renderWithAuth(<RoadmapCandidatesPage />, ctx);

    await waitFor(() => {
      expect(screen.getByText('No topic candidates yet')).toBeInTheDocument();
    });
  });

  it('renders candidates with their current score', async () => {
    mocks.getRoadmapCandidates.mockResolvedValue({
      items: [{
        id: 7,
        title: 'GST return due dates 2026',
        portfolio_stream: 'problem_to_product',
        status: 'scored',
        total_score: 72.5,
        trend_7d: 4.25,
        trend_28d: null,
        scored_for_date: '2026-07-30',
      }],
      total: 1,
    });

    renderWithAuth(<RoadmapCandidatesPage />, ctx);

    await waitFor(() => {
      expect(screen.getByText('GST return due dates 2026')).toBeInTheDocument();
    });
    expect(screen.getByText('72.5')).toBeInTheDocument();
    expect(screen.getByText('+4.3')).toBeInTheDocument();
    // The stream label also appears as a filter option, so assert on the row cell.
    const row = screen.getByText('GST return due dates 2026').closest('tr');
    expect(row).toHaveTextContent('problem to product');
  });

  it('surfaces API errors rather than rendering a silent empty table', async () => {
    mocks.getRoadmapCandidates.mockRejectedValue(new Error('permission denied'));

    renderWithAuth(<RoadmapCandidatesPage />, ctx);

    await waitFor(() => {
      expect(screen.getByText('permission denied')).toBeInTheDocument();
    });
  });
});

describe('RoadmapScoredPage', () => {
  it('shows the empty state when nothing has been scored', async () => {
    mocks.getRoadmapScored.mockResolvedValue({ items: [] });

    renderWithAuth(<RoadmapScoredPage />, ctx);

    await waitFor(() => {
      expect(screen.getByText('Nothing scored yet')).toBeInTheDocument();
    });
  });

  it('ranks scored topics and flags deductions', async () => {
    mocks.getRoadmapScored.mockResolvedValue({
      items: [{
        id: 1,
        topic_candidate_id: 7,
        title: 'Cannibalising topic',
        portfolio_stream: 'marketing',
        total_score: 55,
        deduction_total: 10,
        deductions_json: '{"cannibalisation":10}',
        trend_7d: -2,
        scored_for_date: '2026-07-30',
      }],
    });

    renderWithAuth(<RoadmapScoredPage />, ctx);

    await waitFor(() => {
      expect(screen.getByText('Cannibalising topic')).toBeInTheDocument();
    });
    expect(screen.getByText('-10.0')).toBeInTheDocument();
    expect(screen.getByText('-2.0')).toBeInTheDocument();
  });
});

describe('RoadmapOptimizerPage', () => {
  const weights = {
    search_opportunity: 20,
    product_priority: 20,
    audience_problem: 15,
    conversion_potential: 15,
    content_gap: 10,
    seasonality: 10,
    internal_link_value: 5,
    evidence_readiness: 5,
  };

  it('reports when the optimiser has never run', async () => {
    mocks.getOptimizerRuns.mockResolvedValue({ items: [] });
    mocks.getRoadmapDecisions.mockResolvedValue({ items: [] });
    mocks.getScoringWeights.mockResolvedValue(weights);

    renderWithAuth(<RoadmapOptimizerPage />, ctx);

    await waitFor(() => {
      expect(screen.getByText('No optimiser runs recorded')).toBeInTheDocument();
    });
    expect(screen.getByText('No decisions yet')).toBeInTheDocument();
  });

  it('shows a skipped run with its reason', async () => {
    mocks.getOptimizerRuns.mockResolvedValue({
      items: [{
        id: 3,
        run_for_date: '2026-07-30',
        status: 'skipped',
        candidates_scored: 0,
        decisions_created: 0,
        work_blocks_created: 0,
        skipped_reason: 'optimizer_disabled',
        duration_ms: 12,
      }],
    });
    mocks.getRoadmapDecisions.mockResolvedValue({ items: [] });
    mocks.getScoringWeights.mockResolvedValue(weights);

    renderWithAuth(<RoadmapOptimizerPage />, ctx);

    await waitFor(() => {
      expect(screen.getByText('optimizer_disabled')).toBeInTheDocument();
    });
    expect(screen.getByText('skipped')).toBeInTheDocument();
  });

  it('enables saving only when the weights total exactly 100', async () => {
    mocks.getOptimizerRuns.mockResolvedValue({ items: [] });
    mocks.getRoadmapDecisions.mockResolvedValue({ items: [] });
    mocks.getScoringWeights.mockResolvedValue({ ...weights, evidence_readiness: 30 });

    renderWithAuth(<RoadmapOptimizerPage />, ctx);

    await waitFor(() => {
      expect(screen.getByText(/Total: 125 \/ 100/)).toBeInTheDocument();
    });
    expect(screen.getByRole('button', { name: 'Save weights' })).toBeDisabled();
  });

  it('marks decisions that require human review', async () => {
    mocks.getOptimizerRuns.mockResolvedValue({ items: [] });
    mocks.getRoadmapDecisions.mockResolvedValue({
      items: [{
        id: 11,
        title: 'High risk tax change',
        decision: 'REQUIRE_HUMAN_REVIEW',
        score_at_decision: 81,
        rank_at_decision: 1,
        requires_human_review: true,
        decision_reason: 'weak evidence',
        decided_for_date: '2026-07-30',
      }],
    });
    mocks.getScoringWeights.mockResolvedValue(weights);

    renderWithAuth(<RoadmapOptimizerPage />, ctx);

    await waitFor(() => {
      expect(screen.getByText('REQUIRE_HUMAN_REVIEW')).toBeInTheDocument();
    });
    expect(screen.getByText('Human review')).toBeInTheDocument();
  });
});
