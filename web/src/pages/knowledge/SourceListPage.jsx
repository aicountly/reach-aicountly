import { useCallback, useEffect, useState } from 'react';
import { knowledgeService } from '../../services/knowledgeService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { SearchBar } from '../../components/common/SearchBar';
import { Pagination } from '../../components/common/Pagination';
import { KnowledgeStatusBadge } from '../../components/knowledge/KnowledgeStatusBadge';
import { SourceAuthorityBadge } from '../../components/knowledge/SourceAuthorityBadge';

const STATUS_OPTIONS  = ['', 'draft', 'needs_review', 'approved', 'rejected', 'deprecated', 'archived'];
const SOURCE_TYPES    = ['', 'official_docs', 'press_release', 'third_party', 'community', 'internal'];

export function SourceListPage() {
  const [rows, setRows]           = useState([]);
  const [total, setTotal]         = useState(0);
  const [page, setPage]           = useState(1);
  const [limit]                   = useState(25);
  const [status, setStatus]       = useState('');
  const [sourceType, setType]     = useState('');
  const [search, setSearch]       = useState('');
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    knowledgeService.listSources({ page, limit, status, source_type: sourceType, search })
      .then((d) => { setRows(d.items || d.data || []); setTotal(d.total ?? 0); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, limit, status, sourceType, search]);

  useEffect(() => { load(); }, [load]);

  const columns = [
    { key: 'name', label: 'Source', render: (r) => (
      <div>
        <div className="font-semibold">{r.name}</div>
        <div className="text-xs text-muted">{r.slug}</div>
      </div>
    )},
    { key: 'url', label: 'URL', render: (r) => r.url ? (
      <a href={r.url} target="_blank" rel="noreferrer" className="text-sm" style={{ maxWidth: 260, display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
        {r.url}
      </a>
    ) : '—' },
    { key: 'source_type', label: 'Type', render: (r) => <span className="badge">{r.source_type || '—'}</span> },
    { key: 'authority_score', label: 'Authority', render: (r) => <SourceAuthorityBadge score={r.authority_score} sourceType={r.source_type} /> },
    { key: 'status', label: 'Status', render: (r) => <KnowledgeStatusBadge status={r.knowledge_status || r.status} /> },
    { key: 'updated_at', label: 'Updated', render: (r) => r.updated_at ? new Date(r.updated_at).toLocaleDateString() : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Sources</h1>
          <p className="text-sm text-muted">Verified sources cited as evidence for product claims.</p>
        </div>
      </div>

      <FilterBar>
        <select value={status} onChange={(e) => { setPage(1); setStatus(e.target.value); }}>
          {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s ? s.replace(/_/g, ' ') : 'All statuses'}</option>)}
        </select>
        <select value={sourceType} onChange={(e) => { setPage(1); setType(e.target.value); }}>
          {SOURCE_TYPES.map((t) => <option key={t} value={t}>{t ? t.replace(/_/g, ' ') : 'All types'}</option>)}
        </select>
        <SearchBar value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Search name / URL…" />
        <button className="btn btn-secondary" onClick={() => { setPage(1); load(); }}>Search</button>
      </FilterBar>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <DataTable columns={columns} rows={rows} emptyMessage="No sources defined yet." />
      )}
      <Pagination page={page} limit={limit} total={total} onPage={setPage} />
    </div>
  );
}
