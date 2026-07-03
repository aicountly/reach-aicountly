function daysInMonth(year, month) {
  return new Date(year, month + 1, 0).getDate();
}

export function ContentCalendarGrid({ items = [], monthOffset = 0 }) {
  const now = new Date();
  now.setDate(1);
  now.setMonth(now.getMonth() + monthOffset);
  const year = now.getFullYear();
  const month = now.getMonth();
  const first = new Date(year, month, 1);
  const startDay = first.getDay(); // 0=Sun
  const total = daysInMonth(year, month);

  // Group items by date string 'YYYY-MM-DD'
  const map = {};
  for (const it of items) {
    const d = String(it.date || '').slice(0, 10);
    (map[d] = map[d] || []).push(it);
  }

  const cells = [];
  for (let i = 0; i < startDay; i++) {
    cells.push(<div key={`pad-${i}`} className="calendar-cell" style={{ background: 'transparent', border: '1px dashed var(--color-border)' }} />);
  }
  for (let d = 1; d <= total; d++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    const dayItems = map[dateStr] || [];
    cells.push(
      <div key={dateStr} className="calendar-cell">
        <div className="calendar-cell__date">{d}</div>
        {dayItems.map((it) => (
          <div key={it.id} className="calendar-cell__item" title={it.notes || ''}>
            {it.item_kind}: {it.title || '(untitled)'}
          </div>
        ))}
      </div>,
    );
  }
  return (
    <div>
      <div className="text-sm text-secondary mb-2">
        {first.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}
      </div>
      <div className="calendar-grid">
        {['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map((d) => (
          <div key={d} className="text-xs text-muted text-center" style={{ padding: '0.25rem 0' }}>{d}</div>
        ))}
        {cells}
      </div>
    </div>
  );
}
