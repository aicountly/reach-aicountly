# Reach environment reference

`server-php/.env.example` lists only what an operator must set. This file is
the complete picture: what is required, what can be overridden, and what was
removed because nothing reads it.

The split is deliberate. A value belongs in `.env.example` when it is a
credential, a per-environment URL, or a switch that is genuinely flipped.
Anything that encodes a *rule* — a relevance floor, a score threshold, a
provider endpoint, a retry budget — lives in code, where it can be reviewed,
tested and shipped like any other behaviour. The 159-entry file this replaced
made policy look like configuration, which is how `BLOG_IMAGE_GENERATION_ENABLED`
ended up silently disabling gallery covers.

---

## 1. Required — set these

See `server-php/.env.example`. In short:

| Group | Variables |
|---|---|
| App | `CI_ENVIRONMENT`, `app.baseURL`, `app.indexPage` |
| Database | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` |
| Auth | `JWT_SECRET`, `ALLOWED_ORIGINS`, `SUPER_ADMIN_*` |
| Identity | `REACH_APP_URL`, `AICOUNTLY_SITE_URL` |
| Console | `CONSOLE_API_URL`, `CONSOLE_API_BASE_URL`, `CONSOLE_API_TOKEN`, `CONSOLE_INBOUND_TOKEN` |
| Engage / Worker | `ENGAGE_*`, `WORKER_*` |
| Publishing | `AICOUNTLY_PUBLIC_SITE_BASE_URL`, `_SERVICE_TOKEN`, `_SIGNING_KEY` |
| Reach tokens | `MEDIA_SIGNING_KEY`, `PUBLIC_LEAD_CAPTURE_TOKEN`, `REACH_AUTOMATION_TOKEN` |
| AI keys | `AI_OPENAI_API_KEY`, `AI_GEMINI_API_KEY`, `AI_PERPLEXITY_API_KEY` |
| Channels | social tokens, `EMAIL_API_KEY`, `SMS_*`, `WHATSAPP_*` |
| Analytics | `GA4_*`, `SEARCH_CONSOLE_*`, `CONTENT_ANALYTICS_*`, `INDEXNOW_*` |
| Switches | `BLOG_*_ENABLED`, `KB_AUTO_APPROVE`, `BLOG_GALLERY_ALERT_EMAIL` |

---

## 2. Optional overrides — code defaults, add only to change them

Every variable below is still read by the code. It is absent from
`.env.example` because the default is the intended value.

### Blog covers and media

| Variable | Default | Effect |
|---|---|---|
| `BLOG_REQUIRE_COVER_IMAGE` | `true` | No blog publishes without a suitable cover; a miss parks it in `internal_review`. Set false to publish with a blank listing tile. |
| `MEDIA_GALLERY_MIN_RELEVANCE` | `3` | Score an asset must clear to be considered suitable |
| `MEDIA_GALLERY_ALLOW_UNMATCHED_FALLBACK` | `false` | True assigns any free cover when nothing matches — the "GST article, landscape photo" failure. Leave false. |
| `MEDIA_GALLERY_REUSE_COOLDOWN_DAYS` | `30` | Gap before a cover may front another blog |
| `MEDIA_GALLERY_LOOKAHEAD_DAYS` | `14` | Deficit horizon for the cover shortfall report |
| `BLOG_ROUTINE_AI_COVER_FALLBACK` | `true` | Allow one generated cover on a gallery miss (needs `BLOG_IMAGE_GENERATION_ENABLED`) |
| `REACH_PUBLIC_BASE_URL` | `REACH_APP_URL` | Host for signed media URLs, when media is served from a different origin |

### Fact verification and roadmap

| Variable | Default | Effect |
|---|---|---|
| `BLOG_VERIFICATION_PASS_THRESHOLD` | `0.95` | Claim pass rate required to proceed |
| `BLOG_AUTHORITATIVE_SOURCE_HOSTS` | incometax, gst, mca, rbi, sebi, egazette (`.gov.in`) | Hosts treated as authoritative |
| `BLOG_FACT_REVISION_MAX_ATTEMPTS` | `2` | Self-heal attempts before parking for a human |
| `BLOG_ROADMAP_MIN_SCORE` | `40` | Score below which a topic stays HOLD |
| `BLOG_ROADMAP_MIN_SCORE_MOVEMENT` | `5` | Movement needed to re-decide a topic |
| `BLOG_ROADMAP_COOLDOWN_DAYS` | `14` | Re-scoring cooldown |
| `BLOG_ROADMAP_MAX_DAILY_CANDIDATES` | `10` | Candidates scored per optimiser run |
| `BLOG_ROADMAP_MAX_WEEKLY_PUBLICATIONS` | `5` | Publication ceiling per week |
| `BLOG_ROADMAP_PORTFOLIO_OVER_PCT_MARGIN` | `15` | Allowed drift from the portfolio mix |
| `BLOG_ROADMAP_COLD_START_ENABLED` | `true` | Score discovered topics without GSC history |
| `BLOG_ROADMAP_COLD_START_SCORE` | `55` | Score assigned to cold-start topics |
| `BLOG_AUTO_DISCOVER_ON_OPTIMIZE` | `true` | Run DISCOVER_TOPICS when the backlog empties |

### Feature flags that match their default

`BLOG_COMMAND_CENTRE_ENABLED` (`true`), `BLOG_LEGACY_CREATE_DISABLED` (`true`),
`BLOG_LEGACY_REDIRECT_ENABLED` (`true`), `BLOG_DB_BODY_FALLBACK_ENABLED`
(`true`), `BLOG_FILE_RENDERING_ENABLED` (`false`).

### AI providers

| Variable | Default |
|---|---|
| `AI_OPENAI_BASE_URL` | `https://api.openai.com/v1` |
| `AI_OPENAI_DEFAULT_MODEL` | `gpt-5-mini` |
| `AI_OPENAI_IMAGE_MODEL` | `gpt-image-1` |
| `AI_GEMINI_BASE_URL` | `https://generativelanguage.googleapis.com` |
| `AI_GEMINI_DEFAULT_MODEL` | `gemini-2.5-flash` |
| `AI_GEMINI_IMAGE_MODEL` | `gemini-3-pro-image-preview` |
| `AI_GEMINI_IMAGE_FALLBACK_MODEL` | `gemini-2.5-flash-image` |
| `AI_PERPLEXITY_BASE_URL` | `https://api.perplexity.ai` |
| `AI_PERPLEXITY_DEFAULT_MODEL` | `sonar-pro` |
| `AI_PERPLEXITY_HEALTHCHECK_ALLOW_SPEND` | `false` |
| `REACH_AI_MOCK` | `false` — local development and tests only |

