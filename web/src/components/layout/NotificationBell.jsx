import { useCallback, useEffect, useRef, useState } from 'react';
import { Bell } from 'lucide-react';
import { contentService } from '../../services/contentService';

export function NotificationBell() {
  const [count, setCount]       = useState(0);
  const [open, setOpen]         = useState(false);
  const [items, setItems]       = useState([]);
  const [loading, setLoading]   = useState(false);
  const panelRef                = useRef(null);

  const refreshCount = useCallback(async () => {
    try {
      const d = await contentService.getNotificationCount();
      setCount(d.unread_count ?? 0);
    } catch { /* ignore */ }
  }, []);

  useEffect(() => {
    refreshCount();
    const timer = setInterval(refreshCount, 60_000);
    return () => clearInterval(timer);
  }, [refreshCount]);

  const handleOpen = async () => {
    setOpen((v) => !v);
    if (!open) {
      setLoading(true);
      try {
        const d = await contentService.getNotifications();
        setItems(d.notifications ?? []);
      } catch { /* ignore */ }
      finally { setLoading(false); }
    }
  };

  const markRead = async (id) => {
    try {
      await contentService.markNotificationRead(id);
      setItems((prev) => prev.filter((n) => n.id !== id));
      setCount((c) => Math.max(0, c - 1));
    } catch { /* ignore */ }
  };

  const markAllRead = async () => {
    try {
      await contentService.markAllNotificationsRead();
      setItems([]);
      setCount(0);
    } catch { /* ignore */ }
  };

  // Close on outside click
  useEffect(() => {
    const handler = (e) => {
      if (panelRef.current && !panelRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  return (
    <div ref={panelRef} style={{ position: 'relative' }}>
      <button
        type="button"
        onClick={handleOpen}
        aria-label="Notifications"
        style={{ background: 'none', border: 'none', cursor: 'pointer', position: 'relative', padding: '4px 6px' }}
      >
        <Bell size={18} />
        {count > 0 && (
          <span style={{
            position: 'absolute', top: 0, right: 0,
            background: '#ef4444', color: '#fff',
            borderRadius: '50%', fontSize: 9, fontWeight: 700,
            minWidth: 14, height: 14, display: 'flex', alignItems: 'center', justifyContent: 'center',
            padding: '0 3px',
          }}>
            {count > 99 ? '99+' : count}
          </span>
        )}
      </button>

      {open && (
        <div style={{
          position: 'absolute', right: 0, top: '110%',
          width: 320, background: '#fff',
          border: '1px solid #e5e7eb', borderRadius: 8,
          boxShadow: '0 4px 16px rgba(0,0,0,0.12)',
          zIndex: 200,
        }}>
          <div style={{ padding: '10px 14px', borderBottom: '1px solid #e5e7eb', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <strong style={{ fontSize: 13 }}>Notifications</strong>
            {items.length > 0 && (
              <button onClick={markAllRead} style={{ fontSize: 11, color: '#3b82f6', background: 'none', border: 'none', cursor: 'pointer' }}>
                Mark all read
              </button>
            )}
          </div>
          <div style={{ maxHeight: 340, overflowY: 'auto' }}>
            {loading && <div style={{ padding: 14, fontSize: 12, color: '#9ca3af' }}>Loading…</div>}
            {!loading && items.length === 0 && (
              <div style={{ padding: 14, fontSize: 12, color: '#9ca3af' }}>No unread notifications.</div>
            )}
            {items.map((n) => (
              <div key={n.id} style={{ padding: '10px 14px', borderBottom: '1px solid #f3f4f6', display: 'flex', gap: 8, alignItems: 'flex-start' }}>
                <div style={{ flex: 1 }}>
                  <div style={{ fontSize: 12, fontWeight: 600, color: '#111' }}>{n.notification_type?.replace(/\./g, ' ')}</div>
                  <div style={{ fontSize: 11, color: '#374151', marginTop: 2 }}>{n.message}</div>
                  <div style={{ fontSize: 10, color: '#9ca3af', marginTop: 2 }}>{new Date(n.created_at).toLocaleString()}</div>
                </div>
                <button
                  onClick={() => markRead(n.id)}
                  style={{ fontSize: 10, color: '#6b7280', background: 'none', border: 'none', cursor: 'pointer', whiteSpace: 'nowrap' }}
                >
                  ✕
                </button>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
