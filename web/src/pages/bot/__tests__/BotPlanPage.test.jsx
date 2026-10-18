import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, within, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

const plan = vi.fn();
const performance = vi.fn();
vi.mock('../../../services/botPlanService', () => ({
  botPlanService: {
    plan: (...a) => plan(...a),
    performance: (...a) => performance(...a),
  },
}));

import { BotPlanPage } from '../BotPlanPage';
import { describeChange, formatIstDate, formatIstDateTime, formatValue, pct, pctTone, splitPlanItems } from '../botPlan/model';

const planData = {
  period: { key: 'today', label: 'Today', type: 'day', start: '2026-10-18', end: '2026-10-18', days: 1, elapsed_days: 1, partial_today: true },
  plan: { stored: true, version: 2, generated_at: '2026-10-17T19:30:00+00:00', last_checked_at: '2026-10-18T08:30:00+00:00' },
  versions: [{ version: 2 }, { version: 1 }],
  self_tune_enabled: true,
  items: [
    { metric_key: 'content.blog.published', label: 'Blog posts published', kind: 'output', metric_group: 'content', unit: 'count', status: 'target',
      target_value: 6, base_target: 6, source: 'config', method: 'Daily volume setting, capped by routine capacity.', confidence: 'high',
      rationale: 'automation.blog.daily_limit = 6; the reach-blog routine fires 12× a day.', achieved_to_date: 2, expected_to_date: 6, pct_of_expected: 33, adjusted: false },
    { metric_key: 'video.vlog.published', label: 'Vlogs published', kind: 'output', metric_group: 'video', unit: 'count', status: 'target',
      target_value: 0, base_target: 1, source: 'self_tune', method: 'x', confidence: 'high', rationale: 'Lowered by the self-tuner from 1 to 0 a day: Shotstack API key not configured.',
      achieved_to_date: 0, expected_to_date: 0, pct_of_expected: null, adjusted: true },
    { metric_key: 'content.workflow_guide.published', label: 'Workflow guides published', kind: 'output', metric_group: 'content', unit: 'count', status: 'off',
      target_value: 0, source: 'config', method: 'Automation switched off.', confidence: 'none', rationale: 'automation.workflow_guide.enabled is off.' },
    { metric_key: 'search.clicks', label: 'Search clicks', kind: 'outcome', metric_group: 'search', unit: 'clicks', status: 'target',
      target_value: 120, low_value: 90, high_value: 150, source: 'trend', confidence: 'medium', method: '28-day trailing mean 120/day, trend +0.5/day', rationale: 'Expected from history.' },
    { metric_key: 'leads.captured', label: 'Leads captured', kind: 'outcome', metric_group: 'leads', unit: 'leads', status: 'baseline',
      target_value: null, source: 'trend', confidence: 'none', method: 'Baseline being established: 3 of 14 days of history needed for a forecast.', rationale: 'Baseline being established.' },
  ],
  scheduled: { social_posts: 3, email_dispatches: 1, email_recipients: 500, calendar_entries: 0, content_schedules: 0, topic_queue: { available: 4, roadmap_selected: 1 }, campaigns: [], items: [] },
  overrides: { 'video.vlog.published': { target_value: 0, base_value: 1, cause: 'integration_missing', reason: 'Shotstack API key not configured.' } },
};

