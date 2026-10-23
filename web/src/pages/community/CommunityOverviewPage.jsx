import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { DrillLink, drillHref } from '../../components/drill';
import { normalizeCommunityObject } from './communityListUtils';

/** A tile's link, only when there is something behind the number. */
function TileLink({ count, to, children }) {
  return Number(count) > 0 ? <Link to={to} className="stat-card__link">{children}</Link> : null;
}

/** Each row opens its list filtered to that status (`listPath?status=`). */
function StatusTable({ title, rows, emptyLabel, listPath }) {
  const entries = Object.entries(rows ?? {});

  return (
    <section className="card">
      <div className="card__header">
        <h2 className="card__title">{title}</h2>
      </div>
      <div className="card__body">
        {entries.length === 0 ? (
          <p className="muted" style={{ margin: 0 }}>{emptyLabel}</p>
        ) : (
          <table className="data-table data-table--compact">
            <tbody>
              {entries.map(([status, cnt]) => (
                <tr key={status}>
                  <td><span className="badge badge--neutral">{status}</span></td>
                  <td className="text-right">
                    <DrillLink to={Number(cnt) > 0 ? drillHref(listPath, { status }) : undefined} label={`${cnt} ${status}`}>
                      {cnt}
                    </DrillLink>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </section>
  );
}

export default function CommunityOverviewPage() {
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('v1/community/analytics/overview')
      .then(r => setStats(normalizeCommunityObject(r)))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="muted">Loading overview…</p>;
  if (error) return <p className="text-error">{error}</p>;

  const q = stats?.questions_by_status ?? {};
  const a = stats?.answers_by_status ?? {};

  return (
    <div className="community-overview">
      <div className="stat-grid">
        <div className="stat-card">
          {/* 'intake' is a new question's status; there is no 'new' status,
              so this tile read 0 and its link filtered to nothing. */}
          <div className="stat-card__value">{q.intake ?? 0}</div>
          <div className="stat-card__label">New questions</div>
          <TileLink count={q.intake} to="/community/questions?status=intake">View →</TileLink>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{stats?.pending_approval ?? 0}</div>
          <div className="stat-card__label">Pending approval</div>
          <TileLink count={stats?.pending_approval} to="/community/answers?status=awaiting_approval">Review →</TileLink>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{stats?.published_answers ?? 0}</div>
          <div className="stat-card__label">Published answers</div>
          <TileLink count={stats?.published_answers} to="/community/answers?status=published">View →</TileLink>
        </div>
        <div className="stat-card stat-card--warning">
          <div className="stat-card__value">{stats?.open_moderation_flags ?? 0}</div>
          <div className="stat-card__label">Open moderation flags</div>
          <TileLink count={stats?.open_moderation_flags} to="/community/moderation">Resolve →</TileLink>
        </div>
      </div>

      <div className="two-col-grid mt-4">
        <StatusTable
          title="Questions by status"
          rows={q}
          listPath="/community/questions"
          emptyLabel="No questions yet."
        />
        <StatusTable
          title="Answers by status"
          rows={a}
          listPath="/community/answers"
          emptyLabel="No answers yet."
        />
      </div>
    </div>
  );
}
