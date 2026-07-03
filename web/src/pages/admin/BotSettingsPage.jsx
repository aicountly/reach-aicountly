import { useEffect, useState } from 'react';
import { Save } from 'lucide-react';
import { botService } from '../../services/botService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';

const ALL_ACTIONS = [
  'generate_campaign_ideas','generate_campaign_copy','generate_blog_draft',
  'generate_seo_brief','generate_social_posts','generate_creative_brief',
  'generate_content_calendar','suggest_hashtags_keywords','generate_analytics_summary',
  'recommend_campaign_improvements','prepare_approval_package','queue_approved_for_publishing',
];

export function BotSettingsPage() {
  const [settings, setSettings] = useState(null);
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    botService.getSettings().then(setSettings).catch((e) => setError(e.message));
  }, []);

  const toggle = (a) => {
    const set = new Set(settings.allowed_auto_actions || []);
    if (set.has(a)) set.delete(a); else set.add(a);
    setSettings({ ...settings, allowed_auto_actions: [...set] });
  };

  const save = async () => {
    setSaving(true); setSaved(false);
    try {
      const s = await botService.updateSettings(settings);
      setSettings(s);
      setSaved(true);
    } catch (e) { setError(e.message); }
    finally { setSaving(false); }
  };

  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!settings) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Marketing bot mode</h1>
          <p className="text-sm text-muted">Auto Mode runs low-risk internal actions immediately. Confirm Mode requires approval on every bot output.</p>
        </div>
        <button className="btn btn-primary" onClick={save} disabled={saving}><Save size={14}/> {saving ? 'Saving…' : 'Save'}</button>
      </div>

      {saved && <Alert variant="success">Bot settings saved.</Alert>}

      <div className="grid grid-2" style={{ alignItems: 'start' }}>
        <Card title="Mode">
          <label className="flex items-center gap-2 mb-3">
            <input
              type="radio" name="mode"
              checked={settings.mode === 'confirm'}
              onChange={() => setSettings({ ...settings, mode: 'confirm' })}
              style={{ width: 'auto' }}
            />
            <span className="text-sm"><strong>Confirm Mode</strong> — every bot action requires superadmin approval.</span>
          </label>
          <label className="flex items-center gap-2">
            <input
              type="radio" name="mode"
              checked={settings.mode === 'auto'}
              onChange={() => setSettings({ ...settings, mode: 'auto' })}
              style={{ width: 'auto' }}
            />
            <span className="text-sm"><strong>Auto Mode</strong> — allowed internal actions run without approval; public actions still need approval.</span>
          </label>
        </Card>

        <Card title="Auto-mode allowed actions">
          <p className="text-xs text-muted mb-3">
            Only used when mode is Auto. Public publishing to AICOUNTLY.com or social channels is <em>never</em> auto-approved.
          </p>
          <div className="grid grid-2">
            {ALL_ACTIONS.map((a) => (
              <label key={a} className="flex items-center gap-2 mb-1 text-sm">
                <input
                  type="checkbox"
                  checked={(settings.allowed_auto_actions || []).includes(a)}
                  onChange={() => toggle(a)}
                  style={{ width: 'auto' }}
                />
                {a.replace(/_/g,' ')}
              </label>
            ))}
          </div>
        </Card>
      </div>
    </div>
  );
}
