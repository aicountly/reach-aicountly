import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { MessageSquareText } from 'lucide-react';
import { getVisibilityRun } from '../../services/growthIntelService.js';
import { formatDateTime } from '../../utils/formatDate';
import { DataTable } from '../../components/common/DataTable';
import { Breadcrumbs } from '../../components/common/Breadcrumbs';
import { drillHref } from '../../components/drill';
import { OBSERVATIONS, PROVIDER_LABEL, RUNS } from './visibilityLabels';

/**
 * One AI-visibility answer: the exact question, the stored answer text, what
 * the parser found in it (brand and competitor mentions with rank and
 * excerpt) and every source it cited.
 */
export default function VisibilityRunDetailPage() {
  const { id } = useParams();
  const [run, setRun] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    setError(null);
    getVisibilityRun(id).then(setRun).catch((e) => setError(e.message || 'Failed to load this answer'));
  }, [id]);

  if (error) return <div className="alert alert-danger">{error}</div>;
  if (!run) return <p className="text-sm text-muted">Loading answer…</p>;

  const provider = PROVIDER_LABEL[run.provider] || run.provider;
  const facts = [
    ['Assistant', <Link key="p" to={drillHref(RUNS, { provider: run.provider })}>{provider}</Link>],
    ['Model', run.model || '—'],
    ['Status', <Link key="s" to={drillHref(RUNS, { status: run.status })}>{run.status}</Link>],
    ['Market', <Link key="m" to={drillHref(RUNS, { market: run.market || 'Unspecified' })}>{run.market || 'Unspecified'}</Link>],
    ['Question', <Link key="q" to={drillHref(RUNS, { prompt_id: run.prompt_id })}>{run.prompt_name} (v{run.prompt_version})</Link>],
    ['Asked', formatDateTime(run.asked_at)],
    ['Run type', run.run_type],
    ['Pass', run.batch_id ? <Link key="b" to={drillHref(RUNS, { batch_id: run.batch_id })}>#{run.batch_id}</Link> : '—'],
    ['Tokens', run.tokens_used ?? '—'],
  ];
  let brand = '—';
  if (run.status === 'completed') {
    if (run.brand_counted === false) brand = 'Not counted — the question itself names the brand';
    else if (run.brand_mentioned) brand = `Named${run.brand_rank ? ` at #${run.brand_rank}` : ''}`;
    else brand = run.gap ? 'Not named — competitors were (a gap)' : 'Not named';
    if (run.brand_cited) brand += ' · brand site cited';
  }

  return (
    <div>
      <Breadcrumbs items={[{ label: 'AI Visibility', to: '/intelligence/visibility' }, { label: 'Run History', to: RUNS }, { label: `Answer #${run.run_id}` }]} />
      <div className="flex items-center gap-3 mb-3">
        <MessageSquareText size={24} style={{ color: 'var(--color-primary)' }} aria-hidden="true" />
        <h1 style={{ fontSize: '1.25rem', fontWeight: 700, margin: 0 }}>{run.question}</h1>
      </div>

      <div className="card mb-3">
        <div className="card-body">
          <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '0.3rem 1rem', margin: 0 }} className="text-sm">
            {facts.map(([k, v]) => (
              <div key={k} style={{ display: 'contents' }}><dt className="text-muted">{k}</dt><dd style={{ margin: 0 }}>{v}</dd></div>
            ))}
            <div style={{ display: 'contents' }}><dt className="text-muted">Brand</dt><dd style={{ margin: 0 }}>{brand}</dd></div>
          </dl>
          {run.error && <p className="text-sm" style={{ color: 'var(--color-danger)', margin: '0.75rem 0 0' }}>{run.error}</p>}
        </div>
      </div>

      <div className="card mb-3">
        <div className="card-header">Answer</div>
        <div className="card-body">
          {(run.responses || []).length === 0 && <p className="text-sm text-muted" style={{ margin: 0 }}>No answer stored for this run ({run.status}).</p>}
          {(run.responses || []).map((r) => (
            <div key={r.id}>
              <pre style={{ whiteSpace: 'pre-wrap', fontFamily: 'inherit', background: 'var(--color-bg)', padding: '0.75rem', borderRadius: 6, margin: 0 }}>{r.text}</pre>
              <p className="text-xs text-muted" style={{ margin: '0.4rem 0 0' }}>
                Received {formatDateTime(r.response_timestamp)} · parser {r.parser_version} ({r.parse_status}){r.model ? ` · ${r.model}` : ''}
              </p>
            </div>
          ))}
        </div>
      </div>

      <div className="card mb-3">
        <div className="card-header flex items-center justify-between">
          <span>Observations ({(run.observations || []).length})</span>
          <Link to={drillHref(OBSERVATIONS, { run_id: run.run_id })} className="text-sm">As a list ›</Link>
        </div>
        <div className="card-body" style={{ padding: 0 }}>
          <DataTable
            columns={[
              { key: 'entity_mentioned', label: 'Name', render: (o) => <strong>{o.entity_mentioned}</strong>,
                drill: (o) => (o.competitor_id ? `/intelligence/competitors/${o.competitor_id}` : null) },
              { key: 'mention_type', label: 'Type' },
              { key: 'coverage_state', label: 'Coverage', render: (o) => `${String(o.coverage_state).replace(/_/g, ' ')}${o.in_question ? ' (in question — not counted)' : ''}` },
              { key: 'mention_order', label: 'Rank', render: (o) => (o.mention_order != null ? `#${o.mention_order}` : '—') },
              { key: 'evidence_excerpt', label: 'Excerpt', render: (o) => <span className="text-xs">{o.evidence_excerpt || '—'}</span> },
              { key: 'others', label: '', render: (o) => (o.coverage_state === 'mentioned' && !o.in_question
                ? <Link className="text-sm" to={drillHref(RUNS, o.mention_type === 'brand' ? { brand: 'named' } : { entity: o.entity_mentioned })}>Other answers naming it ›</Link>
                : null) },
            ]}
            rows={run.observations || []}
            emptyMessage="Nothing was parsed from this answer."
          />
        </div>
      </div>

      <div className="card">
        <div className="card-header">Cited sources ({(run.citations || []).length})</div>
        <div className="card-body" style={{ padding: 0 }}>
          <DataTable
            columns={[
              { key: 'citation_order', label: '#', render: (c) => c.citation_order ?? '—' },
              { key: 'cited_url', label: 'URL', render: (c) => (c.cited_url
                ? <a href={c.cited_url} target="_blank" rel="noreferrer" style={{ wordBreak: 'break-all' }}>{c.cited_url}</a> : '—') },
              { key: 'cited_domain', label: 'Domain', render: (c) => c.cited_domain || '—',
                drill: (c) => (c.cited_domain ? { to: drillHref(RUNS, { cited_domain: c.cited_domain }), title: 'Other answers citing this domain' } : null) },
              { key: 'citation_type', label: 'Type' },
            ]}
            rows={run.citations || []}
            emptyMessage="This answer cited no sources."
          />
        </div>
      </div>
    </div>
  );
}