Text models are seeded into the catalog from the `*_DEFAULT_MODEL` values by
`php spark reach:ai-seed-catalog`; after seeding, the DB catalog is the control
surface, not the environment.

### Publishing connector

| Variable | Default |
|---|---|
| `AICOUNTLY_PUBLIC_SITE_KEY_ID` | `reach-v1` |
| `AICOUNTLY_PUBLIC_SITE_TIMEOUT` | `15` seconds |
| `AICOUNTLY_PUBLIC_SITE_API_VERSION` | `1` |
| `AICOUNTLY_PUBLICATION_CONNECTION_KEY` | `aicountly_com` |
| `REACH_PUB_MOCK` | `false` — forces the mock publisher |
| `AICOUNTLY_SITE_API_BASE_URL` / `_TOKEN` | empty — legacy pre-Phase-4 path |

### Gateways

| Variable | Default |
|---|---|
| `EMAIL_PROVIDER` | empty → gateway sender |
| `EMAIL_API_URL` | Itwalk `/email/1/send` |
| `EMAIL_API_FORMAT` | `auto` (tries multipart, then JSON) |
| `SENDER_EMAIL` / `SENDER_NAME` | `support@aicountly.com` / `AICOUNTLY Reach` |
| `SMS_PROVIDER` | `digimiles` (`aoc-portal` is an alias) |
| `SMS_API_URL` | provider default (`https://api.aoc-portal.com/v1/sms`) |
| `SMS_TYPE` | `TRANS` |
| `SMS_COUNTRY_CODE` | `91` |
| `WHATSAPP_PROVIDER` | empty → gateway sender |
| `WHATSAPP_API_URL` | Infobip text endpoint |
| `CAMPAIGN_DISPATCH_TEST_EMAIL` / `_PHONE` | empty — fallback recipient when an audience is unresolved |

