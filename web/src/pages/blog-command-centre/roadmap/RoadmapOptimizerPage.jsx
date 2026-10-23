import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { blogCommandCentreService } from '../../../services/blogCommandCentreService';
import { Loader } from '../../../components/common/Loader';
import { Alert } from '../../../components/common/Alert';
import { RoadmapEmptyState } from './roadmapShared';
import { formatScore } from './roadmapFormat';
import { formatDate, toDateInputValue } from '../../../utils/formatDate';
import { ROUTES } from '../../../constants/routes';
import { DrillLink, DrillPath, drillHref, useDrillFilters } from '../../../components/drill';

const DECISION_FILTERS = ['run_id', 'has_work_block', 'decision'];
const DECISION_LABELS = { run_id: 'Run', has_work_block: 'With work block', decision: 'Decision' };

const WEIGHT_FIELDS = [
  ['search_opportunity', 'Search opportunity'],
  ['product_priority', 'Product priority'],
  ['audience_problem', 'Audience problem'],
  ['conversion_potential', 'Conversion potential'],
  ['content_gap', 'Content gap'],
  ['seasonality', 'Seasonality'],
  ['internal_link_value', 'Internal link value'],
  ['evidence_readiness', 'Evidence readiness'],
];

function statusVariant(status) {
  if (status === 'completed') return 'success';
  if (status === 'failed') return 'danger';
  if (status === 'skipped') return 'muted';
  return 'info';
}

