import { Outlet } from 'react-router-dom';
import { Header } from './Header';
import { Sidebar } from './Sidebar';
import { ReachCountsProvider } from '../../context/ReachCountsContext';

export function ReachLayout() {
  return (
    <ReachCountsProvider>
      <div style={{ display: 'flex', minHeight: '100vh' }}>
        <Sidebar />
        <div style={{
          marginLeft: 'var(--sidebar-width)',
          flex: 1, display: 'flex', flexDirection: 'column',
          minWidth: 0, position: 'relative', zIndex: 1,
        }}>
          <Header />
          <main style={{ flex: 1, padding: '1.25rem 1.5rem', position: 'relative' }}>
            <Outlet />
          </main>
        </div>
      </div>
    </ReachCountsProvider>
  );
}
