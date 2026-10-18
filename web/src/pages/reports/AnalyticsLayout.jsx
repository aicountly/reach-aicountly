import { BarChart3 } from 'lucide-react';
import { HubLayout } from '../../components/layout/HubLayout';
import { ROUTES } from '../../constants/routes';

/**
 * Growth → Analytics & reports. "Analytics" (Reach + GA4 summary) and
 * "Daily Reports" (the 08:00 IST email) were separate sidebar entries over
 * the same numbers; deeper analysis lives in Intelligence and is linked.
 */
export function AnalyticsLayout() {
  return (
    <HubLayout
      title="Analytics & reports"
      subtitle="Traffic and marketing metrics, and the daily report emailed every morning."
      icon={BarChart3}
      label="Analytics navigation"
      tabs={[
        { to: ROUTES.ANALYTICS, label: 'Summary', end: true, requires: 'analytics.view' },
        { to: ROUTES.REPORTS, label: 'Daily reports', requires: 'analytics.view' },
      ]}
      related={[
        { to: '/intelligence/content', label: 'Content performance', requires: 'intelligence.read' },
        { to: '/intelligence/search', label: 'Search performance', requires: 'intelligence.read' },
        { to: '/intelligence/attribution', label: 'Attribution', requires: 'intelligence.read' },
        { to: '/distribution/analytics', label: 'Delivery', requires: 'distribution.read' },
        { to: '/social/performance', label: 'Social', requires: 'social.view' },
      ]}
    />
  );
}
