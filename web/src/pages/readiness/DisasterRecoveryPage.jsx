import { useEffect, useState } from 'react';
import { usePermission } from '../../hooks/usePermission';
import {
  listDisasterRecovery,
  recordDisasterRecoveryTest,
} from '../../services/readinessService.js';
import { formatDateTime } from '../../utils/formatDate';

const DR_TYPES = ['backup_verify', 'restore_verify', 'rollback_verify', 'migration_verify'];

export default function DisasterRecoveryPage() {
  const { has } = usePermission();
  const canRun = has('disaster_recovery.run');

  const [status, setStatus] = useState(Object.fromEntries(DR_TYPES.map((t) => [t, 'pending'])));
  const [tests, setTests] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(null);
  const [form, setForm] = useState({
    test_type: 'backup_verify',
    environment: 'local',
    status: 'passed',
    procedure_followed: '',
    evidence_notes: '',
  });

  const load = () => {
    setLoading(true);
    setError(null);
    listDisasterRecovery()
      .then((data) => {
        setTests(Array.isArray(data?.tests) ? data.tests : []);
        setStatus(data?.status || Object.fromEntries(DR_TYPES.map((t) => [t, 'pending'])));
      })
      .catch((e) => setError(e.message || 'Failed to load DR tests'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const handleRecord = async (e) => {
    e.preventDefault();
    if (!canRun) return;
    setSaving(form.test_type);
    setError(null);
    try {
      await recordDisasterRecoveryTest(form);
      setForm((f) => ({ ...f, procedure_followed: '', evidence_notes: '' }));
      load();
    } catch (err) {
      setError(err.message || 'Failed to record DR test');
    } finally {
      setSaving(null);
    }
  };

  const badgeFor = (s) => {
    if (s === 'passed') return 'badge--success';
    if (s === 'failed') return 'badge--danger';
    if (s === 'skipped') return 'badge--neutral';
    return 'badge--muted';
  };

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Disaster Recovery</h1>
        <p className="page-header__subtitle">
          DR test evidence. All four test types must pass before release acceptance.
        </p>
      </div>

      {error && <div className="alert alert-danger mb-4">{error}</div>}

      <div className="card mb-4">
        {DR_TYPES.map((t) => (
          <div
            key={t}
            className="card__body section-header"
            style={{ borderBottom: '1px solid var(--color-border)' }}
          >
            <p className="text-sm" style={{ fontWeight: 600, margin: 0, textTransform: 'capitalize' }}>
              {t.replace(/_/g, ' ')}
            </p>
            <span className={`badge ${badgeFor(status[t])}`}>
              {loading ? '…' : (status[t] || 'pending')}
            </span>
          </div>
        ))}
      </div>

      {canRun && (
        <section className="card mb-4">
          <div className="card__header">
            <h2 className="card__title" style={{ margin: 0 }}>Record DR test</h2>
          </div>
          <div className="card__body">
            <form onSubmit={handleRecord} style={{ display: 'grid', gap: '0.75rem' }}>
              <div className="toolbar">
                <label className="toolbar__label">
                  Test type
                  <select
                    className="form-select form-select--sm"
                    value={form.test_type}
                    onChange={(e) => setForm((f) => ({ ...f, test_type: e.target.value }))}
                  >
                    {DR_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                  </select>
                </label>
                <label className="toolbar__label">
                  Environment
                  <select
                    className="form-select form-select--sm"
                    value={form.environment}
                    onChange={(e) => setForm((f) => ({ ...f, environment: e.target.value }))}
                  >
                    <option value="local">local</option>
                    <option value="staging">staging</option>
                  </select>
                </label>
                <label className="toolbar__label">
                  Status
                  <select
                    className="form-select form-select--sm"
                    value={form.status}
                    onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))}
                  >
                    <option value="passed">passed</option>
                    <option value="failed">failed</option>
                    <option value="skipped">skipped</option>
                    <option value="pending">pending</option>
                  </select>
                </label>
              </div>
              <label className="toolbar__label">
                Procedure followed
                <textarea
                  rows={2}
                  required
                  value={form.procedure_followed}
                  onChange={(e) => setForm((f) => ({ ...f, procedure_followed: e.target.value }))}
                />
              </label>
              <label className="toolbar__label">
                Evidence notes
                <textarea
                  rows={2}
                  required
                  value={form.evidence_notes}
                  onChange={(e) => setForm((f) => ({ ...f, evidence_notes: e.target.value }))}
                />
              </label>
              <div>
                <button type="submit" className="btn btn--primary btn--sm" disabled={!!saving}>
                  {saving ? 'Saving…' : 'Record test'}
                </button>
              </div>
            </form>
          </div>
        </section>
      )}

      <section className="card">
        <div className="card__header">
          <h2 className="card__title" style={{ margin: 0 }}>Evidence log</h2>
        </div>
        {tests.length === 0 ? (
          <div className="card__body">
            <p className="muted" style={{ margin: 0 }}>No DR tests recorded yet.</p>
          </div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>Env</th>
                <th>Status</th>
                <th>Tested</th>
              </tr>
            </thead>
            <tbody>
              {tests.map((t) => (
                <tr key={t.id}>
                  <td>{t.test_type}</td>
                  <td>{t.environment}</td>
                  <td><span className={`badge ${badgeFor(t.status)}`}>{t.status}</span></td>
                  <td className="text-sm text-muted">
                    {t.tested_at ? formatDateTime(t.tested_at) : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </div>
  );
}
