import { describe, it, expect, vi, afterEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Routes, Route, useParams } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';
import QuestionWorkspacePage from '../QuestionWorkspacePage';

// Bodies exactly as QuestionController::show and OfficialAnswerController
// emit them, captured from the API. fetch is stubbed rather than the api
// module, so the client's envelope unwrapping runs too.
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
  body: 'Bought a delivery van for the business in June.',
  language: 'en',
  product: null,
  category: 'gst',
  tags: '{}',
  jurisdiction: null,
  question_timestamp: null,
  intake_timestamp: '2026-07-10 12:00:00+00',
  sensitivity_flags: '{}',
  personal_data_detected: false,
  spam_score: '0.000',
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

const ANSWER = {
  id: '1',
  uuid: '1ddd1811-fc35-4469-bf08-7ce41076f510',
  question_id: '1',
  identity_id: '7',
  current_version: '0',
  approved_version: null,
  approved_version_checksum: null,
  public_external_id: null,
  public_url: null,
  publication_status: 'unpublished',
  ai_assisted: false,
  human_reviewed: false,
  risk_classification: 'high',
  jurisdiction: null,
  product: null,
  language: 'en',
  correction_state: 'none',
  correction_note: null,
  withdrawal_state: 'none',
  status: 'draft_requested',
  created_at: '2026-07-10 12:05:00+00',
  updated_at: '2026-07-10 12:05:00+00',
  risk_tier: '2',
  scheduled_at: null,
  verification_provider: null,
  verified_at: null,
  applicability_date: null,
  freshness_deadline: null,
  reverification_state: 'not_required',
  style_profile_id: null,
  operational_role: null,
  revision_number: '1',
  public_correction_note: null,
};

const SHOW = `GET v1/community/questions/${QUESTION.uuid}`;
const ANSWERS = 'GET v1/community/answers';

function respond(status, body) {
  return { ok: status >= 200 && status < 300, status, headers: { get: () => null }, json: async () => body };
}

/**
 * Answer fetch by "METHOD v1/path". A route is a [status, body] pair or a
 * function of the parsed request body; anything unstubbed is a 404.
 */
function stubApi(routes) {
  const fetch = vi.fn(async (url, init = {}) => {
    const { pathname } = new URL(url, 'http://reach.test');
    const route = routes[`${init.method ?? 'GET'} ${pathname.slice(pathname.indexOf('v1/'))}`];
    if (!route) return respond(404, { error: 'Not found' });
    return respond(...(typeof route === 'function' ? route(init.body ? JSON.parse(init.body) : null) : route));
  });
  vi.stubGlobal('fetch', fetch);
  return fetch;
}

const callsTo = (fetch, fragment) => fetch.mock.calls.filter(([url]) => String(url).includes(fragment));

function AnswerEditorStub() {
  const { uuid } = useParams();
  return <p>Answer editor for {uuid}</p>;
}

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['community.view', 'community_question.edit', 'community_answer.generate'],
  },
};

function renderWorkspace(uuid = QUESTION.uuid) {
  return renderWithAuth(
    <Routes>
      <Route path="/community/questions/:uuid" element={<QuestionWorkspacePage />} />
      <Route path="/community/answers/:uuid" element={<AnswerEditorStub />} />
    </Routes>,
    { ...ctx, route: `/community/questions/${uuid}` },
  );
}

const detail = (label) => screen.getByText(label, { selector: 'dt' }).nextElementSibling.textContent;

afterEach(() => { vi.unstubAllGlobals(); });

