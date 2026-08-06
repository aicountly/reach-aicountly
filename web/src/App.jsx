import { Routes, Route, Navigate } from 'react-router-dom';
import { useAuth } from './context/AuthContext';
import { ProtectedRoute } from './auth/ProtectedRoute';
import { ReachLayout } from './components/layout/ReachLayout';
import ControllerGate from './pages/ControllerGate';
import { DashboardPage } from './pages/DashboardPage';

import {
  BlogLegacyListRedirect,
  BlogLegacyNewRedirect,
  BlogLegacyDetailRedirect,
  BlogLegacyEditRedirect,
} from './pages/blog-command-centre/BlogLegacyRedirects.jsx';

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

import { MediaGalleryPage } from './pages/quality/MediaGalleryPage.jsx';
import SeoLayout from './pages/seo-centre/SeoLayout.jsx';
import BlogSeoLayout from './pages/blog-seo/BlogSeoLayout.jsx';
import { BlogSeoScaffoldPage } from './pages/blog-seo/BlogSeoScaffoldPage.jsx';
import { SeoOverviewPage } from './pages/seo-centre/SeoOverviewPage.jsx';
import { TrackedKeywordsPage } from './pages/seo-centre/TrackedKeywordsPage.jsx';
import { BacklinksPage } from './pages/seo-centre/BacklinksPage.jsx';
import { SeoSuggestionsPage } from './pages/seo-centre/SeoSuggestionsPage.jsx';
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

import PublishingLayout          from './pages/publishing/PublishingLayout.jsx';

import CommunityLayout              from './pages/community/CommunityLayout.jsx';
import CommunityOverviewPage        from './pages/community/CommunityOverviewPage.jsx';
import QuestionInboxPage            from './pages/community/QuestionInboxPage.jsx';
import QuestionWorkspacePage        from './pages/community/QuestionWorkspacePage.jsx';
import OfficialAnswerListPage       from './pages/community/OfficialAnswerListPage.jsx';
import OfficialAnswerEditorPage     from './pages/community/OfficialAnswerEditorPage.jsx';
import OfficialIdentitiesPage       from './pages/community/OfficialIdentitiesPage.jsx';
import AgentOperationsPage from './pages/community/AgentOperationsPage.jsx';
import CommunityModerationQueuePage from './pages/community/CommunityModerationQueuePage.jsx';
import CommunityPublishingMonitorPage from './pages/community/CommunityPublishingMonitorPage.jsx';
import CommunityAnalyticsPage       from './pages/community/CommunityAnalyticsPage.jsx';
import CommunitySettingsPage        from './pages/community/CommunitySettingsPage.jsx';
import BlogPublishingListPage   from './pages/publishing/BlogPublishingListPage.jsx';
import KbPublishingListPage     from './pages/publishing/KbPublishingListPage.jsx';
import PublishingCalendarPage   from './pages/publishing/PublishingCalendarPage.jsx';
import DeploymentListPage       from './pages/publishing/DeploymentListPage.jsx';
import DeploymentDetailPage     from './pages/publishing/DeploymentDetailPage.jsx';
import VerificationListPage     from './pages/publishing/VerificationListPage.jsx';
import ConnectionsPage          from './pages/publishing/ConnectionsPage.jsx';
import ReadinessPage            from './pages/publishing/ReadinessPage.jsx';
import SeoEditorPage            from './pages/publishing/SeoEditorPage.jsx';

