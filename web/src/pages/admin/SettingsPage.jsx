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
          <p className="page-header__subtitle">
            Key/value app-level settings stored in the database.
          </p>
        </div>
        <div className="page-header__actions">
          <button type="button" className="btn btn-secondary" onClick={addRow}>
            <Plus size={14} aria-hidden="true" /> Add setting
          </button>
          <button type="button" className="btn btn-primary" onClick={save} disabled={saving}>
            <Save size={14} aria-hidden="true" /> {saving ? 'Saving…' : 'Save all'}
          </button>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {saved && <Alert variant="success">Settings saved.</Alert>}

      {rows.length === 0 ? (
        <Card>
          <p className="text-sm text-muted" style={{ margin: 0 }}>
            No settings entries yet. Add one to get started.
          </p>
        </Card>
      ) : (
        <div className="settings-list">
          {rows.map((r, i) => (
            <div key={i} className="settings-row card">
              <div className="settings-row__toolbar">
                <label className="settings-row__field settings-row__field--key">
                  <span className="settings-row__label">Key</span>
                  <input
                    value={r.key}
                    onChange={(e) => updateVal(i, { key: e.target.value })}
                    placeholder="setting_key"
                    className="settings-row__input settings-row__input--key"
                    spellCheck={false}
                  />
                </label>

                <div className="settings-row__actions">
                  <label className="settings-row__json">
                    <input
                      type="checkbox"
                      checked={r.isObject}
                      onChange={(e) => updateVal(i, { isObject: e.target.checked })}
                    />
                    <span>JSON</span>
                  </label>
                  <button
                    type="button"
                    className="btn btn-danger btn-sm"
                    onClick={() => removeRow(i)}
                    title="Remove setting"
                    aria-label={`Remove ${r.key || 'setting'}`}
                  >
                    <Trash2 size={14} aria-hidden="true" />
                  </button>
                </div>
              </div>

              <label className="settings-row__field">
                <span className="settings-row__label">Value</span>
                {r.isObject ? (
                  <textarea
                    rows={4}
                    value={r.value}
                    onChange={(e) => updateVal(i, { value: e.target.value })}
                    className="settings-row__input settings-row__input--json"
                    spellCheck={false}
                  />
                ) : (
                  <input
                    value={r.value}
                    onChange={(e) => updateVal(i, { value: e.target.value })}
                    className="settings-row__input"
                  />
                )}
              </label>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
