import { useState } from 'react';
import { Users2, Plus } from 'lucide-react';

const COMPETITORS = [
  { id: 1, name: 'QuickBooks India', domain: 'quickbooks.intuit.com', category: 'accounting_software', monitoring_status: 'active', mention_rate: 0.72 },
  { id: 2, name: 'Zoho Books', domain: 'zoho.com/books', category: 'accounting_software', monitoring_status: 'active', mention_rate: 0.64 },
  { id: 3, name: 'Tally Solutions', domain: 'tallysolutions.com', category: 'accounting_software', monitoring_status: 'active', mention_rate: 0.58 },
  { id: 4, name: 'FreshBooks', domain: 'freshbooks.com', category: 'invoicing', monitoring_status: 'active', mention_rate: 0.31 },
];

export default function CompetitorListPage() {
  const [_showForm, setShowForm] = useState(false);

  return (
    <div>
      <div className="page-header">
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
          <Users2 size={22} style={{ color: 'var(--color-primary)', flexShrink: 0 }} aria-hidden="true" />
          <div>
            <h1 style={{ margin: 0 }}>Competitor Monitoring</h1>
            <p className="page-header__subtitle" style={{ marginTop: '0.15rem' }}>
              Track competitor mentions in AI assistant responses
            </p>
          </div>
        </div>
        <div className="page-header__actions">
          <button
            type="button"
            onClick={() => setShowForm((v) => !v)}
            className="btn btn--sm btn--primary"
          >
            <Plus size={14} aria-hidden="true" /> Add Competitor
          </button>
        </div>
      </div>

      <div className="alert alert-warning mb-4">
        <strong>Disclosure:</strong> Mention rates are based on a sample of monitored AI prompt responses and do not represent comprehensive market data or actual search rankings.
      </div>

      <div className="card">
        <table className="data-table">
          <thead>
            <tr>
              <th>Competitor</th>
              <th>Domain</th>
              <th>Category</th>
              <th style={{ textAlign: 'right' }}>Sample Mention Rate</th>
              <th style={{ textAlign: 'center' }}>Status</th>
            </tr>
          </thead>
          <tbody>
            {COMPETITORS.map((c) => (
              <tr key={c.id}>
                <td style={{ fontWeight: 600 }}>{c.name}</td>
                <td className="text-sm text-muted" style={{ fontFamily: 'var(--font-mono, monospace)' }}>{c.domain}</td>
                <td className="text-sm">{c.category.replace(/_/g, ' ')}</td>
                <td style={{ textAlign: 'right', fontWeight: 600 }}>{(c.mention_rate * 100).toFixed(0)}%</td>
                <td style={{ textAlign: 'center' }}>
                  <span className={`badge ${c.monitoring_status === 'active' ? 'badge--success' : 'badge--muted'}`}>
                    {c.monitoring_status}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
