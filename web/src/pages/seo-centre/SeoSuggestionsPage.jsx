import { useEffect, useState } from 'react';
import { api } from '../../services/api';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';

export function SeoSuggestionsPage() {
  const [suggestions, setSuggestions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get('v1/seo/suggestions')
      .then((res) => setSuggestions(res?.suggestions ?? []))
      .catch((err) => setError(err?.message || 'Unable to load suggestions.'))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Loader label="Analysing search data…" />;
  if (error) return <Alert variant="error">{error}</Alert>;

  return (
    <div>
      <Card title={`Title/meta opportunities (${suggestions.length})`}>
        <p className="text-muted text-sm">
          Pages with 100+ impressions in 28 days but CTR under 2% — real Search Console data, no
          fabricated advice. Fixing these feeds the roadmap's IMPROVE_TITLE_META decisions.
        </p>
        {suggestions.length === 0 ? (
          <p className="text-muted">Nothing to improve right now (or Search Console has no data yet).</p>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="data-table">
              <thead><tr><th>Page</th><th>Impressions</th><th>Clicks</th><th>CTR</th><th>Avg position</th></tr></thead>
              <tbody>
                {suggestions.map((row) => (
                  <tr key={row.page_url}>
                    <td className="text-sm" style={{ maxWidth: '24rem', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                      <a href={row.page_url} target="_blank" rel="noreferrer">{row.page_url}</a>
                    </td>
                    <td>{row.impressions}</td>
                    <td>{row.clicks}</td>
                    <td>{row.ctr ? `${(Number(row.ctr) * 100).toFixed(2)}%` : '—'}</td>
                    <td>{row.avg_position ? Number(row.avg_position).toFixed(1) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

export default SeoSuggestionsPage;
