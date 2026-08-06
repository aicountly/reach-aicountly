import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, Save, DownloadCloud } from 'lucide-react';
import { knowledgeService } from '../../services/knowledgeService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { SearchBar } from '../../components/common/SearchBar';
import { Pagination } from '../../components/common/Pagination';
import { Modal } from '../../components/common/Modal';
import { KnowledgeStatusBadge } from '../../components/knowledge/KnowledgeStatusBadge';
import { usePermission } from '../../hooks/usePermission';
import { formatDate } from '../../utils/formatDate';

const STATUS_OPTIONS = ['', 'draft', 'needs_review', 'approved', 'rejected', 'deprecated', 'archived'];
const EMPTY_FORM = { name: '', slug: '', short_description: '', description: '', public_url: '' };

export function ProductListPage() {
  const { has } = usePermission();
  const navigate  = useNavigate();
  const canManage = has('product.manage');
  const [rows, setRows]       = useState([]);
  const [total, setTotal]     = useState(0);
  const [page, setPage]       = useState(1);
  const [limit]               = useState(25);
  const [status, setStatus]   = useState('');
  const [search, setSearch]   = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const [notice, setNotice]   = useState(null);
  const [open, setOpen]       = useState(false);
  const [form, setForm]       = useState(EMPTY_FORM);
  const [saving, setSaving]   = useState(false);
  const [importing, setImporting] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    knowledgeService.listProducts({ page, limit, status, search })
      .then((d) => { setRows(d.items || d.data || []); setTotal(d.total ?? 0); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, limit, status, search]);

  useEffect(() => { load(); }, [load]);

  const submit = async () => {
    if (!form.name.trim()) { setError('Product name is required.'); return; }
    setSaving(true);
    setError(null);
    try {
      const payload = {
        name:              form.name.trim(),
        short_description: form.short_description || null,
        description:       form.description || null,
        public_url:        form.public_url || null,
      };
      if (form.slug.trim()) payload.slug = form.slug.trim();
      await knowledgeService.createProduct(payload);
      setOpen(false);
      setForm(EMPTY_FORM);
      setNotice('Product created as a draft. Submit it for review when it is ready.');
      setPage(1);
      load();
    } catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  const importTaxonomy = async () => {
    setImporting(true);
    setError(null);
    setNotice(null);
    try {
      const r = await knowledgeService.importProductTaxonomy();
      setNotice(
        `Taxonomy import complete — ${r.created} created, ${r.skipped} already present, `
        + `${r.aliases} aliases processed`
        + (r.errors ? `, ${r.errors} skipped with errors` : '')
        + '. Imported products land in “needs review” and are not used for grounding until approved.',
      );
      setPage(1);
      load();
    } catch (e) { setError(e.message); }
    finally { setImporting(false); }
  };

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
    { key: 'updated_at', label: 'Updated', render: (r) => r.updated_at ? formatDate(r.updated_at) : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Products</h1>
          <p className="text-sm text-muted">Authoritative product definitions used to ground AI content.</p>
        </div>
        {canManage && (
          <div className="flex gap-2 flex-wrap">
            <button className="btn btn-secondary" onClick={importTaxonomy} disabled={importing}>
              <DownloadCloud size={14} /> {importing ? 'Importing…' : 'Import taxonomy'}
            </button>
            <button className="btn btn-primary" onClick={() => { setNotice(null); setOpen(true); }}>
              <Plus size={14} /> New Product
            </button>
          </div>
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
      {notice && <Alert variant="success">{notice}</Alert>}
      {loading ? <Loader /> : (
        <DataTable
          columns={columns}
          rows={rows}
          onRowClick={(r) => navigate(`/knowledge/products/${r.id}`)}
          emptyMessage={canManage
            ? 'No products yet. Use “Import taxonomy” to seed them from the legacy product list, or create the first product.'
            : 'No products yet. Ask a knowledge manager to import the taxonomy or create the first product.'}
        />
      )}
      <Pagination page={page} limit={limit} total={total} onPage={setPage} />

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="New product"
        footer={<>
          <button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button>
          <button className="btn btn-primary" onClick={submit} disabled={saving}>
            <Save size={13} /> {saving ? 'Saving…' : 'Save'}
          </button>
        </>}
      >
        <div className="flex flex-col gap-3">
          <div>
            <label className="text-xs text-secondary" htmlFor="product-name">Name</label>
            <input id="product-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </div>
          <div>
            <label className="text-xs text-secondary" htmlFor="product-slug">Slug</label>
            <input id="product-slug" value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} placeholder="auto from name if empty" />
          </div>
          <div>
            <label className="text-xs text-secondary" htmlFor="product-short">Short description</label>
            <input id="product-short" value={form.short_description} onChange={(e) => setForm({ ...form, short_description: e.target.value })} />
          </div>
          <div>
            <label className="text-xs text-secondary" htmlFor="product-desc">Description</label>
            <textarea id="product-desc" rows={5} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </div>
          <div>
            <label className="text-xs text-secondary" htmlFor="product-url">Public URL</label>
            <input id="product-url" value={form.public_url} onChange={(e) => setForm({ ...form, public_url: e.target.value })} placeholder="https://…" />
          </div>
          <div className="text-xs text-muted">New products start as drafts. Submit for review, then approve, before they ground AI content.</div>
        </div>
      </Modal>
    </div>
  );
}
