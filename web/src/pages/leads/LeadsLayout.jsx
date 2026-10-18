import { Users } from 'lucide-react';
import { HubLayout } from '../../components/layout/HubLayout';
import { useReachCounts } from '../../context/ReachCountsContext';
import { ROUTES } from '../../constants/routes';

/**
 * Growth → Leads & Engage. Lead Capture, Engage Lead Push and Lead Outcomes
 * used to be three separate sidebar entries; they are one lead lifecycle
 * (captured → pushed to Engage → outcome), so they are tabs of one area.
 */
export function LeadsLayout() {
  const counts = useReachCounts();
  return (
    <HubLayout
      title="Leads & Engage"
      subtitle="Leads captured from aicountly.com, their push to Engage, and what happened next."
      icon={Users}
      label="Leads navigation"
      tabs={[
        { to: ROUTES.LEADS, label: 'Capture', end: true, requires: 'lead.view' },
        { to: ROUTES.ENGAGE_PUSH, label: 'Engage push', requires: 'lead.view', count: Number(counts.leads_pending_push ?? 0) },
        { to: ROUTES.LEAD_OUTCOMES, label: 'Outcomes', requires: 'lead.view' },
      ]}
      related={[
        { to: '/intelligence/attribution', label: 'Attribution', requires: 'intelligence.read' },
        { to: '/intelligence/attribution/unattributed', label: 'Unattributed leads', requires: 'intelligence.read' },
      ]}
    />
  );
}
