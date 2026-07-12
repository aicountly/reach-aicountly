import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Activity } from 'lucide-react';
import { knowledgeService } from '../../services/knowledgeService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { CompletenessGauge } from '../../components/knowledge/CompletenessGauge';
import { KnowledgeStatusBadge } from '../../components/knowledge/KnowledgeStatusBadge';

function ScoreBar({ score }) {
  const color = score >= 80 ? '#10b981' : score >= 50 ? '#f59e0b' : '#ef4444';
  return (
    <div style={{ width: '100%', height: 6, background: '#e5e7eb', borderRadius: 3, overflow: 'hidden' }}>
      <div style={{ width: `${score}%`, height: '100%', background: color, borderRadius: 3, transition: 'width 0.3s' }} />
    </div>
  );
}

export function CompletenessPage() {
  const navigate = useNavigate();
  const [items, setItems]     = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  useEffect(() => {
    setLoading(true);
    knowledgeService.completenessAll()
      .then((d) => setItems(d.items || []))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  const avg = items.length > 0
    ? Math.round(items.reduce((sum, i) => sum + i.score, 0) / items.length)
    : 0;

  const aiReadyCount = items.filter(i => i.ai_ready).length;

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Activity size={20} /> Knowledge Completeness
          </h1>
          <p className="text-sm text-muted">
            Completeness scores per product. A product must score across all 12 dimensions to be considered AI-ready.
          </p>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}

      {!loading && items.length > 0 && (
        <div style={{ display: 'flex', gap: 16, marginBottom: 24, flexWrap: 'wrap' }}>
          <div style={{ border: '1px solid var(--color-border)', borderRadius: 12, padding: '1.25rem 1.5rem', background: 'var(--color-surface)', minWidth: 160, textAlign: 'center' }}>
            <CompletenessGauge percent={avg} size={80} />
            <div className="text-xs text-muted" style={{ marginTop: 6 }}>Average score</div>
          </div>
          <div style={{ border: '1px solid var(--color-border)', borderRadius: 12, padding: '1.25rem 1.5rem', background: 'var(--color-surface)', display: 'flex', flexDirection: 'column', justifyContent: 'center', minWidth: 140 }}>
            <div style={{ fontSize: 28, fontWeight: 700, color: '#10b981' }}>{aiReadyCount}</div>
            <div className="text-xs text-muted">AI-ready products</div>
          </div>
          <div style={{ border: '1px solid var(--color-border)', borderRadius: 12, padding: '1.25rem 1.5rem', background: 'var(--color-surface)', display: 'flex', flexDirection: 'column', justifyContent: 'center', minWidth: 140 }}>
            <div style={{ fontSize: 28, fontWeight: 700 }}>{items.length}</div>
            <div className="text-xs text-muted">Total products</div>
          </div>
        </div>
      )}

      {loading ? <Loader label="Calculating completeness…" /> : (
        <div style={{ border: '1px solid var(--color-border)', borderRadius: 8, overflow: 'hidden', background: 'var(--color-surface)' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ borderBottom: '2px solid var(--color-border)', background: 'var(--color-muted, #f9fafb)' }}>
                <th style={{ padding: '10px 14px', textAlign: 'left', fontWeight: 600 }}>Product</th>
                <th style={{ padding: '10px 14px', textAlign: 'left', fontWeight: 600 }}>Status</th>
                <th style={{ padding: '10px 14px', textAlign: 'left', fontWeight: 600, width: 200 }}>Score</th>
                <th style={{ padding: '10px 14px', textAlign: 'center', fontWeight: 600 }}>AI-ready</th>
                <th style={{ padding: '10px 14px', textAlign: 'center', fontWeight: 600 }}>Gaps</th>
              </tr>
            </thead>
            <tbody>
              {items.length === 0 && (
                <tr>
                  <td colSpan={5} style={{ padding: '1.5rem', textAlign: 'center', color: '#6b7280' }}>
                    No products found.
                  </td>
                </tr>
              )}
              {items.map((item, i) => (
                <tr
                  key={item.product_id}
                  style={{ borderBottom: i < items.length - 1 ? '1px solid var(--color-border)' : 'none', cursor: 'pointer' }}
                  onClick={() => navigate(`/knowledge/products/${item.product_id}`)}
                >
                  <td style={{ padding: '10px 14px' }}>
                    <div className="font-semibold">{item.name}</div>
                    <div className="text-xs text-muted">{item.slug}</div>
                  </td>
                  <td style={{ padding: '10px 14px' }}>
                    <KnowledgeStatusBadge status={item.status} />
                  </td>
                  <td style={{ padding: '10px 14px' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <div style={{ flex: 1 }}><ScoreBar score={item.score} /></div>
                      <div style={{ fontSize: 12, fontWeight: 600, minWidth: 32, textAlign: 'right' }}>{item.score}%</div>
                    </div>
                  </td>
                  <td style={{ padding: '10px 14px', textAlign: 'center' }}>
                    {item.ai_ready ? (
                      <span style={{ color: '#10b981', fontWeight: 700 }}>✓</span>
                    ) : (
                      <span style={{ color: '#6b7280' }}>—</span>
                    )}
                  </td>
                  <td style={{ padding: '10px 14px', textAlign: 'center' }}>
                    <span style={{
                      background: item.gap_count > 0 ? '#fee2e2' : '#d1fae5',
                      color: item.gap_count > 0 ? '#991b1b' : '#065f46',
                      borderRadius: 12, padding: '2px 8px', fontSize: 11, fontWeight: 600,
                    }}>
                      {item.gap_count}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