const perfData = {
  range: { key: 'last_7_days', label: 'Last 7 days', from: '2026-10-11', to: '2026-10-17', days: 7 },
  coverage: { days: 7, days_with_plan: 5, note: '2 of 7 day(s) in this range have no recorded plan.' },
  latest_review: { review_date: '2026-10-17' },
  metrics: [
    { metric_key: 'content.blog.published', label: 'Blog posts published', group: 'content', kind: 'output', unit: 'count', target: 30, achieved: 12, achievement_pct: 40,
      status: 'lagging', streak: { type: 'lag', days: 4 }, days_without_data: 0,
      series: [{ date: '2026-10-16', target: 6, achieved: 2 }, { date: '2026-10-17', target: 6, achieved: 1 }],
      causes: [{ code: 'vetting_rejections', label: 'Vetting rejections', detail: 'Gemini rejected 5 of 8 submission(s): Unverified GST rate (×3).', owner_action: false, bot_action: 'The routine briefing lists these reasons first.' },
        { code: 'integration_missing', label: 'OpenAI API key not configured', detail: 'OpenAI API key is not set.', owner_action: true, fix: 'Settings → AI & Automation → AI providers → OpenAI API key.' }] },
    { metric_key: 'seo.checks_run', label: 'SEO keyword checks run', group: 'seo', kind: 'output', unit: 'checks', target: 175, achieved: 175, achievement_pct: 100, status: 'met', streak: { type: 'hit', days: 5 }, series: [], causes: [] },
  ],
  top_lags: [{ metric_key: 'content.blog.published', label: 'Blog posts published', target: 30, achieved: 12, achievement_pct: 40,
    cause: { code: 'vetting_rejections', label: 'Vetting rejections', detail: 'Gemini rejected 5 of 8.', bot_action: 'The routine briefing lists these reasons first.' } }],
  recommendations: [{ action: 'Settings → AI & Automation → AI providers → OpenAI API key.', why: 'OpenAI API key not configured', metrics: ['AI covers generated'], severity: 'high' }],
  self_tune: {
    enabled: true,
    bounds: { min_fraction_of_base: 0.5, lag_days_before_lowering: 7, structural_days: 3, restore_after_days_met: 3, cooldown_days: 7, max_actions_per_run: 25, max_requeue_per_run: 10, max_job_retries_per_run: 5 },
    log: [{ id: 9, run_date: '2026-10-17', action: 'lower_target', metric_key: 'video.vlog.published', label: 'Vlogs published', before: { target_per_day: 1 }, after: { target_per_day: 0 },
      reason: 'Shotstack API key not configured — structurally unreachable until configured.', applied: true, skipped_reason: null }],
  },
  topic_mix: { blog: { prefer: ['GST'], avoid: ['Company Law'] }, knowledge_base: { prefer: ['books'] } },
};

