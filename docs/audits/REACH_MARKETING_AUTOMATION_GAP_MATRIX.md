# AICOUNTLY Reach — Marketing Automation Gap Matrix

**Audit date:** 2026-07-12  
**Repository:** reach-aicountly  
**Branch:** main | **Commit:** 1766ec2

Status classifications used: Implemented, Substantially Implemented, Partially Implemented, UI Only, Backend Only, Mock/Hardcoded, Stub/Placeholder, Broken, Legacy/Unused, Missing, Unknown.

---

| ID | Capability | Target requirement | Current status | Existing evidence | Missing layers | Business priority | Technical priority | Risk | Dependencies | Recommended phase |
| -- | ---------- | ------------------ | -------------- | ----------------- | -------------- | ----------------- | ------------------ | ---- | ------------ | ----------------- |
| 1 | Product knowledge graph | Structured Product→Module→Feature→Persona→Evidence relationships | Missing | `SaasProductTaxonomy.php` — hardcoded slug→label map only (12 products) | DB tables, API, admin UI, evidence URLs, versioning | Critical | High | High — unsupported claims | manage.aicountly.com or local master data | Phase 1 |
| 2 | Search-intent library | Search intents mapped to products, personas, funnel stages | Missing | `reach_keyword_ideas`, `reach_seo_plans` — keywords only, no intent taxonomy | Intent table, classification, API, UI | High | Medium | Medium | Product knowledge graph | Phase 1 |
| 3 | Content source ingestion | Ingest from releases, tickets, GSC, community, docs | Missing | No ingestion connectors | Source table, connectors, scheduler, dedup | High | High | Medium | Job queue | Phase 1 |
| 4 | Topic generation | AI-assisted topic suggestions with priority scoring | Stub/Placeholder | `MarketingBotService::generateCampaignIdeas` — hardcoded ideas | Real LLM, source inputs, scoring, dedup | High | High | Medium — thin content | AI provider, product graph | Phase 3 |
| 5 | Content brief generation | Structured briefs with audience, intent, sources, CTAs | Partially Implemented | `reach_creative_briefs`, `reach_seo_plans`, bot `generate_seo_brief` stub | Unified brief model, template, validation | High | Medium | Low | Product graph | Phase 2 |
| 6 | Blog generation | Long-form blog drafts with SEO metadata | Stub/Placeholder | Bot `generate_blog_draft` stub; manual `BlogEditorPage` | Real LLM, grounding, validation | Critical | High | High | AI provider, product graph | Phase 3–4 |
| 7 | Knowledge-base generation | How-to, troubleshooting, concept articles | Missing | No KB routes, APIs, or tables | Full KB module | Critical | High | High | Product graph, publishing | Phase 4 |
| 8 | Community question generation | Official/suggested questions (not fake users) | Missing | No community module | Community integration, transparency rules | High | High | Critical — fake personas | Community platform | Phase 5 |
| 9 | Community answer generation | Expert answers from official accounts | Missing | No community module | Answer workflow, validation, official accounts | High | High | Critical | Community platform, product graph | Phase 5 |
| 10 | Video ideation | Video topic suggestions from content gaps | Missing | Creative brief mentions "hero video" label only | Video module, topic scoring | Medium | Medium | Low | Content analytics | Phase 6 |
| 11 | Video script generation | Scripts, VO, captions, chapters | Missing | No video tables or APIs | Script model, LLM, templates | Medium | Medium | Medium | AI provider | Phase 6 |
| 12 | Video production integration | Rendering, upload, YouTube publishing | Missing | YouTube as social channel enum only | Rendering service, upload APIs | Medium | High | Low | Worker, publishing | Phase 6 |
| 13 | Fact validation | Verify claims against authoritative sources | Missing | No validation code | Validation service, source DB | Critical | High | Critical | Product graph, citations | Phase 3 |
| 14 | Citation validation | Source authority, URL validity, freshness | Missing | No citation tables or checks | Citation model, link checker | Critical | High | Critical | Source ingestion | Phase 3 |
| 15 | Product-claim validation | Feature availability, pricing, version checks | Missing | No claim validation | Product graph integration, rules engine | Critical | High | Critical | Product knowledge graph | Phase 3 |
| 16 | Duplicate-content detection | Prevent cannibalisation and thin pages | Missing | Blog `slug` unique constraint only | Similarity engine, clustering | High | Medium | High — SEO harm | Content index | Phase 3 |
| 17 | SEO optimisation | Meta, headings, keyword density, internal links | Partially Implemented | Blog SEO fields; bot `generate_seo_brief` stub | Automated scoring, recommendations | High | Medium | Medium | Search-intent library | Phase 4 |
| 18 | Structured data | JSON-LD for Article, FAQ, Product, etc. | Missing | No schema generation | Schema templates, validation | High | Medium | Medium — AI discoverability | Publishing connectors | Phase 4 |
| 19 | Internal linking | Automated internal link suggestions | Missing | No link graph or suggestions | Link index, recommendation engine | Medium | Medium | Low | Content index | Phase 4 |
| 20 | Daily approval queue | Today's pending items with preview and priority | Partially Implemented | `reach_approvals`, `ApprovalsPage.jsx` — list only | Preview, priority, filters, calendar view | Critical | High | High | All content modules | Phase 2 |
| 21 | Multi-stage approval | Role-based, multi-reviewer, compliance stage | Missing | Single approve/reject; superadmin only | Role model, stage config, routing | Critical | High | Critical — SoD | Role permissions | Phase 2 |
| 22 | Publishing scheduler | Schedule approved content to channels | Partially Implemented | `scheduled_at` on blog/social; no job runner | Scheduler, job queue, retry | High | High | Medium | Job queues | Phase 2–4 |
| 23 | Website publishing | Publish blogs/pages to aicountly.com | Stub/Placeholder | `AicountlySitePublisher.php` — HTTP placeholder | Real CMS/API integration | Critical | High | High | AICOUNTLY.com write API | Phase 4 |
| 24 | Community publishing | Publish Q&A to aicountly.com/community | Missing | No community integration | Community API connector | High | High | Critical | Community platform | Phase 5 |
| 25 | Social publishing | Auto-post to LinkedIn, X, FB, IG | Stub/Placeholder | `SocialPostController::approve` → manual_queue | Social SDK integration | High | High | Medium | Channel credentials | Phase 7 |
| 26 | Email publishing | Send email campaigns via provider | Stub/Placeholder | `EmailCampaignController::markSent` manual | Email provider SDK | High | High | Medium | Email provider | Phase 7 |
| 27 | WhatsApp publishing | Send WhatsApp campaigns via Business API | Stub/Placeholder | `WhatsAppCampaignController::markSent` manual | WhatsApp Business API | High | High | Medium | WhatsApp provider | Phase 7 |
| 28 | SMS publishing | Send SMS/DLT campaigns | Missing | No SMS routes, APIs, tables | SMS provider, DLT compliance | Medium | Medium | Medium | SMS provider | Phase 7 |
| 29 | Sitemap and IndexNow | XML sitemaps, search-engine notification | Missing | No sitemap generation | Sitemap builder, IndexNow client | High | Medium | Medium — discoverability | Publishing connectors | Phase 8 |
| 30 | Search analytics | GSC impressions, clicks, position | Missing | `GSC_SITE_URL` env placeholder only | GSC API integration | High | Medium | Medium | Google credentials | Phase 8 |
| 31 | Content analytics | Per-content views, engagement, conversion | Partially Implemented | GA4 traffic (site-level); `reach_analytics_snapshots` | Per-content tracking, dashboards | High | Medium | Low | GA4, publishing URLs | Phase 8 |
| 32 | Lead attribution | UTM control, conversion tracking, revenue | Partially Implemented | Campaign UTM fields; Engage push | Attribution model, UTM generator | High | Medium | Medium | Engage, GA4 | Phase 8–9 |
| 33 | AI visibility monitoring | Test prompts on AI platforms, track mentions | Missing | No tables or APIs | Prompt library, test scheduler, capture | High | High | Medium | Job queue | Phase 8 |
| 34 | Competitor visibility monitoring | Track competitor mentions in AI/search | Missing | No implementation | Competitor config, monitoring jobs | Medium | Medium | Low | AI visibility | Phase 8 |
| 35 | Content refresh automation | Performance-based refresh recommendations | Missing | No refresh tasks or rules | Performance thresholds, refresh queue | Medium | Medium | Low | Content analytics | Phase 9 |
| 36 | Notifications | Daily digest, escalation, approval alerts | Missing | No notification system | Email/in-app notifications, digest job | High | Medium | Medium | Job queue, approval centre | Phase 2 |
| 37 | Audit logging | Full audit trail for automated decisions | Substantially Implemented | `reach_audit_logs`, `AuditLogger`, Console fan-out | Bot decision detail, publishing audit | High | Low | Low | — | Phase 0 (extend) |
| 38 | Role permissions | Multi-role RBAC with approval/publish separation | Partially Implemented | `reach_roles` table; only `super_admin` seeded | Role definitions, permission checks, UI | Critical | High | Critical — SoD | Auth system | Phase 0 |
| 39 | Job queues | Async processing for AI, publishing, imports | Missing | `reach_marketing_bot_queue` — synchronous only | Queue abstraction, workers, cron | Critical | Critical | High — timeouts | — | Phase 0 |
| 40 | Security and compliance | Validation, sanitisation, rate limiting, PII controls | Partially Implemented | JWT, CORS, `.env` secrets, session revocation | Rate limiting, HTML sanitisation, PII scrub for AI, compliance rules | Critical | High | Critical | All modules | Phase 0–3 |

---

## Summary by Phase

| Phase | Capabilities addressed |
|-------|----------------------|
| Phase 0 | 37, 38, 39, 40 (foundation) |
| Phase 1 | 1, 2, 3 |
| Phase 2 | 5, 20, 21, 22, 36 |
| Phase 3 | 4, 6, 13, 14, 15, 16, 40 |
| Phase 4 | 7, 17, 18, 19, 23 |
| Phase 5 | 8, 9, 24 |
| Phase 6 | 10, 11, 12 |
| Phase 7 | 25, 26, 27, 28 |
| Phase 8 | 29, 30, 31, 32, 33, 34 |
| Phase 9 | 35, 32 (attribution depth) |

## Autonomy Policy Gap Summary

| Policy area | Enforceable today? | Gap |
|-------------|-------------------|-----|
| Auto: collect sources, generate drafts | Partial — bot generates stubs without real AI | LLM integration needed |
| Must approve: publish to any channel | Partial — workflow states exist; same user can approve+publish | Role segregation needed |
| Must not: fabricate users/reviews | No — no community module or safeguards | Community architecture needed |
| Must not: unsupported legal/tax claims | No — no validation layer | Fact/claim validation needed |
