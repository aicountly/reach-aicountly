import { useCallback, useEffect, useState } from 'react';
import { knowledgeService } from '../../services/knowledgeService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { SearchBar } from '../../components/common/SearchBar';
import { Pagination } from '../../components/common/Pagination';
import { KnowledgeStatusBadge } from '../../components/knowledge/KnowledgeStatusBadge';

const STATUS_OPTIONS   = ['', 'draft', 'needs_review', 'approved', 'rejected', 'deprecated', 'archived'];
const INTENT_TYPES     = ['', 'informational', 'navigational', 'transactional', 'commercial'];
const FUNNEL_STAGES    = ['', 'top', 'middle', 'bottom'];

export function SearchIntentListPage() {
  const [rows, setRows]             = useState([]);
  const [total, setTotal]           = useState(0);
  const [page, setPage]             = useState(1);
  const [limit]                     = useState(25);
  const [status, setStatus]         = useState('');
  const [intentType, setIntentType] = useState('');
  const [funnelStage, setFunnel]    = useState('');
  const [search, setSearch]         = useState('');
  const [loading, setLoading]       = useState(true);
  const [error, setError]           = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    knowledgeService.listIntents({ page, limit, status, intent_type: intentType, funnel_stage: funnelStage, search })
      .then((d) => { setRows(d.items || d.data || []); setTotal(d.total ?? 0); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, limit, status, intentType, funnelStage, search]);

  useEffect(() => { load(); }, [load]);

  const columns = [
    { key: 'intent_text', label: 'Intent', render: (r) => (
      <div>
        <div className="font-semibold">{r.intent_text}</div>
        <div className="text-xs text-muted">{r.slug}</div>
      </div>
    )},
    { key: 'intent_type', label: 'Type', render: (r) => (
      <span className="badge">{r.intent_type || '—'}</span>
    )},
    { key: 'funnel_stage', label: 'Funnel', render: (r) => (
      <span className="badge">{r.funnel_stage || '—'}</span>
    )},
    { key: 'status', label: 'Status', render: (r) => <KnowledgeStatusBadge status={r.knowledge_status || r.status} /> },
    { key: 'updated_at', label: 'Updated', render: (r) => r.updated_at ? new Date(r.updated_at).toLocaleDateString() : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Search Intents</h1>
          <p className="text-sm text-muted">Classified search intents mapped to products, features, and personas.</p>
        </div>
      </div>

      <FilterBar>
        <select value={status} onChange={(e) => { setPage(1); setStatus(e.target.value); }}>
          {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s ? s.replace(/_/g, ' ') : 'All statuses'}</option>)}
        </select>
        <select value={intentType} onChange={(e) => { setPage(1); setIntentType(e.target.value); }}>
          {INTENT_TYPES.map((t) => <option key={t} value={t}>{t || 'All types'}</option>)}
        </select>
        <select value={funnelStage} onChange={(e) => { setPage(1); setFunnel(e.target.value); }}>
          {FUNNEL_STAGES.map((s) => <option key={s} value={s}>{s || 'All stages'}</option>)}
        </select>
        <SearchBar value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Search intent text…" />
        <button className="btn btn-secondary" onClick={() => { setPage(1); load(); }}>Search</button>
      </FilterBar>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <DataTable columns={columns} rows={rows} emptyMessage="No search intents defined yet." />
      )}
      <Pagination page={page} limit={limit} total={total} onPage={setPage} />
    </div>
  );
}
