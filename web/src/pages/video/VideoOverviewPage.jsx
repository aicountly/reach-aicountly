import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import {
  Sparkles, FileText, Activity, Send, Link2, PlugZap,
} from 'lucide-react';
import api from '../../services/api';
import { normalizeVideoTotal } from './videoListUtils';

const SECTIONS = [
  {
    title: 'Idea Backlog',
    description: 'AI-scored video ideas awaiting editorial accept, reject, or convert.',
    to: '/video/ideas',
    action: 'Open backlog',
    icon: Sparkles,
  },
  {
    title: 'Projects',
    description: 'Governed video projects from script through render and publish.',
    to: '/video/projects',
    action: 'View projects',
    icon: FileText,
  },
  {
    title: 'Render Queue',
    description: 'Track render jobs, retries, and provider status across projects.',
    to: '/video/render-queue',
    action: 'Open queue',
    icon: Activity,
  },
  {
    title: 'Publications',
    description: 'YouTube publication profiles, upload state, and retry actions.',
    to: '/video/publications',
    action: 'View publications',
    icon: Send,
  },
  {
    title: 'YouTube Connections',
    description: 'Manage OAuth connections used for video publication.',
    to: '/video/connections',
    action: 'Manage connections',
    icon: Link2,
  },
  {
    title: 'Operations',
    description: 'Pipeline health, provider configuration, and operational status.',
    to: '/video/operations',
    action: 'Open operations',
    icon: PlugZap,
  },
];

export default function VideoOverviewPage() {
  const [stats, setStats] = useState({ total_ideas: null, total_projects: null });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;

    Promise.allSettled([
      api.get('v1/video/ideas', { per_page: 1 }),
      api.get('v1/video/projects', { per_page: 1 }),
    ]).then(([ideasRes, projectsRes]) => {
      if (cancelled) return;

      const next = {
        total_ideas: ideasRes.status === 'fulfilled'
          ? normalizeVideoTotal(ideasRes.value)
          : null,
        total_projects: projectsRes.status === 'fulfilled'
          ? normalizeVideoTotal(projectsRes.value)
          : null,
      };
      setStats(next);

      if (ideasRes.status === 'rejected' && projectsRes.status === 'rejected') {
        setError(
          ideasRes.reason?.message
          || projectsRes.reason?.message
          || 'Failed to load video overview stats.',
        );
      }
    }).finally(() => {
      if (!cancelled) setLoading(false);
    });

    return () => { cancelled = true; };
  }, []);

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Overview</h1>
        <p className="page-header__subtitle">
          AI-governed video content lifecycle — ideation through YouTube publish
        </p>
      </div>

      {error && <div className="alert alert-danger mb-4">{error}</div>}

      <div className="stats-row mb-6">
        <div className="stat-card">
          <div className="stat-card__value">
            {loading && stats.total_ideas === null ? '…' : (stats.total_ideas ?? '—')}
          </div>
          <div className="stat-card__label">Video ideas</div>
          <Link to="/video/ideas" className="stat-card__link">View backlog →</Link>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">
            {loading && stats.total_projects === null ? '…' : (stats.total_projects ?? '—')}
          </div>
          <div className="stat-card__label">Active projects</div>
          <Link to="/video/projects" className="stat-card__link">View projects →</Link>
        </div>
      </div>

      <div className="cards-grid">
        {SECTIONS.map(({ title, description, to, action, icon: Icon }) => (
          <div key={to} className="card">
            <div className="card__header">
              <h3 className="card__title">
                <Icon size={16} className="card__icon" aria-hidden="true" />
                {title}
              </h3>
            </div>
            <div className="card__body">
              <p>{description}</p>
            </div>
            <div className="card__footer">
              <Link to={to} className="btn btn--primary btn--sm">{action}</Link>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
