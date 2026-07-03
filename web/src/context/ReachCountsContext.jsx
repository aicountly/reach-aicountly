import { createContext, useContext, useEffect, useState } from 'react';
import { dashboardService } from '../services/dashboardService';

const ReachCountsContext = createContext({});

export function ReachCountsProvider({ children }) {
  const [counts, setCounts] = useState({});

  useEffect(() => {
    let cancelled = false;
    const load = () => {
      dashboardService.counts()
        .then((d) => { if (!cancelled) setCounts(d || {}); })
        .catch(() => { /* silent */ });
    };
    load();
    const interval = setInterval(load, 45_000);
    return () => { cancelled = true; clearInterval(interval); };
  }, []);

  return (
    <ReachCountsContext.Provider value={counts}>{children}</ReachCountsContext.Provider>
  );
}

// eslint-disable-next-line react-refresh/only-export-components
export function useReachCounts() {
  return useContext(ReachCountsContext) || {};
}