describe('BotPlanPage', () => {
  beforeEach(() => { plan.mockReset(); performance.mockReset(); });

  it('shows today\'s plan with sources, achieved so far, baseline outcomes and the lowered target', async () => {
    plan.mockResolvedValue(planData);
    renderWithAuth(<BotPlanPage />, { auth: { user: { id: 1 }, permissions: ['bot.view'] }, route: '/bot/plan' });

    const blog = await screen.findByTestId('plan-content.blog.published');
    expect(plan).toHaveBeenCalledWith('today');
    expect(within(blog).getByText('From settings')).toBeInTheDocument();
    expect(within(blog).getByText('33%')).toBeInTheDocument();
    expect(within(blog).getByText(/daily_limit = 6/)).toBeInTheDocument();

    expect(within(screen.getByTestId('plan-leads.captured')).getByText('Baseline being established')).toBeInTheDocument();
    expect(within(screen.getByTestId('plan-search.clicks')).getByText(/Confidence/)).toHaveTextContent('medium');
    expect(within(screen.getByTestId('plan-video.vlog.published')).getByText('Self-tuned')).toBeInTheDocument();
    expect(within(screen.getByTestId('plan-content.workflow_guide.published')).getByText('Off')).toBeInTheDocument();
    expect(screen.getByText('Targets the bot lowered')).toBeInTheDocument();
    expect(screen.getByText(/Plan v2/)).toHaveTextContent('IST');
    expect(screen.getByRole('tab', { name: 'Today' })).toHaveAttribute('aria-selected', 'true');
  });

  it('switches period and shows past performance with causes, the self-tune log and owner actions', async () => {
    plan.mockResolvedValue(planData);
    performance.mockResolvedValue(perfData);
    const user = userEvent.setup();
    renderWithAuth(<BotPlanPage />, { auth: { user: { id: 1 }, permissions: ['bot.view'] }, route: '/bot/plan' });
    await screen.findByTestId('plan-content.blog.published');

    await user.click(screen.getByRole('tab', { name: 'Next week' }));
    await waitFor(() => expect(plan).toHaveBeenLastCalledWith('next_week'));

    await user.click(screen.getByRole('tab', { name: 'Past' }));
    await waitFor(() => expect(performance).toHaveBeenCalledWith('last_7_days'));
    const card = await screen.findByTestId('perf-content.blog.published');
    expect(within(card).getByText('Lagging')).toBeInTheDocument();
    expect(within(card).getByText(/lagging 4 day\(s\) running/)).toBeInTheDocument();
    expect(screen.getByText('Top lags')).toBeInTheDocument();
    expect(screen.getAllByText(/Unverified GST rate/).length).toBeGreaterThan(0);
    expect(screen.getByText('Lowered target')).toBeInTheDocument();
    expect(screen.getByText('1 → 0 a day')).toBeInTheDocument();
    expect(screen.getByText(/2 of 7 day\(s\)/)).toBeInTheDocument();
    expect(screen.getByText(/never lowered below 50%/)).toBeInTheDocument();
    expect(screen.getAllByText(/OpenAI API key\./).length).toBeGreaterThan(0);

    await user.click(screen.getByRole('tab', { name: 'Last month' }));
    await waitFor(() => expect(performance).toHaveBeenLastCalledWith('last_month'));
  });

  it('opens on the range in the URL and reports load errors honestly', async () => {
    performance.mockRejectedValue(new Error('Insufficient permissions.'));
    renderWithAuth(<BotPlanPage />, { auth: { user: { id: 1 }, permissions: [] }, route: '/bot/plan?period=past&range=yesterday' });
    expect(await screen.findByText('Insufficient permissions.')).toBeInTheDocument();
    expect(performance).toHaveBeenCalledWith('yesterday');
  });
});

describe('botPlan model', () => {
  it('formats IST dates without shifting calendar days', () => {
    expect(formatIstDate('2026-10-18')).toBe('18 Oct 2026');
    // 19:30 UTC on the 17th is 01:00 IST on the 18th.
    expect(formatIstDateTime('2026-10-17T19:30:00+00:00')).toMatch(/18 Oct 2026.*01:00 IST/);
    expect(formatIstDate(null)).toBe('—');
  });

  it('formats values, percentages and tones', () => {
    expect(formatValue(null)).toBe('—');
    expect(formatValue(1234)).toBe('1,234');
    expect(formatValue(4999, 'inr')).toMatch(/₹\s?4,999/);
    expect(pct(3, 6)).toBe(50);
    expect(pct(3, 0)).toBeNull();
    expect([pctTone(120), pctTone(85), pctTone(10), pctTone(null)]).toEqual(['success', 'warning', 'danger', 'secondary']);
  });

  it('splits outputs from outcomes and describes self-tune changes', () => {
    const { outputs, outcomes } = splitPlanItems(planData.items);
    expect(outputs.map((g) => g.group)).toEqual(['content', 'video']);
    expect(outcomes.map((o) => o.metric_key)).toEqual(['search.clicks', 'leads.captured']);
    expect(describeChange({ action: 'retry_job', before: { job_type: 'reach.seo_snapshot', status: 'dead_letter' }, after: { status: 'pending' } }))
      .toBe('reach.seo_snapshot: dead_letter → pending');
    expect(describeChange({ action: 'topic_mix', before: {}, after: { blog_prefer: ['GST'], blog_avoid: [], kb_prefer: ['books'] } }))
      .toBe('blog prefer: GST; avoid: none; KB prefer: books');
  });
});