import AiLayout            from './pages/ai/AiLayout.jsx';
import AiDashboardPage     from './pages/ai/AiDashboardPage.jsx';
import AiProvidersPage     from './pages/ai/AiProvidersPage.jsx';
import AiProvidersDetailPage from './pages/ai/AiProvidersDetailPage.jsx';
import AiModelsPage        from './pages/ai/AiModelsPage.jsx';
import AiRoutingPage       from './pages/ai/AiRoutingPage.jsx';
import AiPromptsPage       from './pages/ai/AiPromptsPage.jsx';
import AiPromptDetailPage  from './pages/ai/AiPromptDetailPage.jsx';
import AiGenerationsPage   from './pages/ai/AiGenerationsPage.jsx';
import AiGenerationDetailPage from './pages/ai/AiGenerationDetailPage.jsx';
import AiUsagePage         from './pages/ai/AiUsagePage.jsx';
import AiBudgetsPage       from './pages/ai/AiBudgetsPage.jsx';
import AiValidationsPage   from './pages/ai/AiValidationsPage.jsx';
import AiHealthPage        from './pages/ai/AiHealthPage.jsx';

import DistributionLayout               from './pages/distribution/DistributionLayout.jsx';
import DistributionOverviewPage         from './pages/distribution/DistributionOverviewPage.jsx';
import AudienceOverviewPage             from './pages/distribution/AudienceOverviewPage.jsx';
import AudienceSegmentsPage             from './pages/distribution/AudienceSegmentsPage.jsx';
import ConsentListPage                  from './pages/distribution/ConsentListPage.jsx';
import SuppressionPage                  from './pages/distribution/SuppressionPage.jsx';
import SocialOperationsPage             from './pages/distribution/SocialOperationsPage.jsx';
import EmailDispatchPage                from './pages/distribution/EmailDispatchPage.jsx';
import WhatsAppDispatchPage             from './pages/distribution/WhatsAppDispatchPage.jsx';
import SmsOverviewPage                  from './pages/distribution/SmsOverviewPage.jsx';
import SmsDispatchPage                  from './pages/distribution/SmsDispatchPage.jsx';
import DistributionCampaignListPage     from './pages/distribution/CampaignListPage.jsx';
import CampaignWorkspacePage            from './pages/distribution/CampaignWorkspacePage.jsx';
import DispatchOrchestrationPage        from './pages/distribution/DispatchOrchestrationPage.jsx';
import DistributionAnalyticsPage        from './pages/distribution/DistributionAnalyticsPage.jsx';

import IntelligenceLayout                from './pages/intelligence/IntelligenceLayout.jsx';
import IntelligenceOverviewPage          from './pages/intelligence/IntelligenceOverviewPage.jsx';
import SearchIntelligencePage           from './pages/intelligence/SearchIntelligencePage.jsx';
import SearchQueryPerformancePage       from './pages/intelligence/SearchQueryPerformancePage.jsx';
import SearchPagePerformancePage        from './pages/intelligence/SearchPagePerformancePage.jsx';
import ContentPerformancePage           from './pages/intelligence/ContentPerformancePage.jsx';
import ContentDetailAnalyticsPage       from './pages/intelligence/ContentDetailAnalyticsPage.jsx';
import SitemapOverviewPage              from './pages/intelligence/SitemapOverviewPage.jsx';
import IndexNowOperationsPage           from './pages/intelligence/IndexNowOperationsPage.jsx';
import AttributionOverviewPage          from './pages/intelligence/AttributionOverviewPage.jsx';
import UtmTemplatesPage                 from './pages/intelligence/UtmTemplatesPage.jsx';
import UnattributedLeadsPage            from './pages/intelligence/UnattributedLeadsPage.jsx';
import VisibilityOverviewPage           from './pages/intelligence/VisibilityOverviewPage.jsx';
import VisibilityPromptLibraryPage      from './pages/intelligence/VisibilityPromptLibraryPage.jsx';
import VisibilityRunHistoryPage         from './pages/intelligence/VisibilityRunHistoryPage.jsx';
import VisibilityObservationsPage       from './pages/intelligence/VisibilityObservationsPage.jsx';
import CompetitorListPage               from './pages/intelligence/CompetitorListPage.jsx';
import ConnectorConfigPage              from './pages/intelligence/ConnectorConfigPage.jsx';
import IntelligenceOperationsPage       from './pages/intelligence/IntelligenceOperationsPage.jsx';

