import { useState, useEffect } from 'react';
import api from '../../services/api';

export default function PublishingCalendarPage() {
  const [scheduled, setScheduled] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('/publishing/calendar')
      .then(r => setScheduled(r.data?.data ?? []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="muted">Loading calendar…</p>;
  if (error) return <p className="text-error">{error}</p>;

  const grouped = scheduled.reduce((acc, item) => {
    const date = item.scheduled_at ? item.scheduled_at.split('T')[0] : 'Unscheduled';
    acc[date] = acc[date] ?? [];
    acc[date].push(item);
    return acc;
  }, {});

  return (
    <div>
      <div className="page-header">
        <h1>Publication Calendar</h1>
      </div>

      {Object.keys(grouped).length === 0 ? (
        <p className="muted">No scheduled publications.</p>
      ) : (
        Object.entries(grouped).sort(([a], [b]) => a.localeCompare(b)).map(([date, items]) => (
          <section key={date} className="calendar-group">
            <h2 className="calendar-group__date">{date}</h2>
            <ul className="calendar-group__list">
              {items.map(item => (
                <li key={item.id} className="calendar-group__item">
                  <span className={`badge badge--${item.content_type === 'blog' ? 'info' : 'warning'}`}>
                    {item.content_type}
                  </span>
                  &nbsp;{item.content_title ?? `Deployment #${item.id}`}
                  &nbsp;
                  <span className="muted">({item.status})</span>
                </li>
              ))}
            </ul>
          </section>
        ))
      )}
    </div>
  );
}
