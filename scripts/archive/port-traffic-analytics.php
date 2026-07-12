<?php

/**
 * ARCHIVED — One-off migration script.
 *
 * This script was used ONCE to port `TrafficAnalyticsService` from the
 * `flow-react-app` repository into `server-php/app/Libraries/` and rename
 * Flow-specific hooks (env keys, PDO wiring, taxonomy class, stream labels).
 *
 * It is NOT invoked at runtime, NOT referenced by any deploy script or
 * cron entry, and NOT part of composer scripts. The hardcoded path
 * `C:/Users/pc/flow-react-app` is a developer-workstation-only reference
 * from the original port and must not be executed on production servers.
 *
 * Retained for historical traceability only. Do not run.
 *
 * Moved from `scripts/port-traffic-analytics.php` to
 * `scripts/archive/port-traffic-analytics.php` in Phase 0 (feature/phase-0-foundation).
 */

fwrite(STDERR, "This script is archived and disabled. See file header for context.\n");
exit(2);
