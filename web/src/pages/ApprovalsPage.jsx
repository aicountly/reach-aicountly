import { useCallback, useEffect, useState } from 'react';
import { Check, X, RotateCcw, AlertTriangle, Clock, ChevronDown, ChevronUp } from 'lucide-react';
import { approvalService } from '../services/approvalService';
import { Card } from '../components/common/Card';
import { Alert } from '../components/common/Alert';
import { Loader } from '../components/common/Loader';
import { DataTable } from '../components/common/DataTable';
import { ApprovalBadge } from '../components/common/ApprovalBadge';
import { usePermission } from '../hooks/usePermission';

/** Fetch approval queue from Phase 2 endpoint, fall back to Phase 0 list. */
async function fetchQueue(area) {
  try {
    const res = await fetch(`/api/v1/approval-queue?area=${area}`, {
      headers: { Authorization: `Bearer ${localStorage.getItem('reach_token')}` },
    });
    if (res.ok) {
      const d = await res.json();
      return d.data?.items ?? [];
    }
  } catch { /* fall through */ }
  // Phase 0 fallback
  const d = await approvalService.list({ decision: 'pending' });
  return d.items || d;
}

async function fetchStats() {
  try {
    const res = await fetch('/api/v1/approval-queue/stats', {
      headers: { Authorization: `Bearer ${localStorage.getItem('reach_token')}` },
    });
    if (res.ok) {
      const d = await res.json();
      return d.data?.counts ?? {};
    }
  } catch { /* ignore */ }
  return {};
}

async function queueAction(id, action, body = {}) {
  const res = await fetch(`/api/v1/approval-queue/${id}/${action}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${localStorage.getItem('reach_token')}`,
    },
    body: JSON.stringify(body),
  });
  if (!res.ok) {
    const d = await res.json();
    throw new Error(d.error || 'Action failed');
  }
  return res.json();
}

/** Dashboard area definitions */
const AREAS = [
  { id: 'today',             label: 'Today',              color: '#3b82f6' },
  { id: 'overdue',           label: 'Overdue',            color: '#ef4444' },
  { id: 'high_risk',         label: 'High Risk',          color: '#f97316' },
  { id: 'changes_requested', label: 'Changes Requested',  color: '#8b5cf6' },
  { id: 'ready_for_approval',label: 'Ready for Approval', color: '#10b981' },
  { id: 'scheduled',         label: 'Scheduled',          color: '#6366f1' },
  { id: 'recently_approved', label: 'Recently Approved',  color: '#14b8a6' },
  { id: 'all',               label: 'All',                color: '#6b7280' },
];

const RISK_COLORS = {
  low:      '#10b981',
  medium:   '#f59e0b',
  high:     '#f97316',
  critical: '#ef4444',
};

function RiskBadge({ level }) {
  return (
    <span style={{
      background: RISK_COLORS[level] || '#6b7280',
      color: '#fff',
      borderRadius: 4,
      padding: '1px 7px',
      fontSize: 11,
      fontWeight: 600,
      textTransform: 'uppercase',
    }}>{level || 'low'}</span>
  );
}

function WorkflowBadge({ status }) {
  const colors = {
    review_pending:    '#3b82f6',
    changes_requested: '#8b5cf6',
    approved:          '#10b981',
    rejected:          '#ef4444',
    scheduled:         '#6366f1',
  };
  return (
    <span style={{
      background: colors[status] || '#6b7280',
      color: '#fff',
      borderRadius: 4,
      padding: '1px 7px',
      fontSize: 11,
    }}>{status?.replace(/_/g, ' ')}</span>
  );
}

