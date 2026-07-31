import { Card } from '../../../components/common/Card';
import { Alert } from '../../../components/common/Alert';

export function VerificationThresholdsPage() {
  return (
    <div>
      <h2>Verification Thresholds</h2>
      <p className="text-sm text-muted">Confidence thresholds for factual verification (Perplexity Sonar Pro).</p>
      <Alert variant="info">Verification thresholds API is not available yet.</Alert>
      <Card title="Default thresholds">
        <dl className="definition-list">
          <dt>Minimum confidence</dt><dd>0.85</dd>
          <dt>Unsupported claim action</dt><dd>Block publication</dd>
          <dt>Source review required</dt><dd>When confidence below threshold</dd>
        </dl>
      </Card>
    </div>
  );
}
