import { useState, useEffect, useCallback } from 'react';
import { useParams, Link } from 'react-router-dom';
import api from '../../services/api';

function VersionCard({ version, campaignId, onAction }) {
  const [loading, setLoading] = useState(false);

  const act = async (action, body = {}) => {
    setLoading(true);
    try {
      await api.post(`/campaigns/${campaignId}/versions/${version.id}/${action}`, body);
      onAction();
    } catch (e) {
      alert(e.response?.data?.message ?? e.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="card mb-3">
      <div className="card__header">
        <h4 className="card__title">Version {version.version_number}</h4>
        <span className="badge badge--neutral">
          {version.approved_at ? 'Approved' : version.submitted_at ? 'Submitted' : version.rejected_at ? 'Rejected' : 'Draft'}
        </span>
      </div>

      <dl className="definition-list">
        <dt>Created</dt>
        <dd>{version.created_at ? new Date(version.created_at).toLocaleDateString() : '—'}</dd>
        {version.approved_at && <><dt>Approved</dt><dd>{new Date(version.approved_at).toLocaleDateString()}</dd></>}
        {version.rejection_reason && <><dt>Rejection reason</dt><dd>{version.rejection_reason}</dd></>}
      </dl>

      <div className="btn-group mt-3">
        {!version.submitted_at && !version.approved_at && (
          <button className="btn btn--sm btn--primary mr-1" onClick={() => act('submit')} disabled={loading}>
            Submit for review
          </button>
        )}
        {version.submitted_at && !version.approved_at && !version.rejected_at && (
          <>
            <button className="btn btn--sm btn--success mr-1" onClick={() => act('approve')} disabled={loading}>
              Approve
            </button>
            <button className="btn btn--sm btn--error mr-1" onClick={() => {
              const reason = prompt('Rejection reason:');
              if (reason) act('reject', { reason });
            }} disabled={loading}>
              Reject
            </button>
            <button className="btn btn--sm btn--outline" onClick={() => {
              const notes = prompt('Change request notes:') ?? '';
              act('request-changes', { notes });
            }} disabled={loading}>
              Request changes
            </button>
          </>
        )}
      </div>
    </div>
  );
}

export default function CampaignWorkspacePage() {
  const { id } = useParams();
  const [campaign, setCampaign]   = useState(null);
  const [versions, setVersions]   = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    Promise.all([
      api.get(`/campaigns/${id}`),
      api.get(`/campaigns/${id}/versions`),
    ]).then(([cRes, vRes]) => {
      setCampaign(cRes.data?.data ?? cRes.data);
      setVersions(vRes.data?.data ?? []);
    }).catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const handleCreateVersion = async () => {
    try {
      await api.post(`/campaigns/${id}/versions`, {});
      load();
    } catch (e) {
      alert(e.response?.data?.message ?? e.message);
    }
  };

  return (
    <div>
      <div className="page-header">
        <div>
          <Link to="/distribution/campaigns" className="breadcrumb-link">Campaigns</Link>
          {' / '}
          <h1>{campaign?.name ?? `Campaign #${id}`}</h1>
        </div>
        <span className="badge badge--neutral">{campaign?.status?.replace(/_/g, ' ')}</span>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading campaign workspace…</p>}

      {!loading && (
        <>
          <div className="section-header mt-4 mb-3">
            <h2>Versions</h2>
            <button className="btn btn--sm btn--primary" onClick={handleCreateVersion}>
              + New version
            </button>
          </div>

          {versions.length === 0 && (
            <p className="muted">No versions yet. Create the first version to start building channel content.</p>
          )}

          {versions.map(v => (
            <VersionCard
              key={v.id}
              version={v}
              campaignId={id}
              onAction={load}
            />
          ))}
        </>
      )}
    </div>
  );
}