export function ApprovalsPage() {
  const [activeArea, setActiveArea]     = useState('today');
  const [rows, setRows]                 = useState([]);
  const [stats, setStats]               = useState({});
  const [loading, setLoading]           = useState(true);
  const [statsLoading, setStatsLoading] = useState(true);
  const [error, setError]               = useState(null);
  const [expandedId, setExpandedId]     = useState(null);
  const [bulkSelected, setBulkSelected] = useState([]);
  const { has } = usePermission();

  const canApprove = has('content.approve') || has('approval.decide');
  const canReview  = has('content.review') || has('approval.decide');

  const loadStats = useCallback(() => {
    setStatsLoading(true);
    fetchStats().then(setStats).finally(() => setStatsLoading(false));
  }, []);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    setBulkSelected([]);
    fetchQueue(activeArea)
      .then(setRows)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [activeArea]);

  useEffect(load, [load]);
  useEffect(loadStats, [loadStats]);

  const handleApprove = async (id) => {
    if (!canApprove) return;
    try { await queueAction(id, 'approve'); load(); loadStats(); }
    catch (e) { setError(e.message); }
  };

  const handleReject = async (id) => {
    if (!canApprove) return;
    const reason = window.prompt('Rejection reason (required):');
    if (!reason) return;
    try { await queueAction(id, 'reject', { reason }); load(); loadStats(); }
    catch (e) { setError(e.message); }
  };

  const handleReturn = async (id) => {
    if (!canReview) return;
    const reason = window.prompt('Reason for requesting changes (required):');
    if (!reason) return;
    try { await queueAction(id, 'return', { reason }); load(); loadStats(); }
    catch (e) { setError(e.message); }
  };

  /** Bulk approve — high/critical risk items are blocked server-side. */
  const handleBulkApprove = async () => {
    if (!canApprove || bulkSelected.length === 0) return;
    try {
      const res = await fetch('/api/v1/approval-queue/bulk-approve', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${localStorage.getItem('reach_token')}`,
        },
        body: JSON.stringify({ ids: bulkSelected }),
      });
      const d = await res.json();
      if (d.data?.blocked?.length) {
        setError(`${d.data.blocked.length} item(s) blocked: ${d.data.blocked.map(b => b.reason).join('; ')}`);
      }
      load();
      loadStats();
    } catch (e) { setError(e.message); }
  };

  const toggleSelect = (id) => {
    setBulkSelected((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    );
  };

  const isHighRisk = (row) => ['high', 'critical'].includes(row.risk_level);

  const columns = [
    {
      key: 'select',
      label: '',
      render: (r) => (
        <input
          type="checkbox"
          disabled={isHighRisk(r)}
          title={isHighRisk(r) ? 'High/critical risk requires individual approval' : ''}
          checked={bulkSelected.includes(r.id)}
          onChange={() => toggleSelect(r.id)}
        />
      ),
    },
    { key: 'id', label: '#', render: (r) => r.id },
    { key: 'title', label: 'Title', render: (r) => (
      <div>
        <div style={{ fontWeight: 600, fontSize: 13 }}>{r.title || r.summary || r.subject_type + ' #' + r.subject_id}</div>
        {r.content_type && <div style={{ fontSize: 11, color: '#6b7280' }}>{r.content_type?.replace(/_/g, ' ')}</div>}
      </div>
    )},
    { key: 'status', label: 'Status', render: (r) => <WorkflowBadge status={r.workflow_status || r.decision} /> },
    { key: 'risk', label: 'Risk', render: (r) => r.risk_level ? <RiskBadge level={r.risk_level} /> : '—' },
    { key: 'due', label: 'Review Due', render: (r) => r.review_due_at ? (
      <span style={{ color: new Date(r.review_due_at) < new Date() ? '#ef4444' : undefined }}>
        {new Date(r.review_due_at).toLocaleDateString()}
      </span>
    ) : '—' },
    { key: 'product', label: 'Product', render: (r) => r.primary_product_id ? `#${r.primary_product_id}` : '—' },
    { key: 'expand', label: '', render: (r) => (
      <button className="btn btn-ghost btn-sm" onClick={() => setExpandedId(expandedId === r.id ? null : r.id)}>
        {expandedId === r.id ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
      </button>
    )},
    { key: 'actions', label: '', render: (r) => (
      <div className="flex gap-1">
        {canApprove && (r.workflow_status === 'review_pending' || r.decision === 'pending') && (
          <button className="btn btn-primary btn-sm" onClick={() => handleApprove(r.id)}>
            <Check size={12} /> Approve
          </button>
        )}
        {canReview && (r.workflow_status === 'review_pending' || r.decision === 'pending') && (
          <>
            <button className="btn btn-warning btn-sm" onClick={() => handleReturn(r.id)}>
              <RotateCcw size={12} /> Return
            </button>
            <button className="btn btn-danger btn-sm" onClick={() => handleReject(r.id)}>
              <X size={12} /> Reject
            </button>
          </>
        )}
      </div>
    )},
  ];

  const activeAreaDef = AREAS.find((a) => a.id === activeArea);

  return (
    <div>
      {/* Page header */}
      <div className="page-header">
        <div>
          <h1>Daily Approval Centre</h1>
          <p className="text-sm text-muted">Cross-type content approval queue — review, approve, reject, or return for changes.</p>
        </div>
        {canApprove && bulkSelected.length > 0 && (
          <button className="btn btn-primary" onClick={handleBulkApprove}>
            Bulk approve {bulkSelected.length} item(s)
          </button>
        )}
      </div>

      {/* Area stats bar */}
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 20 }}>
        {AREAS.map((area) => (
          <button
            key={area.id}
            onClick={() => setActiveArea(area.id)}
            style={{
              padding: '6px 14px',
              borderRadius: 20,
              border: `2px solid ${area.color}`,
              background: activeArea === area.id ? area.color : 'transparent',
              color: activeArea === area.id ? '#fff' : area.color,
              fontWeight: 600,
              fontSize: 12,
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: 6,
            }}
          >
            {area.label}
            {!statsLoading && stats[area.id] !== undefined && (
              <span style={{
                background: activeArea === area.id ? 'rgba(255,255,255,0.25)' : area.color,
                color: '#fff',
                borderRadius: 10,
                padding: '0 6px',
                fontSize: 11,
              }}>{stats[area.id]}</span>
            )}
          </button>
        ))}
      </div>

      {error && <Alert variant="danger" onDismiss={() => setError(null)}>{error}</Alert>}

      {loading ? <Loader /> : (
        <Card padding={false}>
          <div style={{ padding: '12px 16px', borderBottom: '1px solid #e5e7eb', background: '#f9fafb' }}>
            <span style={{ fontWeight: 700, fontSize: 14, color: activeAreaDef?.color }}>
              {activeAreaDef?.label}
            </span>
            <span style={{ marginLeft: 8, color: '#6b7280', fontSize: 12 }}>
              {rows.length} item{rows.length !== 1 ? 's' : ''}
            </span>
            {bulkSelected.length > 0 && (
              <span style={{ marginLeft: 12, color: '#6366f1', fontSize: 12 }}>
                {bulkSelected.length} selected (high/critical risk excluded from bulk)
              </span>
            )}
          </div>
          <DataTable
            columns={columns}
            rows={rows}
            expandedRowId={expandedId}
            renderExpanded={(r) => (
              <div style={{ padding: 16, background: '#f9fafb', fontSize: 13 }}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12 }}>
                  <div><strong>Content type:</strong> {r.content_type || '—'}</div>
                  <div><strong>Language:</strong> {r.language || '—'}</div>
                  <div><strong>Market:</strong> {r.market_id ? `#${r.market_id}` : '—'}</div>
                  <div><strong>Validation status:</strong> {r.validation_status || '—'}</div>
                  <div><strong>Publication status:</strong> {r.publication_status || '—'}</div>
                  <div><strong>Creation source:</strong> {r.creation_source || '—'}</div>
                  {r.summary && <div style={{ gridColumn: '1/-1' }}><strong>Summary:</strong> {r.summary}</div>}
                </div>
              </div>
            )}
            emptyMessage="No items in this area."
          />
        </Card>
      )}
    </div>
  );
}
