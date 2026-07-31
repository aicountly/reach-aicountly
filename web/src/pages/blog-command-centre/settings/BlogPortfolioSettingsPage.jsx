import { useEffect, useState } from 'react';
import { Alert } from '../../../components/common/Alert';
import { Loader } from '../../../components/common/Loader';
import { Card } from '../../../components/common/Card';
import { blogCommandCentreService } from '../../../services/blogCommandCentreService';

const DEFAULT_MIX = { marketing: 45, product: 35, problem_to_product: 20 };

export function BlogPortfolioSettingsPage() {
  const [mix, setMix] = useState(DEFAULT_MIX);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    blogCommandCentreService.getPortfolioSettings()
      .then((data) => {
        if (data) setMix({ ...DEFAULT_MIX, ...data });
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const total = mix.marketing + mix.product + mix.problem_to_product;

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setSaved(false);
    try {
      await blogCommandCentreService.savePortfolioSettings(mix);
      setSaved(true);
    } catch (err) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <Loader />;

  return (
    <div>
      <h2>Portfolio Mix</h2>
      <p className="text-sm text-muted">Editable blog portfolio balance (must total 100%).</p>
      {error && <Alert variant="info">Save failed — API may not be available yet ({error}).</Alert>}
      {saved && <Alert variant="success">Portfolio mix saved.</Alert>}
      <Card title="Stream allocation">
        <form onSubmit={handleSave} className="flex flex-col gap-3">
          {['marketing', 'product', 'problem_to_product'].map((key) => (
            <div key={key}>
              <label className="text-xs text-secondary">{key.replace(/_/g, ' ')} (%)</label>
              <input
                type="number"
                min={0}
                max={100}
                value={mix[key]}
                onChange={(e) => setMix((s) => ({ ...s, [key]: Number(e.target.value) }))}
              />
            </div>
          ))}
          <p className={`text-sm ${total === 100 ? 'text-muted' : ''}`} style={total !== 100 ? { color: 'var(--color-danger)' } : undefined}>
            Total: {total}% {total !== 100 && '(must equal 100%)'}
          </p>
          <button type="submit" className="btn btn-primary" disabled={saving || total !== 100}>
            {saving ? 'Saving…' : 'Save portfolio mix'}
          </button>
        </form>
      </Card>
    </div>
  );
}
