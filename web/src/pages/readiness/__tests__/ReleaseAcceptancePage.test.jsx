import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/readinessService.js', () => ({
  getReleasePrerequisites: vi.fn(),
  getLatestReleaseAcceptance: vi.fn(),
  createReleaseAcceptance: vi.fn(),
}));

import {
  getLatestReleaseAcceptance,
  getReleasePrerequisites,
} from '../../../services/readinessService.js';
import ReleaseAcceptancePage from '../ReleaseAcceptancePage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['readiness.read', 'readiness.accept'],
  },
};

beforeEach(() => {
  getReleasePrerequisites.mockReset();
  getLatestReleaseAcceptance.mockReset();
});

describe('ReleaseAcceptancePage', () => {
  it('shows blocked prerequisites when gates fail', async () => {
    getReleasePrerequisites.mockResolvedValueOnce({
      ready: false,
      checks: [
        {
          key: 'security_findings',
          label: 'Security findings',
          passed: false,
          detail: '2 open critical/high finding(s)',
          href: '/readiness/security',
        },
      ],
    });
    getLatestReleaseAcceptance.mockResolvedValueOnce(null);

    renderWithAuth(<ReleaseAcceptancePage />, ctx);
    await waitFor(() => expect(screen.getByText(/Not yet accepted/i)).toBeInTheDocument());
    expect(screen.getByText('Security findings')).toBeInTheDocument();
    expect(screen.getByText('blocked')).toBeInTheDocument();
  });

  it('shows existing acceptance record', async () => {
    getReleasePrerequisites.mockResolvedValueOnce({
      ready: true,
      checks: [],
    });
    getLatestReleaseAcceptance.mockResolvedValueOnce({
      id: 1,
      release_name: 'Phase 9 controlled',
      recommendation: 'ready_controlled',
      evidence_summary: 'All gates green',
      blockers_resolved: true,
      limitations_accepted: [],
      accepted_risks: [],
      accepted_at: '2026-07-29T10:00:00Z',
    });

    renderWithAuth(<ReleaseAcceptancePage />, ctx);
    await waitFor(() => expect(screen.getByText('Accepted.')).toBeInTheDocument());
    expect(screen.getByText('Phase 9 controlled')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: /Create acceptance record/i })).not.toBeInTheDocument();
  });
});
