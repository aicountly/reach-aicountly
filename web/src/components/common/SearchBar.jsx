import { Search } from 'lucide-react';

export function SearchBar({ value, onChange, placeholder = 'Search…' }) {
  return (
    <div style={{ position: 'relative' }}>
      <Search size={14} style={{ position: 'absolute', top: '50%', left: 10, transform: 'translateY(-50%)', color: 'var(--color-text-muted)' }} />
      <input
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        style={{ paddingLeft: 30 }}
      />
    </div>
  );
}
