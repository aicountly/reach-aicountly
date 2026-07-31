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
      subtitle="Blog items in review or validation stages."
      defaultWorkflowStatus="review_pending"
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
  deployments: () => <DeploymentListPage />,
  rollback: () => <DeploymentListPage />,
  'queue-monitor': () => <JobMonitorPage />,
  'failed-jobs': () => <JobMonitorPage />,
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
