import { Routes, Route, Navigate } from 'react-router-dom';
import { ProtectedRoute } from './auth/ProtectedRoute';
import { ReachLayout } from './components/layout/ReachLayout';
import { LoginPage } from './pages/LoginPage';
import { DashboardPage } from './pages/DashboardPage';

import { BlogListPage } from './pages/blog/BlogListPage';
import { BlogEditorPage } from './pages/blog/BlogEditorPage';
import { BlogDetailPage } from './pages/blog/BlogDetailPage';

import { ContentCalendarPage } from './pages/ContentCalendarPage';

import { CampaignListPage } from './pages/campaigns/CampaignListPage';
import { CampaignEditorPage } from './pages/campaigns/CampaignEditorPage';
import { CampaignDetailPage } from './pages/campaigns/CampaignDetailPage';

import { LandingListPage } from './pages/landing/LandingListPage';
import { LandingDetailPage } from './pages/landing/LandingDetailPage';

import { SocialPlannerPage } from './pages/social/SocialPlannerPage';
import { SocialQueuePage } from './pages/social/SocialQueuePage';

import { EmailListPage } from './pages/email/EmailListPage';
import { EmailDetailPage } from './pages/email/EmailDetailPage';
import { WhatsappListPage } from './pages/whatsapp/WhatsappListPage';
import { WhatsappDetailPage } from './pages/whatsapp/WhatsappDetailPage';

import { SeoPlansPage } from './pages/seo/SeoPlansPage';
import { KeywordIdeasPage } from './pages/seo/KeywordIdeasPage';
import { CreativeBriefsPage } from './pages/creative/CreativeBriefsPage';

import { AnalyticsPage } from './pages/AnalyticsPage';

import { LeadsPage } from './pages/leads/LeadsPage';
import { EngagePushPage } from './pages/leads/EngagePushPage';

import { BotQueuePage } from './pages/bot/BotQueuePage';
import { BotReportsPage } from './pages/bot/BotReportsPage';
import { BotReportDetailPage } from './pages/bot/BotReportDetailPage';
import { ApprovalsPage } from './pages/ApprovalsPage';

import { SettingsPage } from './pages/admin/SettingsPage';
import { BotSettingsPage } from './pages/admin/BotSettingsPage';
import { AuditLogsPage } from './pages/admin/AuditLogsPage';
import { ApiHealthPage } from './pages/admin/ApiHealthPage';
import { ConsoleSyncPage } from './pages/admin/ConsoleSyncPage';
import { WorkerStatusPage } from './pages/admin/WorkerStatusPage';
import { LocalBotReportsPage } from './pages/admin/LocalBotReportsPage';

import { ROUTES } from './constants/routes';

export default function App() {
  return (
    <Routes>
      <Route path={ROUTES.LOGIN} element={<LoginPage />} />

      <Route element={<ProtectedRoute><ReachLayout /></ProtectedRoute>}>
        <Route path={ROUTES.DASHBOARD} element={<DashboardPage />} />

        <Route path={ROUTES.BLOG_LIST}    element={<BlogListPage />} />
        <Route path={ROUTES.BLOG_NEW}     element={<BlogEditorPage />} />
        <Route path={ROUTES.BLOG_EDIT}    element={<BlogEditorPage />} />
        <Route path={ROUTES.BLOG_DETAIL}  element={<BlogDetailPage />} />

        <Route path={ROUTES.CONTENT_CALENDAR} element={<ContentCalendarPage />} />

        <Route path={ROUTES.CAMPAIGN_LIST}   element={<CampaignListPage />} />
        <Route path={ROUTES.CAMPAIGN_NEW}    element={<CampaignEditorPage />} />
        <Route path={ROUTES.CAMPAIGN_EDIT}   element={<CampaignEditorPage />} />
        <Route path={ROUTES.CAMPAIGN_DETAIL} element={<CampaignDetailPage />} />

        <Route path={ROUTES.LANDING_LIST}   element={<LandingListPage />} />
        <Route path={ROUTES.LANDING_DETAIL} element={<LandingDetailPage />} />

        <Route path={ROUTES.SOCIAL_PLANNER} element={<SocialPlannerPage />} />
        <Route path={ROUTES.SOCIAL_QUEUE}   element={<SocialQueuePage />} />

        <Route path={ROUTES.EMAIL_LIST}      element={<EmailListPage />} />
        <Route path={ROUTES.EMAIL_DETAIL}    element={<EmailDetailPage />} />
        <Route path={ROUTES.WHATSAPP_LIST}   element={<WhatsappListPage />} />
        <Route path={ROUTES.WHATSAPP_DETAIL} element={<WhatsappDetailPage />} />

        <Route path={ROUTES.SEO_PLANS}      element={<SeoPlansPage />} />
        <Route path={ROUTES.KEYWORD_IDEAS}  element={<KeywordIdeasPage />} />
        <Route path={ROUTES.CREATIVE_BRIEFS} element={<CreativeBriefsPage />} />

        <Route path={ROUTES.ANALYTICS} element={<AnalyticsPage />} />

        <Route path={ROUTES.LEADS}       element={<LeadsPage />} />
        <Route path={ROUTES.ENGAGE_PUSH} element={<EngagePushPage />} />

        <Route path={ROUTES.BOT_QUEUE}          element={<BotQueuePage />} />
        <Route path={ROUTES.BOT_REPORTS}        element={<BotReportsPage />} />
        <Route path={ROUTES.BOT_REPORT_DETAIL}  element={<BotReportDetailPage />} />
        <Route path={ROUTES.APPROVALS}          element={<ApprovalsPage />} />

        <Route path={ROUTES.SETTINGS}          element={<SettingsPage />} />
        <Route path={ROUTES.BOT_SETTINGS}      element={<BotSettingsPage />} />
        <Route path={ROUTES.AUDIT_LOGS}        element={<AuditLogsPage />} />
        <Route path={ROUTES.API_HEALTH}        element={<ApiHealthPage />} />
        <Route path={ROUTES.CONSOLE_SYNC}      element={<ConsoleSyncPage />} />
        <Route path={ROUTES.WORKER_STATUS}     element={<WorkerStatusPage />} />
        <Route path={ROUTES.LOCAL_BOT_REPORTS} element={<LocalBotReportsPage />} />
      </Route>

      <Route path="*" element={<Navigate to={ROUTES.DASHBOARD} replace />} />
    </Routes>
  );
}
