import { describe, it, expect, vi, afterEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';
import QuestionInboxPage from '../QuestionInboxPage';
import QuestionWorkspacePage from '../QuestionWorkspacePage';

// A row exactly as QuestionController::index emits it, captured from the API.
// fetch is stubbed rather than the api module, so the client's envelope
// unwrapping runs too. The old mocks used fields the API never sends
// (external_id, source_received_at), which is how an Open link to
// /community/questions/undefined shipped with green tests.
const QUESTION = {
  id: '1',
  uuid: '2dffaf0e-f420-4db7-b2ec-6463f6ff508d',
  content_item_id: null,
  space_id: '3',
  source_type: 'manual',
  source_url: null,
  external_question_id: null,
  author_reference: null,
  author_display_consent: false,
  title: 'How do I claim GST input tax credit on capital goods?',
  body: '',
  language: 'en',
  product: null,
  category: 'gst',
  tags: '{}',
  jurisdiction: null,
  question_timestamp: null,
  intake_timestamp: '2026-07-10 12:00:00+00',
  sensitivity_flags: '{}',
  personal_data_detected: false,
  spam_score: '0.100',
  moderation_state: 'clean',
  duplicate_cluster_id: null,
  triage_score: '49.500',
  assigned_to: null,
  status: 'intake',
  created_at: '2026-07-10 12:00:00+00',
  updated_at: '2026-07-10 12:00:00+00',
  public_external_id: null,
  public_url: null,
  space_title: 'GST help',
  space_slug: 'gst-help',
  risk_classification: 'high',
};

const listBody = (rows) => ({
  data: rows,
  meta: { current_page: 1, per_page: 25, total: rows.length, last_page: rows.length ? 1 : 0 },
});

function respond(status, body) {
  return { ok: status >= 200 && status < 300, status, headers: { get: () => null }, json: async () => body };
}

/** Answer fetch by "METHOD v1/path"; anything unstubbed is a 404. */
function stubApi(routes) {
  const fetch = vi.fn(async (url, init = {}) => {
    const { pathname } = new URL(url, 'http://reach.test');
    const key = `${init.method ?? 'GET'} ${pathname.slice(pathname.indexOf('v1/'))}`;
    return key in routes ? respond(...routes[key]) : respond(404, { error: 'Not found' });
  });
  vi.stubGlobal('fetch', fetch);
  return fetch;
}

const requestedUrls = (fetch) => fetch.mock.calls.map(([url]) => String(url));

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['community.view'],
  },
};

afterEach(() => { vi.unstubAllGlobals(); });

