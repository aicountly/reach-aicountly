import { useCallback, useEffect, useState } from 'react';
import { contentService } from '../../services/contentService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DailyPackSlot } from '../../components/content/DailyPackSlot';
import { usePermission } from '../../hooks/usePermission';

export function DailyPackPage() {
  const { has } = usePermission();
  const canManage = has('daily_pack.manage') || has('daily_pack.create');
  const [packs, setPacks]       = useState([]);
  const [selected, setSelected] = useState(null);
  const [loading, setLoading]   = useState(true);
  const [genLoading, setGen]    = useState(false);
  const [error, setError]       = useState(null);
  const [date, setDate]         = useState(new Date().toISOString().split('T')[0]);

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

  useEffect(load, [load]);
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

  if (loading) return <Loader />;

  const slots = packDetail?.items || [];
  const approvedCount = slots.filter((s) => s.content_item?.workflow_status === 'approved').length;
  const totalSlots    = slots.length;

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Daily Marketing Pack</h1>
          <p className="text-sm text-muted">Content slots planned for each day.</p>
        </div>
        {canManage && (
          <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
            <input type="date" value={date} onChange={(e) => setDate(e.target.value)}
              style={{ borderRadius: 4, border: '1px solid #d1d5db', padding: '5px 10px', fontSize: 13 }} />
            <button className="btn btn-primary" onClick={handleGenerate} disabled={genLoading}>
              {genLoading ? 'Generating…' : 'Generate Pack'}
            </button>
          </div>
        )}
      </div>

      {error && <Alert variant="danger">{error}</Alert>}

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
              <Card style={{ marginBottom: 12 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <div>
                    <div style={{ fontWeight: 700, fontSize: 14 }}>Pack for {packDetail.pack_date}</div>
                    <div style={{ fontSize: 11, color: '#6b7280' }}>{packDetail.pack_status}</div>
                  </div>
                  <div style={{ fontSize: 12 }}>
                    Approval: <strong style={{ color: approvedCount === totalSlots ? '#10b981' : '#f59e0b' }}>
                      {approvedCount}/{totalSlots}
                    </strong> slots approved
                  </div>
                </div>
              </Card>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(200px,1fr))', gap: 10 }}>
                {slots.map((slot) => (
                  <DailyPackSlot key={slot.id} slot={slot} canManage={canManage} />
                ))}
                {slots.length === 0 && <div style={{ color: '#9ca3af', fontSize: 13 }}>No slots in this pack.</div>}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
