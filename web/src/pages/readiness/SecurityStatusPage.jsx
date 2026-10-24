import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { listFindingBlockers, listFindings } from '../../services/readinessService.js';
import { DrillLink, drillHref, useUrlFilter } from '../../components/drill';

const SEVERITY_BADGE = {
  critical: 'badge--danger',
  high: 'badge--warning',
  medium: 'badge--info',
  low: 'badge--neutral',
};

const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
const RESOLUTIONS = ['open', 'in_progress', 'resolved', 'accepted_risk', 'deferred'];
// Same scope as the release blockers (ReadinessFindingModel::getOpenBlockers).
const OPEN = 'open,in_progress';
const BLOCKERS = { severity: 'critical,high', resolution_status: OPEN };

const label = (v) => v.replace(/,/g, ' + ').replace(/_/g, ' ');

// reach_readiness_audit_runs.audit_type. The readiness overview's Privacy and
// AI Governance cards open this page narrowed to their audit type.
const AUDIT_TYPES = ['security', 'privacy', 'ai_governance', 'migration', 'performance', 'operational', 'dr'];
const AUDIT_TITLES = {
  privacy: ['Privacy Status', 'Findings from privacy audits (personal data controls).'],
  ai_governance: ['AI Governance', 'Findings from AI governance audits (model use, prompts, controls).'],
  migration: ['Migration Findings', 'Findings from database migration audits.'],
};

export default function SecurityStatusPage() {
  const [findings, setFindings] = useState([]);
  const [blockers, setBlockers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  // In the URL: the operations dashboard links ?severity=critical&resolution_status=open,in_progress.
  const [severity, setSeverity] = useUrlFilter('severity');
  const [resolution, setResolution] = useUrlFilter('resolution_status');
  const [auditType, setAuditType] = useUrlFilter('audit_type');

  useEffect(() => {
    setLoading(true);
    const params = {};
    if (severity) params.severity = severity;
    if (resolution) params.resolution_status = resolution;
    if (auditType) params.audit_type = auditType;
    Promise.all([listFindings(params), listFindingBlockers()])
      .then(([all, open]) => {
        setFindings(Array.isArray(all) ? all : []);
        setBlockers(Array.isArray(open) ? open : []);
      })
      .catch((e) => setError(e.message || 'Failed to load findings'))
      .finally(() => setLoading(false));
  }, [severity, resolution, auditType]);

  const filtered = Boolean(severity || resolution || auditType);
  const [title, subtitle] = AUDIT_TITLES[auditType] || ['Security Status', null];
  const blockersHref = drillHref('/readiness/security', BLOCKERS);

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>{title}</h1>
        <p className="page-header__subtitle">
          {subtitle || 'Open security findings from readiness audit runs.'} Critical and high findings
          must be resolved or risk-accepted before release.
        </p>
      </div>

      {error && <div className="alert alert-danger mb-4">{error}</div>}

      <div className="stat-grid mb-4">
        <div className={`stat-card${blockers.length ? ' stat-card--warning' : ''}`}>
          <div className="stat-card__value">
            <DrillLink to={!loading && blockers.length > 0 ? blockersHref : undefined} label={`${blockers.length} open critical/high blockers — list them`}>
              {loading ? '…' : blockers.length}
            </DrillLink>
          </div>
          <div className="stat-card__label">Open critical/high blockers</div>
          <Link to="/readiness/release" className="stat-card__link">Release acceptance →</Link>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{loading ? '…' : findings.length}</div>
          <div className="stat-card__label">{filtered ? 'Matching findings' : 'Total findings loaded'}</div>
        </div>
      </div>

      <div className="filter-bar mb-4">
        <select value={auditType} onChange={(e) => setAuditType(e.target.value)} aria-label="Audit type">
          <option value="">All audit types</option>
          {AUDIT_TYPES.map((v) => <option key={v} value={v}>{label(v)}</option>)}
          {auditType && !AUDIT_TYPES.includes(auditType) && <option value={auditType}>{label(auditType)}</option>}
        </select>
        <select value={severity} onChange={(e) => setSeverity(e.target.value)} aria-label="Severity">
          <option value="">All severities</option>
          {SEVERITIES.map((v) => <option key={v} value={v}>{v}</option>)}
          {severity && !SEVERITIES.includes(severity) && <option value={severity}>{label(severity)}</option>}
        </select>
        <select value={resolution} onChange={(e) => setResolution(e.target.value)} aria-label="Resolution status">
          <option value="">Any status</option>
          <option value={OPEN}>open or in progress</option>
          {RESOLUTIONS.map((v) => <option key={v} value={v}>{label(v)}</option>)}
          {resolution && resolution !== OPEN && !RESOLUTIONS.includes(resolution) && <option value={resolution}>{label(resolution)}</option>}
        </select>
      </div>

      <div className="card">
        {loading ? (
          <div className="card__body"><p className="muted" style={{ margin: 0 }}>Loading findings…</p></div>
        ) : findings.length === 0 ? (
          <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              {filtered
                ? 'No findings match this filter.'
                : 'No audit findings recorded yet. Empty blockers means this gate can pass for release acceptance.'}
              {auditType && ` No ${label(auditType)} audit has recorded findings.`}
            </p>
          </div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Severity</th>
                <th>Title</th>
                <th>Category</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {findings.map((f) => (
                <tr key={f.id}>
                  <td>
                    <DrillLink onClick={f.severity !== severity ? () => setSeverity(f.severity) : undefined} title="Only this severity">
                      <span className={`badge ${SEVERITY_BADGE[f.severity] ?? 'badge--neutral'}`}>
                        {f.severity}
                      </span>
                    </DrillLink>
                  </td>
                  <td className="td--primary">{f.title}</td>
                  <td>{f.category ?? '—'}</td>
                  <td>{f.resolution_status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
