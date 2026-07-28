import { useRef, useState } from 'react';

function daysInMonth(year, month) {
  return new Date(year, month + 1, 0).getDate();
}

export function ContentCalendarGrid({ items = [], monthOffset = 0, onItemClick, onItemMove }) {
  const now = new Date();
  now.setDate(1);
  now.setMonth(now.getMonth() + monthOffset);
  const year = now.getFullYear();
  const month = now.getMonth();
  const first = new Date(year, month, 1);
  const startDay = first.getDay(); // 0=Sun
  const total = daysInMonth(year, month);
  const [dragOverDate, setDragOverDate] = useState(null);
  const dragItemRef = useRef(null);
  const didDragRef = useRef(false);

  // Group items by date string 'YYYY-MM-DD'
  const map = {};
  for (const it of items) {
    const d = String(it.date || '').slice(0, 10);
    (map[d] = map[d] || []).push(it);
  }

  const handleDragStart = (e, item) => {
    dragItemRef.current = item;
    didDragRef.current = false;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(item.id));
    // Defer class so drag ghost renders correctly
    requestAnimationFrame(() => {
      e.target.classList.add('calendar-cell__item--dragging');
    });
  };

  const handleDragEnd = (e) => {
    e.target.classList.remove('calendar-cell__item--dragging');
    dragItemRef.current = null;
    setDragOverDate(null);
    // Ignore the click that follows a successful drag
    setTimeout(() => { didDragRef.current = false; }, 0);
  };

  const handleDragOver = (e, dateStr) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (dragOverDate !== dateStr) setDragOverDate(dateStr);
  };

  const handleDragLeave = (e, dateStr) => {
    if (!e.currentTarget.contains(e.relatedTarget)) {
      if (dragOverDate === dateStr) setDragOverDate(null);
    }
  };

  const handleDrop = (e, dateStr) => {
    e.preventDefault();
    setDragOverDate(null);
    const item = dragItemRef.current;
    if (!item) return;
    didDragRef.current = true;
    onItemMove?.(item, dateStr);
  };

  const handleItemClick = (item) => {
    if (didDragRef.current) return;
    onItemClick?.(item);
  };

  const cells = [];
  for (let i = 0; i < startDay; i++) {
    cells.push(<div key={`pad-${i}`} className="calendar-cell" style={{ background: 'transparent', border: '1px dashed var(--color-border)' }} />);
  }
  for (let d = 1; d <= total; d++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    const dayItems = map[dateStr] || [];
    const isDropTarget = dragOverDate === dateStr;
    cells.push(
      <div
        key={dateStr}
        className={`calendar-cell${isDropTarget ? ' calendar-cell--drop-target' : ''}`}
        onDragOver={(e) => handleDragOver(e, dateStr)}
        onDragLeave={(e) => handleDragLeave(e, dateStr)}
        onDrop={(e) => handleDrop(e, dateStr)}
      >
        <div className="calendar-cell__date">{d}</div>
        {dayItems.map((it) => (
          <div
            key={it.id}
            className="calendar-cell__item"
            title={it.notes || it.title || ''}
            draggable={Boolean(onItemMove)}
            onDragStart={(e) => handleDragStart(e, it)}
            onDrag={(e) => { if (e.clientX || e.clientY) didDragRef.current = true; }}
            onDragEnd={handleDragEnd}
            onClick={() => handleItemClick(it)}
            role="button"
            tabIndex={0}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                handleItemClick(it);
              }
            }}
          >
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
