import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { usePermission } from '../../hooks/usePermission';
import {
  createReleaseAcceptance,
  getLatestReleaseAcceptance,
  getReleasePrerequisites,
} from '../../services/readinessService.js';

const RECOMMENDATIONS = [
  { value: 'ready_controlled', label: 'Ready for controlled production deployment' },
  { value: 'ready_with_limitations', label: 'Ready with approved limitations' },
  { value: 'not_ready', label: 'Not ready — blocking remediation required' },
];

function recommendationBadge(rec) {
  if (rec === 'ready_controlled') return 'badge--success';
  if (rec === 'ready_with_limitations') return 'badge--warning';
  return 'badge--danger';
}

export default function ReleaseAcceptancePage() {
  const { has } = usePermission();
  const canAccept = has('readiness.accept');

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [prereq, setPrereq] = useState(null);
  const [record, setRecord] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [form, setForm] = useState({
    release_name: '',
    recommendation: 'ready_controlled',
    evidence_summary: '',
    blockers_resolved: true,
    limitations_text: '',
    accepted_risks_text: '',
  });

  const load = () => {
    setLoading(true);
    setError(null);
    Promise.all([getReleasePrerequisites(), getLatestReleaseAcceptance()])
      .then(([p, r]) => {
        setPrereq(p || null);
        setRecord(r || null);
        if (p?.ready) {
          setForm((f) => ({ ...f, blockers_resolved: true }));
        }
      })
      .catch((e) => setError(e.message || 'Failed to load release acceptance'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const parseLines = (text) => text
    .split('\n')
    .map((s) => s.trim())
    .filter(Boolean);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!canAccept) return;
    setSubmitting(true);
    setError(null);
    try {
      const created = await createReleaseAcceptance({
        release_name: form.release_name.trim(),
        recommendation: form.recommendation,
        evidence_summary: form.evidence_summary.trim(),
        blockers_resolved: form.blockers_resolved,
        limitations_accepted: parseLines(form.limitations_text),
        accepted_risks: parseLines(form.accepted_risks_text),
      });
      setRecord(created);
    } catch (err) {
      setError(err.message || 'Failed to create acceptance record');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return <p className="muted">Loading release acceptance…</p>;
  }

  const checks = prereq?.checks ?? [];
  const ready = Boolean(prereq?.ready);
  const showForm = !record && canAccept;

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Release Acceptance</h1>
        <p className="page-header__subtitle">
          Final go/no-go decision record. Release acceptance may only be created when
          all critical and high findings are resolved or risk-accepted, all DR tests
          pass, and all operational readiness checks are confirmed.
        </p>
      </div>

      {error && <div className="alert alert-danger mb-4">{error}</div>}

      {record ? (
        <div className="alert alert-success mb-4">
          <strong>Accepted.</strong>{' '}
          <span className={`badge ${recommendationBadge(record.recommendation)}`}>
            {record.recommendation?.replace(/_/g, ' ')}
          </span>
          {' · '}
          {record.release_name}
          {record.accepted_at ? ` · ${new Date(record.accepted_at).toLocaleString()}` : ''}
        </div>
      ) : (
        <div className={`alert ${ready ? 'alert-success' : 'alert-warning'} mb-4`}>
          {ready ? (
            <>
              <strong>Prerequisites met.</strong> You can create a release acceptance record
              {canAccept ? '' : ' once an actor with readiness.accept permission is available'}.
            </>
          ) : (
            <>
              <strong>Not yet accepted.</strong> Complete all prerequisite checks in the
              Security, DR, Operations, and Technical Debt sections before creating an
              acceptance record.
            </>
          )}
        </div>
      )}

      <section className="card mb-4">
        <div className="card__header">
          <h2 className="card__title" style={{ margin: 0 }}>Prerequisite checklist</h2>
          <button type="button" className="btn btn--sm btn--secondary" onClick={load}>
            Refresh
          </button>
        </div>
        <div className="card__body" style={{ padding: 0 }}>
          {checks.length === 0 ? (
            <p className="muted" style={{ margin: 0, padding: '1rem 1.1rem' }}>
              No prerequisite evaluation available.
            </p>
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Gate</th>
                  <th>Status</th>
                  <th>Detail</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {checks.map((c) => (
                  <tr key={c.key}>
                    <td style={{ fontWeight: 600 }}>{c.label}</td>
                    <td>
                      <span className={`badge ${c.passed ? 'badge--success' : 'badge--warning'}`}>
                        {c.passed ? 'passed' : 'blocked'}
                      </span>
                    </td>
                    <td className="text-sm">{c.detail}</td>
                    <td>
                      {c.href ? (
                        <Link to={c.href} className="btn btn--sm btn--secondary">Open</Link>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </section>

      {record && (
        <section className="card mb-4">
          <div className="card__header">
            <h2 className="card__title" style={{ margin: 0 }}>Acceptance record</h2>
          </div>
          <div className="card__body">
            <dl className="definition-list">
              <dt>Release</dt>
              <dd>{record.release_name}</dd>
              <dt>Recommendation</dt>
              <dd>
                <span className={`badge ${recommendationBadge(record.recommendation)}`}>
                  {record.recommendation?.replace(/_/g, ' ')}
                </span>
              </dd>
              <dt>Evidence</dt>
              <dd style={{ whiteSpace: 'pre-wrap' }}>{record.evidence_summary}</dd>
              <dt>Blockers resolved</dt>
              <dd>{record.blockers_resolved ? 'Yes' : 'No'}</dd>
              <dt>Limitations</dt>
              <dd>
                {(record.limitations_accepted || []).length
                  ? (record.limitations_accepted || []).join('; ')
                  : '—'}
              </dd>
              <dt>Accepted risks</dt>
              <dd>
                {(record.accepted_risks || []).length
                  ? (record.accepted_risks || []).join('; ')
                  : '—'}
              </dd>
            </dl>
          </div>
        </section>
      )}

      {showForm && (
        <section className="card">
          <div className="card__header">
            <h2 className="card__title" style={{ margin: 0 }}>Create acceptance record</h2>
          </div>
          <div className="card__body">
            {!ready && form.recommendation !== 'not_ready' && (
              <div className="alert alert-warning mb-3">
                Ready recommendations are blocked until all gates pass. You may still record
                a <strong>not ready</strong> decision.
              </div>
            )}
            <form onSubmit={handleSubmit} style={{ display: 'grid', gap: '0.85rem' }}>
              <label className="toolbar__label">
                Release name
                <input
                  value={form.release_name}
                  onChange={(e) => setForm((f) => ({ ...f, release_name: e.target.value }))}
                  placeholder="Reach Phase 9 controlled release"
                  required
                  maxLength={100}
                />
              </label>

              <label className="toolbar__label">
                Recommendation
                <select
                  className="form-select"
                  value={form.recommendation}
                  onChange={(e) => setForm((f) => ({ ...f, recommendation: e.target.value }))}
                >
                  {RECOMMENDATIONS.map((r) => (
                    <option key={r.value} value={r.value}>{r.label}</option>
                  ))}
                </select>
              </label>

              <label className="toolbar__label">
                Evidence summary
                <textarea
                  rows={4}
                  value={form.evidence_summary}
                  onChange={(e) => setForm((f) => ({ ...f, evidence_summary: e.target.value }))}
                  placeholder="Summarize audits, DR evidence, ops confirmation, and residual risk posture."
                  required
                />
              </label>

              <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontSize: '0.85rem' }}>
                <input
                  type="checkbox"
                  checked={form.blockers_resolved}
                  onChange={(e) => setForm((f) => ({ ...f, blockers_resolved: e.target.checked }))}
                />
                Blockers resolved (required for ready recommendations)
              </label>

              <label className="toolbar__label">
                Limitations accepted (one per line)
                <textarea
                  rows={3}
                  value={form.limitations_text}
                  onChange={(e) => setForm((f) => ({ ...f, limitations_text: e.target.value }))}
                  placeholder="Required when recommending ready with limitations"
                />
              </label>

              <label className="toolbar__label">
                Accepted risks (one per line)
                <textarea
                  rows={3}
                  value={form.accepted_risks_text}
                  onChange={(e) => setForm((f) => ({ ...f, accepted_risks_text: e.target.value }))}
                  placeholder="Optional risk acceptances carried into the release record"
                />
              </label>

              <div>
                <button
                  type="submit"
                  className="btn btn--primary"
                  disabled={submitting || !form.release_name.trim() || !form.evidence_summary.trim()}
                >
                  {submitting ? 'Saving…' : 'Create acceptance record'}
                </button>
              </div>
            </form>
          </div>
        </section>
      )}

      {!record && !canAccept && (
        <div className="card">
          <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              You need the <code>readiness.accept</code> permission to create an acceptance record.
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
