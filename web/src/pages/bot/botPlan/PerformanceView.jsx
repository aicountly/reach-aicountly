import {
  ACTION_LABELS, STATUS_META, describeChange, formatIstDate, formatIstRange, formatValue, groupBy, pctTone, statusCounts,
} from './model';

function Sparkline({ series = [] }) {
  const points = series.filter((s) => s.target !== null || s.achieved !== null);
  if (points.length < 2) return null;
  const max = Math.max(1, ...points.map((s) => Math.max(Number(s.target) || 0, Number(s.achieved) || 0)));
  const w = 100 / points.length;
  return (
    <svg className="bp-spark" viewBox="0 0 100 28" preserveAspectRatio="none" role="img"
      aria-label={`Daily achieved vs planned over ${points.length} days`}>
      {points.map((s, i) => {
        const t = ((Number(s.target) || 0) / max) * 26;
        const a = ((Number(s.achieved) || 0) / max) * 26;
        const low = s.target && s.achieved !== null && s.achieved < s.target * 0.8;
        return (
          <g key={s.date}>
            <rect className="bp-spark__target" x={i * w + w * 0.1} width={w * 0.8} y={28 - t} height={t} />
            <rect className={`bp-spark__achieved${low ? ' bp-spark__achieved--low' : ''}`} x={i * w + w * 0.25} width={w * 0.5} y={28 - a} height={a}>
              <title>{`${formatIstDate(s.date)}: ${s.achieved === null ? 'no data' : s.achieved} of ${s.target ?? '—'}`}</title>
            </rect>
          </g>
        );
      })}
    </svg>
  );
}

function StatusBadge({ status }) {
  const m = STATUS_META[status] || { label: status, tone: 'secondary' };
  return <span className={`badge badge-${m.tone}`} style={{ textTransform: 'none' }}>{m.label}</span>;
}

function MetricCard({ m }) {
  const tone = pctTone(m.achievement_pct);
  const width = m.achievement_pct === null ? 0 : Math.min(100, m.achievement_pct);
  return (
    <div className={`bp-card bp-card--${m.status}`} data-testid={`perf-${m.metric_key}`}>
      <div className="bp-card__head">
        <span className="bp-card__label">{m.label}</span>
        <StatusBadge status={m.status} />
      </div>
      <div className="bp-card__value">
        {formatValue(m.achieved, m.unit)} <small>of {formatValue(m.target, m.unit)} planned</small>
      </div>
      {m.achievement_pct !== null && (
        <>
          <div className={`bp-bar bp-bar--${tone}`} aria-hidden="true"><span style={{ width: `${width}%` }} /></div>
          <div className="bp-card__line"><span className={`bp-pct bp-pct--${tone}`}>{m.achievement_pct}%</span> achieved
            {m.streak && <> · {m.streak.type === 'lag' ? `lagging ${m.streak.days} day(s) running` : `met ${m.streak.days} day(s) running`}</>}
          </div>
        </>
      )}
      <Sparkline series={m.series} />
      {m.days_without_data > 0 && <div className="bp-muted">{m.days_without_data} day(s) not reported by the source yet.</div>}
    </div>
  );
}

