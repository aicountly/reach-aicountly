import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Factory, Map, ShieldCheck, Globe, Search, BrainCircuit,
} from 'lucide-react';
import { blogCommandCentreService } from '../../services/blogCommandCentreService';
import { ROUTES } from '../../constants/routes';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';

const OK_STATUSES = ['OK', 'CONNECTED', 'ENABLED'];

function badgeVariant(status) {
  if (OK_STATUSES.includes(status)) return 'success';
  if (status === 'MISCONFIGURED' || status === 'ERROR') return 'danger';
  if (status === 'MOCK' || status === 'STALE' || status === 'WARN') return 'warning';
  return 'muted';
}

function metric(label, value) {
  return { label, value: value === null || value === undefined ? '—' : String(value) };
}

function countProviders(map) {
  const entries = Object.entries(map ?? {});
  const configured = entries.filter(([, state]) => state === 'CONFIGURED').length;
  return { configured, total: entries.length };
}

/** Roadmap card: OK only when optimiser is enabled and last run looks fresh. */
function roadmapStatus(enabled, lastRun) {
  if (!enabled) return 'DISABLED';
  if (!lastRun) return 'NO DATA';
  if (lastRun.status === 'skipped' || lastRun.skipped_reason) return 'WARN';
  const runDate = lastRun.run_for_date;
  if (runDate) {
    const run = new Date(`${runDate}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const ageDays = Math.floor((today - run) / 86400000);
    if (!Number.isNaN(ageDays) && ageDays >= 2) return 'STALE';
  }
  if ((lastRun.candidates_scored ?? 0) === 0 && (lastRun.decisions_created ?? 0) === 0) {
    return 'WARN';
  }
  return 'OK';
}

/**
 * Publishing card status.
 *
 * ERROR is reserved for failures inside the recent window; older ones drop to
 * WARN. A lifetime failed count kept the card permanently red, which made it
 * useless as a signal — you could not tell a broken pipeline from one bad
 * deployment months ago.
 */
function publishingStatus(publishing) {
  if ((publishing.failed_recent ?? 0) > 0) return 'ERROR';
  if ((publishing.failed ?? 0) > 0) return 'WARN';
  if ((publishing.published ?? 0) > 0 || (publishing.verified ?? 0) > 0) return 'OK';
  if ((publishing.in_flight ?? 0) > 0) return 'OK';
  return 'NO DATA';
}

function publishingDetail(publishing) {
  const failedRecent = publishing.failed_recent ?? 0;
  const failed = publishing.failed ?? 0;
  const days = publishing.recent_days ?? 7;

  if (failedRecent > 0) {
    return `${failedRecent} failed in the last ${days} days — open to retry.`;
  }
  if (failed > 0) {
    return `No failures in the last ${days} days; ${failed} older one${failed === 1 ? '' : 's'} still unresolved.`;
  }
  if ((publishing.verified ?? 0) > 0) {
    return `${publishing.verified} deployment${publishing.verified === 1 ? '' : 's'} verified live.`;
  }
  return null;
}

function publishingLink(publishing) {
  return (publishing.failed ?? 0) > 0
    ? `${ROUTES.BCC_PUBLISHING_DEPLOYMENTS}?status=failed,blocked`
    : ROUTES.BCC_PUBLISHING_DEPLOYMENTS;
}

/**
 * Builds the six dashboard cards from the real overview payload. Every card
 * reports an explicit state so an empty system reads as "no data yet" rather
 * than implying activity that has not happened.
 */
function buildCards(data) {
  const workflow = data.workflow_counts ?? {};
  const publishing = data.publishing ?? {};
  const workBlocks = data.work_blocks ?? {};
  const searchConsole = data.connectors?.search_console ?? {};
  const providers = data.ai_providers ?? {};
  const optimizer = data.optimizer ?? {};

  const briefStage = (workflow.brief_draft ?? 0) + (workflow.brief_ready ?? 0)
    + (workflow.outline_draft ?? 0) + (workflow.outline_ready ?? 0)
    + (workflow.idea ?? 0) + (workflow.roadmap_planned ?? 0);
  const draftStage = (workflow.draft ?? 0) + (workflow.draft_generating ?? 0)
    + (workflow.fact_verifying ?? 0) + (workflow.fact_verified ?? 0);
  const reviewStage = (workflow.seo_review ?? 0) + (workflow.internal_review ?? 0)
    + (workflow.changes_requested ?? 0);
  const inProduction = briefStage + draftStage + reviewStage;
  const blogTotal = data.content?.blog_total ?? 0;
  const textProviders = countProviders(providers.text);
  const imageProviders = countProviders(providers.image);
  const lastRun = optimizer.last_run;

  return [
    {
      key: 'production',
      title: 'Production',
      icon: Factory,
      // Never send someone to the Drafts filter when there are no drafts —
      // that lands on "No blog content items match this filter" and reads as
      // an empty system. With nothing in production, show the whole library.
      link: reviewStage > 0
        ? ROUTES.BCC_VERIFICATION
        : inProduction > 0
          ? ROUTES.BCC_PIPELINE_DRAFTS
          : ROUTES.BCC_PIPELINE_VERSIONS,
      // NO DATA means "no blog content exists". A library that has simply moved
      // past the production stages is IDLE, not empty.
      status: inProduction > 0 ? 'OK' : blogTotal > 0 ? 'IDLE' : 'NO DATA',
      detail: inProduction === 0 && blogTotal > 0
        ? `Nothing in production — all ${blogTotal} blog item${blogTotal === 1 ? '' : 's'} are past this stage.`
        : null,
      metrics: [
        metric('briefs/outlines', briefStage),
        metric('drafts', draftStage),
        metric('awaiting review', reviewStage),
      ],
    },
    {
      key: 'roadmap',
      title: 'Roadmap',
      icon: Map,
      link: ROUTES.BCC_ROADMAP_OPTIMIZER,
      status: roadmapStatus(optimizer.enabled, lastRun),
      detail: lastRun?.skipped_reason
        ? `Last run skipped: ${lastRun.skipped_reason}`
        : lastRun?.status === 'skipped'
          ? 'Last optimiser run was skipped'
          : undefined,
      metrics: [
        metric('last run', lastRun?.run_for_date),
        metric('scored', lastRun?.candidates_scored),
        metric('decisions', lastRun?.decisions_created),
      ],
    },
    {
      key: 'quality',
      title: 'Quality',
      icon: ShieldCheck,
      link: ROUTES.BCC_VERIFICATION,
      status: providers.verification_enabled ? 'OK' : 'DISABLED',
      metrics: [
        metric('approved', workflow.approved ?? 0),
        metric('rejected', workflow.rejected ?? 0),
        metric('verification', providers.verification_enabled ? 'on' : 'off'),
      ],
    },
    {
      key: 'publishing',
      title: 'Publishing',
      icon: Globe,
      // The metrics on this card are deployment states, so it has to land on
      // the deployment list — pre-filtered to the failures when it is red.
      // It used to link to Ready to Publish, which cannot show a single one.
      link: publishingLink(publishing),
      status: publishingStatus(publishing),
      detail: publishingDetail(publishing),
      metrics: [
        metric('in flight', publishing.in_flight ?? publishing.queued ?? 0),
        metric('published', publishing.published ?? 0),
        metric('failed', publishing.failed ?? 0),
      ],
    },
    {
      key: 'search',
      title: 'Search',
      icon: Search,
      link: ROUTES.BLOG_SEO,
      status: searchConsole.status ?? 'DISABLED',
      detail: searchConsole.detail,
      metrics: [
        metric('provider', searchConsole.provider),
        metric('property', searchConsole.site_property),
        metric('scheduled posts', workflow.scheduled ?? 0),
      ],
    },
    {
      key: 'ai_ops',
      title: 'AI Ops',
      icon: BrainCircuit,
      link: ROUTES.BCC_OPERATIONS_AI_USAGE,
      status: textProviders.configured > 0 ? 'OK' : 'NO DATA',
      metrics: [
        metric('text providers', `${textProviders.configured}/${textProviders.total}`),
        metric('image providers', `${imageProviders.configured}/${imageProviders.total}`),
        metric('work blocks queued', workBlocks.queued ?? 0),
      ],
    },
  ];
}

export function BlogOverviewPage() {
  const [overview, setOverview] = useState(null);
  const [loading, setLoading] = useState(true);
  const [fetchFailed, setFetchFailed] = useState(false);

  useEffect(() => {
    blogCommandCentreService.getOverview()
      .then((data) => {
        setOverview(data);
        setFetchFailed(false);
      })
      .catch(() => {
        setFetchFailed(true);
        setOverview(null);
      })
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Loader label="Loading overview…" />;

  const cards = overview ? buildCards(overview) : [];

  return (
    <div>
      {fetchFailed && (
        <Alert variant="info">
          Overview API is not available. No metrics are shown rather than invented ones.
        </Alert>
      )}

      <div className="bcc-cards">
        {cards.map(({ key, title, icon: CardIcon, link, status, detail, metrics }) => (
          <Link key={key} to={link} className="bcc-card card" style={{ textDecoration: 'none', color: 'inherit' }}>
            <div className="bcc-card__header">
              <CardIcon size={18} aria-hidden="true" />
              <h3>{title}</h3>
              <span className={`badge badge--${badgeVariant(status)}`}>{status}</span>
            </div>
            <dl className="definition-list">
              {metrics.map(({ label, value }) => (
                <div key={label} className="bcc-card__metric">
                  <dt>{label}</dt>
                  <dd>{value}</dd>
                </div>
              ))}
            </dl>
            {detail && <p className="text-muted" style={{ fontSize: '0.8rem', marginTop: '0.5rem' }}>{detail}</p>}
          </Link>
        ))}
      </div>
    </div>
  );
}
