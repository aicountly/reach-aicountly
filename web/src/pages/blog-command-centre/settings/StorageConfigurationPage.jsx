import { Card } from '../../../components/common/Card';
import { Alert } from '../../../components/common/Alert';
import { isBlogDbBodyFallbackEnabled } from '../../../constants/blogFeatureFlags';

export function StorageConfigurationPage() {
  const dbFallback = isBlogDbBodyFallbackEnabled();

  return (
    <div>
      <h2>Storage Configuration</h2>
      <p className="text-sm text-muted">File-package storage for blog content outside public_html.</p>
      <Alert variant="info">Storage configuration is managed on the public site. Reach stores references only.</Alert>
      <Card title="Current flags">
        <dl className="definition-list">
          <dt>DB body fallback</dt><dd>{dbFallback ? 'ENABLED' : 'DISABLED'}</dd>
          <dt>Package storage</dt><dd>Configured on aicountly.com</dd>
        </dl>
      </Card>
    </div>
  );
}
