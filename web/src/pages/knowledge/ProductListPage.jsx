import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { knowledgeService } from '../../services/knowledgeService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { SearchBar } from '../../components/common/SearchBar';
import { Pagination } from '../../components/common/Pagination';
import { KnowledgeStatusBadge } from '../../components/knowledge/KnowledgeStatusBadge';
import { usePermission } from '../../hooks/usePermission';

const STATUS_OPTIONS = ['', 'draft', 'needs_review', 'approved', 'rejected', 'deprecated', 'archived'];

export function ProductListPage() {
  const { has } = usePermission();
  const navigate  = useNavigate();
  const [rows, setRows]       = useState([]);
  const [total, setTotal]     = useState(0);
  const [page, setPage]       = useState(1);
  const [limit]               = useState(25);
  const [status, setStatus]   = useState('');
  const [search, setSearch]   = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    knowledgeService.listProducts({ page, limit, status, search })
      .then((d) => { setRows(d.items || d.data || []); setTotal(d.total ?? 0); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, limit, status, search]);

  useEffect(() => { load(); }, [load]);

  const columns = [
    { key: 'name', label: 'Product', render: (r) => (
      <div>
        <div className="font-semibold">{r.name}</div>
        <div className="text-xs text-muted">{r.slug}</div>
      </div>
    )},
    { key: 'short_description', label: 'Description', render: (r) => (
      <div className="text-sm" style={{ maxWidth: 300, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
        {r.short_description || '—'}
      </div>
    )},
    { key: 'knowledge_status', label: 'Status', render: (r) => <KnowledgeStatusBadge status={r.knowledge_status || r.status} /> },
    { key: 'public_url', label: 'URL', render: (r) => r.public_url ? (
      <a href={r.public_url} target="_blank" rel="noreferrer" className="text-sm">↗</a>
    ) : '—' },
    { key: 'updated_at', label: 'Updated', render: (r) => r.updated_at ? new Date(r.updated_at).toLocaleDateString() : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Products</h1>
          <p className="text-sm text-muted">Authoritative product definitions used to ground AI content.</p>
        </div>
        {has('product.create') && (
          <button className="btn btn-primary" onClick={() => alert('Create product form coming in next sprint')}>
            <Plus size={14} /> New Product
          </button>
        )}
      </div>

      <FilterBar>
        <select value={status} onChange={(e) => { setPage(1); setStatus(e.target.value); }}>
          {STATUS_OPTIONS.map((s) => (
            <option key={s} value={s}>{s ? s.replace(/_/g, ' ') : 'All statuses'}</option>
          ))}
        </select>
        <SearchBar value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Search name / slug…" />
        <button className="btn btn-secondary" onClick={() => { setPage(1); load(); }}>Search</button>
      </FilterBar>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <DataTable
          columns={columns}
          rows={rows}
          onRowClick={(r) => navigate(`/knowledge/products/${r.id}`)}
          emptyMessage="No products yet. Import the taxonomy or create the first product."
        />
      )}
      <Pagination page={page} limit={limit} total={total} onPage={setPage} />
    </div>
  );
}
