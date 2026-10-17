import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../services/api';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { formatDateTime } from '../../utils/formatDate';
import { useAuth } from '../../context/AuthContext';

const OUTCOME_BADGES = {
  published: 'badge badge--success',
  approved: 'badge badge--info',
  pending: 'badge badge--warning',
  withdrawn: 'badge badge--danger',
  blocked: 'badge badge--danger',
  failed: 'badge badge--danger',
};

const OUTCOME_LABELS = {
  published: 'Published',
  approved: 'Approved — publishing',
  pending: 'Waiting for vetting',
  withdrawn: 'Not published',
  blocked: 'Blocked (prohibited topic)',
  failed: 'Failed',
};

/**
 * Community → Automated feed: what the reach-community-qa routine submitted,
 * what the vetting model decided and why, and where it went live.
 *
 * @returns {JSX.Element}
 */
export function CommunityAutomatedFeedPage() {
  const { hasPermission } = useAuth() ?? {};
  const canRetry = typeof hasPermission === 'function' && hasPermission('community_answer.publish');

  const [feed, setFeed] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');

  const load = useCallback(() => {
    setLoading(true);
    api.get('v1/community/automation/feed', { limit: 100 })
      .then((res) => { setFeed(res); setError(''); })
      .catch((err) => setError(err?.message || 'Unable to load the automated feed.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  async function retry(id) {
    try {
      const res = await api.post(`v1/community/automation/submissions/${id}/retry`, {});
      setMessage(`Submission #${id}: ${OUTCOME_LABELS[res?.outcome] ?? res?.outcome ?? 'retried'}.`);
      load();
    } catch (err) {
      setMessage(`Retry failed: ${err?.message || 'unknown error'}`);
    }
  }

  if (loading && !feed) return <Loader label="Loading automated feed…" />;

  const summary = feed?.summary ?? {};
  const items = feed?.items ?? [];

  return (
    <div>
      {error && <Alert variant="error">{error}</Alert>}
      {message && <Alert variant="info">{message}</Alert>}

      <Alert variant={summary.enabled === false ? 'warning' : 'info'}>
        Questions and answers here are written by the <code>reach-community-qa</code> Claude Routine and checked by the
        vetting model, whose decision is final — no person reviews them. Approved posts go live on aicountly.com under the
        AICOUNTLY desk, labelled AI-assisted.
        {summary.enabled === false && <> Community automation is <strong>switched off</strong> in Settings → AI &amp; Automation.</>}
      </Alert>

      <Card title="Today">
        <div className="stat-row" style={{ display: 'flex', flexWrap: 'wrap', gap: '1.5rem' }}>
          <Stat label="Submitted today" value={`${summary.used_today ?? 0} / ${summary.daily_limit ?? 0}`} />
          <Stat label="Remaining today" value={summary.remaining_today ?? 0} />
          <Stat label="Waiting for vetting" value={summary.pending ?? 0} hint={`${summary.retrying ?? 0} retrying`} />
          <Stat label="Approved, publishing" value={summary.approved ?? 0} />
          <Stat label="Published" value={summary.published ?? 0} />
          <Stat label="Not published" value={(summary.withdrawn ?? 0) + (summary.blocked ?? 0) + (summary.failed ?? 0)} />
        </div>
      </Card>

      <Card title="Recent submissions">
        <div style={{ marginBottom: '0.75rem' }}>
          <button type="button" className="btn btn--secondary" onClick={load}>Refresh</button>
        </div>
        {items.length === 0 ? (
          <p className="text-muted">No routine submissions yet.</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="data-table">
              <thead>
                <tr><th>When</th><th>Question</th><th>Desk</th><th>Outcome</th><th>Reasons</th><th>Public link</th><th></th></tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.submission_id}>
                    <td className="text-sm">{formatDateTime(item.created_at)}</td>
                    <td>
                      {item.answer_id
                        ? <Link to={`/community/answers/${item.answer_id}`}>{item.question_title}</Link>
                        : item.question_title}
                      <div className="text-muted text-sm">
                        {item.kind === 'member_answer' ? 'Member question' : 'Desk FAQ'}
                        {item.category_slug ? ` · ${item.category_slug}` : ''}
                        {item.product_slug ? ` · ${item.product_slug}` : ''}
                        {item.risk_tier !== null && item.risk_tier !== undefined ? ` · tier ${item.risk_tier}` : ''}
                      </div>
                    </td>
                    <td className="text-sm">{item.desk || '—'}</td>
                    <td>
                      <span className={OUTCOME_BADGES[item.outcome] || 'badge'}>{OUTCOME_LABELS[item.outcome] ?? item.outcome}</span>
                      {item.decision && item.decision !== item.outcome && (
                        <div className="text-muted text-sm">vetting: {item.decision}</div>
                      )}
                      {item.outcome === 'pending' && (
                        <div className="text-muted text-sm">
                          attempt {item.attempts}{item.next_retry_at ? `, next ${formatDateTime(item.next_retry_at)}` : ''}
                        </div>
                      )}
                    </td>
                    <td className="text-sm">
                      {(item.reasons ?? []).length > 0 && (
                        <ul style={{ margin: 0, paddingLeft: '1rem' }}>
                          {item.reasons.map((reason, i) => <li key={i}>{reason}</li>)}
                        </ul>
                      )}
                      {item.last_error && <div className="text-muted">{item.last_error}</div>}
                    </td>
                    <td className="text-sm">
                      {item.public_url
                        ? <a href={item.public_url} target="_blank" rel="noopener noreferrer">View live</a>
                        : '—'}
                    </td>
                    <td>
                      {canRetry && ['pending', 'approved'].includes(item.outcome) && (
                        <button type="button" className="btn btn--sm" onClick={() => retry(item.submission_id)}>Retry now</button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

/**
 * @param {{label: string, value: string|number, hint?: string}} props
 * @returns {JSX.Element}
 */
function Stat({ label, value, hint }) {
  return (
    <div>
      <div className="text-muted text-sm">{label}</div>
      <div style={{ fontSize: '1.4rem', fontWeight: 600 }}>{value}</div>
      {hint && <div className="text-muted text-sm">{hint}</div>}
    </div>
  );
}

export default CommunityAutomatedFeedPage;
