import { useState, useEffect } from 'react';
import { useParams, Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import api from '../../services/api';

function ProjectStatusBadge({ status }) {
  const colorMap = {
    draft: 'badge--neutral', script_draft: 'badge--info',
    script_in_review: 'badge--warning', script_approved: 'badge--success',
    rendered: 'badge--success', published: 'badge--success',
    cancelled: 'badge--muted', render_failed: 'badge--error', publish_failed: 'badge--error',
  };
  return <span className={`badge ${colorMap[status] ?? 'badge--neutral'}`}>{status?.replace(/_/g,' ')}</span>;
}

export default function VideoProjectWorkspacePage() {
  const { id: uuid } = useParams();
  const location      = useLocation();
  const navigate      = useNavigate();
  const [project, setProject] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  useEffect(() => {
    setLoading(true);
    api.get(`/video/projects/${uuid}`)
      .then(r => setProject(r.data?.data ?? null))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [uuid]);

  if (loading) return <p className="muted">Loading project…</p>;
  if (error)   return <p className="text-error">{error}</p>;
  if (!project) return <p className="text-error">Project not found.</p>;

  const tabs = [
    { label: 'Overview', path: `/video/projects/${uuid}` },
    { label: 'Script',   path: `/video/projects/${uuid}/script` },
    { label: 'Render',   path: `/video/projects/${uuid}/render` },
    { label: 'Publish',  path: `/video/projects/${uuid}/publish` },
    { label: 'Audit',    path: `/video/projects/${uuid}/audit` },
  ];

  const currentTab = location.pathname;

  return (
    <div>
      <div className="page-header">
        <div>
          <Link to="/video/projects" className="text-muted text-sm">← Projects</Link>
          <h1 className="mt-1">{project.title}</h1>
          <ProjectStatusBadge status={project.status} />
        </div>
      </div>

      <div className="tab-bar mt-4">
        {tabs.map(tab => (
          <button
            key={tab.path}
            className={`tab-bar__tab ${currentTab === tab.path ? 'tab-bar__tab--active' : ''}`}
            onClick={() => navigate(tab.path)}
          >
            {tab.label}
          </button>
        ))}
      </div>

      <div className="tab-content mt-4">
        <ProjectOverviewTab project={project} onRefresh={() => {
          api.get(`/video/projects/${uuid}`).then(r => setProject(r.data?.data ?? null));
        }} />
      </div>
    </div>
  );
}

function ProjectOverviewTab({ project, onRefresh }) {
  return (
    <div className="two-col-grid">
      <section className="card">
        <h2 className="card__title">Project details</h2>
        <dl className="definition-list">
          <dt>Status</dt>
          <dd><span className="badge badge--neutral">{project.status?.replace(/_/g,' ')}</span></dd>
          <dt>Title</dt>
          <dd>{project.title}</dd>
          <dt>Created</dt>
          <dd>{project.created_at ? new Date(project.created_at).toLocaleDateString() : '—'}</dd>
          <dt>Last updated</dt>
          <dd>{project.updated_at ? new Date(project.updated_at).toLocaleDateString() : '—'}</dd>
        </dl>
      </section>
      <section className="card">
        <h2 className="card__title">Status timeline</h2>
        <p className="muted">Full audit timeline available in the Audit tab.</p>
      </section>
    </div>
  );
}
