import { Card } from '../../../components/common/Card';
import { Alert } from '../../../components/common/Alert';

export function NotificationsSettingsPage() {
  return (
    <div>
      <h2>Notifications</h2>
      <p className="text-sm text-muted">Alerts for blog automation, approvals, and publishing events.</p>
      <Alert variant="info">Notification settings API is not available yet.</Alert>
      <Card title="Notification channels">
        <dl className="definition-list">
          <dt>Approval required</dt><dd>Email + in-app</dd>
          <dt>Publish failure</dt><dd>Email + audit log</dd>
          <dt>Verification failure</dt><dd>In-app</dd>
        </dl>
      </Card>
    </div>
  );
}
