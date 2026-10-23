import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Library, FileText, BookOpen, MessagesSquare } from 'lucide-react';
import { contentBaseService } from '../../services/contentBaseService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { formatDate, formatDateTime } from '../../utils/formatDate';
import { ROUTES } from '../../constants/routes';
import { DrillLink, useUrlFilter } from '../../components/drill';

/**
 * The living content base — the topics the Claude routines work through for
 * blog, knowledge base and community Q&A.
 *
 * Topics and their progress live in Reach's database. Git holds only the seed
 * baseline (imported hourly; a deploy adds seeds but never resets progress).
 * Routines take topics from GET v1/automation/content-base/next, add new ones
 * with POST …/propose and report with POST …/produced; Reach moves each topic
 * planned → queued → produced → published from its own pipeline.
 */

const SURFACES = [
  { id: 'blog', icon: FileText },
  { id: 'knowledge_base', icon: BookOpen },
  { id: 'community', icon: MessagesSquare },
];

const STATUS = {
  planned: { label: 'Planned', className: 'badge badge-warning', help: 'Waiting for a routine to write it.' },
  queued: { label: 'Queued', className: 'badge badge-secondary', help: 'Submitted; Reach is vetting it.' },
  produced: { label: 'Produced', className: 'badge badge-info', help: 'Approved by vetting; Reach is publishing it.' },
  published: { label: 'Published', className: 'badge badge-success', help: 'Live on aicountly.com.' },
  retired: { label: 'Retired', className: 'badge badge-muted', help: 'Dropped.' },
};

function StatusBadge({ status }) {
  const s = STATUS[status] || STATUS.planned;
  return <span className={s.className} title={s.help}>{s.label}</span>;
}

function ago(hours) {
  if (hours === null || hours === undefined) return 'never';
  if (hours < 1) return 'under an hour ago';
  if (hours < 48) return `${Math.round(hours)} h ago`;
  return `${Math.round(hours / 24)} days ago`;
}

function RoutineLine({ health }) {
  if (!health) return null;
  const quiet = health.state === 'stale' || health.state === 'never_seen';
  return (
    <p className={`text-sm ${quiet ? 'text-warning' : 'text-muted'}`} data-testid="routine-line">
      Routine <code>{health.routine}</code>: last activity {ago(health.hours_since)}
      {health.last_activity_at ? ` (${formatDateTime(health.last_activity_at)})` : ''}
      {health.state === 'off' ? ' — automation switched off' : ''}
      {quiet ? ` — quiet for longer than ${health.threshold_hours} h` : ''}
    </p>
  );
}

