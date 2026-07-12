import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Send } from 'lucide-react';
import { botService } from '../../services/botService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { Modal } from '../../components/common/Modal';
import { RequirePermission } from '../../components/auth/RequirePermission';
import { ROUTES } from '../../constants/routes';

const BOT_ACTIONS = [
  ['generate_campaign_ideas',       'Generate campaign ideas'],
  ['generate_campaign_copy',        'Generate campaign copy'],
  ['generate_blog_draft',           'Generate blog draft'],
  ['generate_seo_brief',            'Generate SEO brief'],
  ['generate_social_posts',         'Generate social posts'],
  ['generate_creative_brief',       'Generate creative brief'],
  ['generate_content_calendar',     'Generate content calendar'],
  ['suggest_hashtags_keywords',     'Suggest hashtags / keywords'],
  ['generate_analytics_summary',    'Generate analytics summary'],
  ['recommend_campaign_improvements','Recommend campaign improvements'],
  ['prepare_approval_package',      'Prepare approval package'],
  ['queue_approved_for_publishing', 'Queue approved for publishing'],
];

export function BotQueuePage() {
  const [rows, setRows]     = useState([]);
  const [loading, setLoading]= useState(true);
  const [error, setError]   = useState(null);
  const [success, setSuccess] = useState(null);
  const [open, setOpen]     = useState(false);
  const [action, setAction] = useState(BOT_ACTIONS[0][0]);
  const [payload, setPayload] = useState('{}');
  const [dispatching, setDispatching] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    botService.queue()
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);
  useEffect(load, [load]);

  const dispatch = async () => {
    setDispatching(true);
    setError(null);
    setSuccess(null);
    try {
      let obj = {};
      try { obj = payload ? JSON.parse(payload) : {}; }
      catch { throw new Error('Payload must be valid JSON.'); }
      const res = await botService.dispatch(action, obj);
      setOpen(false);
      setPayload('{}');
      const jobId = res?.job_id;
      const queueId = res?.queue_id;
      setSuccess(
        jobId
          ? `Bot dispatch accepted — queue #${queueId ?? '?'} (job #${jobId}). Follow progress in the Job Monitor.`
          : 'Bot dispatch accepted.',
      );
      load();
    } catch (e) { setError(e.message); }
    finally { setDispatching(false); }
  };

  const columns = [
    { key: 'id', label: '#' },
    { key: 'action', label: 'Action', render: (r) => (r.action || '').replace(/_/g,' ') },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'requested_by', label: 'By', render: (r) => r.requested_by || '—' },
    { key: 'created_at', label: 'When', render: (r) => r.created_at ? new Date(r.created_at).toLocaleString() : '—' },
    { key: 'error_message', label: 'Error', render: (r) => r.error_message ? <span className="text-danger text-xs">{r.error_message}</span> : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Marketing bot queue</h1>
          <p className="text-sm text-muted">Dispatch bot actions, review awaiting-approval items, monitor runs.</p>
        </div>
        <RequirePermission permission="bot.dispatch">
          <button className="btn btn-primary" onClick={() => setOpen(true)}><Send size={14}/> Dispatch bot action</button>
        </RequirePermission>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {success && (
        <Alert variant="success">
          {success}{' '}
          <Link to={ROUTES.JOBS} style={{ color: 'inherit', textDecoration: 'underline' }}>Open Job Monitor →</Link>
        </Alert>
      )}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable columns={columns} rows={rows} emptyMessage="Bot queue is empty." />
        </Card>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="Dispatch bot action" width={520}
        footer={<>
          <button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button>
          <button className="btn btn-primary" onClick={dispatch} disabled={dispatching}>Dispatch</button>
        </>}
      >
        <div className="flex flex-col gap-3">
          <div>
            <label className="text-xs text-secondary">Action</label>
            <select value={action} onChange={(e) => setAction(e.target.value)}>
              {BOT_ACTIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs text-secondary">Payload (JSON)</label>
            <textarea rows={8} value={payload} onChange={(e) => setPayload(e.target.value)} style={{ fontFamily: 'monospace', fontSize: '0.8rem' }} />
            <p className="text-xs text-muted mt-1">
              Examples: <code>{'{ "topic": "AICOUNTLY features" }'}</code> ·
              <code>{' { "campaign_id": 12 }'}</code>
            </p>
          </div>
        </div>
      </Modal>
    </div>
  );
}
