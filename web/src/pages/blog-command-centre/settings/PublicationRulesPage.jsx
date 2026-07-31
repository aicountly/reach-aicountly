import { Card } from '../../../components/common/Card';
import { Alert } from '../../../components/common/Alert';

export function PublicationRulesPage() {
  return (
    <div>
      <h2>Publication Rules</h2>
      <p className="text-sm text-muted">Rules governing when blog content may be published.</p>
      <Alert variant="info">Publication rules API is not available yet. High-risk auto-publish is permanently prohibited.</Alert>
      <Card title="Default rules">
        <dl className="definition-list">
          <dt>Auto-publish high risk</dt><dd>Never</dd>
          <dt>Require human approval</dt><dd>Yes</dd>
          <dt>Require verification pass</dt><dd>When enabled</dd>
        </dl>
      </Card>
    </div>
  );
}
