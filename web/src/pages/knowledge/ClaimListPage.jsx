import { useCallback, useEffect, useState } from 'react';
import { knowledgeService } from '../../services/knowledgeService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { SearchBar } from '../../components/common/SearchBar';
import { Pagination } from '../../components/common/Pagination';
import { KnowledgeStatusBadge } from '../../components/knowledge/KnowledgeStatusBadge';
import { ClaimRiskBadge } from '../../components/knowledge/ClaimRiskBadge';

const STATUS_OPTIONS = ['', 'draft', 'needs_review', 'approved', 'rejected', 'deprecated', 'archived'];
const RISK_OPTIONS   = ['', 'low', 'medium', 'high', 'critical'];

export function ClaimListPage() {
  const [rows, setRows]         = useState([]);
  const [total, setTotal]       = useState(0);
  const [page, setPage]         = useState(1);
  const [limit]                 = useState(25);
  const [status, setStatus]     = useState('');
  const [riskLevel, setRisk]    = useState('');
  const [search, setSearch]     = useState('');
  const [loading, setLoading]   = useState(true);
  const [error, setError]       = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    knowledgeService.listClaims({ page, limit, status, risk_level: riskLevel, search })
      .then((d) => { setRows(d.items || d.data || []); setTotal(d.total ?? 0); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, limit, status, riskLevel, search]);

  useEffect(() => { load(); }, [load]);

  const columns = [
    { key: 'claim_text', label: 'Claim', render: (r) => (
      <div>
        <div className="text-sm" style={{ maxWidth: 340, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {r.claim_text || '—'}
        </div>
        <div className="text-xs text-muted">Product #{r.product_id}</div>
      </div>
    )},
    { key: 'risk_level', label: 'Risk', render: (r) => <ClaimRiskBadge risk={r.risk_level} /> },
    { key: 'requires_evidence', label: 'Evidence req.', render: (r) => r.requires_evidence ? 'Yes' : 'No' },
    { key: 'valid_from', label: 'Valid from', render: (r) => r.valid_from ? new Date(r.valid_from).toLocaleDateString() : '—' },
    { key: 'valid_until', label: 'Valid until', render: (r) => r.valid_until ? new Date(r.valid_until).toLocaleDateString() : '—' },
    { key: 'status', label: 'Status', render: (r) => <KnowledgeStatusBadge status={r.knowledge_status || r.status} /> },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Product Claims</h1>
          <p className="text-sm text-muted">Governed claims about products. High/critical claims require evidence before approval.</p>
        </div>
      </div>

      <FilterBar>
        <select value={status} onChange={(e) => { setPage(1); setStatus(e.target.value); }}>
          {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s ? s.replace(/_/g, ' ') : 'All statuses'}</option>)}
        </select>
        <select value={riskLevel} onChange={(e) => { setPage(1); setRisk(e.target.value); }}>
          {RISK_OPTIONS.map((r) => <option key={r} value={r}>{r ? `Risk: ${r}` : 'All risks'}</option>)}
        </select>
        <SearchBar value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Search claim text…" />
        <button className="btn btn-secondary" onClick={() => { setPage(1); load(); }}>Search</button>
      </FilterBar>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <DataTable columns={columns} rows={rows} emptyMessage="No product claims defined yet." />
      )}
      <Pagination page={page} limit={limit} total={total} onPage={setPage} />
    </div>
  );
}
