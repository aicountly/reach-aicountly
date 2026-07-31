import { Card } from '../../../components/common/Card';
import { Alert } from '../../../components/common/Alert';
import { isBlogAutomationEnabled } from '../../../constants/blogFeatureFlags';

export function AutomationWindowPage() {
  const automationEnabled = isBlogAutomationEnabled();

  return (
    <div>
      <h2>Automation Window</h2>
      <p className="text-sm text-muted">Asia/Kolkata dispatch window for automated blog production.</p>
      {!automationEnabled && (
        <Alert variant="info">BLOG_AUTOMATION_ENABLED is off. Window configuration is stored but dispatch is disabled.</Alert>
      )}
      <Card title="Window schedule">
        <dl className="definition-list">
          <dt>Primary window</dt><dd>00:00 – 08:59 IST</dd>
          <dt>Secondary window</dt><dd>19:00 – 23:59 IST</dd>
          <dt>Status</dt><dd>{automationEnabled ? 'ENABLED' : 'DISABLED'}</dd>
        </dl>
      </Card>
    </div>
  );
}