### Connectors and misc

| Variable | Default |
|---|---|
| `SEARCH_CONSOLE_DATA_LAG_DAYS` | `3` — never ingest days newer than this |
| `SEARCH_CONSOLE_TIMEOUT_SECONDS` | `30` |
| `SEARCH_CONSOLE_USE_MOCK` | `false` |
| `CONTENT_ANALYTICS_TIMEOUT_SECONDS` | `30` |
| `CONTENT_ANALYTICS_USE_MOCK` | `false` |
| `INDEXNOW_ENDPOINT` | `https://api.indexnow.org/indexnow` |
| `JWT_TTL_MINUTES` | `720` |
| `REACH_BOT_MODE` | `confirm` — the `reach_bot_settings` row is authoritative |
| `CONTROLLER_APP_CODE` | `reach` |
| `CONTENT_BASE_PATH` | deployed content-base directory |
| `AIC_GLOBAL_URL` | `https://my.aicountly.com` |
| `GA4_PROPERTY_ID_PORTAL` / `GOOGLE_SERVICE_ACCOUNT_JSON_PORTAL` | fallback for the `_REACH` pair |
| `REACH_REQUEST_ID_PREFIX` | empty — prefix stamped on generated request IDs |

---

## 3. Removed — nothing reads these

These were carried in `.env.example` but no code path reads them. Setting them
has no effect; they are not listed above because they do not exist as far as
the application is concerned.

`DATAFORSEO_PASSWORD`, `KB_DEMO_EMAIL`, `KB_DEMO_PASSWORD`,
`KB_SCREENSHOTS_ENABLED`, `VISIBILITY_MAX_BUDGET_CENTS`, `GSC_SITE_URL`,
`META_ACCESS_TOKEN`, `LINKEDIN_ANALYTICS_TOKEN`, `TWITTER_ANALYTICS_TOKEN`,
`YOUTUBE_ANALYTICS_TOKEN`, `EMAIL_PROVIDER_API_KEY`, `WHATSAPP_PROVIDER_API_KEY`,
`INDEXNOW_BATCH_SIZE`, `INDEXNOW_KEY_LOCATION`, `SEARCH_CONSOLE_PROPERTY`,
`SEARCH_CONSOLE_BACKFILL_DAYS`, `SEARCH_CONSOLE_BATCH_SIZE`,
`SEARCH_CONSOLE_CREDENTIAL_REFERENCE`, `CONTENT_ANALYTICS_PROVIDER`,
`CONTENT_ANALYTICS_BACKFILL_DAYS`, `CONTENT_ANALYTICS_CREDENTIAL_REFERENCE`.

`DATAFORSEO_LOGIN` is kept: it is read as a sentinel that switches the SEO
backlink provider label from `internal_signals` to `dataforseo`. The paid API
call itself is not implemented, so the password has nothing to authenticate.

---

## 4. Existing deployments

Nothing here changes behaviour. A real `.env` that still carries removed or
optional variables keeps working exactly as before — the code reads the same
keys with the same precedence. This is a documentation change, not a
migration. There is no need to prune a working server `.env`, though matching
it to this reference makes the next incident shorter.
