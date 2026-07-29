import { Outlet } from 'react-router-dom';
import { Header } from './Header';
import { Sidebar } from './Sidebar';
import { ReachCountsProvider } from '../../context/ReachCountsContext';

export function ReachLayout() {
  return (
    <ReachCountsProvider>
      <div className="reach-shell">
        <Sidebar />
        <div className="reach-shell__main">
          <Header />
          <main className="reach-shell__content">
            <Outlet />
          </main>
        </div>
      </div>
    </ReachCountsProvider>
  );
}
