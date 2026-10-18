import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { botPlanService } from '../../services/botPlanService';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { PLAN_PERIODS, PAST_RANGES } from './botPlan/model';
import { PlanView } from './botPlan/PlanView';
import { PerformanceView } from './botPlan/PerformanceView';
import './botPlan/botPlan.css';

const PLAN_KEYS = PLAN_PERIODS.map((p) => p.key);
const RANGE_KEYS = PAST_RANGES.map((r) => r.key);

/**
 * Bot → Plan & Performance (/bot/plan). What the bot plans for each period
 * with the expected outcome, and for past ranges what it achieved against
 * that plan, why it lagged and how it is adjusting itself. Read-only.
 * `?period=` and `?range=` keep the view linkable.
 */
export function BotPlanPage() {
  const [params, setParams] = useSearchParams();
  const period = PLAN_KEYS.includes(params.get('period')) ? params.get('period') : 'today';
  const range = RANGE_KEYS.includes(params.get('range')) ? params.get('range') : 'last_7_days';
  const [state, setState] = useState({ loading: true, error: null, data: null, key: null });

  const key = period === 'past' ? `past:${range}` : period;

  useEffect(() => {
    let live = true;
    setState((s) => ({ ...s, loading: true, error: null }));
    const req = period === 'past' ? botPlanService.performance(range) : botPlanService.plan(period);
    req
      .then((data) => { if (live) setState({ loading: false, error: null, data, key }); })
      .catch((e) => { if (live) setState({ loading: false, error: e.message || 'Could not load.', data: null, key }); });
    return () => { live = false; };
  }, [period, range, key]);

  const select = (next) => {
    const p = new URLSearchParams(params);
    p.set('period', next);
    if (next !== 'past') p.delete('range');
    setParams(p, { replace: true });
  };
  const selectRange = (next) => {
    const p = new URLSearchParams(params);
    p.set('period', 'past');
    p.set('range', next);
    setParams(p, { replace: true });
  };

  return (
    <div className="bp-page">
      <div className="page-header">
        <div>
          <h1>Bot plan &amp; performance</h1>
          <p className="text-sm text-muted">
            What the marketing bot plans to produce and expects to happen, what it actually achieved, why it lagged and what it changed on its own. Dates are IST.
          </p>
        </div>
      </div>

      <div className="bp-tabs" role="tablist" aria-label="Plan period">
        {PLAN_PERIODS.map((p) => (
          <button key={p.key} type="button" role="tab" className="bp-tab" aria-selected={period === p.key} onClick={() => select(p.key)}>
            {p.label}
          </button>
        ))}
      </div>

      {period === 'past' && (
        <div className="bp-tabs bp-tabs--sub" role="tablist" aria-label="Past range">
          {PAST_RANGES.map((r) => (
            <button key={r.key} type="button" role="tab" className="bp-tab" aria-selected={range === r.key} onClick={() => selectRange(r.key)}>
              {r.label}
            </button>
          ))}
        </div>
      )}

      {state.error && <Alert variant="danger">{state.error}</Alert>}
      {!state.error && (state.loading || state.key !== key) && <Loader label="Loading the plan…" />}
      {!state.loading && state.data && state.key === key && (period === 'past'
        ? <PerformanceView data={state.data} />
        : <PlanView data={state.data} />)}
    </div>
  );
}
