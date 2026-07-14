import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';

export default function AudienceSegmentsPage() {
  const [segments, setSegments] = useState([]);
  const [loading, setLoading]   = useState(true);
  const [error, setError]       = useState(null);
  const [creating, setCreating] = useState(false);
  const [newName, setNewName]   = useState('');
  const [newType, setNewType]   = useState('dynamic');

  const load = useCallback(() => {
    setLoading(true);
    api.get('/distribution/segments')
      .then(r => setSegments(r.data?.data ?? []))
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const handleCreate = async () => {
    try {
      await api.post('/distribution/segments', { name: newName, segment_type: newType });
      setCreating(false);
      setNewName('');
      load();
    } catch (e) {
      alert(e.response?.data?.message ?? e.message);
    }
  };

  const handleDelete = async (id) => {
    if (!confirm('Deactivate this segment?')) return;
    try {
      await api.delete(`/distribution/segments/${id}`);
      load();
    } catch (e) {
      alert(e.response?.data?.message ?? e.message);
    }
  };

  const handlePreview = async (id) => {
    try {
      const r = await api.post(`/distribution/segments/${id}/preview`);
      alert(`Estimated count: ${r.data?.data?.estimated_count ?? 0}`);
      load();
    } catch (e) {
      alert(e.response?.data?.message ?? e.message);
    }
  };

  return (
    <div>
      <div className="page-header">
        <h1>Audience Segments</h1>
        <p className="page-header__subtitle">Define reusable recipient segments for campaign targeting</p>
        <div className="page-header__actions">
          <button className="btn btn--primary" onClick={() => setCreating(s => !s)}>
            + New segment
          </button>
        </div>
      </div>

      {creating && (
        <div className="card mb-4">
          <h3 className="card__title">New segment</h3>
          <div className="form-row mb-2">
            <input
              className="form-input"
              placeholder="Segment name"
              value={newName}
              onChange={e => setNewName(e.target.value)}
            />
          </div>
          <div className="form-row mb-3">
            <select className="form-select" value={newType} onChange={e => setNewType(e.target.value)}>
              <option value="dynamic">Dynamic</option>
              <option value="static">Static</option>
            </select>
          </div>
          <button className="btn btn--primary" onClick={handleCreate} disabled={!newName.trim()}>
            Create segment
          </button>
        </div>
      )}

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading segments…</p>}

      {!loading && segments.length === 0 && (
        <p className="muted">No audience segments yet. Create one to start targeting recipients.</p>
      )}

      {!loading && segments.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Est. count</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {segments.map(s => (
              <tr key={s.id}>
                <td>{s.name}</td>
                <td><span className="badge badge--neutral">{s.segment_type}</span></td>
                <td>{s.estimated_count ?? '—'}</td>
                <td>
                  <button className="btn btn--sm btn--outline mr-1" onClick={() => handlePreview(s.uuid ?? s.id)}>
                    Preview
                  </button>
                  <button className="btn btn--sm btn--error" onClick={() => handleDelete(s.uuid ?? s.id)}>
                    Delete
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
