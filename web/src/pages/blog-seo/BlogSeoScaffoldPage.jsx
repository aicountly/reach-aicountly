import { useLocation } from 'react-router-dom';
import { Construction } from 'lucide-react';
import { findBlogSeoNavItem } from '../../constants/blogSeoNav';

export function BlogSeoScaffoldPage() {
  const { pathname } = useLocation();
  const title = findBlogSeoNavItem(pathname)?.label ?? 'Coming soon';

  return (
    <div className="empty-state" style={{ padding: '3rem 1rem', textAlign: 'center' }}>
      <Construction size={40} style={{ color: 'var(--color-text-muted)', marginBottom: '1rem' }} aria-hidden="true" />
      <h2 style={{ marginBottom: '0.5rem' }}>{title}</h2>
      <p className="text-muted">Coming in a later phase.</p>
    </div>
  );
}

export default BlogSeoScaffoldPage;