describe('QuestionWorkspacePage', () => {
  it('shows loading state initially', () => {
    vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
    renderWorkspace();
    expect(screen.getByText(/Loading question/i)).toBeInTheDocument();
  });

  it('loads the question named in the route and renders its details', async () => {
    const fetch = stubApi({ [SHOW]: [200, { data: QUESTION }], [ANSWERS]: [200, { data: [], meta: { total: 0 } }] });
    renderWorkspace();

    expect(await screen.findByRole('heading', { name: QUESTION.title })).toBeInTheDocument();
    expect(callsTo(fetch, `v1/community/questions/${QUESTION.uuid}`)).toHaveLength(1);
    expect(detail('UUID')).toBe(QUESTION.uuid);
    expect(detail('Space')).toBe('gst-help');
    expect(detail('Source')).toBe('manual');
    expect(detail('Risk')).toBe('high');
    expect(detail('Triage score')).toBe('49.500');
    // 12:00 UTC is the 10th in any zone within ±11h; the time is local.
    expect(detail('Received')).toMatch(/^10-07-2026 \d{2}:\d{2}$/);
    expect(screen.getByText(QUESTION.body)).toBeInTheDocument();
  });

  it("lists this question's answer, linked by the answer uuid", async () => {
    const fetch = stubApi({
      [SHOW]: [200, { data: { ...QUESTION, status: 'draft_requested' } }],
      [ANSWERS]: [200, { data: [ANSWER], meta: { total: 1 } }],
    });
    renderWorkspace();

    expect(await screen.findByText('Official answers (1)')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: ANSWER.uuid })).toHaveAttribute('href', `/community/answers/${ANSWER.uuid}`);
    expect(callsTo(fetch, `v1/community/answers?question_uuid=${QUESTION.uuid}`)).toHaveLength(1);
    // The API refuses a second official answer for a question.
    expect(screen.queryByRole('button', { name: /Create official answer/ })).not.toBeInTheDocument();
  });

  it('offers only the status moves the API accepts from the current status', async () => {
    stubApi({ [SHOW]: [200, { data: QUESTION }], [ANSWERS]: [200, { data: [], meta: { total: 0 } }] });
    renderWorkspace();

    const select = await screen.findByRole('combobox');
    expect([...select.options].map((o) => o.value)).toEqual(['', 'triaged', 'archived']);
  });

  it('has no status form for a status with no manual move', async () => {
    stubApi({
      [SHOW]: [200, { data: { ...QUESTION, status: 'duplicate_merged' } }],
      [ANSWERS]: [200, { data: [], meta: { total: 0 } }],
    });
    renderWorkspace();

    expect(await screen.findByText('No manual status change from duplicate_merged.')).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('sends the status change with its note, then reloads the question', async () => {
    let status = 'intake';
    const put = vi.fn((body) => { status = body.status; return [200, { success: true }]; });
    stubApi({
      [SHOW]: () => [200, { data: { ...QUESTION, status } }],
      [ANSWERS]: [200, { data: [], meta: { total: 0 } }],
      [`PUT v1/community/questions/${QUESTION.uuid}/status`]: put,
    });
    renderWorkspace();
    const user = userEvent.setup();

    await user.selectOptions(await screen.findByRole('combobox'), 'triaged');
    await user.type(screen.getByPlaceholderText('Note (optional)'), 'Checked by the GST desk');
    await user.click(screen.getByRole('button', { name: 'Update' }));

    expect(put).toHaveBeenCalledWith({ status: 'triaged', note: 'Checked by the GST desk' });
    await waitFor(() => expect([...screen.getByRole('combobox').options].map((o) => o.value)).toEqual(['', 'archived']));
    expect(screen.getByText('triaged', { selector: '.badge' })).toBeInTheDocument();
  });

  it('creates an official answer and opens it by its uuid', async () => {
    const post = vi.fn(() => [201, { data: { ...ANSWER, question_uuid: QUESTION.uuid } }]);
    stubApi({
      [SHOW]: [200, { data: QUESTION }],
      [ANSWERS]: [200, { data: [], meta: { total: 0 } }],
      'POST v1/community/answers': post,
    });
    renderWorkspace();

    await userEvent.setup().click(await screen.findByRole('button', { name: '+ Create official answer' }));

    expect(post).toHaveBeenCalledWith({ question_uuid: QUESTION.uuid });
    expect(await screen.findByText(`Answer editor for ${ANSWER.uuid}`)).toBeInTheDocument();
  });

  it('shows the API error for a question that does not exist', async () => {
    stubApi({ [ANSWERS]: [200, { data: [], meta: { total: 0 } }] });
    renderWorkspace('undefined');

    expect(await screen.findByText('Not found')).toBeInTheDocument();
  });
});
