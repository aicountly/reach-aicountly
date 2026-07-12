import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { contentService } from '../../services/contentService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { usePermission } from '../../hooks/usePermission';

export function ContentSchedulePage() {
  const { id } = useParams();
  const { has } = usePermission();
  const canSchedule = has('content_schedule.create');
  const [schedules, setSchedules] = useState([]);
  const [targets, setTargets]     = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [form, setForm]           = useState({ publication_target_id: '', scheduled_at: '', timezone: 'UTC' });
  const [saving, setSaving]       = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [sch, tgt] = await Promise.all([
        contentService.listSchedules(id),
        contentService.listTargets(),
      ]);
      setSchedules(sch.schedules || []);
      setTargets(tgt.targets || []);
    } catch (e) { setError(e.message); }
    finally { setLoading(false); }
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const handleSchedule = async (e) => {
    e.preventDefault();
    if (!form.publication_target_id || !form.scheduled_at) return;
    setSaving(true);
    try {
      await contentService.createSchedule(id, form);
      setForm({ publication_target_id: '', scheduled_at: '', timezone: 'UTC' });
      load();
    } catch (err) { setError(err.message); }
    finally { setSaving(false); }
  };

  const handleCancel = async (scheduleId) => {
    const reason = window.prompt('Cancellation reason:');
    if (!reason) return;
    try { await contentService.cancelSchedule(id, scheduleId, reason); load(); }
    catch (err) { setError(err.message); }
  };

  if (loading) return <Loader />;

  return (
    <div>
      <div className="page-header"><h1>Schedule</h1></div>
      {error && <Alert variant="danger">{error}</Alert>}

      {schedules.length > 0 && (
        <Card style={{ marginBottom: 16 }}>
          <div style={{ fontWeight: 700, marginBottom: 8 }}>Existing Schedules</div>
          {schedules.map((s) => (
            <div key={s.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 0', borderBottom: '1px solid #f3f4f6' }}>
              <div>
                <div style={{ fontSize: 13 }}>{new Date(s.scheduled_at).toLocaleString()} ({s.timezone})</div>
                <div style={{ fontSize: 11, color: '#6b7280' }}>Target #{s.publication_target_id} · {s.schedule_status}</div>
              </div>
              {s.cancelled_at === null && (
                <button className="btn btn-ghost btn-sm" onClick={() => handleCancel(s.id)}>Cancel</button>
              )}
            </div>
          ))}
        </Card>
      )}

      {canSchedule && (
        <Card>
          <div style={{ fontWeight: 700, marginBottom: 12 }}>Schedule Publication</div>
          <form onSubmit={handleSchedule}>
            <div style={{ marginBottom: 10 }}>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Publication Target</label>
              <select value={form.publication_target_id} onChange={(e) => setForm((f) => ({ ...f, publication_target_id: e.target.value }))}
                style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 13 }}>
                <option value="">Select target…</option>
                {targets.map((t) => <option key={t.id} value={t.id}>{t.name} ({t.channel})</option>)}
              </select>
            </div>
            <div style={{ marginBottom: 10 }}>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Scheduled At</label>
              <input type="datetime-local" value={form.scheduled_at} onChange={(e) => setForm((f) => ({ ...f, scheduled_at: e.target.value }))}
                style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 13 }} />
            </div>
            <button type="submit" className="btn btn-primary" disabled={saving || !form.publication_target_id || !form.scheduled_at}>
              {saving ? 'Scheduling…' : 'Schedule'}
            </button>
          </form>
        </Card>
      )}
    </div>
  );
}

