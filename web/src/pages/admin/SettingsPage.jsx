import { useEffect, useState } from 'react';
import { Save, Plus, Trash2 } from 'lucide-react';
import { adminService } from '../../services/adminService';
import { Card } from '../../components/common/Card';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';

export function SettingsPage() {
  const [rows, setRows] = useState([]);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    adminService.settings()
      .then((d) => {
        const list = Object.entries(d || {}).map(([key, value]) => ({
          key,
          value: value == null ? '' : (typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value)),
          isObject: typeof value === 'object' && value !== null,
        }));
        setRows(list);
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  const updateVal = (i, patch) => setRows((prev) => prev.map((r, idx) => idx === i ? { ...r, ...patch } : r));
  const removeRow = (i) => setRows((prev) => prev.filter((_, idx) => idx !== i));
  const addRow    = () => setRows((prev) => [...prev, { key: '', value: '', isObject: false }]);

  const save = async () => {
    setSaving(true); setSaved(false); setError(null);
    try {
      const body = {};
      for (const r of rows) {
        if (!r.key) continue;
        let val = r.value;
        if (r.isObject) {
          try { val = JSON.parse(r.value); }
          catch { throw new Error(`Setting "${r.key}" must be valid JSON.`); }
        }
        body[r.key] = val;
      }
      await adminService.updateSettings(body);
      setSaved(true);
    } catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  if (loading) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Portal settings</h1>
          <p className="text-sm text-muted">Key/value app-level settings stored in the database.</p>
        </div>
        <div className="flex gap-2">
          <button className="btn btn-secondary" onClick={addRow}><Plus size={14}/> Add setting</button>
          <button className="btn btn-primary" onClick={save} disabled={saving}><Save size={14}/> {saving ? 'Saving…' : 'Save all'}</button>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {saved && <Alert variant="success">Settings saved.</Alert>}

      <Card padding={false}>
        <div style={{ padding: '1rem' }}>
          {rows.length === 0 && <div className="text-sm text-muted">No settings entries yet. Add one to get started.</div>}
          {rows.map((r, i) => (
            <div key={i} className="flex flex-col gap-1 mb-4" style={{ borderBottom: '1px solid var(--color-border)', paddingBottom: 12 }}>
              <div className="flex items-center gap-2">
                <input
                  value={r.key}
                  onChange={(e) => updateVal(i, { key: e.target.value })}
                  placeholder="setting_key"
                  style={{ fontFamily: 'monospace', flex: 1 }}
                />
                <label className="text-xs text-secondary flex items-center gap-1">
                  <input type="checkbox" checked={r.isObject} onChange={(e) => updateVal(i, { isObject: e.target.checked })} style={{ width: 'auto' }} /> JSON
                </label>
                <button className="btn btn-danger btn-sm" onClick={() => removeRow(i)} title="Remove"><Trash2 size={12}/></button>
              </div>
              {r.isObject ? (
                <textarea rows={4} value={r.value} onChange={(e) => updateVal(i, { value: e.target.value })} style={{ fontFamily: 'monospace', fontSize: '0.8rem' }} />
              ) : (
                <input value={r.value} onChange={(e) => updateVal(i, { value: e.target.value })} />
              )}
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
