import { useCallback, useEffect, useState } from 'react';
import { knowledgeService } from '../../services/knowledgeService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { SearchBar } from '../../components/common/SearchBar';
import { Pagination } from '../../components/common/Pagination';
import { KnowledgeStatusBadge } from '../../components/knowledge/KnowledgeStatusBadge';

const STATUS_OPTIONS      = ['', 'draft', 'needs_review', 'approved', 'rejected', 'deprecated', 'archived'];
const POLICY_TYPE_OPTIONS = ['', 'legal', 'brand', 'accuracy', 'format', 'channel'];

export function ContentPoliciesPage() {
  const [rows, setRows]             = useState([]);
  const [total, setTotal]           = useState(0);
  const [page, setPage]             = useState(1);
  const [limit]                     = useState(25);
  const [status, setStatus]         = useState('');
  const [policyType, setPolicyType] = useState('');
  const [search, setSearch]         = useState('');
  const [loading, setLoading]       = useState(true);
  const [error, setError]           = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    knowledgeService.listPolicies({ page, limit, status, policy_type: policyType, search })
      .then((d) => { setRows(d.items || d.data || []); setTotal(d.total ?? 0); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, limit, status, policyType, search]);

  useEffect(() => { load(); }, [load]);

  const columns = [
    { key: 'name', label: 'Policy', render: (r) => (
      <div>
        <div className="font-semibold">{r.name}</div>
        <div className="text-xs text-muted">{r.policy_type}</div>
      </div>
    )},
    { key: 'policy_text', label: 'Text', render: (r) => (
      <div className="text-sm" style={{ maxWidth: 380, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
        dangerouslySetInnerHTML={{ __html: r.policy_text || '—' }}
      />
    )},
    { key: 'applies_to_channels', label: 'Channels', render: (r) => {
      const ch = Array.isArray(r.applies_to_channels)
        ? r.applies_to_channels
        : (typeof r.applies_to_channels === 'string' ? JSON.parse(r.applies_to_channels || '[]') : []);
      return <div className="text-sm">{ch.length > 0 ? ch.join(', ') : 'All'}</div>;
    }},
    { key: 'status', label: 'Status', render: (r) => <KnowledgeStatusBadge status={r.knowledge_status || r.status} /> },
    { key: 'updated_at', label: 'Updated', render: (r) => r.updated_at ? new Date(r.updated_at).toLocaleDateString() : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Content Policies</h1>
          <p className="text-sm text-muted">Legal, brand, accuracy, format, and channel policies enforced during content generation.</p>
        </div>
      </div>

      <FilterBar>
        <select value={status} onChange={(e) => { setPage(1); setStatus(e.target.value); }}>
          {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s ? s.replace(/_/g, ' ') : 'All statuses'}</option>)}
        </select>
        <select value={policyType} onChange={(e) => { setPage(1); setPolicyType(e.target.value); }}>
          {POLICY_TYPE_OPTIONS.map((t) => <option key={t} value={t}>{t || 'All policy types'}</option>)}
        </select>
        <SearchBar value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Search policy name…" />
        <button className="btn btn-secondary" onClick={() => { setPage(1); load(); }}>Search</button>
      </FilterBar>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <DataTable columns={columns} rows={rows} emptyMessage="No content policies defined yet." />
      )}
      <Pagination page={page} limit={limit} total={total} onPage={setPage} />
    </div>
  );
}
