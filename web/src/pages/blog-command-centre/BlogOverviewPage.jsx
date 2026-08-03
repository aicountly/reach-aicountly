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
  const textProviders = countProviders(providers.text);
  const imageProviders = countProviders(providers.image);
  const lastRun = optimizer.last_run;

  return [
    {
      key: 'production',
      title: 'Production',
      icon: Factory,
      link: ROUTES.BCC_PIPELINE_DRAFTS,
      status: inProduction > 0 ? 'OK' : 'NO DATA',
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
      link: ROUTES.BCC_PUBLISHING_READY,
      status: (publishing.failed ?? 0) > 0 ? 'ERROR' : (publishing.published ?? 0) > 0 ? 'OK' : 'NO DATA',
      metrics: [
        metric('queued', publishing.queued ?? 0),
        metric('published', publishing.published ?? 0),
        metric('failed', publishing.failed ?? 0),
      ],
    },
    {
      key: 'search',
      title: 'Search',
      icon: Search,
      link: ROUTES.BCC_SEO,
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
