import { useCallback, useEffect, useState } from 'react';
import { contentService } from '../../services/contentService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DailyPackSlot } from '../../components/content/DailyPackSlot';
import { usePermission } from '../../hooks/usePermission';

/** Pack config editor — reads/writes reach_settings.daily_pack_config */
function PackConfigPanel({ onClose }) {
  const [config, setConfig] = useState(null);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg]       = useState(null);

  useEffect(() => {
    contentService.getPackConfig().then((d) => setConfig(d.config)).catch(() => {});
  }, []);

  const handleSave = async () => {
    setSaving(true);
    setMsg(null);
    try {
      await contentService.updatePackConfig(config);
      setMsg({ type: 'ok', text: 'Config saved.' });
    } catch (e) {
      setMsg({ type: 'err', text: e.message });
    } finally {
      setSaving(false);
    }
  };

  if (!config) return <Loader />;

  return (
    <Card style={{ marginBottom: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <strong>Pack Configuration</strong>
        <button className="btn btn-ghost btn-sm" onClick={onClose}>Close</button>
      </div>
      {msg && (
        <div style={{ padding: '6px 10px', borderRadius: 4, marginBottom: 8,
          background: msg.type === 'ok' ? '#d1fae5' : '#fee2e2',
          color: msg.type === 'ok' ? '#065f46' : '#991b1b', fontSize: 12 }}>
          {msg.text}
        </div>
      )}
      <div style={{ marginBottom: 8 }}>
        <label style={{ fontSize: 12, fontWeight: 600 }}>Max Pending Backlog</label>
        <input type="number" min={1} max={500}
          value={config.max_pending_backlog ?? 50}
          onChange={(e) => setConfig((c) => ({ ...c, max_pending_backlog: Number(e.target.value) }))}
          style={{ display: 'block', width: 120, marginTop: 4, borderRadius: 4, border: '1px solid #d1d5db', padding: '4px 8px', fontSize: 12 }} />
      </div>
      <div style={{ marginBottom: 8 }}>
        <label style={{ fontSize: 12, fontWeight: 600 }}>Slot Types (JSON)</label>
        <textarea rows={8}
          value={JSON.stringify(config.slot_types ?? [], null, 2)}
          onChange={(e) => {
            try { setConfig((c) => ({ ...c, slot_types: JSON.parse(e.target.value) })); } catch { /* invalid JSON */ }
          }}
          style={{ display: 'block', width: '100%', marginTop: 4, borderRadius: 4, border: '1px solid #d1d5db', padding: '6px 8px', fontSize: 12, fontFamily: 'monospace', resize: 'vertical' }}
        />
        <div style={{ fontSize: 10, color: '#9ca3af', marginTop: 2 }}>
          Array of {`{ content_type, target_count, priority }`}
        </div>
      </div>
      <button className="btn btn-primary btn-sm" onClick={handleSave} disabled={saving}>
        {saving ? 'Saving…' : 'Save Config'}
      </button>
    </Card>
  );
}

export function DailyPackPage() {
  const { has } = usePermission();
  const canManage = has('daily_pack.manage') || has('daily_pack.create');
  const [packs, setPacks]           = useState([]);
  const [selected, setSelected]     = useState(null);
  const [loading, setLoading]       = useState(true);
  const [genLoading, setGen]        = useState(false);
  const [error, setError]           = useState(null);
  const [date, setDate]             = useState(new Date().toISOString().split('T')[0]);
  const [showConfig, setShowConfig] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await contentService.listPacks();
      const list = data.packs || [];
      setPacks(list);
      setSelected((prev) => (list.length > 0 && !prev ? list[0].id : prev));
    } catch (e) { setError(e.message); }
    finally { setLoading(false); }
  }, []);

  const [packDetail, setPackDetail] = useState(null);
  const loadDetail = useCallback(async (id) => {
    if (!id) return;
    try {
      const data = await contentService.getPack(id);
      setPackDetail(data);
    } catch { /* ignore */ }
  }, []);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { loadDetail(selected); }, [loadDetail, selected]);

  const handleGenerate = async () => {
    setGen(true);
    setError(null);
    try {
      const pack = await contentService.generatePack({ pack_date: date });
      await load();
      setSelected(pack.id);
    } catch (e) { setError(e.message); }
    finally { setGen(false); }
  };

  const handleAssign = async (slot) => {
    const input = window.prompt(`Enter content item ID to assign to "${slot.slot_type}" slot:`);
    if (!input) return;
    const cid = parseInt(input, 10);
    if (!cid) return;
    try {
      await contentService.assignPackItem(packDetail.id, slot.id, cid);
      loadDetail(selected);
    } catch (e) { setError(e.message); }
  };

  if (loading) return <Loader />;

  const slots = packDetail?.items || [];
  const approvedCount  = slots.filter((s) => s.content_item?.workflow_status === 'approved').length;
  const placeholderCount = slots.filter((s) => s.is_placeholder).length;
  const totalSlots    = slots.length;
  const progress = totalSlots > 0 ? Math.round((approvedCount / totalSlots) * 100) : 0;

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Daily Marketing Pack</h1>
          <p className="text-sm text-muted">Content slots planned for each day.</p>
        </div>
        {canManage && (
          <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
            <button className="btn btn-ghost btn-sm" onClick={() => setShowConfig((v) => !v)}>
              {showConfig ? 'Hide Config' : 'Pack Config'}
            </button>
            <input type="date" value={date} onChange={(e) => setDate(e.target.value)}
              style={{ borderRadius: 4, border: '1px solid #d1d5db', padding: '5px 10px', fontSize: 13 }} />
            <button className="btn btn-primary" onClick={handleGenerate} disabled={genLoading}>
              {genLoading ? 'Generating…' : 'Generate Pack'}
            </button>
          </div>
        )}
      </div>

      {error && <Alert variant="danger">{error}</Alert>}

      {showConfig && canManage && <PackConfigPanel onClose={() => setShowConfig(false)} />}

      <div style={{ display: 'grid', gridTemplateColumns: '200px 1fr', gap: 16 }}>
        {/* Pack list */}
        <div>
          <div style={{ fontWeight: 700, fontSize: 13, marginBottom: 8 }}>Packs</div>
          {packs.map((p) => (
            <div key={p.id}
              onClick={() => setSelected(p.id)}
              style={{
                padding: '8px 10px',
                borderRadius: 6,
                marginBottom: 4,
                background: selected === p.id ? '#dbeafe' : '#f9fafb',
                cursor: 'pointer',
                fontSize: 12,
                fontWeight: 600,
                border: '1px solid ' + (selected === p.id ? '#bfdbfe' : '#e5e7eb'),
              }}
            >
              {p.pack_date}
              <div style={{ fontSize: 10, color: '#6b7280' }}>{p.pack_status}</div>
            </div>
          ))}
          {packs.length === 0 && <div style={{ color: '#9ca3af', fontSize: 12 }}>No packs yet.</div>}
        </div>

        {/* Pack detail */}
        <div>
          {packDetail && (
            <>
              {/* Approval progress summary */}
              <Card style={{ marginBottom: 12 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
                  <div>
                    <div style={{ fontWeight: 700, fontSize: 14 }}>Pack for {packDetail.pack_date}</div>
                    <div style={{ fontSize: 11, color: '#6b7280' }}>{packDetail.pack_status}</div>
                  </div>
                  <div style={{ display: 'flex', gap: 16, fontSize: 12 }}>
                    <div>
                      Approved: <strong style={{ color: approvedCount === totalSlots && totalSlots > 0 ? '#10b981' : '#f59e0b' }}>
                        {approvedCount}/{totalSlots}
                      </strong>
                    </div>
                    {placeholderCount > 0 && (
                      <div style={{ color: '#ef4444' }}>
                        Missing: <strong>{placeholderCount}</strong>
                      </div>
                    )}
                  </div>
                </div>
                {/* Progress bar */}
                <div style={{ height: 6, borderRadius: 3, background: '#e5e7eb', marginTop: 10, overflow: 'hidden' }}>
                  <div style={{
                    height: '100%',
                    width: `${progress}%`,
                    background: progress === 100 ? '#10b981' : '#3b82f6',
                    transition: 'width 0.3s',
                  }} />
                </div>
                <div style={{ fontSize: 10, color: '#9ca3af', marginTop: 2 }}>
                  {progress}% approved
                </div>
              </Card>

              {/* Slot grid */}
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(200px,1fr))', gap: 10 }}>
                {slots.map((slot) => (
                  <DailyPackSlot key={slot.id} slot={slot} canManage={canManage} onAssign={handleAssign} />
                ))}
                {slots.length === 0 && <div style={{ color: '#9ca3af', fontSize: 13 }}>No slots in this pack.</div>}
              </div>
            </>
          )}
          {!packDetail && !loading && (
            <div style={{ color: '#9ca3af', fontSize: 13, padding: 20 }}>
              Select a pack from the list or generate a new one.
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
