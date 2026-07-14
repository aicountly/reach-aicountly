import { Link } from 'react-router-dom';

export default function SmsOverviewPage() {
  return (
    <div>
      <div className="page-header">
        <h1>SMS Channel</h1>
        <p className="page-header__subtitle">
          Manage SMS campaigns with DLT compliance and suppression controls
        </p>
      </div>

      <div className="cards-grid">
        <div className="card">
          <div className="card__header">
            <h3>SMS Dispatch</h3>
          </div>
          <div className="card__body">
            <p>Dispatch approved SMS campaigns to the provider. Validates DLT entity, template, and sender IDs before sending.</p>
          </div>
          <div className="card__footer">
            <Link to="/distribution/sms/dispatch" className="btn btn--primary">Open Dispatch</Link>
          </div>
        </div>

        <div className="card">
          <div className="card__header">
            <h3>Suppression List</h3>
          </div>
          <div className="card__body">
            <p>Manage opted-out numbers. Suppressed numbers are automatically excluded from all SMS dispatch batches.</p>
          </div>
          <div className="card__footer">
            <Link to="/distribution/suppressions?channel=sms" className="btn btn--outline">Manage Suppressions</Link>
          </div>
        </div>

        <div className="card">
          <div className="card__header">
            <h3>DLT Compliance</h3>
          </div>
          <div className="card__body">
            <p>Validate DLT entity ID, template ID, and sender ID for Indian regulatory compliance (TRAI).</p>
          </div>
          <div className="card__footer">
            <Link to="/distribution/sms/dispatch" className="btn btn--outline">Validate DLT</Link>
          </div>
        </div>
      </div>
    </div>
  );
}
