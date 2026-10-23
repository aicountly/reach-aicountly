import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/readinessService.js', () => ({
  getOperationsSummary: vi.fn(),
  listOperationalChecks: vi.fn(),
  ensureOperationalCheckDefaults: vi.fn(),
  upsertOperationalCheck: vi.fn(),
  listFindings: vi.fn(),
  listFindingBlockers: vi.fn(),
  listRefreshOutcomes: vi.fn(),
  getRefreshOutcome: vi.fn(),
  measureRefreshOutcome: vi.fn(),
}));

import * as readiness from '../../../services/readinessService.js';
import OperationsDashboardPage from '../OperationsDashboardPage';
import SecurityStatusPage from '../SecurityStatusPage';
import RefreshOutcomePage from '../RefreshOutcomePage';
import ReadinessOverviewPage from '../ReadinessOverviewPage';

const ctx = { auth: { user: { id: 1 }, permissions: ['readiness.read', 'refresh_outcome.read'] } };

beforeEach(() => {
  vi.clearAllMocks();
  readiness.getOperationsSummary.mockResolvedValue({
    summary: { recommendation_backlog: 2, active_workflows: 1, failed_publications: 3, pending_outcome_windows: 4, open_critical_findings: 1, open_high_findings: 0 },
    jobs: { job_types: [{ job_type: 'reach.seo_tracker', total: 5, completed: 4, failed: 1 }] },
  });
  readiness.listOperationalChecks.mockResolvedValue([]);
  readiness.listFindings.mockResolvedValue([]);
  readiness.listFindingBlockers.mockResolvedValue([]);
  readiness.listRefreshOutcomes.mockResolvedValue([]);
});

describe('operations dashboard drill links', () => {
  it('cards open the records they counted; zero cards stay plain', async () => {
    renderWithAuth(<OperationsDashboardPage />, ctx);
    expect(await screen.findByRole('link', { name: /Failed Publications: 3/ })).toHaveAttribute('href', '/publishing/deployments?status=failed');
    expect(screen.getByRole('link', { name: /Pending Outcomes: 4/ })).toHaveAttribute('href', '/readiness/outcomes?measurement_status=pending');
    expect(screen.getByRole('link', { name: /Open Critical: 1/ })).toHaveAttribute(
      'href', '/readiness/security?severity=critical&resolution_status=open%2Cin_progress',
    );
    expect(screen.queryByRole('link', { name: /Open High/ })).not.toBeInTheDocument();
  });

  it('job reliability counts open the job monitor for that type over 7 days', async () => {
    renderWithAuth(<OperationsDashboardPage />, ctx);
    expect(await screen.findByRole('link', { name: '1' })).toHaveAttribute(
      'href', '/admin/jobs?job_type=reach.seo_tracker&status=failed&since_hours=168',
    );
    expect(screen.getByRole('link', { name: '5' })).toHaveAttribute('href', '/admin/jobs?job_type=reach.seo_tracker&since_hours=168');
  });
});

describe('readiness lists read their filters from the URL', () => {
  it('security status applies severity and resolution_status', async () => {
    renderWithAuth(<SecurityStatusPage />, { ...ctx, route: '/readiness/security?severity=critical&resolution_status=open,in_progress' });
    await waitFor(() => expect(readiness.listFindings).toHaveBeenCalledWith({ severity: 'critical', resolution_status: 'open,in_progress' }));
    expect(screen.getByLabelText('Severity')).toHaveValue('critical');
    expect(screen.getByLabelText('Resolution status')).toHaveValue('open,in_progress');
  });

  it('refresh outcomes apply measurement_status and content_identity_id', async () => {
    renderWithAuth(<RefreshOutcomePage />, { ...ctx, route: '/readiness/outcomes?measurement_status=pending&content_identity_id=12' });
    await waitFor(() => expect(readiness.listRefreshOutcomes).toHaveBeenCalledWith({ measurement_status: 'pending', content_identity_id: '12' }));
    expect(screen.getByRole('tab', { name: 'Pending' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('button', { name: /clear content identity 12 filter/i })).toBeInTheDocument();
  });

  it('refresh outcome identity opens the content analytics', async () => {
    readiness.listRefreshOutcomes.mockResolvedValue([{ id: 9, content_identity_id: 33, measurement_status: 'complete' }]);
    renderWithAuth(<RefreshOutcomePage />, ctx);
    expect(await screen.findByRole('link', { name: 'Identity #33' })).toHaveAttribute('href', '/intelligence/content/33');
  });
});

describe('readiness overview', () => {
  it('does not link sections that have no route', () => {
    renderWithAuth(<ReadinessOverviewPage />);
    expect(screen.queryByRole('link', { name: /Privacy Status/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Refresh Recommendations/ })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Security Status/ })).toHaveAttribute('href', '/readiness/security');
  });
});
