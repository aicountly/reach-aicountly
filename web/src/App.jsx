import { Routes, Route, Navigate } from 'react-router-dom';
import { useAuth } from './context/AuthContext';
import { ProtectedRoute } from './auth/ProtectedRoute';
import { ReachLayout } from './components/layout/ReachLayout';
import ControllerGate from './pages/ControllerGate';
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
import { JobMonitorPage } from './pages/admin/JobMonitorPage';
import { LocalBotReportsPage } from './pages/admin/LocalBotReportsPage';

import { KnowledgeLayout } from './pages/knowledge/KnowledgeLayout';
import { KnowledgeIndexPage } from './pages/knowledge/KnowledgeIndexPage';
import { ProductListPage } from './pages/knowledge/ProductListPage';
import { ProductDetailPage } from './pages/knowledge/ProductDetailPage';
import { PersonaListPage } from './pages/knowledge/PersonaListPage';
import { IndustryListPage } from './pages/knowledge/IndustryListPage';
import { MarketListPage } from './pages/knowledge/MarketListPage';
import { BusinessProblemListPage } from './pages/knowledge/BusinessProblemListPage';
import { SearchIntentListPage } from './pages/knowledge/SearchIntentListPage';
import { TopicClusterListPage } from './pages/knowledge/TopicClusterListPage';
import { SourceListPage } from './pages/knowledge/SourceListPage';
import { CitationListPage } from './pages/knowledge/CitationListPage';
import { ClaimListPage } from './pages/knowledge/ClaimListPage';
import { BrandRulesPage } from './pages/knowledge/BrandRulesPage';
import { ContentPoliciesPage } from './pages/knowledge/ContentPoliciesPage';
import { CompletenessPage } from './pages/knowledge/CompletenessPage';

import { ContentLayout }        from './pages/content/ContentLayout';
import { ContentListPage }      from './pages/content/ContentListPage';
import { ContentNewPage }       from './pages/content/ContentNewPage';
import { ContentDetailPage }    from './pages/content/ContentDetailPage';
import { ContentEditorPage }    from './pages/content/ContentEditorPage';
import { ContentVersionsPage }  from './pages/content/ContentVersionsPage';
import { ContentBriefPage }     from './pages/content/ContentBriefPage';
import { ContentCommentsPage }  from './pages/content/ContentCommentsPage';
import { ContentValidationsPage } from './pages/content/ContentValidationsPage';
import { ContentSchedulePage }  from './pages/content/ContentSchedulePage';
import { DailyPackPage }        from './pages/content/DailyPackPage';

import { ROUTES } from './constants/routes';
import { Loader } from './components/common/Loader';

export default function App() {
  const { user, loading, ssoPending } = useAuth();

  if (loading || ssoPending) {
    return (
      <div style={{
        display: 'flex',
        justifyContent: 'center',
        alignItems: 'center',
        height: '100vh',
      }}>
        <Loader label={ssoPending ? 'Signing you in from Console…' : 'Loading Reach Portal…'} />
      </div>
    );
  }

  if (!user) {
    return <ControllerGate />;
  }

  return (
    <Routes>
      <Route path={ROUTES.LOGIN} element={<Navigate to={ROUTES.DASHBOARD} replace />} />

      <Route element={<ProtectedRoute><ReachLayout /></ProtectedRoute>}>
        <Route path={ROUTES.DASHBOARD} element={<DashboardPage />} />

        <Route path={ROUTES.BLOG_LIST}    element={<BlogListPage />} />
        <Route path={ROUTES.BLOG_NEW}     element={<BlogEditorPage />} />
        <Route path={ROUTES.BLOG_EDIT}    element={<BlogEditorPage />} />
        <Route path={ROUTES.BLOG_DETAIL}  element={<BlogDetailPage />} />

        <Route path={ROUTES.CONTENT_CALENDAR_LEGACY} element={<ContentCalendarPage />} />

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

        <Route path="/knowledge" element={<KnowledgeLayout />}>
          <Route index element={<KnowledgeIndexPage />} />
          <Route path="products" element={<ProductListPage />} />
          <Route path="products/:id" element={<ProductDetailPage />} />
          <Route path="personas" element={<PersonaListPage />} />
          <Route path="industries" element={<IndustryListPage />} />
          <Route path="markets" element={<MarketListPage />} />
          <Route path="problems" element={<BusinessProblemListPage />} />
          <Route path="search-intents" element={<SearchIntentListPage />} />
          <Route path="topic-clusters" element={<TopicClusterListPage />} />
          <Route path="sources" element={<SourceListPage />} />
          <Route path="citations" element={<CitationListPage />} />
          <Route path="claims" element={<ClaimListPage />} />
          <Route path="brand-rules" element={<BrandRulesPage />} />
          <Route path="content-policies" element={<ContentPoliciesPage />} />
          <Route path="completeness" element={<CompletenessPage />} />
        </Route>

        {/* Phase 2 — Content Studio */}
        <Route path="/content" element={<ContentLayout />}>
          <Route index element={<ContentListPage />} />
          <Route path="new" element={<ContentNewPage />} />
          <Route path="daily-pack" element={<DailyPackPage />} />
          <Route path=":id" element={<ContentDetailPage />} />
          <Route path=":id/edit" element={<ContentEditorPage />} />
          <Route path=":id/versions" element={<ContentVersionsPage />} />
          <Route path=":id/brief" element={<ContentBriefPage />} />
          <Route path=":id/comments" element={<ContentCommentsPage />} />
          <Route path=":id/validations" element={<ContentValidationsPage />} />
          <Route path=":id/schedule" element={<ContentSchedulePage />} />
          <Route path="calendar" element={<ContentListPage />} />
        </Route>

        <Route path={ROUTES.SETTINGS}          element={<SettingsPage />} />
        <Route path={ROUTES.BOT_SETTINGS}      element={<BotSettingsPage />} />
        <Route path={ROUTES.AUDIT_LOGS}        element={<AuditLogsPage />} />
        <Route path={ROUTES.API_HEALTH}        element={<ApiHealthPage />} />
        <Route path={ROUTES.CONSOLE_SYNC}      element={<ConsoleSyncPage />} />
        <Route path={ROUTES.WORKER_STATUS}     element={<WorkerStatusPage />} />
        <Route path={ROUTES.JOBS}              element={<JobMonitorPage />} />
        <Route path={ROUTES.LOCAL_BOT_REPORTS} element={<LocalBotReportsPage />} />
      </Route>

      <Route path="*" element={<Navigate to={ROUTES.DASHBOARD} replace />} />
    </Routes>
  );
}
