import { useEffect, useState } from 'react';
import { Card } from '../../../components/common/Card';
import { Alert } from '../../../components/common/Alert';
import { Loader } from '../../../components/common/Loader';
import { isBlogAutomationEnabled } from '../../../constants/blogFeatureFlags';
import { blogCommandCentreService } from '../../../services/blogCommandCentreService';

const FALLBACK_WINDOW = {
  timezone: 'Asia/Kolkata',
  windows: [
    { start: '00:00', end: '08:59' },
    { start: '21:00', end: '23:59' },
  ],
  cadence_minutes: 120,
};

export function AutomationWindowPage() {
  const automationEnabled = isBlogAutomationEnabled();
  const [config, setConfig] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    blogCommandCentreService
      .getPortfolioSettings()
      .then((data) => {
        if (cancelled) return;
        const settings = data?.settings_json || {};
        setConfig(settings.automation_window || FALLBACK_WINDOW);
      })
      .catch(() => {
        if (cancelled) return;
        setError('Could not load the stored window; showing the dispatcher fallback.');
        setConfig(FALLBACK_WINDOW);
      })
      .finally(() => !cancelled && setLoading(false));
    return () => {
      cancelled = true;
    };
  }, []);

  if (loading) return <Loader />;

  const windows = Array.isArray(config?.windows) && config.windows.length > 0 ? config.windows : FALLBACK_WINDOW.windows;
  const timezone = config?.timezone || FALLBACK_WINDOW.timezone;
  const cadence = config?.cadence_minutes;

  return (
    <div>
      <h2>Automation Window</h2>
      <p className="text-sm text-muted">
        Dispatch window read by <code>reach:blog-dispatch</code> from portfolio settings. The API blog route
        generates every {cadence ? `${cadence} minutes` : '2 hours'} inside these windows.
      </p>
      {!automationEnabled && (
        <Alert variant="info">BLOG_AUTOMATION_ENABLED is off. Window configuration is stored but dispatch is disabled.</Alert>
      )}
      {error && <Alert variant="warning">{error}</Alert>}
      <Card title="Window schedule">
        <dl className="definition-list">
          {windows.map((w, i) => (
            <div key={`${w.start}-${w.end}`} style={{ display: 'contents' }}>
              <dt>{i === 0 ? 'Primary window' : `Window ${i + 1}`}</dt>
              <dd>
                {w.start} – {w.end} ({timezone})
              </dd>
            </div>
          ))}
          {cadence ? (
            <>
              <dt>Cadence</dt>
              <dd>Every {cadence} minutes (cron-driven)</dd>
            </>
          ) : null}
          <dt>Status</dt>
          <dd>{automationEnabled ? 'ENABLED' : 'DISABLED'}</dd>
        </dl>
      </Card>
    </div>
  );
}
