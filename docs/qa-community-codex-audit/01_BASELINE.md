# Baseline

Recorded at audit start (Phase 0) and re-checked at Phase 6.

## Reach (`reach-aicountly`)

- Path: `c:\Users\pc\reach-aicountly`
- Branch: `audit/blog-automation-acceptance`
- Start HEAD: `6d9d61dfb286dcfec4f2147f7c4de69f4dc36b1b`
- Upstream divergence (`origin/main`): 0 ahead / 0 behind
- **Pre-existing uncommitted changes at audit start (NOT created by this audit,
  unrelated blog-automation work-in-progress on the shared branch — left
  untouched throughout)**:
  - `docs/blog-automation/WHM_CPANEL_DEPLOYMENT_RUNBOOK.md`
  - `server-php/.env.example`
  - `server-php/app/Commands/ReachWork.php`
  - `server-php/app/Libraries/Ai/Generation/AiGenerationOrchestrator.php`
  - `server-php/app/Libraries/Blog/BlogStateMachine.php`
  - `server-php/app/Libraries/Blog/Roadmap/RoadmapOptimizerService.php`
  - `server-php/app/Libraries/Blog/WorkBlockService.php`
  - `server-php/app/Libraries/Intelligence/Connectors/ConnectorProviderFactory.php`
  - `server-php/app/Libraries/Publishing/Blog/BlogPublicationPayloadBuilder.php`
  - `server-php/app/Libraries/Publishing/Jobs/PublicationDeploymentService.php`
  - New untracked: `2026-07-31-200001_CreateReachBlogContentApprovals.php`,
    `2026-07-31-200002_CreateReachBlogProtectedReviewers.php`,
    `2026-07-31-200003_WidenReachContentItemsWorkflowStatusCheck.php`,
    `BlogContentApprovalService.php`, `GoogleAnalyticsContentConnector.php`,
    `GoogleAnalyticsContentConnectorTest.php`

## Public (`aicountly-com`)

- Path: `c:\Users\pc\aicountly-com`
- Branch: `audit/blog-automation-acceptance`
- Start HEAD: `6e5150235cd97cb62ade21e139f4edffdc1d4177`
- Upstream divergence (`origin/main`): 0 ahead / 0 behind
- **Pre-existing uncommitted changes at audit start (unrelated publisher/blog
  work-in-progress, left untouched)**:
  - `includes/publisher/PackageWriter.php`
  - `includes/publisher/PublicationRegistry.php`
  - `includes/publisher/PublisherController.php`
  - New untracked: `includes/publisher/HeroImageFetcher.php`

## Ground rules honored throughout

- No commits, pushes, resets, stashes, or branch changes.
- No overwriting of the unrelated pre-existing dirty files listed above —
  every diff produced by this audit is scoped to Community Q&A files.
- Source-of-truth docs consulted: `docs/phases/PHASE_5_*`,
  `docs/architecture/REACH_COMMUNITY_*` (Reach). No pre-existing
  `docs/qa-community-*` folder in either repo before this audit.
