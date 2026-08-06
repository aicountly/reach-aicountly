import { useCallback, useEffect, useState } from 'react';
import { Library, FileText, BookOpen, MessagesSquare } from 'lucide-react';
import { contentBaseService } from '../../services/contentBaseService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { formatDate, formatDateTime } from '../../utils/formatDate';

/**
 * The shared content base — the plan Claude works through for every surface.
 *
 * This used to be a tab inside the Blog Command Centre roadmap, which made a
 * cross-surface artefact look blog-only: the knowledge-base queue and the
 * community question seeds were in the same repo directory but invisible in
 * the console. Quality Centre is where shared assets live, so the whole base
 * lives here now, one section per surface.
 */

const SURFACES = [
  { id: 'blog', icon: FileText },
  { id: 'knowledge_base', icon: BookOpen },
  { id: 'community', icon: MessagesSquare },
];

const SYNC = {
  produced: { label: 'Produced', className: 'badge badge-success' },
  queued: { label: 'Queued', className: 'badge badge-secondary' },
  pending_sync: { label: 'Pending sync', className: 'badge badge-warning' },
  retired: { label: 'Retired', className: 'badge badge-muted' },
};

function SyncBadge({ state }) {
  const sync = SYNC[state] || SYNC.pending_sync;
  return <span className={sync.className}>{sync.label}</span>;
}

function EntryTable({ entries, keyLabel = 'Key' }) {
  if (!entries.length) {
    return <div className="text-sm text-muted">No entries in this section of the base yet.</div>;
  }

  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="data-table">
        <thead>
          <tr>
            <th>{keyLabel}</th><th>Title</th><th>Stream</th><th>Target</th><th>Status</th><th>Sync</th><th>Prompt</th>
          </tr>
        </thead>
        <tbody>
          {entries.map((entry) => (
            <tr key={entry.key}>
              <td><code>{entry.key}</code></td>
              <td>{entry.title}</td>
              <td>{entry.stream || '—'}</td>
              <td>{entry.target_date ? formatDate(entry.target_date) : '—'}</td>
              <td>{entry.status}</td>
              <td><SyncBadge state={entry.sync?.state} /></td>
              <td className="text-sm text-muted" style={{ maxWidth: '28rem' }}>{entry.prompt}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function CountStrip({ counts }) {
  return (
    <p className="text-sm text-muted">
      {counts.total} entries · {counts.produced} produced · {counts.queued} queued ·{' '}
      {counts.pending_sync} pending sync{counts.retired ? ` · ${counts.retired} retired` : ''}
    </p>
  );
}

export function ContentBasePage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [active, setActive] = useState('blog');

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
  const totals = data?.totals ?? { entries: 0, pending: 0, done: 0 };
  const lastSync = data?.last_sync;
  const section = surfaces[active] ?? { entries: [], counts: {}, meta: {} };

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 className="flex items-center gap-2"><Library size={18} /> Content Base</h1>
          <p className="text-sm text-muted">
            The shared plan Claude works through — blog, knowledge base and community Q&A.
            {' '}{totals.entries} entries, {totals.done} produced, {totals.pending} outstanding.
          </p>
        </div>
      </div>

      <Alert variant="info">
        The content base lives in the repo (<code>{data?.base_path || 'server-php/content-base/'}</code>) and is
        <strong> read-only here</strong>. Edit prompts and upcoming topics in git — every deploy
        overwrites the server copy (<code>rsync --delete</code>), and the daily Claude routine
        revises the index from product-repo changes.
      </Alert>

      {lastSync ? (
        <p className="text-sm text-muted">
          Last DB sync: {formatDateTime(lastSync.ran_at)} — {lastSync.created_count} created,{' '}
          {lastSync.updated_count} updated, {lastSync.retired_count} retired of {lastSync.entries_seen} entries.
        </p>
      ) : (
        <Alert variant="warning">
          Never synced — run <code>php spark reach:content-base-sync</code> (also runs post-deploy and daily at 06:00).
        </Alert>
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
          title={`${section.label} — ${section.source_file}`}
          actions={section.meta?.updated_at
            ? <span className="text-xs text-muted">base updated {formatDate(section.meta.updated_at)}</span>
            : null}
        >
          <CountStrip counts={{ total: 0, produced: 0, queued: 0, pending_sync: 0, retired: 0, ...section.counts }} />
          {section.meta?.notes && <p className="text-sm text-muted">{section.meta.notes}</p>}

          {active === 'knowledge_base' ? (
            (section.products ?? []).map((product) => (
              <div key={product.product_slug} className="mt-4">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-semibold">{product.product_slug}</h3>
                  <span className="text-xs text-muted">
                    {product.tier} tier · {product.daily_quota}/day{product.enabled ? '' : ' · disabled'}
                  </span>
                </div>
                <EntryTable entries={product.topics ?? []} />
              </div>
            ))
          ) : (
            <EntryTable entries={section.entries ?? []} keyLabel={active === 'community' ? 'Seed' : 'Key'} />
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