function EntryTable({ entries, keyLabel = 'Key', filtered = false }) {
  if (!entries.length) {
    return <div className="text-sm text-muted">{filtered ? 'No topics in this status.' : 'No topics of this type yet.'}</div>;
  }

  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="data-table">
        <thead>
          <tr>
            <th>{keyLabel}</th><th>Title</th><th>Stream</th><th>Target</th><th>Status</th><th>Origin</th><th>Detail</th>
          </tr>
        </thead>
        <tbody>
          {entries.map((entry) => (
            <tr key={entry.key}>
              <td><code>{entry.key}</code></td>
              <td>
                {/* A written topic opens the article it produced. */}
                {entry.content_item_id
                  ? <Link to={ROUTES.CONTENT_DETAIL.replace(':id', entry.content_item_id)}>{entry.title}</Link>
                  : entry.title}
              </td>
              <td>{entry.stream || '—'}</td>
              <td>{entry.target_date ? formatDate(entry.target_date) : '—'}</td>
              <td><StatusBadge status={entry.status} /></td>
              <td className="text-sm">{entry.origin === 'routine' ? 'Routine' : 'Git seed'}</td>
              <td className="text-sm text-muted" style={{ maxWidth: '28rem' }}>{entry.status_reason || entry.prompt}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Each count filters the table below to that status (?status=…). */
function CountStrip({ counts, onStatus }) {
  const count = (key) => (
    <DrillLink onClick={counts[key] > 0 ? () => onStatus(key) : undefined} label={`Show ${counts[key]} ${key} topics`}>
      {counts[key]} {key}
    </DrillLink>
  );
  return (
    <p className="text-sm text-muted">
      <DrillLink onClick={() => onStatus('')} label="Show all topics">{counts.total} topics</DrillLink>
      {' · '}{count('planned')} · {count('queued')} · {count('produced')} ·{' '}
      {count('published')}{counts.retired ? <> · {count('retired')}</> : ''}
    </p>
  );
}

const byStatus = (entries, status) => (status ? entries.filter((e) => e.status === status) : entries);

const EMPTY_COUNTS = { total: 0, planned: 0, queued: 0, produced: 0, published: 0, retired: 0 };

export function ContentBasePage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  // Tab and status filter live in the URL so a count elsewhere can open
  // e.g. ?surface=knowledge_base&status=planned.
  const [active, setActive] = useUrlFilter('surface', 'blog');
  const [status, setStatus] = useUrlFilter('status');

  const load = useCallback(() => {
    setLoading(true);
    contentBaseService.overview()
      .then((res) => { setData(res); setError(''); })
      .catch((err) => setError(err?.message || 'Unable to load the content base.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  if (loading) return <Loader label="Loading content base…" />;
  if (error) return <Alert variant="danger">{error}</Alert>;

  const surfaces = data?.surfaces ?? {};
  const totals = data?.totals ?? { entries: 0, published: 0, in_progress: 0, planned: 0 };
  const lastSync = data?.last_sync;
  const section = surfaces[active] ?? { entries: [], counts: {} };
  const stale = data?.stale ?? [];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="flex items-center gap-2"><Library size={18} /> Content Base</h1>
          <p className="text-sm text-muted">
            The topics the Claude routines write — blog, knowledge base and community Q&A.
            {' '}{totals.entries} topics: {totals.published} published, {totals.in_progress} in progress, {totals.planned} planned.
          </p>
        </div>
      </div>

      {data?.available === false && <Alert variant="warning">{data.note}</Alert>}

      <Alert variant="info">
        Updated by the routines daily through the API: they take the next topics
        (<code>GET v1/automation/content-base/next</code>), add new ones from real product changes
        (<code>POST …/propose</code>) and report what they wrote (<code>POST …/produced</code>). Reach moves each topic
        from planned to published from its own vetting and publishing pipeline. The repo
        (<code>{data?.base_path || 'server-php/content-base/'}</code>) holds only the seed baseline and the strategy
        below; seeds are imported hourly and a deploy never resets progress.
      </Alert>

      {stale.length > 0 && (
        <Alert variant="warning">
          <strong>Quiet routines (informational):</strong>
          <ul style={{ margin: '0.25rem 0 0 1rem' }}>
            {stale.map((line) => <li key={line}>{line}</li>)}
          </ul>
        </Alert>
      )}

      {lastSync ? (
        <p className="text-sm text-muted">
          Last seed import that changed something: {formatDateTime(lastSync.ran_at)} ({lastSync.file_key}) —{' '}
          {lastSync.created_count} added, {lastSync.updated_count} updated, {lastSync.retired_count} retired of {lastSync.entries_seen} seeds.
        </p>
      ) : (
        <p className="text-sm text-muted">Seeds not imported yet — the hourly content-base sync imports them (<code>php spark reach:content-base-sync</code> by hand).</p>
      )}

      <div className="flex gap-2 mt-4" role="tablist">
        {SURFACES.map((tab) => {
          const surface = surfaces[tab.id];
          if (!surface) return null;
          return (
            <button
              key={tab.id}
              type="button"
              role="tab"
              aria-selected={active === tab.id}
              className={`btn btn-sm ${active === tab.id ? 'btn-primary' : 'btn-secondary'}`}
              onClick={() => setActive(tab.id)}
            >
              <tab.icon size={14} /> {surface.label} ({surface.counts?.total ?? 0})
            </button>
          );
        })}
      </div>

      <div className="mt-4">
        <Card
          title={section.label || ''}
          actions={section.activity?.last_proposal_at
            ? <span className="text-xs text-muted">last routine proposal {formatDateTime(section.activity.last_proposal_at)}</span>
            : <span className="text-xs text-muted">no routine proposals yet</span>}
        >
          <RoutineLine health={section.routine} />
          <CountStrip counts={{ ...EMPTY_COUNTS, ...section.counts }} onStatus={setStatus} />
          {status && (
            <div className="flex items-center gap-2 mb-2">
              <button type="button" className="badge badge-secondary" onClick={() => setStatus('')}
                      aria-label={`Clear ${status} filter`}>
                Status: {STATUS[status]?.label ?? status} ✕
              </button>
            </div>
          )}

          {active === 'knowledge_base' ? (
            (section.products ?? []).filter((product) => !status || byStatus(product.topics ?? [], status).length > 0).map((product) => (
              <div key={product.product_slug} className="mt-4">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-semibold">{product.product_slug}</h3>
                  <span className="text-xs text-muted">
                    {product.tier ? `${product.tier} tier · ` : ''}{product.daily_quota}/day{product.enabled ? '' : ' · no enabled KB cadence (not offered to the routine)'}
                  </span>
                </div>
                <EntryTable entries={byStatus(product.topics ?? [], status)} filtered={!!status} />
              </div>
            ))
          ) : (
            <EntryTable
              entries={byStatus(section.entries ?? [], status)}
              keyLabel={active === 'community' ? 'Seed' : 'Key'}
              filtered={!!status}
            />
          )}
        </Card>
      </div>

      <Card title="Strategy base (blog/base.md)">
        <pre style={{ whiteSpace: 'pre-wrap', fontFamily: 'inherit', fontSize: '0.875rem' }}>
          {data?.base_markdown || 'base.md not found in the deployed content-base directory.'}
        </pre>
      </Card>
    </div>
  );
}
