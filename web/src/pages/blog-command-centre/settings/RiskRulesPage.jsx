import { Card } from '../../../components/common/Card';
import { Alert } from '../../../components/common/Alert';

export function RiskRulesPage() {
  return (
    <div>
      <h2>Risk Rules</h2>
      <p className="text-sm text-muted">Classification thresholds for blog content risk levels.</p>
      <Alert variant="info">Risk rules configuration API is not available yet.</Alert>
      <Card title="Risk classes">
        <dl className="definition-list">
          <dt>Low risk</dt><dd>Standard editorial review</dd>
          <dt>Medium risk</dt><dd>Additional fact verification</dd>
          <dt>High risk</dt><dd>Human approval required; no auto-publish</dd>
        </dl>
      </Card>
    </div>
  );
}
