import { Alert } from '../../../components/common/Alert';
import { CHECK_LABEL, STATUS_TEXT, detailEntries, formatRanAt as when } from './model';

function Details({ detail }) {
  const details = detailEntries(detail);
  if (details.length === 0) return null;
  return (
    <details>
      <summary className="text-sm text-muted">Details</summary>
      <ul className="text-sm" style={{ margin: '4px 0 0', paddingLeft: 18 }}>
        {details.map(([k, v]) => <li key={k}><strong>{k}</strong>: {v}</li>)}
      </ul>
    </details>
  );
}

/** The full "Run live checks" report, one row per integration. */
export function LiveCheckReport({ report }) {
  return (
    <>
      <Alert variant={report.ok ? 'success' : 'danger'}>
        {report.passed} passed, {report.failed} failed{report.ran_at ? ` · ${when(report.ran_at)}` : ''}
      </Alert>
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr><th>Check</th><th>Result</th><th>Reason</th></tr>
          </thead>
          <tbody>
            {(report.results ?? []).map((r) => (
              <tr key={r.target}>
                <td>{r.target}</td>
                <td><span className={`badge ${r.ok ? 'badge--success' : 'badge--danger'}`}>{r.ok ? 'OK' : 'FAIL'}</span></td>
                <td>
                  {r.message}
                  <Details detail={r.detail} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}

/**
 * The latest result of each of a section's live checks, shown inside the
 * section. `optional` checks (YouTube OAuth while videos go through
 * social-api.ai) are labelled so a failure does not read as a fault.
 */
export function SectionLiveChecks({ targets, results, pending, errors, optional = [] }) {
  const shown = targets.filter((t) => results[t] || pending[t] || errors[t]);
  if (shown.length === 0) return null;
  return (
    <ul className="as-checks" aria-label="Live check results">
      {shown.map((t) => {
        const r = results[t];
        return (
          <li key={t} className={`as-checks__item${r && !r.ok ? ' as-checks__item--fail' : ''}`} data-testid={`live-result-${t}`}>
            <div className="as-checks__head">
              <span className="font-semibold">{CHECK_LABEL[t] ?? t}</span>
              {pending[t] && <span className="badge badge--muted as-badge">checking…</span>}
              {r && <span className={`badge ${r.ok ? 'badge--success' : 'badge--danger'}`}>{r.ok ? 'OK' : 'FAIL'}</span>}
              {r && !r.ok && optional.includes(t) && <span className="badge badge--muted as-badge">only needed for direct upload</span>}
              {r?.ran_at && <span className="text-xs text-muted">{when(r.ran_at)}</span>}
            </div>
            {errors[t] && <p className="text-sm text-error">{t}: {errors[t]}</p>}
            {r && <p className="text-sm as-checks__msg">{r.message}</p>}
            {r && <Details detail={r.detail} />}
          </li>
        );
      })}
    </ul>
  );
}

export function StatusDot({ status }) {
  const title = status.reasons.length ? `${STATUS_TEXT[status.level]}: ${status.reasons.join('; ')}` : STATUS_TEXT[status.level];
  return (
    <span className={`as-dot as-dot--${status.level}`} title={title} data-status={status.level}>
      <span className="as-sr-only">{STATUS_TEXT[status.level]}</span>
    </span>
  );
}
