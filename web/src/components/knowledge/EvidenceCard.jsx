import { AlertTriangle, ExternalLink } from 'lucide-react';
import { KnowledgeStatusBadge } from './KnowledgeStatusBadge';

/** Renders a compact evidence item card. */
export function EvidenceCard({ evidence }) {
  const isExpired = evidence.is_expired ||
    (evidence.valid_until && new Date(evidence.valid_until) < new Date());

  return (
    <div style={{
      border: '1px solid var(--color-border, #e5e7eb)',
      borderRadius: 8,
      padding: '0.75rem 1rem',
      marginBottom: '0.5rem',
      background: isExpired ? '#fff7ed' : 'var(--color-surface, #fff)',
    }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 8 }}>
        <div style={{ flex: 1 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4 }}>
            {isExpired && <AlertTriangle size={13} color="#f59e0b" />}
            <span style={{ fontWeight: 600, fontSize: 13 }}>{evidence.title || '(untitled)'}</span>
          </div>
          {evidence.summary && <p style={{ fontSize: 12, color: '#6b7280', margin: 0 }}>{evidence.summary}</p>}
          <div style={{ marginTop: 6, display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
            <span style={{ fontSize: 11, background: '#f3f4f6', borderRadius: 6, padding: '1px 6px' }}>
              {evidence.evidence_type}
            </span>
            <KnowledgeStatusBadge status={evidence.status} />
            {isExpired && <span style={{ fontSize: 11, color: '#d97706', fontWeight: 600 }}>EXPIRED</span>}
          </div>
        </div>
        {evidence.external_url && (
          <a href={evidence.external_url} target="_blank" rel="noopener noreferrer"
            style={{ color: '#3b82f6', flexShrink: 0 }}>
            <ExternalLink size={14} />
          </a>
        )}
      </div>
    </div>
  );
}
