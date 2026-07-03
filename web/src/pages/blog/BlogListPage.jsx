import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { blogService } from '../../services/blogService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { ApprovalBadge } from '../../components/common/ApprovalBadge';
import { FilterBar } from '../../components/common/FilterBar';
import { SearchBar } from '../../components/common/SearchBar';
import { Pagination } from '../../components/common/Pagination';
import { ROUTES } from '../../constants/routes';

const STATUS_OPTIONS = ['', 'idea','draft','seo_review','internal_review','approved','scheduled','published','rejected','archived'];

export function BlogListPage() {
  const [rows, setRows]     = useState([]);
  const [total, setTotal]   = useState(0);
  const [page, setPage]     = useState(1);
  const [limit]             = useState(25);
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading]= useState(true);
  const [error, setError]   = useState(null);
  const navigate = useNavigate();

  const load = useCallback(() => {
    setLoading(true);
    blogService.list({ page, limit, status, search })
      .then((d) => { setRows(d.items || d.data || []); setTotal(d.total ?? (d.items?.length || 0)); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, limit, status, search]);
  useEffect(() => { load(); }, [page, limit, status, load]);

  const columns = [
    { key: 'title', label: 'Title', render: (r) => (
      <div>
        <div className="font-semibold">{r.title || '(untitled)'}</div>
        <div className="text-xs text-muted">{r.slug || ''}</div>
      </div>
    )},
    { key: 'category', label: 'Category', render: (r) => r.category || '—' },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'approval_status', label: 'Approval', render: (r) => <ApprovalBadge status={r.approval_status} /> },
    { key: 'publishing_status', label: 'Publishing', render: (r) => <StatusBadge status={r.publishing_status || 'none'} /> },
    { key: 'bot_generated', label: 'Bot?', render: (r) => r.bot_generated ? 'Yes' : 'No' },
    { key: 'updated_at', label: 'Updated', render: (r) => r.updated_at ? new Date(r.updated_at).toLocaleString() : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Blog management</h1>
          <p className="text-sm text-muted">Drafts, approvals & publishing to AICOUNTLY.com.</p>
        </div>
        <Link to={ROUTES.BLOG_NEW} className="btn btn-primary"><Plus size={14}/> New post</Link>
      </div>

      <FilterBar>
        <select value={status} onChange={(e) => { setPage(1); setStatus(e.target.value); }}>
          {STATUS_OPTIONS.map((s) => (
            <option key={s} value={s}>{s ? s.replace(/_/g,' ') : 'All statuses'}</option>
          ))}
        </select>
        <SearchBar value={search} onChange={setSearch} placeholder="Search title/slug…" />
        <button className="btn btn-secondary" onClick={() => { setPage(1); load(); }}>Search</button>
      </FilterBar>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <DataTable
          columns={columns}
          rows={rows}
          onRowClick={(r) => navigate(`/blog/${r.id}`)}
          emptyMessage="No blog posts yet. Create the first draft."
        />
      )}
      <Pagination page={page} limit={limit} total={total} onPage={setPage} />
    </div>
  );
}
