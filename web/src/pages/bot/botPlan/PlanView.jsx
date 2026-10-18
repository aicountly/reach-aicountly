import {
  SOURCE_LABELS, formatIstDate, formatIstDateTime, formatIstRange, formatValue, pctTone, splitPlanItems,
} from './model';

function Badge({ tone = 'secondary', children, title }) {
  return <span className={`badge badge-${tone}`} title={title} style={{ textTransform: 'none' }}>{children}</span>;
}

function PlanCard({ item, started }) {
  const off = item.status === 'off';
  const baseline = item.status === 'baseline';
  const p = item.pct_of_expected;
  return (
    <div className={`bp-card${off ? ' bp-card--off' : ''}`} data-testid={`plan-${item.metric_key}`}>
      <div className="bp-card__head">
        <span className="bp-card__label">{item.label}</span>
        <Badge tone={item.adjusted ? 'warning' : item.source === 'trend' ? 'info' : 'secondary'} title={item.method}>
          {SOURCE_LABELS[item.source] || item.source}
        </Badge>
      </div>
      {off && <div className="bp-card__value">Off</div>}
      {baseline && <div className="bp-card__value" style={{ fontSize: '0.95rem' }}>Baseline being established</div>}
      {!off && !baseline && (
        <div className="bp-card__value">
          {formatValue(item.target_value, item.unit)}
          {item.low_value !== null && item.low_value !== undefined && item.high_value !== null && item.high_value !== undefined && (
            <small> ({formatValue(item.low_value, item.unit)}–{formatValue(item.high_value, item.unit)})</small>
          )}
          {item.adjusted && item.base_target !== null && <small> base {formatValue(item.base_target, item.unit)}</small>}
        </div>
      )}
      {started && !off && (
        <div className="bp-card__line">
          Achieved so far <strong>{formatValue(item.achieved_to_date, item.unit)}</strong>
          {item.expected_to_date !== null && item.expected_to_date !== undefined && <> of {formatValue(item.expected_to_date, item.unit)} expected by now</>}
          {p !== null && p !== undefined && <> · <span className={`bp-pct bp-pct--${pctTone(p)}`}>{p}%</span></>}
        </div>
      )}
      {item.kind === 'outcome' && !off && (
        <div className="bp-card__line">Confidence: <strong>{item.confidence === 'none' ? 'not enough history' : item.confidence}</strong></div>
      )}
      <details className="bp-card__why">
        <summary>Why this number</summary>
        <p>{item.rationale}</p>
        {item.method && <p><em>Method:</em> {item.method}</p>}
        {item.actuals_note && <p>{item.actuals_note}</p>}
      </details>
    </div>
  );
}

/** One plan period: planned output, expected outcomes, what is scheduled. */
export function PlanView({ data }) {
  const { period, plan, items = [], scheduled = {}, overrides = {}, versions = [] } = data;
  const started = (period?.elapsed_days ?? 0) > 0;
  const { outputs, outcomes } = splitPlanItems(items);
  const overrideRows = Object.entries(overrides || {});

  return (
    <div>
      <div className="bp-meta">
        <span><strong>{period?.label}</strong> · {formatIstRange(period?.start, period?.end)} (IST)</span>
        {plan?.stored
          ? <span>Plan v{plan.version} · generated {formatIstDateTime(plan.generated_at)} · last checked {formatIstDateTime(plan.last_checked_at)}</span>
          : <span>{plan?.note || 'Preview — not stored yet.'}</span>}
        {versions.length > 1 && <span>{versions.length} versions kept</span>}
        <Badge tone={data.self_tune_enabled ? 'success' : 'secondary'}>Self-tuning {data.self_tune_enabled ? 'on' : 'off'}</Badge>
        {period?.partial_today && <span>Today is still in progress.</span>}
      </div>

      <section className="bp-section" aria-labelledby="bp-planned">
        <h2 className="bp-section__title" id="bp-planned">Planned output</h2>
        {outputs.map((g) => (
          <div key={g.group}>
            <div className="bp-group__label">{g.label}</div>
            <div className="bp-grid">
              {g.rows.map((it) => <PlanCard key={it.metric_key} item={it} started={started} />)}
            </div>
          </div>
        ))}
      </section>

      <section className="bp-section" aria-labelledby="bp-outcomes">
        <h2 className="bp-section__title" id="bp-outcomes">
          Expected outcomes <span className="bp-section__hint">forecast from each metric&apos;s own history — no number until there is enough</span>
        </h2>
        <div className="bp-grid">
          {outcomes.map((it) => <PlanCard key={it.metric_key} item={it} started={started} />)}
        </div>
      </section>

      <section className="bp-section" aria-labelledby="bp-scheduled">
        <h2 className="bp-section__title" id="bp-scheduled">Scheduled in this period</h2>
        <div className="bp-summary">
          <div className="bp-tile"><div className="bp-tile__label">Social posts</div><div className="bp-tile__value">{scheduled.social_posts ?? 0}</div></div>
          <div className="bp-tile"><div className="bp-tile__label">Email sends</div><div className="bp-tile__value">{scheduled.email_dispatches ?? 0}</div>
            <div className="bp-muted">{formatValue(scheduled.email_recipients ?? 0)} recipients</div></div>
          <div className="bp-tile"><div className="bp-tile__label">Calendar entries</div><div className="bp-tile__value">{scheduled.calendar_entries ?? 0}</div></div>
          <div className="bp-tile"><div className="bp-tile__label">Content schedules</div><div className="bp-tile__value">{scheduled.content_schedules ?? 0}</div></div>
          <div className="bp-tile"><div className="bp-tile__label">Topic queue</div><div className="bp-tile__value">{scheduled.topic_queue ? scheduled.topic_queue.available : '—'}</div>
            {scheduled.topic_queue && <div className="bp-muted">{scheduled.topic_queue.roadmap_selected} roadmap-selected</div>}</div>
        </div>
        {(scheduled.campaigns || []).length > 0 && (
          <ul className="bp-list">
            {scheduled.campaigns.map((c) => (
              <li key={c.id}>Campaign <strong>{c.name}</strong> · {formatIstRange(c.start, c.end || c.start)} · {c.status}</li>
            ))}
          </ul>
        )}
        {(scheduled.items || []).length > 0 ? (
          <ul className="bp-list">
            {scheduled.items.slice(0, 15).map((it, i) => (
              <li key={`${it.kind}-${it.id ?? i}`}>{formatIstDate(it.at)} · {it.kind === 'social' ? it.channel : it.item_kind} · {it.title}</li>
            ))}
          </ul>
        ) : <div className="bp-empty">Nothing individually scheduled — volumes above come from the routines&apos; daily settings.</div>}
      </section>

      {overrideRows.length > 0 && (
        <section className="bp-section" aria-labelledby="bp-overrides">
          <h2 className="bp-section__title" id="bp-overrides">Targets the bot lowered</h2>
          <ul className="bp-list">
            {overrideRows.map(([k, o]) => (
              <li key={k}><strong>{items.find((i) => i.metric_key === k)?.label || k}</strong>: {formatValue(o.base_value)} → {formatValue(o.target_value)} a day — {o.reason}</li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
