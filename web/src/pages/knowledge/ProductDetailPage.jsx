import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Check, X } from 'lucide-react';
import { knowledgeService } from '../../services/knowledgeService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { KnowledgeStatusBadge } from '../../components/knowledge/KnowledgeStatusBadge';
import { CompletenessGauge } from '../../components/knowledge/CompletenessGauge';
import { usePermission } from '../../hooks/usePermission';

export function ProductDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { has } = usePermission();

  const [product, setProduct]     = useState(null);
  const [modules, setModules]     = useState([]);
  const [claims, setClaims]       = useState([]);
  const [completeness, setCompl]  = useState(null);
  const [error, setError]         = useState(null);
  const [busy, setBusy]           = useState(false);

  const load = useCallback(() => {
    knowledgeService.getProduct(id).then(setProduct).catch((e) => setError(e.message));
    knowledgeService.listModules({ product_id: id, limit: 50 }).then((d) => setModules(d.items || [])).catch(() => {});
    knowledgeService.listClaims({ product_id: id, limit: 50 }).then((d) => setClaims(d.items || [])).catch(() => {});
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const approve = async () => {
    setBusy(true);
    try { await knowledgeService.approveProduct(id); load(); }
    catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };

  const reject = async () => {
    const note = window.prompt('Rejection reason (optional):') || '';
    setBusy(true);
    try { await knowledgeService.rejectProduct(id, note); load(); }
    catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };

  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!product) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <button className="btn btn-secondary btn-sm" onClick={() => navigate('/knowledge/products')}>
            <ArrowLeft size={12} /> Products
          </button>
          <h1 style={{ marginTop: 6 }}>{product.name}</h1>
          <div className="text-xs text-muted">{product.slug}</div>
        </div>
        <div className="flex gap-2 flex-wrap">
          <KnowledgeStatusBadge status={product.knowledge_status || product.status} />
          {has('product.approve') && product.knowledge_status !== 'approved' && (
            <button className="btn btn-primary btn-sm" disabled={busy} onClick={approve}>
              <Check size={13} /> Approve
            </button>
          )}
          {has('product.approve') && product.knowledge_status !== 'rejected' && (
            <button className="btn btn-danger btn-sm" disabled={busy} onClick={reject}>
              <X size={13} /> Reject
            </button>
          )}
        </div>
      </div>

      <div className="grid grid-2" style={{ alignItems: 'start', gap: '1rem' }}>
        <div className="flex flex-col gap-3">
          <Card title="Overview">
            <div className="grid grid-2">
              <div>
                <div className="text-xs text-muted">Public URL</div>
                <div className="text-sm">
                  {product.public_url ? (
                    <a href={product.public_url} target="_blank" rel="noreferrer">{product.public_url}</a>
                  ) : '—'}
                </div>
              </div>
              <div>
                <div className="text-xs text-muted">Knowledge Status</div>
                <KnowledgeStatusBadge status={product.knowledge_status || product.status} />
              </div>
              <div style={{ gridColumn: 'span 2' }}>
                <div className="text-xs text-muted">Short Description</div>
                <div className="text-sm">{product.short_description || '—'}</div>
              </div>
              <div style={{ gridColumn: 'span 2' }}>
                <div className="text-xs text-muted">Description</div>
                <div className="text-sm" dangerouslySetInnerHTML={{ __html: product.description || '—' }} />
              </div>
            </div>
          </Card>

          <Card title={`Modules (${modules.length})`}>
            {modules.length === 0 && <div className="text-sm text-muted">No modules defined.</div>}
            {modules.map((m) => (
              <div key={m.id} style={{ padding: '0.4rem 0', borderBottom: '1px solid var(--color-border)' }}>
                <div className="font-semibold text-sm">{m.name}</div>
                <div className="text-xs text-muted">{m.slug} — {m.description || '—'}</div>
              </div>
            ))}
          </Card>
        </div>

        <div className="flex flex-col gap-3">
          {completeness && (
            <Card title="Completeness">
              <CompletenessGauge percent={completeness.percent} size={80} />
              {completeness.missing?.length > 0 && (
                <ul style={{ marginTop: 8, fontSize: 12, color: '#6b7280' }}>
                  {completeness.missing.map((m) => <li key={m}>• {m}</li>)}
                </ul>
              )}
            </Card>
          )}

          <Card title={`Claims (${claims.length})`}>
            {claims.length === 0 && <div className="text-sm text-muted">No claims defined.</div>}
            {claims.slice(0, 5).map((c) => (
              <div key={c.id} style={{ padding: '0.35rem 0', borderBottom: '1px solid var(--color-border)' }}>
                <div className="text-sm">{c.claim_text}</div>
                <div className="text-xs text-muted">Risk: {c.risk_level}</div>
              </div>
            ))}
            {claims.length > 5 && <div className="text-xs text-muted" style={{ marginTop: 4 }}>+{claims.length - 5} more…</div>}
          </Card>

          <Card title="Metadata">
            <div className="text-xs text-muted">Created</div>
            <div className="text-sm">{product.created_at ? new Date(product.created_at).toLocaleString() : '—'}</div>
            <div className="text-xs text-muted mt-2">Updated</div>
            <div className="text-sm">{product.updated_at ? new Date(product.updated_at).toLocaleString() : '—'}</div>
            {product.approved_at && <>
              <div className="text-xs text-muted mt-2">Approved</div>
              <div className="text-sm">{new Date(product.approved_at).toLocaleString()}</div>
            </>}
          </Card>
        </div>
      </div>
    </div>
  );
}
