import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent, within } from '@testing-library/react';
import { Routes, Route, useLocation } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => {
  const client = { get: vi.fn(), getPage: vi.fn(), post: vi.fn() };
  return { default: client, api: client };
});
import api from '../../../services/api';
import CommunityAnalyticsPage from '../CommunityAnalyticsPage';

function Where() {
  const { search } = useLocation();
  return <output data-testid="search">{search}</output>;
}

const at = (route) => renderWithAuth(
  <Routes><Route path="*" element={<><CommunityAnalyticsPage /><Where /></>} /></Routes>,
  { route, auth: { user: { id: 1, role: 'super_admin' }, permissions: ['*'] } },
);

const ENGAGEMENT = [
  { day: '2026-10-21', event_type: 'helpful', cnt: '2' },
  { day: '2026-10-22', event_type: 'helpful', cnt: '1' },
  { day: '2026-10-22', event_type: 'page_view', cnt: '4' },
];
const COVERAGE = [{ source_type: 'kb_article', source_id: 77, source_title: 'GST rates KB', usage_count: '2' }];

const EVENTS = {
  items: [{
    id: 1, event_type: 'helpful', event_timestamp: '2026-10-22 10:00:00+00', source: 'public_site',
    answer_uuid: 'ans-1', answer_status: 'published', question_uuid: 'q-1', question_title: 'How do I file GSTR-1?',
  }],
  total: 1,
};
const GROUNDED = {
  items: [{
    answer_version_id: 9, version_number: 2, answer_uuid: 'ans-1', answer_status: 'published', question_uuid: 'q-1',
    question_title: 'How do I file GSTR-1?', claims: [{ claim: 'SaaS is taxed at 18%', coverage_status: 'covered' }],
  }],
  total: 1,
  source: { title: 'GST rates KB', url: 'https://aicountly.com/kb/gst-rates' },
};

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((path) => Promise.resolve({
    'v1/community/analytics/overview': { published_answers: 0 },
    'v1/community/analytics/engagement': ENGAGEMENT,
    'v1/community/analytics/coverage': COVERAGE,
    'v1/community/analytics/engagement/events': EVENTS,
    'v1/community/analytics/coverage/answers': GROUNDED,
  }[path]));
});

describe('Community analytics drill-down', () => {
  it('an event-type count opens its events, narrows by day, and each event opens its question and answer', async () => {
    at('/community/analytics?days=14');
    fireEvent.click(await screen.findByRole('button', { name: 'Show 3 helpful events' }));

    await waitFor(() => expect(screen.getByTestId('search')).toHaveTextContent('?days=14&event_type=helpful'));
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('v1/community/analytics/engagement/events',
      expect.objectContaining({ event_type: 'helpful', days: 14, page: 1 })));
    const panel = await screen.findByTestId('engagement-events');
    expect(await within(panel).findByRole('link', { name: 'How do I file GSTR-1?' })).toHaveAttribute('href', '/community/questions/q-1');
    expect(within(panel).getByRole('link', { name: /Answer · published/ })).toHaveAttribute('href', '/community/answers/ans-1');

    fireEvent.click(within(panel).getByRole('button', { name: 'Show 2 events on 2026-10-21' }));
    await waitFor(() => expect(api.get).toHaveBeenLastCalledWith('v1/community/analytics/engagement/events',
      expect.objectContaining({ event_type: 'helpful', day: '2026-10-21' })));
  });

  it('applies a drilled URL directly', async () => {
    at('/community/analytics?event_type=page_view&day=2026-10-22');
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('v1/community/analytics/engagement/events',
      expect.objectContaining({ event_type: 'page_view', day: '2026-10-22', days: 30 })));
    expect(await screen.findByRole('navigation', { name: 'Drill-down path' })).toHaveTextContent('Type: page view');
  });

  it('a grounding source opens the answers grounded on it', async () => {
    at('/community/analytics');
    fireEvent.click(await screen.findByRole('button', { name: 'Show 2 answers grounded on GST rates KB' }));

    await waitFor(() => expect(screen.getByTestId('search')).toHaveTextContent('?source_type=kb_article&source_id=77'));
    const panel = await screen.findByTestId('grounded-answers');
    expect(await within(panel).findByRole('link', { name: 'v2 · published' })).toHaveAttribute('href', '/community/answers/ans-1');
    expect(within(panel).getByRole('link', { name: 'How do I file GSTR-1?' })).toHaveAttribute('href', '/community/questions/q-1');
    expect(within(panel).getByText('SaaS is taxed at 18%')).toBeInTheDocument();
    expect(api.get).toHaveBeenCalledWith('v1/community/analytics/coverage/answers', expect.objectContaining({ source_type: 'kb_article', source_id: '77' }));

    fireEvent.click(within(panel).getByRole('button', { name: 'Close the grounded answers' }));
    await waitFor(() => expect(screen.queryByTestId('grounded-answers')).not.toBeInTheDocument());
  });
});
