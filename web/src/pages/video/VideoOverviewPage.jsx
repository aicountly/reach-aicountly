import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

export default function VideoOverviewPage() {
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    Promise.all([
      api.get('/video/ideas?per_page=1'),
      api.get('/video/projects?per_page=1'),
    ])
      .then(([ideasRes, projectsRes]) => {
        setStats({
          total_ideas:    ideasRes.data?.data?.total ?? 0,
          total_projects: projectsRes.data?.data?.total ?? 0,
        });
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="muted">Loading video overview…</p>;
  if (error) return <p className="text-error">{error}</p>;

  return (
    <div>
      <div className="page-header">
        <h1>Video Automation</h1>
        <p className="page-header__subtitle">AI-governed video content lifecycle</p>
      </div>

      <div className="stat-grid">
        <div className="stat-card">
          <div className="stat-card__value">{stats.total_ideas}</div>
          <div className="stat-card__label">Video ideas</div>
          <Link to="/video/ideas" className="stat-card__link">View backlog →</Link>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{stats.total_projects}</div>
          <div className="stat-card__label">Active projects</div>
          <Link to="/video/projects" className="stat-card__link">View projects →</Link>
        </div>
      </div>

      <div className="mt-4">
        <div className="quick-links">
          <h2>Quick actions</h2>
          <ul>
            <li><Link to="/video/ideas">Idea backlog</Link></li>
            <li><Link to="/video/projects">Project list</Link></li>
            <li><Link to="/video/render-queue">Render queue</Link></li>
            <li><Link to="/video/publications">Publications</Link></li>
            <li><Link to="/video/connections">YouTube connections</Link></li>
            <li><Link to="/video/operations">Operations</Link></li>
          </ul>
        </div>
      </div>
    </div>
  );
}
