import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/leadsService', () => ({
  leadsService: { get: vi.fn(), push: vi.fn(), pushHistory: vi.fn(), retry: vi.fn() },
}));

import { leadsService } from '../../../services/leadsService';
import { LeadDetailPage } from '../LeadDetailPage';
import { EngagePushPage } from '../EngagePushPage';

const lead = {
  id: 41, name: 'Asha Rao', email: 'asha@example.test', mobile: '+91 98', organization: 'Rao & Co',
  source_kind: 'social_inbox', campaign_id: 7, landing_page_id: 3, ref_code: 'SLR-1',
  utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'gst-oct', landing_url: 'https://www.aicountly.com/gst',
  engage_push_status: 'failed', engage_push_attempts: 2, last_push_error: 'bad gateway', engage_stage: 'converted',
  created_at: '2026-10-20T10:00:00Z',
  related: {
    campaign: { id: 7, name: 'Diwali GST push' },
    landing_page: { id: 3, title: 'GST filing' },
    push_attempts: [
      { id: 2, attempt_number: 2, ok: false, response_status: 502, error_message: 'bad gateway', response_body: { ok: false }, attempted_at: '2026-10-20T10:05:00Z' },
      { id: 1, attempt_number: 1, ok: true, response_status: 201, error_message: null, response_body: null, attempted_at: '2026-10-20T10:01:00Z' },
    ],
    outcomes: [{ id: 9, event_id: 'ev-1', stage: 'converted', value_inr: '1500.00', occurred_at: '2026-10-21T10:00:00Z',
      campaign_id: 7, campaign_name: 'Diwali GST push', attribution_method: 'lead_campaign', match_method: 'reach_lead_id', seller_code: 'SLR-1' }],
    attribution: {
      conversions: [{ id: 5, conversion_type: 'lead', attributed: true, first_touch_channel: 'social', last_touch_channel: 'search',
        matching_method: 'last_touch', confidence_state: 'inferred', converted_at: '2026-10-20T10:00:00Z' }],
      touchpoints: [
        { id: 1, channel: 'social', touchpoint_type: 'click', utm_source: 'linkedin', touched_at: '2026-10-18T10:00:00Z' },
        { id: 2, channel: 'search', touchpoint_type: 'visit', touched_at: '2026-10-19T10:00:00Z', campaign_id: 7 },
      ],
    },
    social_inbox: [{ id: 3, network: 'instagram', kind: 'comment', author_handle: '@asha', text: 'Price for GST filing?', status: 'lead_created' }],
    audit: [{ id: 8, action: 'lead.create', user_name: 'Admin', new_value: { name: 'Asha Rao' }, created_at: '2026-10-20T10:00:00Z' }],
  },
};

const renderDetail = (id = '41') => renderWithAuth(
  <Routes><Route path="/leads/:id" element={<LeadDetailPage />} /></Routes>,
  { route: `/leads/${id}` },
);

beforeEach(() => {
  vi.clearAllMocks();
  leadsService.get.mockResolvedValue(lead);
});

describe('LeadDetailPage', () => {
  it('loads the lead named in the URL and shows its fields with drills', async () => {
    renderDetail();
    expect(await screen.findByRole('heading', { name: 'Asha Rao' })).toBeInTheDocument();
    expect(leadsService.get).toHaveBeenCalledWith('41');
    screen.getAllByRole('link', { name: 'Diwali GST push' }).forEach((a) => expect(a).toHaveAttribute('href', '/campaigns/7'));
    expect(screen.getByRole('link', { name: 'GST filing' })).toHaveAttribute('href', '/landing/3');
    expect(screen.getByRole('link', { name: 'social_inbox' })).toHaveAttribute('href', '/leads?source_kind=social_inbox');
    expect(screen.getByRole('link', { name: 'google' })).toHaveAttribute('href', '/leads?utm_source=google');
    screen.getAllByRole('link', { name: 'SLR-1' }).forEach((a) => expect(a).toHaveAttribute('href', '/leads/outcomes?days=all&seller_code=SLR-1'));
    expect(screen.getByRole('link', { name: /aicountly\.com\/gst/ })).toHaveAttribute('href', 'https://www.aicountly.com/gst');
    expect(screen.getByRole('link', { name: 'failed' })).toHaveAttribute('href', '/leads/engage-push?status=failed');
  });

  it('lists push attempts, outcomes, attribution, social origin and audit', async () => {
    renderDetail();
    expect(await screen.findByText('Push attempts (2)')).toBeInTheDocument();
    expect(screen.getByText('502')).toBeInTheDocument();
    expect(screen.getByText('Engage outcomes (1)')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Open in Outcomes/ })).toHaveAttribute('href', '/leads/outcomes?days=all&lead_id=41');
    expect(screen.getByText('Attribution conversions (1)')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'social' })).toHaveAttribute('href', '/intelligence/attribution/conversions?attributed=1&channel=social');
    expect(screen.getByRole('link', { name: 'last touch' })).toHaveAttribute('href', '/intelligence/attribution/conversions?model=last_touch');
    expect(screen.getByText('Touchpoint journey (2)')).toBeInTheDocument();
    expect(screen.getByText('Price for GST filing?')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /In the inbox/ })).toHaveAttribute('href', '/social/inbox?has=lead&network=instagram&search=%40asha');
    expect(screen.getByText('lead.create')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Push to Engage' })).toBeInTheDocument();
  });

  it('says so when a section has no records', async () => {
    leadsService.get.mockResolvedValue({ id: 5, name: 'Bare', engage_push_status: 'pushed', related: {
      campaign: null, landing_page: null, push_attempts: [], outcomes: [], attribution: { conversions: [], touchpoints: [] }, social_inbox: [], audit: [],
    } });
    renderDetail('5');
    expect(await screen.findByText('No push attempts recorded for this lead.')).toBeInTheDocument();
    expect(screen.getByText('Engage has not reported any outcome for this lead.')).toBeInTheDocument();
    expect(screen.getByText('No attribution conversion is linked to this lead.')).toBeInTheDocument();
    expect(screen.getByText('This lead did not come from the social inbox.')).toBeInTheDocument();
    expect(screen.getByText('No audit entries for this lead.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Push to Engage' })).not.toBeInTheDocument();
  });

  it('shows the API error for a missing lead', async () => {
    leadsService.get.mockRejectedValue(new Error('Lead not found.'));
    renderDetail('999');
    expect(await screen.findByText('Lead not found.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /All leads/ })).toHaveAttribute('href', '/leads');
  });
});

describe('Engage push rows', () => {
  it('open the lead detail page', async () => {
    leadsService.pushHistory.mockResolvedValue({ items: [{ id: 41, name: 'Asha Rao', engage_push_status: 'failed' }] });
    renderWithAuth(<EngagePushPage />, { route: '/leads/engage-push' });
    const link = await screen.findByRole('link', { name: '#41 · Asha Rao' });
    expect(link).toHaveAttribute('href', '/leads/41');
    expect(within(link.closest('tr')).getByText('failed')).toBeInTheDocument();
  });
});
