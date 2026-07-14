import { useState, useEffect } from 'react';
import api from '../../services/api';

/**
 * Render profile selector for video render job queuing.
 *
 * Props:
 *  - value      {string|null} — Selected profile UUID.
 *  - onChange   {Function(uuid)} — Called with the selected profile UUID.
 *  - disabled   {boolean}
 */
export function VideoRenderProfileSelector({ value, onChange, disabled = false }) {
  const [profiles, setProfiles] = useState([]);
  const [loading, setLoading]   = useState(true);

  useEffect(() => {
    api.get('/video/render-profiles')
      .then(r => setProfiles(r.data?.data?.data ?? r.data?.data ?? []))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="muted text-sm">Loading render profiles…</p>;

  return (
    <div className="form-group">
      <label className="form-label" htmlFor="render-profile">Render profile</label>
      <select
        id="render-profile"
        className="select"
        value={value ?? ''}
        onChange={e => onChange(e.target.value || null)}
        disabled={disabled}
      >
        <option value="">Default profile</option>
        {profiles.map(p => (
          <option key={p.uuid ?? p.id} value={p.uuid ?? p.id}>
            {p.name} {p.is_default ? '(default)' : ''} — {p.resolution} @ {p.frame_rate}fps
          </option>
        ))}
      </select>
    </div>
  );
}
