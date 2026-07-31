import { Link } from 'react-router-dom';
import { Alert } from '../../../components/common/Alert';
import { ROUTES } from '../../../constants/routes';

export function ProviderRoutingPage() {
  return (
    <div>
      <h2>Provider Routing</h2>
      <p className="text-sm text-muted">
        Blog generation provider routing is managed in AI Control Centre.
      </p>
      <Alert variant="info">
        Provider routing applies platform-wide. Open{' '}
        <Link to={ROUTES.AI_ROUTING}>AI Control Centre → Routing</Link>{' '}
        to configure OpenAI, Gemini, and other providers.
      </Alert>
    </div>
  );
}