/** Plan vs achieved for a past range: top lags, cards, variance, self-tune log, recommendations. */
export function PerformanceView({ data }) {
  const metrics = data.metrics || [];
  const planned = metrics.filter((m) => !['no_plan', 'nothing_planned'].includes(m.status) || (m.achieved ?? 0) > 0);
  const counts = statusCounts(metrics);
  const lagging = metrics.filter((m) => m.status === 'lagging' || m.status === 'behind');
  const log = data.self_tune?.log || [];
  const mix = data.topic_mix;

  return (
    <div>
      <div className="bp-meta">
        <span><strong>{data.range?.label}</strong> · {formatIstRange(data.range?.from, data.range?.to)} (IST)</span>
        {data.latest_review?.review_date && <span>Last review: {formatIstDate(data.latest_review.review_date)}</span>}
        <span className={`badge badge-${data.self_tune?.enabled ? 'success' : 'secondary'}`} style={{ textTransform: 'none' }}>
          Self-tuning {data.self_tune?.enabled ? 'on' : 'off'}
        </span>
      </div>
      {data.coverage?.note && <div className="alert alert-info" style={{ marginBottom: '1rem' }}>{data.coverage.note}</div>}

      <div className="bp-summary">
        <div className="bp-tile"><div className="bp-tile__label">Met</div><div className="bp-tile__value bp-pct--success">{counts.met}</div></div>
        <div className="bp-tile"><div className="bp-tile__label">Slightly behind</div><div className="bp-tile__value bp-pct--warning">{counts.behind}</div></div>
        <div className="bp-tile"><div className="bp-tile__label">Lagging</div><div className="bp-tile__value bp-pct--danger">{counts.lagging}</div></div>
        <div className="bp-tile"><div className="bp-tile__label">Days with a plan</div><div className="bp-tile__value">{data.coverage?.days_with_plan ?? 0}/{data.coverage?.days ?? 0}</div></div>
      </div>

      <section className="bp-section" aria-labelledby="bp-toplags">
        <h2 className="bp-section__title" id="bp-toplags">Top lags</h2>
        {(data.top_lags || []).length === 0 ? <div className="bp-empty">No metric is lagging in this range.</div> : (
          <div className="bp-lags">
            {data.top_lags.map((l) => (
              <div className="bp-lag" key={l.metric_key}>
                <div className="bp-lag__title">{l.label} — {l.achievement_pct}%</div>
                <div className="bp-muted">{formatValue(l.achieved)} of {formatValue(l.target)} planned</div>
                {l.cause && <div className="bp-lag__cause"><strong>{l.cause.label}:</strong> {l.cause.detail}</div>}
                {l.cause?.bot_action && <div className="bp-lag__fix">Bot: {l.cause.bot_action}</div>}
                {l.cause?.owner_action && l.cause?.fix && <div className="bp-lag__fix">Owner: {l.cause.fix}</div>}
              </div>
            ))}
          </div>
        )}
      </section>

      <section className="bp-section" aria-labelledby="bp-metrics">
        <h2 className="bp-section__title" id="bp-metrics">Per metric</h2>
        {groupBy(planned).map((g) => (
          <div key={g.group}>
            <div className="bp-group__label">{g.label}</div>
            <div className="bp-grid">{g.rows.map((m) => <MetricCard key={m.metric_key} m={m} />)}</div>
          </div>
        ))}
      </section>

      <section className="bp-section" aria-labelledby="bp-variance">
        <h2 className="bp-section__title" id="bp-variance">Variance and root causes</h2>
        {lagging.length === 0 ? <div className="bp-empty">Nothing behind plan.</div> : (
          <div className="card"><div className="bp-table-wrap">
            <table className="bp-table">
              <thead><tr><th>Metric</th><th className="num">Planned</th><th className="num">Achieved</th><th className="num">%</th><th>Status</th><th>Root causes</th></tr></thead>
              <tbody>
                {lagging.map((m) => (
                  <tr key={m.metric_key}>
                    <td>{m.label}</td>
                    <td className="num">{formatValue(m.target, m.unit)}</td>
                    <td className="num">{formatValue(m.achieved, m.unit)}</td>
                    <td className={`num bp-pct bp-pct--${pctTone(m.achievement_pct)}`}>{m.achievement_pct ?? '—'}%</td>
                    <td><StatusBadge status={m.status} /></td>
                    <td>
                      {(m.causes || []).length === 0 ? <span className="bp-muted">Within 80 % — not classified.</span> : (
                        <ul className="bp-causes">
                          {m.causes.map((c) => (
                            <li key={`${c.code}-${c.label}`}><strong>{c.label}</strong> — {c.detail}
                              {c.owner_action && c.fix && <span className="bp-muted">Owner: {c.fix}</span>}
                              {c.bot_action && <span className="bp-muted">Bot: {c.bot_action}</span>}
                            </li>
                          ))}
                        </ul>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div></div>
        )}
      </section>

      <section className="bp-section" aria-labelledby="bp-recs">
        <h2 className="bp-section__title" id="bp-recs">Needs the owner <span className="bp-section__hint">keys, switches, quotas, budgets — the bot never changes these itself</span></h2>
        {(data.recommendations || []).length === 0 ? <div className="bp-empty">Nothing needs the owner right now.</div> : (
          <ul className="bp-list">
            {data.recommendations.map((r) => (
              <li key={r.action}><span className={`badge badge-${r.severity === 'high' ? 'danger' : r.severity === 'medium' ? 'warning' : 'info'}`}>{r.severity}</span>{' '}
                {r.action} <span className="bp-muted">({r.why}: {r.metrics.join(', ')})</span></li>
            ))}
          </ul>
        )}
      </section>

      <section className="bp-section" aria-labelledby="bp-log">
        <h2 className="bp-section__title" id="bp-log">Self-tuning log <span className="bp-section__hint">every automatic adjustment, with before → after and why</span></h2>
        {log.length === 0 ? <div className="bp-empty">No automatic adjustments yet.</div> : (
          <div className="card"><div className="bp-table-wrap">
            <table className="bp-table">
              <thead><tr><th>Review day</th><th>Action</th><th>Metric</th><th>Change</th><th>Reason</th><th>Applied</th></tr></thead>
              <tbody>
                {log.map((r) => (
                  <tr key={r.id}>
                    <td>{formatIstDate(r.run_date)}</td>
                    <td>{ACTION_LABELS[r.action] || r.action}</td>
                    <td>{r.label || (r.subject_type ? `${r.subject_type} #${r.subject_id ?? ''}` : '—')}</td>
                    <td>{describeChange(r)}</td>
                    <td>{r.reason}</td>
                    <td>{r.applied ? <span className="badge badge-success">applied</span>
                      : <span className="badge badge-secondary" title={r.skipped_reason}>{r.skipped_reason === 'self_tune_disabled' ? 'not applied (off)' : 'skipped'}</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div></div>
        )}
        {data.self_tune?.bounds && (
          <p className="bp-muted" style={{ marginTop: '0.5rem' }}>
            Bounds: a target is never lowered below {Math.round(data.self_tune.bounds.min_fraction_of_base * 100)}% of its base for a chronic lag
            (only after {data.self_tune.bounds.lag_days_before_lowering} lagging days, at most once every {data.self_tune.bounds.cooldown_days} days);
            0 only while a required integration is missing; restored after {data.self_tune.bounds.restore_after_days_met} days met.
            At most {data.self_tune.bounds.max_actions_per_run} actions, {data.self_tune.bounds.max_requeue_per_run} retried drafts and {data.self_tune.bounds.max_job_retries_per_run} job retries per run.
          </p>
        )}
      </section>

      {mix && (
        <section className="bp-section" aria-labelledby="bp-mix">
          <h2 className="bp-section__title" id="bp-mix">Topic mix the routines are steered to</h2>
          <ul className="bp-list">
            <li>Blog — prefer: {(mix.blog?.prefer || []).join(', ') || 'no signal yet'}; avoid: {(mix.blog?.avoid || []).join(', ') || 'none'}</li>
            {mix.blog?.steer?.why && <li>Blog mix (target 50% app-led, about 1 in 5 of those on a Beta app) — {mix.blog.steer.why}</li>}
            <li>Help articles — products furthest behind cadence: {(mix.knowledge_base?.prefer || []).join(', ') || 'none'}</li>
          </ul>
        </section>
      )}
    </div>
  );
}