// Phase 9: Readiness
import ReadinessLayout                  from './pages/readiness/ReadinessLayout.jsx';
import ReadinessOverviewPage            from './pages/readiness/ReadinessOverviewPage.jsx';
import RefreshOutcomePage               from './pages/readiness/RefreshOutcomePage.jsx';
import AttributionMaturityPage          from './pages/readiness/AttributionMaturityPage.jsx';
import SecurityStatusPage               from './pages/readiness/SecurityStatusPage.jsx';
import OperationsDashboardPage          from './pages/readiness/OperationsDashboardPage.jsx';
import DisasterRecoveryPage             from './pages/readiness/DisasterRecoveryPage.jsx';
import TechnicalDebtPage                from './pages/readiness/TechnicalDebtPage.jsx';
import ReleaseAcceptancePage            from './pages/readiness/ReleaseAcceptancePage.jsx';

import VideoLayout                from './pages/video/VideoLayout.jsx';
import VideoOverviewPage          from './pages/video/VideoOverviewPage.jsx';
import VideoIdeaBacklogPage       from './pages/video/VideoIdeaBacklogPage.jsx';
import VideoProjectListPage       from './pages/video/VideoProjectListPage.jsx';
import VideoProjectWorkspacePage  from './pages/video/VideoProjectWorkspacePage.jsx';
import VideoRenderQueuePage       from './pages/video/VideoRenderQueuePage.jsx';
import VideoPublicationListPage   from './pages/video/VideoPublicationListPage.jsx';
import VideoConnectionsPage       from './pages/video/VideoConnectionsPage.jsx';
import VideoOperationsDashboardPage from './pages/video/VideoOperationsDashboardPage.jsx';

