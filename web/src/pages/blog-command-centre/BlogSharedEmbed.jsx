import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import BlogPublishingListPage from '../publishing/BlogPublishingListPage.jsx';
import PublishingCalendarPage from '../publishing/PublishingCalendarPage.jsx';
import DeploymentListPage from '../publishing/DeploymentListPage.jsx';
import VerificationListPage from '../publishing/VerificationListPage.jsx';
import { ApprovalsPage } from '../ApprovalsPage';
import { JobMonitorPage } from '../admin/JobMonitorPage';
import AiUsagePage from '../ai/AiUsagePage';
import AiHealthPage from '../ai/AiHealthPage';
import AiRoutingPage from '../ai/AiRoutingPage';
import { AuditLogsPage } from '../admin/AuditLogsPage';
import { BlogContentListWrapper } from './BlogContentListWrapper.jsx';
import { Alert } from '../../components/common/Alert';

/**
 * Opens a shared page on the slice this Command Centre tab is about (Failed
 * Jobs → ?status=dead_letter,failed) by writing the filter into the URL, so
 * the shared page's own filter controls show it and can clear it. Applied
 * once on arrival and only when the URL sets none of these keys: a drill link
 * with its own filter wins, and clearing the filter by hand stays cleared.
 */
function UrlPreset({ params, children }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const [needed] = useState(() => Object.keys(params).every((k) => !searchParams.has(k)));
  // The shared page mounts only once the URL carries the preset, so it never
  // fetches the unfiltered list first; after that it stays mounted even when
  // the viewer clears the filter.
  const [ready, setReady] = useState(!needed);
  if (!ready && Object.keys(params).some((k) => searchParams.has(k))) setReady(true);

  useEffect(() => {
    if (!needed) return;
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      Object.entries(params).forEach(([k, v]) => next.set(k, v));
      return next;
    }, { replace: true });
    // Once on mount by design (see above).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return ready ? children : null;
}

const EMBED_MAP = {
  'content-briefs': () => (
    <BlogContentListWrapper
      title="Content Briefs"
      subtitle="Blog briefs in the unified content pipeline."
      defaultWorkflowStatus="brief"
    />
  ),
  'content-drafts': () => (
    <BlogContentListWrapper
      title="Drafts"
      subtitle="Blog drafts awaiting production and review."
      defaultWorkflowStatus="draft"
    />
  ),
  'content-versions': () => (
    <BlogContentListWrapper
      title="Versions"
      subtitle="All blog content items — open a row to compare versions in Content Studio."
    />
  ),
  'content-editorial': () => (
    <BlogContentListWrapper
      title="Editorial Review"
      subtitle="Blog items awaiting human review (internal review / SEO review)."
      defaultWorkflowStatus="internal_review"
    />
  ),
  approvals: () => <ApprovalsPage />,
  'approvals-history': () => (
    <div>
      <Alert variant="info">Approval history is shared with the Daily Approval Centre.</Alert>
      <ApprovalsPage />
    </div>
  ),
  published: () => <BlogPublishingListPage />,
  calendar: () => <PublishingCalendarPage />,
  scheduled: () => (
    <BlogContentListWrapper
      title="Scheduled Blogs"
      subtitle="Blog content with a scheduled publication date."
      defaultWorkflowStatus="scheduled"
    />
  ),
  // The Command Centre's publishing counts are blog-only; so is this list.
  deployments: () => <UrlPreset params={{ content_type: 'blog' }}><DeploymentListPage /></UrlPreset>,
  // What can be rolled back is what is live.
  rollback: () => (
    <UrlPreset params={{ status: 'published,verified', content_type: 'blog' }}><DeploymentListPage /></UrlPreset>
  ),
  'queue-monitor': () => <JobMonitorPage />,
  'failed-jobs': () => <UrlPreset params={{ status: 'dead_letter,failed' }}><JobMonitorPage /></UrlPreset>,
  'ai-usage': () => <AiUsagePage />,
  'provider-health': () => <AiHealthPage />,
  'provider-routing': () => (
    <div>
      <Alert variant="info">
        Provider routing is managed in AI Control Centre. Changes apply platform-wide.
      </Alert>
      <AiRoutingPage />
    </div>
  ),
  audit: () => <AuditLogsPage />,
};

export function BlogSharedEmbed({ embed }) {
  const Component = EMBED_MAP[embed];
  if (!Component) {
    return (
      <div className="empty-state">
        <p>Shared page &ldquo;{embed}&rdquo; is not configured.</p>
      </div>
    );
  }
  return <Component />;
}
