import { Card } from '../../../components/common/Card';
import { Alert } from '../../../components/common/Alert';

export function SeoConfigurationPage() {
  return (
    <div>
      <h2>SEO Configuration</h2>
      <p className="text-sm text-muted">Blog SEO defaults, canonical URLs, and structured data settings.</p>
      <Alert variant="info">SEO configuration API is not available yet.</Alert>
      <Card title="Defaults">
        <dl className="definition-list">
          <dt>Canonical path</dt><dd>/blogs/{'{slug}'}</dd>
          <dt>Legacy redirect</dt><dd>/blog/{'{slug}'} → 301</dd>
          <dt>Structured data</dt><dd>BlogPosting + breadcrumb</dd>
        </dl>
      </Card>
    </div>
  );
}
