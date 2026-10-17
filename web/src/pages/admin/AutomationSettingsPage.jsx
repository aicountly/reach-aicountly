import { useEffect, useMemo, useState } from 'react';
import { Save, PlugZap, Eraser } from 'lucide-react';
import { adminService } from '../../services/adminService';
import { Card } from '../../components/common/Card';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';

// Which provider check each group's "Test" button runs.
const GROUP_TESTS = {
  'AI providers': ['gemini', 'openai', 'perplexity'],
  'SEO data': ['dataforseo'],
};

const SOURCE_LABEL = { settings: 'saved here', env: 'from server .env', default: 'default', unset: 'not set' };

function groupRows(rows) {
  const groups = [];
  for (const row of rows) {
    let g = groups.find((x) => x.name === row.group);
    if (!g) { g = { name: row.group, rows: [] }; groups.push(g); }
    g.rows.push(row);
  }
  return groups;
}

function displayValue(row) {
  if (row.type === 'bool') return Boolean(row.value);
  return row.value == null ? '' : String(row.value);
}

/**
 * Settings → AI & Automation. Secrets are write-only: the API only says
 * whether one is set and where it comes from, and a field left empty is not
 * sent, so saving never touches a key nobody retyped.
 */
export function AutomationSettingsPage() {
  const [rows, setRows]       = useState([]);
  const [edits, setEdits]     = useState({});
  const [models, setModels]   = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving]   = useState(null);
  const [error, setError]     = useState(null);
  const [notice, setNotice]   = useState(null);
  const [tests, setTests]     = useState({});

  const load = () => adminService.automationSettings().then((d) => setRows(Array.isArray(d) ? d : []));

  useEffect(() => {
    Promise.all([load(), adminService.geminiModels().then((m) => setModels(Array.isArray(m) ? m : [])).catch(() => setModels([]))])
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  const groups = useMemo(() => groupRows(rows), [rows]);

  const setEdit = (key, value) => setEdits((prev) => ({ ...prev, [key]: value }));

  const saveGroup = async (group, override = null) => {
    const body = override ?? {};
    if (!override) {
      for (const row of group.rows) {
        if (!(row.key in edits)) continue;
        if (row.type === 'secret' && edits[row.key] === '') continue;
        body[row.key] = edits[row.key];
      }
    }
    if (Object.keys(body).length === 0) { setNotice(`Nothing changed in ${group.name}.`); return; }
    setSaving(group.name); setError(null); setNotice(null);
    try {
      const d = await adminService.updateAutomationSettings(body);
      setRows(Array.isArray(d) ? d : rows);
      setEdits((prev) => Object.fromEntries(Object.entries(prev).filter(([k]) => !(k in body))));
      setNotice(`${group.name} saved.`);
      if (Object.keys(body).some((k) => k.startsWith('ai.gemini'))) {
        adminService.geminiModels().then((m) => setModels(Array.isArray(m) ? m : [])).catch(() => {});
      }
    } catch (e) { setError(e.message); }
    finally { setSaving(null); }
  };

  const clearSecret = (group, row) => {
    if (!window.confirm(`Clear the saved ${row.label}? The server .env value (if any) will be used instead.`)) return;
    saveGroup(group, { [row.key]: '' });
  };

  const runTest = async (target) => {
    setTests((prev) => ({ ...prev, [target]: { pending: true } }));
    try {
      const r = await adminService.testAutomationProvider(target);
      setTests((prev) => ({ ...prev, [target]: r }));
    } catch (e) {
      setTests((prev) => ({ ...prev, [target]: { ok: false, message: e.message } }));
    }
  };

  if (loading) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>AI &amp; Automation</h1>
          <p className="page-header__subtitle">
            Keys, the model that vets and finalises content, image generation, daily volumes and reports.
            The vetting model&apos;s decision is final — approved content is published without further review.
          </p>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {notice && <Alert variant="success">{notice}</Alert>}

      {groups.map((group) => (
        <Card key={group.name} title={group.name}>
          <div className="settings-list">
            {group.rows.map((row) => {
              const id = `setting-${row.key}`;
              return (
                <div key={row.key} className="settings-row">
                  <label className="settings-row__field" htmlFor={id}>
                    <span className="settings-row__label">
                      {row.label}{' '}
                      <span className="text-muted text-sm">
                        {row.type === 'secret'
                          ? (row.is_set ? `· set (${SOURCE_LABEL[row.source] ?? row.source})` : '· not set')
                          : `· ${SOURCE_LABEL[row.source] ?? row.source}`}
                      </span>
                    </span>
                    {row.type === 'secret' && (
                      <input
                        id={id}
                        type="password"
                        autoComplete="new-password"
                        placeholder={row.is_set ? 'Leave blank to keep the current key' : 'Paste key'}
                        value={edits[row.key] ?? ''}
                        onChange={(e) => setEdit(row.key, e.target.value)}
                        className="settings-row__input"
                      />
                    )}
                    {row.type === 'bool' && (
                      <input
                        id={id}
                        type="checkbox"
                        checked={row.key in edits ? Boolean(edits[row.key]) : displayValue(row)}
                        onChange={(e) => setEdit(row.key, e.target.checked)}
                      />
                    )}
                    {row.type === 'int' && (
                      <input
                        id={id}
                        type="number"
                        min="0"
                        value={edits[row.key] ?? displayValue(row)}
                        onChange={(e) => setEdit(row.key, e.target.value)}
                        className="settings-row__input"
                      />
                    )}
                    {row.type === 'model' && (
                      <>
                        <input
                          id={id}
                          list={`${id}-models`}
                          placeholder={row.key === 'ai.vetting.model.default' ? 'e.g. gemini-2.5-flash' : 'Blank = default vetting model'}
                          value={edits[row.key] ?? displayValue(row)}
                          onChange={(e) => setEdit(row.key, e.target.value)}
                          className="settings-row__input"
                          spellCheck={false}
                        />
                        <datalist id={`${id}-models`}>
                          {models.map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
                        </datalist>
                      </>
                    )}
                    {(row.type === 'string' || row.type === 'email_list') && (
                      <input
                        id={id}
                        value={edits[row.key] ?? displayValue(row)}
                        onChange={(e) => setEdit(row.key, e.target.value)}
                        className="settings-row__input"
                        spellCheck={false}
                      />
                    )}
                  </label>
                  {row.help && <p className="text-sm text-muted" style={{ margin: '4px 0 0' }}>{row.help}</p>}
                  {row.type === 'secret' && row.is_set && row.source === 'settings' && (
                    <button type="button" className="btn btn-secondary btn-sm" onClick={() => clearSecret(group, row)}>
                      <Eraser size={14} aria-hidden="true" /> Clear saved key
                    </button>
                  )}
                </div>
              );
            })}
          </div>

          <div className="page-header__actions" style={{ marginTop: 12 }}>
            {(GROUP_TESTS[group.name] ?? []).map((target) => (
              <button key={target} type="button" className="btn btn-secondary" onClick={() => runTest(target)} disabled={tests[target]?.pending}>
                <PlugZap size={14} aria-hidden="true" /> Test {target}
              </button>
            ))}
            <button type="button" className="btn btn-primary" onClick={() => saveGroup(group)} disabled={saving === group.name}>
              <Save size={14} aria-hidden="true" /> {saving === group.name ? 'Saving…' : `Save ${group.name}`}
            </button>
          </div>
          {(GROUP_TESTS[group.name] ?? []).map((target) => tests[target] && !tests[target].pending && (
            <Alert key={target} variant={tests[target].ok ? 'success' : 'danger'}>
              {target}: {tests[target].message}
            </Alert>
          ))}
        </Card>
      ))}
    </div>
  );
}