import BlogCommandCentreLayout from './pages/blog-command-centre/BlogCommandCentreLayout.jsx';
import { BlogOverviewPage } from './pages/blog-command-centre/BlogOverviewPage.jsx';
import { BlogScaffoldPage } from './pages/blog-command-centre/BlogScaffoldPage.jsx';
import { BlogSharedEmbed } from './pages/blog-command-centre/BlogSharedEmbed.jsx';
import { BlogDeepLinkRedirect } from './pages/blog-command-centre/BlogDeepLinkRedirect.jsx';
import { ContentBasePage } from './pages/blog-command-centre/roadmap/ContentBasePage.jsx';
import { RoadmapCandidatesPage } from './pages/blog-command-centre/roadmap/RoadmapCandidatesPage.jsx';
import { RoadmapScoredPage } from './pages/blog-command-centre/roadmap/RoadmapScoredPage.jsx';
import { RoadmapOptimizerPage } from './pages/blog-command-centre/roadmap/RoadmapOptimizerPage.jsx';
import { ReadyToPublishPage } from './pages/blog-command-centre/ReadyToPublishPage.jsx';
import { EmergencyUnpublishPage } from './pages/blog-command-centre/EmergencyUnpublishPage.jsx';
import { PortfolioPerformancePage } from './pages/blog-command-centre/PortfolioPerformancePage.jsx';
import { BlogReviewQueuePage } from './pages/blog-command-centre/BlogReviewQueuePage.jsx';
import { BlogReviewDetailPage } from './pages/blog-command-centre/BlogReviewDetailPage.jsx';
import { BlogPublishedManagePage } from './pages/blog-command-centre/BlogPublishedManagePage.jsx';
import { BlogPortfolioSettingsPage } from './pages/blog-command-centre/settings/BlogPortfolioSettingsPage.jsx';
import { AutomationWindowPage } from './pages/blog-command-centre/settings/AutomationWindowPage.jsx';
import { PublicationRulesPage } from './pages/blog-command-centre/settings/PublicationRulesPage.jsx';
import { RiskRulesPage } from './pages/blog-command-centre/settings/RiskRulesPage.jsx';
import { ProviderRoutingPage } from './pages/blog-command-centre/settings/ProviderRoutingPage.jsx';
import { VerificationThresholdsPage } from './pages/blog-command-centre/settings/VerificationThresholdsPage.jsx';
import { StorageConfigurationPage } from './pages/blog-command-centre/settings/StorageConfigurationPage.jsx';
import { SeoConfigurationPage } from './pages/blog-command-centre/settings/SeoConfigurationPage.jsx';
import { NotificationsSettingsPage } from './pages/blog-command-centre/settings/NotificationsSettingsPage.jsx';
import { isBlogCommandCentreEnabled } from './constants/blogFeatureFlags';

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

        <Route path={ROUTES.BLOG_LIST}    element={<BlogLegacyListRedirect />} />
        <Route path={ROUTES.BLOG_NEW}     element={<BlogLegacyNewRedirect />} />
        <Route path={ROUTES.BLOG_EDIT}    element={<BlogLegacyEditRedirect />} />
        <Route path={ROUTES.BLOG_DETAIL}  element={<BlogLegacyDetailRedirect />} />

        <Route path="/blogs/manage" element={<Navigate to={ROUTES.BLOG_COMMAND_CENTRE} replace />} />
        <Route path="/marketing/blogs" element={<Navigate to={ROUTES.BLOG_COMMAND_CENTRE} replace />} />

        {/* The "SEO and Indexing" section left the Blog Command Centre tab
            strip for the SEO sidebar block. These live outside the feature
            flag and outside BlogCommandCentreLayout on purpose: /blog-seo is
            reachable in both flag states and under seo.view alone, so an old
            bookmark must resolve there rather than dead-ending at the
            catch-all or at the BCC layout's blog.view denial. */}
        <Route path={ROUTES.BCC_SEO} element={<Navigate to={ROUTES.BLOG_SEO} replace />} />
        <Route path={ROUTES.BCC_SEO_SEARCH} element={<Navigate to={ROUTES.BLOG_SEO_SEARCH} replace />} />
        <Route path={ROUTES.BCC_SEO_INTERNAL_LINKS} element={<Navigate to={ROUTES.BLOG_SEO_INTERNAL_LINKS} replace />} />
        <Route path={ROUTES.BCC_SEO_CANNIBALISATION} element={<Navigate to={ROUTES.BLOG_SEO_CANNIBALISATION} replace />} />
        <Route path={ROUTES.BCC_SEO_SITEMAP} element={<Navigate to={ROUTES.BLOG_SEO_SITEMAP} replace />} />
        <Route path={ROUTES.BCC_SEO_TECHNICAL} element={<Navigate to={ROUTES.BLOG_SEO_TECHNICAL} replace />} />
        <Route path={ROUTES.BCC_INDEXING} element={<Navigate to={ROUTES.BLOG_SEO_INDEXING} replace />} />

        {/* Blog Command Centre */}
        {isBlogCommandCentreEnabled() && (
        <Route path={ROUTES.BLOG_COMMAND_CENTRE} element={<BlogCommandCentreLayout />}>
          <Route index element={<BlogOverviewPage />} />

          <Route path="roadmap" element={<Navigate to="candidates" replace />} />
          <Route path="roadmap/content-base" element={<ContentBasePage />} />
          <Route path="roadmap/candidates" element={<RoadmapCandidatesPage />} />
          <Route path="roadmap/scored" element={<RoadmapScoredPage />} />
          <Route path="roadmap/gaps" element={<BlogScaffoldPage />} />
          <Route path="roadmap/refresh" element={<BlogScaffoldPage />} />
          <Route path="roadmap/optimizer" element={<RoadmapOptimizerPage />} />
          <Route path="roadmap/clusters" element={<BlogDeepLinkRedirect />} />

          <Route path="pipeline" element={<Navigate to="drafts" replace />} />
          <Route path="pipeline/briefs" element={<BlogSharedEmbed embed="content-briefs" />} />
          <Route path="pipeline/drafts" element={<BlogSharedEmbed embed="content-drafts" />} />
          <Route path="pipeline/versions" element={<BlogSharedEmbed embed="content-versions" />} />
          <Route path="pipeline/editorial" element={<BlogSharedEmbed embed="content-editorial" />} />
          <Route path="pipeline/research" element={<BlogScaffoldPage />} />

          <Route path="verification" element={<BlogReviewQueuePage />} />
          <Route path="verification/review/:id" element={<BlogReviewDetailPage />} />
          <Route path="verification/unsupported-claims" element={<BlogScaffoldPage />} />
          <Route path="verification/source-review" element={<BlogScaffoldPage />} />
          <Route path="verification/risk-review" element={<BlogScaffoldPage />} />
          <Route path="verification/media" element={<BlogScaffoldPage />} />

          <Route path="approvals" element={<BlogSharedEmbed embed="approvals" />} />
          <Route path="approvals/history" element={<BlogSharedEmbed embed="approvals-history" />} />

          <Route path="publishing" element={<Navigate to="ready" replace />} />
          <Route path="publishing/ready" element={<ReadyToPublishPage />} />
          <Route path="publishing/calendar" element={<BlogSharedEmbed embed="calendar" />} />
          <Route path="publishing/scheduled" element={<BlogSharedEmbed embed="scheduled" />} />
          <Route path="publishing/deployments" element={<BlogSharedEmbed embed="deployments" />} />
          <Route path="publishing/rollback" element={<BlogSharedEmbed embed="rollback" />} />
          <Route path="publishing/emergency-unpublish" element={<EmergencyUnpublishPage />} />
          <Route path="published" element={<BlogPublishedManagePage />} />

          <Route path="analytics" element={<Navigate to="portfolio" replace />} />
          <Route path="analytics/portfolio" element={<PortfolioPerformancePage />} />
          <Route path="analytics/content" element={<BlogDeepLinkRedirect />} />
          <Route path="analytics/search" element={<BlogDeepLinkRedirect />} />
          <Route path="analytics/product" element={<BlogDeepLinkRedirect />} />
          <Route path="analytics/attribution" element={<BlogDeepLinkRedirect />} />

          <Route path="operations" element={<Navigate to="queue" replace />} />
          <Route path="operations/queue" element={<BlogSharedEmbed embed="queue-monitor" />} />
          <Route path="operations/failed-jobs" element={<BlogSharedEmbed embed="failed-jobs" />} />
          <Route path="operations/cron" element={<BlogDeepLinkRedirect />} />
          <Route path="operations/ai-usage" element={<BlogSharedEmbed embed="ai-usage" />} />
          <Route path="operations/provider-health" element={<BlogSharedEmbed embed="provider-health" />} />
          <Route path="operations/provider-routing" element={<BlogSharedEmbed embed="provider-routing" />} />
          <Route path="operations/audit" element={<BlogSharedEmbed embed="audit" />} />

          <Route path="settings" element={<Navigate to="portfolio" replace />} />
          <Route path="settings/portfolio" element={<BlogPortfolioSettingsPage />} />
          <Route path="settings/automation-window" element={<AutomationWindowPage />} />
          <Route path="settings/publication-rules" element={<PublicationRulesPage />} />
          <Route path="settings/risk-rules" element={<RiskRulesPage />} />
          <Route path="settings/provider-routing" element={<ProviderRoutingPage />} />
          <Route path="settings/verification-thresholds" element={<VerificationThresholdsPage />} />
          <Route path="settings/storage" element={<StorageConfigurationPage />} />
          <Route path="settings/seo" element={<SeoConfigurationPage />} />
          <Route path="settings/notifications" element={<NotificationsSettingsPage />} />
        </Route>
        )}

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
          <Route path="calendar" element={<ContentCalendarPage />} />
        </Route>

        {/* Phase 4 — Publishing */}
        <Route path="/publishing" element={<PublishingLayout />}>
          <Route index element={<Navigate to="deployments" replace />} />
          <Route path="blogs" element={<BlogPublishingListPage />} />
          <Route path="knowledge-bases" element={<KbPublishingListPage />} />
          <Route path="calendar" element={<PublishingCalendarPage />} />
          <Route path="deployments" element={<DeploymentListPage />} />
          <Route path="deployments/:id" element={<DeploymentDetailPage />} />
          <Route path="verifications" element={<VerificationListPage />} />
          <Route path="connections" element={<ConnectionsPage />} />
          <Route path="readiness" element={<ReadinessPage />} />
          <Route path="seo/:contentId" element={<SeoEditorPage />} />
        </Route>

        {/* Phase 5 — Community Control Centre */}
        <Route path="/community" element={<CommunityLayout />}>
          <Route index element={<CommunityOverviewPage />} />
          <Route path="overview" element={<CommunityOverviewPage />} />
          <Route path="questions" element={<QuestionInboxPage />} />
          <Route path="questions/:uuid" element={<QuestionWorkspacePage />} />
          <Route path="answers" element={<OfficialAnswerListPage />} />
          <Route path="answers/:uuid" element={<OfficialAnswerEditorPage />} />
          <Route path="identities" element={<OfficialIdentitiesPage />} />
          <Route path="agents" element={<AgentOperationsPage />} />
          <Route path="moderation" element={<CommunityModerationQueuePage />} />
          <Route path="deployments" element={<CommunityPublishingMonitorPage />} />
          <Route path="analytics" element={<CommunityAnalyticsPage />} />
          <Route path="settings" element={<CommunitySettingsPage />} />
        </Route>

        {/* Phase 3 — AI Control Centre */}
        <Route path="/ai" element={<AiLayout />}>
          <Route index element={<AiDashboardPage />} />
          <Route path="dashboard" element={<AiDashboardPage />} />
          <Route path="providers" element={<AiProvidersPage />} />
          <Route path="providers/:id" element={<AiProvidersDetailPage />} />
          <Route path="models" element={<AiModelsPage />} />
          <Route path="routing" element={<AiRoutingPage />} />
          <Route path="prompts" element={<AiPromptsPage />} />
          <Route path="prompts/:id" element={<AiPromptDetailPage />} />
          <Route path="generations" element={<AiGenerationsPage />} />
          <Route path="generations/:uuid" element={<AiGenerationDetailPage />} />
          <Route path="usage" element={<AiUsagePage />} />
          <Route path="budgets" element={<AiBudgetsPage />} />
          <Route path="validations" element={<AiValidationsPage />} />
          <Route path="health" element={<AiHealthPage />} />
        </Route>

        {/* Phase 7 — Omnichannel Distribution */}
        <Route path="/distribution" element={<DistributionLayout />}>
          <Route index element={<DistributionOverviewPage />} />
          <Route path="campaigns" element={<DistributionCampaignListPage />} />
          <Route path="campaigns/:id" element={<CampaignWorkspacePage />} />
          <Route path="audience" element={<AudienceOverviewPage />} />
          <Route path="audience/segments" element={<AudienceSegmentsPage />} />
          <Route path="audience/consents" element={<ConsentListPage />} />
          <Route path="segments" element={<AudienceSegmentsPage />} />
          <Route path="suppressions" element={<SuppressionPage />} />
          <Route path="social" element={<SocialOperationsPage />} />
          <Route path="email" element={<EmailDispatchPage />} />
          <Route path="whatsapp" element={<WhatsAppDispatchPage />} />
          <Route path="sms" element={<SmsOverviewPage />} />
          <Route path="sms/dispatch" element={<SmsDispatchPage />} />
          <Route path="orchestration" element={<DispatchOrchestrationPage />} />
          <Route path="analytics" element={<DistributionAnalyticsPage />} />
        </Route>

        {/* Phase 8 — Intelligence Control Centre */}
        <Route path="/intelligence" element={<IntelligenceLayout />}>
          <Route index element={<IntelligenceOverviewPage />} />
          <Route path="search" element={<SearchIntelligencePage />} />
          <Route path="search/queries" element={<SearchQueryPerformancePage />} />
          <Route path="search/pages" element={<SearchPagePerformancePage />} />
          <Route path="content" element={<ContentPerformancePage />} />
          <Route path="content/:id" element={<ContentDetailAnalyticsPage />} />
          <Route path="sitemaps" element={<SitemapOverviewPage />} />
          <Route path="indexnow" element={<IndexNowOperationsPage />} />
          <Route path="attribution" element={<AttributionOverviewPage />} />
          <Route path="attribution/utm" element={<UtmTemplatesPage />} />
          <Route path="attribution/unattributed" element={<UnattributedLeadsPage />} />
          <Route path="visibility" element={<VisibilityOverviewPage />} />
          <Route path="visibility/prompts" element={<VisibilityPromptLibraryPage />} />
          <Route path="visibility/runs" element={<VisibilityRunHistoryPage />} />
          <Route path="visibility/observations" element={<VisibilityObservationsPage />} />
          <Route path="competitors" element={<CompetitorListPage />} />
          <Route path="connectors" element={<ConnectorConfigPage />} />
          <Route path="operations" element={<IntelligenceOperationsPage />} />
        </Route>

        {/* Phase 9 — Product Readiness Centre */}
        <Route path="/readiness" element={<ReadinessLayout />}>
          <Route index element={<ReadinessOverviewPage />} />
          <Route path="outcomes" element={<RefreshOutcomePage />} />
          <Route path="attribution" element={<AttributionMaturityPage />} />
          <Route path="security" element={<SecurityStatusPage />} />
          <Route path="operations" element={<OperationsDashboardPage />} />
          <Route path="disaster-recovery" element={<DisasterRecoveryPage />} />
          <Route path="technical-debt" element={<TechnicalDebtPage />} />
          <Route path="release" element={<ReleaseAcceptancePage />} />
        </Route>

        {/* Phase 6 — Video Content Automation */}
        <Route path="/video" element={<VideoLayout />}>
          <Route index element={<VideoOverviewPage />} />
          <Route path="ideas" element={<VideoIdeaBacklogPage />} />
          <Route path="projects" element={<VideoProjectListPage />} />
          <Route path="projects/:id" element={<VideoProjectWorkspacePage />} />
          <Route path="render-queue" element={<VideoRenderQueuePage />} />
          <Route path="publications" element={<VideoPublicationListPage />} />
          <Route path="connections" element={<VideoConnectionsPage />} />
          <Route path="operations" element={<VideoOperationsDashboardPage />} />
        </Route>

        {/* Quality Centre — cover gallery + content base */}
        <Route path="/quality/gallery" element={<MediaGalleryPage />} />

        {/* SEO Command Centre */}
        <Route path="/seo-centre" element={<SeoLayout />}>
          <Route index element={<Navigate to="overview" replace />} />
          <Route path="overview" element={<SeoOverviewPage />} />
          <Route path="keywords" element={<TrackedKeywordsPage />} />
          <Route path="backlinks" element={<BacklinksPage />} />
          <Route path="suggestions" element={<SeoSuggestionsPage />} />
          <Route path="search" element={<SearchIntelligencePage />} />
          <Route path="indexnow" element={<IndexNowOperationsPage />} />
          <Route path="sitemap" element={<SitemapOverviewPage />} />
          <Route path="connections" element={<ConnectorConfigPage />} />
        </Route>

        {/* Blog SEO and Indexing — promoted out of the Blog Command Centre
            tab strip (its leaves used to redirect into /intelligence/*). */}
        <Route path={ROUTES.BLOG_SEO} element={<BlogSeoLayout />}>
          <Route index element={<Navigate to="search-console" replace />} />
          <Route path="search-console" element={<SearchIntelligencePage />} />
          <Route path="indexing" element={<IndexNowOperationsPage />} />
          <Route path="internal-links" element={<BlogSeoScaffoldPage />} />
          <Route path="cannibalisation" element={<BlogSeoScaffoldPage />} />
          <Route path="sitemap" element={<SitemapOverviewPage />} />
          <Route path="technical" element={<BlogSeoScaffoldPage />} />
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
