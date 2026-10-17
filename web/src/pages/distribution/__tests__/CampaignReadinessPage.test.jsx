import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import CampaignReadinessPage from '../CampaignReadinessPage';
import api from '../../../services/api';

vi.mock('../../../services/api', () => ({ default: { get: vi.fn() } }));

const REPORT = {
  generated_at: '2026-10-17T18:00:00+00:00',
  environment: 'production',
  production: true,
  queue: { name: 'distribution', pending: 0, stalled: false, note: 'Campaign sends run on the `distribution` queue.' },
  channels: [
    {
      channel: 'email', label: 'Email', group: 'messaging', state: 'not_configured', provider: 'itwalk_email',
      reasons: ['Sends fail closed: nothing is marked sent.', 'EMAIL_API_KEY is not set.'],
      checks: [{ key: 'EMAIL_API_KEY', kind: 'env', ok: false, note: '' }, { key: 'SENDER_EMAIL', kind: 'env', ok: true, note: '' }],
      manual: ['SPF, DKIM and DMARC records published.'],
    },
    {
      channel: 'sms', label: 'SMS', group: 'messaging', state: 'test_mode', provider: 'aoc_portal_sms',
      reasons: ['SMS_SKIP_SEND is on.'], checks: [], manual: [], opted_in_leads: 12,
    },
    {
      channel: 'linkedin', label: 'LinkedIn', group: 'social', state: 'blocked', provider: 'socialapi',
      reasons: ['socialapi.ai adapter pending: API docs required. Nothing was posted.'], checks: [], manual: [],
    },
    {
      channel: 'google_ads', label: 'Google Ads', group: 'ads', state: 'not_supported', provider: null,
      reasons: ['No Google Ads integration exists in Reach.'], checks: [], manual: [],
    },
  ],
};

function renderPage() {
  return render(
    <MemoryRouter>
      <CampaignReadinessPage />
    </MemoryRouter>,
  );
}

describe('CampaignReadinessPage', () => {
  beforeEach(() => {
    api.get.mockReset();
  });

  it('loads readiness from the API and shows each channel state', async () => {
    api.get.mockResolvedValue(REPORT);
    renderPage();
    await waitFor(() => expect(screen.getByTestId('readiness-email')).toBeInTheDocument());
    expect(api.get).toHaveBeenCalledWith('v1/distribution/readiness');

    const email = within(screen.getByTestId('readiness-email'));
    expect(email.getByText('Not configured')).toBeInTheDocument();
    expect(email.getByText('EMAIL_API_KEY')).toBeInTheDocument();

    expect(within(screen.getByTestId('readiness-sms')).getByText('Test mode')).toBeInTheDocument();
    expect(within(screen.getByTestId('readiness-sms')).getByText(/opt-in: 12/)).toBeInTheDocument();
    expect(within(screen.getByTestId('readiness-linkedin')).getByText(/socialapi\.ai adapter pending/)).toBeInTheDocument();
    expect(within(screen.getByTestId('readiness-google_ads')).getByText('Not supported')).toBeInTheDocument();
  });

  it('never shows a channel as ready unless the API says so', async () => {
    api.get.mockResolvedValue(REPORT);
    renderPage();
    await waitFor(() => expect(screen.getByTestId('readiness-email')).toBeInTheDocument());
    expect(screen.queryByText('Ready')).not.toBeInTheDocument();
  });

  it('shows the API error instead of a status', async () => {
    api.get.mockRejectedValue(new Error('Insufficient permissions.'));
    renderPage();
    await waitFor(() => expect(screen.getByText('Insufficient permissions.')).toBeInTheDocument());
    expect(screen.queryByTestId('readiness-email')).not.toBeInTheDocument();
  });
});
