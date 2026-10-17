import { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import api from '../../services/api';
import { formatDate } from '../../utils/formatDate';

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
  const [project, setProject] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  useEffect(() => {
    setLoading(true);
    api.get(`v1/video/projects/${uuid}`)
      .then(r => setProject(r ?? null))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, [uuid]);

  if (loading) return <p className="muted">Loading project…</p>;
  if (error)   return <p className="text-error">{error}</p>;
  if (!project) return <p className="text-error">Project not found.</p>;

  return (
    <div>
      <div className="page-header">
        <div>
          <Link to="/video/projects" className="text-muted text-sm">← Projects</Link>
          <h1 className="mt-1">{project.title}</h1>
          <ProjectStatusBadge status={project.status} />
        </div>
      </div>

      {/* The Script / Render / Publish / Audit tabs pointed at routes that never
          existed. Rendering and YouTube publishing run in the automated
          pipeline now, so this workspace shows the project only. */}
      <p className="muted mt-4">
        Rendering and YouTube publishing are automatic: approved scripts from the reach-video routine
        move through the <Link to="/video">pipeline</Link>.
      </p>

      <div className="tab-content mt-4">
        <ProjectOverviewTab project={project} onRefresh={() => {
          api.get(`v1/video/projects/${uuid}`).then(r => setProject(r ?? null));
        }} />
      </div>
    </div>
  );
}

function ProjectOverviewTab({ project, onRefresh: _onRefresh }) {
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
          <dd>{project.created_at ? formatDate(project.created_at) : '—'}</dd>
          <dt>Last updated</dt>
          <dd>{project.updated_at ? formatDate(project.updated_at) : '—'}</dd>
        </dl>
      </section>
      <section className="card">
        <h2 className="card__title">Status timeline</h2>
        <p className="muted">Full audit timeline available in the Audit tab.</p>
      </section>
    </div>
  );
}