describe('QuestionInboxPage', () => {
  it('shows loading state initially', () => {
    vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
    renderWithAuth(<QuestionInboxPage />, ctx);
    expect(screen.getByText(/Loading/i)).toBeInTheDocument();
  });

  it('renders "No questions" when inbox is empty', async () => {
    stubApi({ 'GET v1/community/questions': [200, listBody([])] });
    renderWithAuth(<QuestionInboxPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No questions/i)).toBeInTheDocument());
  });

  it('renders a question row from the list API', async () => {
    stubApi({ 'GET v1/community/questions': [200, listBody([QUESTION])] });
    renderWithAuth(<QuestionInboxPage />, ctx);

    const title = await screen.findByText(QUESTION.title);
    const cells = [...title.closest('tr').querySelectorAll('td')].map((td) => td.textContent);
    expect(cells.slice(0, 6)).toEqual([
      QUESTION.title,
      'gst-help',
      'intake',
      'high',
      '49.500',
      '10-07-2026',
    ]);
  });

  it('links Open to the question uuid', async () => {
    stubApi({ 'GET v1/community/questions': [200, listBody([QUESTION])] });
    renderWithAuth(<QuestionInboxPage />, ctx);

    const open = await screen.findByRole('link', { name: 'Open' });
    expect(open).toHaveAttribute('href', `/community/questions/${QUESTION.uuid}`);
  });

  it('opens the question workspace from the Open link', async () => {
    const fetch = stubApi({
      'GET v1/community/questions': [200, listBody([QUESTION])],
      [`GET v1/community/questions/${QUESTION.uuid}`]: [200, { data: QUESTION }],
      'GET v1/community/answers': [200, { data: [], meta: { total: 0 } }],
    });
    renderWithAuth(
      <Routes>
        <Route path="/community/questions" element={<QuestionInboxPage />} />
        <Route path="/community/questions/:uuid" element={<QuestionWorkspacePage />} />
      </Routes>,
      { ...ctx, route: '/community/questions' },
    );

    await userEvent.setup().click(await screen.findByRole('link', { name: 'Open' }));

    expect(await screen.findByText('Question details')).toBeInTheDocument();
    expect(screen.getByText(QUESTION.uuid)).toBeInTheDocument();
    expect(requestedUrls(fetch)).toContainEqual(expect.stringContaining(`v1/community/questions/${QUESTION.uuid}`));
    expect(requestedUrls(fetch).some((url) => url.includes('undefined'))).toBe(false);
  });

  it('filters by a real question status', async () => {
    const fetch = stubApi({ 'GET v1/community/questions': [200, listBody([QUESTION])] });
    renderWithAuth(<QuestionInboxPage />, ctx);
    await screen.findByText(QUESTION.title);

    const statusSelect = screen.getByDisplayValue('All');
    expect([...statusSelect.options].map((o) => o.value)).toEqual(
      ['', 'intake', 'triaged', 'draft_requested', 'archived', 'duplicate_merged'],
    );

    await userEvent.setup().selectOptions(statusSelect, 'intake');
    await waitFor(() => expect(requestedUrls(fetch).at(-1)).toContain('status=intake'));
  });

  it('starts filtered by the status in the URL, as the Overview tiles link it', async () => {
    const fetch = stubApi({ 'GET v1/community/questions': [200, listBody([QUESTION])] });
    renderWithAuth(<QuestionInboxPage />, { ...ctx, route: '/community/questions?status=intake' });
    await screen.findByText(QUESTION.title);

    expect(requestedUrls(fetch)[0]).toContain('status=intake');
    expect(screen.getByDisplayValue('Intake')).toBeInTheDocument();
  });

  it('sends the chosen sort to the API', async () => {
    const fetch = stubApi({ 'GET v1/community/questions': [200, listBody([QUESTION])] });
    renderWithAuth(<QuestionInboxPage />, ctx);
    await screen.findByText(QUESTION.title);

    await userEvent.setup().selectOptions(screen.getByDisplayValue('Triage score'), 'newest');
    await waitFor(() => expect(requestedUrls(fetch).at(-1)).toContain('sort=newest'));
  });

  // The client used to unwrap { data, meta } down to data, so last_page never
  // arrived and the pager never rendered, however many questions there were.
  it('pages through a list the API reports as longer than one page', async () => {
    const fetch = stubApi({
      'GET v1/community/questions': [200, { data: [QUESTION], meta: { current_page: 1, per_page: 25, total: 60, last_page: 3 } }],
    });
    renderWithAuth(<QuestionInboxPage />, ctx);

    expect(await screen.findByText('Page 1 / 3')).toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(requestedUrls(fetch).at(-1)).toContain('page=2'));
  });

  it('shows error on API failure', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('fetch failed')));
    renderWithAuth(<QuestionInboxPage />, ctx);
    await waitFor(() => expect(screen.getByText(/fetch failed/i)).toBeInTheDocument());
  });

  it('renders page heading', async () => {
    stubApi({ 'GET v1/community/questions': [200, listBody([])] });
    renderWithAuth(<QuestionInboxPage />, ctx);
    await waitFor(() => expect(screen.getByText('Question Inbox')).toBeInTheDocument());
  });
});
