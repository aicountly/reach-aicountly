import { Link } from 'react-router-dom';

export default function AudienceOverviewPage() {
  return (
    <div>
      <div className="page-header">
        <h1>Audience Management</h1>
        <p className="page-header__subtitle">Segments, consent records, and suppression lists for campaign targeting</p>
      </div>

      <div className="two-col-grid">
        <div className="card">
          <h3 className="card__title">Audience Segments</h3>
          <p className="text-muted mb-3">Define dynamic and static recipient groups used for campaign targeting.</p>
          <Link to="/distribution/segments" className="btn btn--primary btn--sm">View Segments</Link>
        </div>

        <div className="card">
          <h3 className="card__title">Suppression List</h3>
          <p className="text-muted mb-3">Manage unsubscribes, bounces, and complaints to ensure compliant sending.</p>
          <Link to="/distribution/suppressions" className="btn btn--primary btn--sm">View Suppressions</Link>
        </div>
      </div>
    </div>
  );
}