export function RoadmapOptimizerPage() {
  const [runs, setRuns] = useState([]);
  const [decisions, setDecisions] = useState([]);
  const [weights, setWeights] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [saveError, setSaveError] = useState('');
  const [saved, setSaved] = useState(false);
  const [saving, setSaving] = useState(false);
  // The decisions table is drilled from the run counts (?run_id=…) and from
  // the decision badges; filters live in the URL so Back steps out again.
  const drill = useDrillFilters(DECISION_FILTERS);
  const { run_id: runId, has_work_block: hasWorkBlock, decision: decisionFilter } = drill.filters;
  const [decisionsTotal, setDecisionsTotal] = useState(0);

  useEffect(() => {
    Promise.all([
      blogCommandCentreService.getOptimizerRuns(),
      blogCommandCentreService.getScoringWeights(),
    ])
      .then(([runsData, weightsData]) => {
        setRuns(runsData?.items ?? []);
        setWeights(weightsData ?? null);
        setError('');
      })
      .catch((err) => setError(err?.message || 'Unable to load optimiser data.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    const params = {};
    if (runId) params.run_id = runId;
    if (hasWorkBlock) params.has_work_block = hasWorkBlock;
    if (decisionFilter) params.decision = decisionFilter;
    blogCommandCentreService.getRoadmapDecisions(params)
      .then((data) => {
        setDecisions(data?.items ?? []);
        setDecisionsTotal(data?.total ?? 0);
      })
      .catch((err) => setError(err?.message || 'Unable to load roadmap decisions.'));
  }, [runId, hasWorkBlock, decisionFilter]);

  const weightSum = weights
    ? WEIGHT_FIELDS.reduce((sum, [key]) => sum + Number(weights[key] ?? 0), 0)
    : 0;

  const handleWeightChange = (key, value) => {
    setSaved(false);
    setWeights((prev) => ({ ...prev, [key]: value === '' ? '' : Number(value) }));
  };

  const handleSave = (event) => {
    event.preventDefault();
    setSaving(true);
    setSaveError('');
    setSaved(false);

    const payload = Object.fromEntries(
      WEIGHT_FIELDS.map(([key]) => [key, Number(weights?.[key] ?? 0)]),
    );

    blogCommandCentreService.saveScoringWeights(payload)
      .then(() => setSaved(true))
      .catch((err) => setSaveError(err?.message || 'Unable to save scoring weights.'))
      .finally(() => setSaving(false));
  };

  if (loading) return <Loader label="Loading optimiser…" />;

  return (
    <div style={{ display: 'grid', gap: '2rem' }}>
      {error && <Alert variant="error">{error}</Alert>}

      <section>
        <h3>Recent runs</h3>
        <p className="text-muted">
          The optimiser is scheduled for 00:30 Asia/Kolkata. Run it manually with
          {' '}<code>php spark reach:blog-optimize-roadmap --force</code>.
        </p>
        {runs.length === 0 ? (
          <RoadmapEmptyState
            title="No optimiser runs recorded"
            detail="The table is empty until the daily command runs for the first time."
          />
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Run date</th>
                <th>Status</th>
                <th>Scored</th>
                <th>Decisions</th>
                <th>Work blocks</th>
                <th>Skipped reason</th>
                <th>Duration</th>
              </tr>
            </thead>
            <tbody>
              {runs.map((run) => (
                <tr key={run.id}>
                  <td>{formatDate(run.run_for_date)}</td>
                  <td><span className={`badge badge--${statusVariant(run.status)}`}>{run.status}</span></td>
                  <td>
                    <DrillLink
                      to={run.candidates_scored > 0 && run.run_for_date
                        ? drillHref(ROUTES.BCC_ROADMAP_SCORED, { scored_for_date: toDateInputValue(run.run_for_date) })
                        : undefined}
                      label={`Topics scored on ${formatDate(run.run_for_date)}`}
                    >
                      {run.candidates_scored ?? 0}
                    </DrillLink>
                  </td>
                  <td>
                    <DrillLink
                      onClick={run.decisions_created > 0 ? () => drill.drill({ run_id: run.id, has_work_block: '', decision: '' }) : undefined}
                      label={`Decisions from the run on ${formatDate(run.run_for_date)}`}
                    >
                      {run.decisions_created ?? 0}
                    </DrillLink>
                  </td>
                  <td>
                    <DrillLink
                      onClick={run.work_blocks_created > 0 ? () => drill.drill({ run_id: run.id, has_work_block: '1', decision: '' }) : undefined}
                      label={`Decisions that queued a work block on ${formatDate(run.run_for_date)}`}
                    >
                      {run.work_blocks_created ?? 0}
                    </DrillLink>
                  </td>
                  <td>{run.skipped_reason ?? '—'}</td>
                  <td>{run.duration_ms ? `${run.duration_ms} ms` : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      <section>
        <h3>{drill.depth > 0 ? `Decisions (${decisionsTotal})` : 'Recent decisions'}</h3>
        {drill.depth > 0 && (
          <DrillPath
            drill={drill}
            rootLabel="All decisions"
            labels={DECISION_LABELS}
            format={(key, value) => (key === 'run_id' ? `#${value}` : key === 'has_work_block' ? 'yes' : value)}
          />
        )}
        {decisions.length === 0 ? (
          <RoadmapEmptyState
            title={drill.depth > 0 ? 'No decisions match this filter' : 'No decisions yet'}
            detail="Decisions are recorded each time the optimiser ranks and classifies candidates."
          />
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Topic</th>
                <th>Decision</th>
                <th>Score</th>
                <th>Rank</th>
                <th>Review</th>
                <th>Reason</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              {decisions.map((decision) => (
                <tr key={decision.id}>
                  <td>
                    {decision.content_item_id
                      ? <Link to={ROUTES.CONTENT_DETAIL.replace(':id', decision.content_item_id)}>{decision.title ?? `Article #${decision.content_item_id}`}</Link>
                      : (decision.title ?? '—')}
                  </td>
                  <td>
                    <DrillLink
                      onClick={() => drill.drill({ decision: decision.decision })}
                      label={`Only ${decision.decision} decisions`}
                    >
                      <span className="badge badge--info">{decision.decision}</span>
                    </DrillLink>
                  </td>
                  <td>{formatScore(decision.score_at_decision)}</td>
                  <td>{decision.rank_at_decision ?? '—'}</td>
                  <td>
                    {decision.requires_human_review
                      ? <span className="badge badge--warning">Human review</span>
                      : <span className="text-muted">—</span>}
                  </td>
                  <td>{decision.decision_reason ?? '—'}</td>
                  <td>{formatDate(decision.decided_for_date)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      <section>
        <h3>Scoring weights</h3>
        {saveError && <Alert variant="error">{saveError}</Alert>}
        {saved && <Alert variant="success">Scoring weights saved.</Alert>}

        <form onSubmit={handleSave}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '1rem' }}>
            {WEIGHT_FIELDS.map(([key, label]) => (
              <label key={key} className="form-field">
                <span>{label}</span>
                <input
                  type="number"
                  min="0"
                  max="100"
                  value={weights?.[key] ?? 0}
                  onChange={(e) => handleWeightChange(key, e.target.value)}
                />
              </label>
            ))}
          </div>

          <p className={weightSum === 100 ? 'text-muted' : 'text-danger'} style={{ marginTop: '0.75rem' }}>
            Total: {weightSum} / 100 {weightSum !== 100 && '— weights must sum to exactly 100.'}
          </p>

          <button type="submit" className="btn btn--primary" disabled={saving || weightSum !== 100}>
            {saving ? 'Saving…' : 'Save weights'}
          </button>
        </form>
      </section>
    </div>
  );
}
