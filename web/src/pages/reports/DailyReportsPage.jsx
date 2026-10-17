import { useCallback, useEffect, useState } from 'react';
import { FileBarChart, RefreshCw, Eye, Send } from 'lucide-react';
import { reportService } from '../../services/reportService';
import { useAuth } from '../../context/AuthContext';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { Loader } from '../../components/common/Loader';
import { formatTime } from '../../utils/formatDate';

const STATUS_TONE = {
  sent: 'var(--color-success)',
  partial: 'var(--color-warning)',
  generated: 'var(--color-info)',
  failed: 'var(--color-danger)',
};

function StatusLabel({ status }) {
  return (
    <span className="text-xs" style={{ fontWeight: 700, color: STATUS_TONE[status] || 'inherit' }}>
      {String(status || '').toUpperCase()}
    </span>
  );
}

/**
 * Daily analytics report archive. The 08:00 IST email is stored here whether
 * or not it could be sent; a report that was not sent says so and why.
 */
export function DailyReportsPage() {
  const { user } = useAuth();
  const [list, setList] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(null);
  const [viewing, setViewing] = useState(null); // { title, html, status?, error? }

  const load = useCallback(() => {
    setLoading(true);
    reportService.listDaily()
      .then((res) => { setList(res); setError(''); })
      .catch((err) => setError(err?.message || 'Unable to load reports.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const canSend = !!list?.can_send || user?.role === 'super_admin';

  const openReport = async (row) => {
    setBusy(`view-${row.id}`);
    setError('');
    try {
      const report = await reportService.getDaily(row.id);
      setViewing({ title: `Report for ${report.report_date}`, html: report.html, status: report.status, error: report.error, sentTo: report.sent_to });
    } catch (err) {
      setError(err?.message || 'Unable to open the report.');
    } finally {
      setBusy(null);
    }
  };

  const preview = async () => {
    setBusy('preview');
    setError('');
    try {
      const p = await reportService.previewDaily();
      setViewing({ title: `Preview — ${p.report_date} (not stored, not sent)`, html: p.html });
    } catch (err) {
      setError(err?.message || 'Unable to build the preview.');
    } finally {
      setBusy(null);
    }
  };

  const sendNow = async () => {
    const recipients = (list?.recipients || []).join(', ') || 'the configured recipients';
    if (!window.confirm(`Build today's report and email it to ${recipients}?`)) return;
    setBusy('send');
    setError('');
    setNotice('');
    try {
      const row = await reportService.sendDaily({ date: list?.today });
      setNotice(row?.status === 'sent' ? `Sent to ${row.sent_to}.` : `Report ${row?.status}: ${row?.error || ''}`);
      load();
    } catch (err) {
      setError(err?.message || 'The report was not sent.');
      load();
    } finally {
      setBusy(null);
    }
  };

  if (loading && !list) return <Loader label="Loading reports…" />;

  const reports = list?.reports || [];

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap', marginBottom: '1.25rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
          <FileBarChart size={26} style={{ color: 'var(--color-info)' }} />
          <div>
            <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Daily Reports</h1>
            <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
              Search, traffic, SEO Tracker, content, vetting and pipeline health — emailed every day at 08:00 IST
              {list?.recipients?.length ? <> to {list.recipients.join(', ')}</> : null}
            </p>
          </div>
        </div>
        <div style={{ display: 'flex', gap: '0.5rem' }}>
          <button type="button" className="btn btn-secondary btn-sm" onClick={load} disabled={!!busy}>
            <RefreshCw size={14} /> Refresh
          </button>
          <button type="button" className="btn btn-secondary btn-sm" onClick={preview} disabled={!!busy}>
            <Eye size={14} /> {busy === 'preview' ? 'Building…' : 'Preview today'}
          </button>
          {canSend && (
            <button type="button" className="btn btn-primary btn-sm" onClick={sendNow} disabled={!!busy}>
              <Send size={14} /> {busy === 'send' ? 'Sending…' : 'Send now'}
            </button>
          )}
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {notice && <Alert variant="success">{notice}</Alert>}
      {list?.note && <Alert variant="warning">{list.note}</Alert>}

      <Card title={`Archive (${reports.length})`}>
        {reports.length === 0 ? (
          <p className="text-sm text-muted" style={{ margin: 0 }}>No reports yet. The first one is built at 08:00 IST, or use “Preview today”.</p>
        ) : (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr><th>Date</th><th>Status</th><th>Subject</th><th>Sent to</th><th>Updated</th><th /></tr>
              </thead>
              <tbody>
                {reports.map((r) => (
                  <tr key={r.id}>
                    <td style={{ fontWeight: 600 }}>{r.report_date}</td>
                    <td>
                      <StatusLabel status={r.status} />
                      {r.error && <div className="text-xs text-muted" style={{ maxWidth: '22rem' }}>{r.error}</div>}
                    </td>
                    <td className="text-sm">{r.subject}</td>
                    <td className="text-sm">{r.sent_to || '—'}</td>
                    <td className="text-sm">{formatTime(r.updated_at)}</td>
                    <td>
                      <button type="button" className="btn btn-secondary btn-sm" onClick={() => openReport(r)} disabled={!!busy}>
                        {busy === `view-${r.id}` ? 'Opening…' : 'View'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {viewing && (
        <Card
          title={viewing.title}
          actions={<button type="button" className="btn btn-secondary btn-sm" onClick={() => setViewing(null)}>Close</button>}
        >
          {viewing.status && viewing.status !== 'sent' && (
            <Alert variant={viewing.status === 'failed' ? 'danger' : 'info'}>
              {viewing.status === 'failed' ? `Not sent: ${viewing.error}` : `Status: ${viewing.status}`}
            </Alert>
          )}
          {/* The email HTML is rendered sandboxed: no scripts, no same-origin access. */}
          <iframe
            title={viewing.title}
            sandbox=""
            srcDoc={viewing.html}
            style={{ width: '100%', minHeight: '70vh', border: '1px solid var(--color-border)', borderRadius: '6px', background: '#fff' }}
          />
        </Card>
      )}
    </div>
  );
}

export default DailyReportsPage;
