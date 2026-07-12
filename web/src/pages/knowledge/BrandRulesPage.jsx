import { useCallback, useEffect, useState } from 'react';
import { knowledgeService } from '../../services/knowledgeService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { SearchBar } from '../../components/common/SearchBar';
import { Pagination } from '../../components/common/Pagination';
import { KnowledgeStatusBadge } from '../../components/knowledge/KnowledgeStatusBadge';

const STATUS_OPTIONS    = ['', 'draft', 'needs_review', 'approved', 'rejected', 'deprecated', 'archived'];
const RULE_TYPE_OPTIONS = ['', 'preferred_name', 'avoid_term', 'tone', 'trademark', 'competitor_mention'];

export function BrandRulesPage() {
  const [rows, setRows]         = useState([]);
  const [total, setTotal]       = useState(0);
  const [page, setPage]         = useState(1);
  const [limit]                 = useState(25);
  const [status, setStatus]     = useState('');
  const [ruleType, setRuleType] = useState('');
  const [search, setSearch]     = useState('');
  const [loading, setLoading]   = useState(true);
  const [error, setError]       = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    knowledgeService.listBrandRules({ page, limit, status, rule_type: ruleType, search })
      .then((d) => { setRows(d.items || d.data || []); setTotal(d.total ?? 0); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, limit, status, ruleType, search]);

  useEffect(() => { load(); }, [load]);

  const columns = [
    { key: 'rule_type', label: 'Type', render: (r) => (
      <span className="badge">{r.rule_type?.replace(/_/g, ' ') || '—'}</span>
    )},
    { key: 'rule_text', label: 'Rule', render: (r) => (
      <div className="text-sm" style={{ maxWidth: 380, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
        {r.rule_text || '—'}
      </div>
    )},
    { key: 'product_id', label: 'Product', render: (r) => r.product_id ? `#${r.product_id}` : 'Global' },
    { key: 'status', label: 'Status', render: (r) => <KnowledgeStatusBadge status={r.knowledge_status || r.status} /> },
    { key: 'updated_at', label: 'Updated', render: (r) => r.updated_at ? new Date(r.updated_at).toLocaleDateString() : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Brand Rules</h1>
          <p className="text-sm text-muted">Naming conventions, tone rules, and trademark guidance for AI-generated content.</p>
        </div>
      </div>

      <FilterBar>
        <select value={status} onChange={(e) => { setPage(1); setStatus(e.target.value); }}>
          {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s ? s.replace(/_/g, ' ') : 'All statuses'}</option>)}
        </select>
        <select value={ruleType} onChange={(e) => { setPage(1); setRuleType(e.target.value); }}>
          {RULE_TYPE_OPTIONS.map((t) => <option key={t} value={t}>{t ? t.replace(/_/g, ' ') : 'All types'}</option>)}
        </select>
        <SearchBar value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Search rule text…" />
        <button className="btn btn-secondary" onClick={() => { setPage(1); load(); }}>Search</button>
      </FilterBar>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <DataTable columns={columns} rows={rows} emptyMessage="No brand rules defined yet." />
      )}
      <Pagination page={page} limit={limit} total={total} onPage={setPage} />
    </div>
  );
}
