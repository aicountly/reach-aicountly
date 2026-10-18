import { Share2 } from 'lucide-react';
import { HubLayout } from '../../components/layout/HubLayout';
import { ROUTES } from '../../constants/routes';

/**
 * Channels → Social & Ads. One place for the organic social networks Reach
 * posts to through social-api.ai (accounts, the post calendar incl. YouTube
 * videos/Shorts, outcomes) and an honest view of paid-ads platforms, which
 * Reach does not connect to.
 */
export default function SocialAdsLayout() {
  return (
    <HubLayout
      title="Social & Ads"
      subtitle="Social posts and YouTube videos go out through social-api.ai. Paid ads are not connected to Reach."
      icon={Share2}
      label="Social & Ads navigation"
      tabs={[
        { to: ROUTES.SOCIAL_ACCOUNTS, label: 'Accounts', requires: 'distribution.read' },
        { to: ROUTES.SOCIAL_CALENDAR, label: 'Calendar & queue', requires: 'social.view' },
        { to: ROUTES.SOCIAL_INBOX, label: 'Inbox', requires: 'social.view' },
        { to: ROUTES.SOCIAL_PERFORMANCE, label: 'Performance', requires: 'social.view' },
        { to: ROUTES.SOCIAL_ADS, label: 'Ads', requires: ['social.view', 'distribution.read'] },
      ]}
      related={[
        { to: '/admin/ai-automation#social', label: 'Social settings', requires: 'settings.view' },
        { to: '/distribution/readiness', label: 'All channel readiness', requires: 'distribution.read' },
        { to: '/video', label: 'Video pipeline', requires: 'video.read' },
      ]}
    />
  );
}
