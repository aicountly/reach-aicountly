import { Link } from 'react-router-dom';
import { ROUTES } from '../../constants/routes.js';

export default function AiValidationsPage() {
  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>AI Validation Runs</h1>
        <p className="page-header__subtitle">
          Validation results are linked to content versions. Only humans can waive findings.
        </p>
      </div>

      <div className="card mb-3">
        <div className="card__body">
          <p style={{ margin: 0 }}>
            Navigate to a content item in the{' '}
            <Link to={ROUTES.CONTENT}>Content Studio</Link>
            {' '}to view its validation history.
          </p>
        </div>
      </div>

      <div className="alert alert--warning">
        AI cannot approve content, waive validation findings, or send campaigns. All decisions require human review.
      </div>
    </div>
  );
}
