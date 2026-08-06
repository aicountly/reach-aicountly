import { useState, useEffect, useCallback } from 'react';
import { useParams, Link } from 'react-router-dom';
import api from '../../services/api';
import { formatDate } from '../../utils/formatDate';

function normalizeList(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.items)) return payload.items;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
}

function VersionCard({ version, campaignId, onAction }) {
  const [loading, setLoading] = useState(false);

  const act = async (action, body = {}) => {
    setLoading(true);
    try {
      await api.post(`v1/campaigns/${campaignId}/versions/${version.id}/${action}`, body);
      onAction();
    } catch (e) {
      alert(e?.message || 'Action failed.');
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
        <dd>{version.created_at ? formatDate(version.created_at) : '—'}</dd>
        {version.approved_at && <><dt>Approved</dt><dd>{formatDate(version.approved_at)}</dd></>}
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
            <button
              className="btn btn--sm btn--error mr-1"
              onClick={() => {
                const reason = prompt('Rejection reason:');
                if (reason) act('reject', { reason });
              }}
              disabled={loading}
            >
              Reject
            </button>
            <button
              className="btn btn--sm btn--secondary"
              onClick={() => {
                const notes = prompt('Change request notes:') ?? '';
                act('request-changes', { notes });
              }}
              disabled={loading}
            >
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
  const campaignId = id && id !== 'undefined' ? String(id) : null;

  const [campaign, setCampaign] = useState(null);
  const [versions, setVersions] = useState([]);
  const [loading, setLoading] = useState(Boolean(campaignId));
  const [error, setError] = useState(null);

  const load = useCallback(() => {
    if (!campaignId) {
      setLoading(false);
      setError('Campaign id is missing. Open a campaign from the list.');
      return;
    }

    setLoading(true);
    setError(null);
    Promise.all([
      api.get(`v1/campaigns/${campaignId}`),
      api.get(`v1/campaigns/${campaignId}/versions`),
    ])
      .then(([cRes, vRes]) => {
        setCampaign(cRes && !Array.isArray(cRes) ? cRes : null);
        setVersions(normalizeList(vRes));
      })
      .catch((e) => setError(e?.message || 'Failed to load campaign workspace.'))
      .finally(() => setLoading(false));
  }, [campaignId]);

  useEffect(() => {
    load();
  }, [load]);

  const handleCreateVersion = async () => {
    if (!campaignId) return;
    try {
      await api.post(`v1/campaigns/${campaignId}/versions`, {});
      load();
    } catch (e) {
      alert(e?.message || 'Failed to create version.');
    }
  };

  if (!campaignId) {
    return (
      <div>
        <div className="page-header">
          <div>
            <Link to="/distribution/campaigns" className="breadcrumb-link">Campaigns</Link>
            {' / '}
            <h1>Campaign workspace</h1>
          </div>
        </div>
        <p className="text-error">Campaign id is missing.</p>
        <p className="muted">
          <Link to="/distribution/campaigns">Back to campaign list</Link>
        </p>
      </div>
    );
  }

  return (
    <div>
      <div className="page-header">
        <div>
          <Link to="/distribution/campaigns" className="breadcrumb-link">Campaigns</Link>
          {' / '}
          <h1>{campaign?.name ?? `Campaign #${campaignId}`}</h1>
        </div>
        {campaign?.status && (
          <span className="badge badge--neutral">{String(campaign.status).replace(/_/g, ' ')}</span>
        )}
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading campaign workspace…</p>}

      {!loading && !error && (
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

          {versions.map((v) => (
            <VersionCard
              key={v.id}
              version={v}
              campaignId={campaignId}
              onAction={load}
            />
          ))}
        </>
      )}
    </div>
  );
}
