import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { analyticsService } from '../../services/analyticsService';
import { Card } from '../common/Card';
import { Loader } from '../common/Loader';
import { Alert } from '../common/Alert';
import { formatInr } from '../../utils/formatInr';
import { OutcomesNotice } from './OutcomesNotice';
import { OutcomeBreakdownTable } from './OutcomeBreakdownTable';
import {
  campaignLabel, sourceLabel, contentLabel, sellerLabel,
  campaignFilter, sourceFilter, contentFilter, sellerFilter,
} from './outcomeLabels';
import { localDay } from '../../utils/localDay';
import { ROUTES } from '../../constants/routes';
import { DrillLink, drillHref } from '../drill';

const WINDOWS = [7, 30, 90];

/** A tile that opens the Lead outcomes events it summarises; plain when there is nothing to open. */
function Tile({ label, value, hint, to }) {
  const body = (
    <>
      <div className="stat-tile__label">{label}</div>
      <div className="stat-tile__value">{value}</div>
      {hint && <div className="stat-tile__hint">{hint}</div>}
    </>
  );
  return to ? <Link className="stat-tile" to={to}>{body}</Link> : <div className="stat-tile">{body}</div>;
}

/**
 * Sales outcomes reported by Engage — conversions and revenue per campaign,
 * source, content and seller. Figures come only from received Engage events.
 */
export function OutcomesAnalyticsSection() {
  const [days, setDays] = useState(30);
  const [summary, setSummary] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const to = new Date();
    const from = new Date(to.getTime() - (days - 1) * 86400000);
    setLoading(true);
    setError(null);
    analyticsService.outcomes({ from: localDay(from), to: localDay(to) })
      .then(setSummary)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [days]);

  // Same window as this card: Lead outcomes computes from/to from `days` the same way.
  const outcomes = (params) => drillHref(ROUTES.LEAD_OUTCOMES, { days, ...params });
  const rowDrill = (filterOf) => (r) => {
    const f = filterOf(r);
    return f ? outcomes(f) : null;
  };
  const ifAny = (n, params) => (Number(n) > 0 ? outcomes(params) : null);

  return (
    <Card
      title="Sales outcomes (Engage)"
      actions={(
        <select aria-label="Outcome window" value={days} onChange={(e) => setDays(Number(e.target.value))}>
          {WINDOWS.map((d) => <option key={d} value={d}>Last {d} days</option>)}
        </select>
      )}
    >
      {error && <Alert variant="danger">{error}</Alert>}
      {loading && !summary ? <Loader /> : summary && (
        <div className="flex flex-col gap-3">
          <OutcomesNotice summary={summary} />
          <div className="grid grid-4">
            <Tile label="Conversions" value={summary.conversions} to={ifAny(summary.conversions, { stage: 'converted' })} />
            <Tile label="Revenue (payments)" value={formatInr(summary.revenue_inr)} to={ifAny(summary.revenue_inr, { paid: '1' })} />
            <Tile label="Converted value" value={formatInr(summary.converted_value_inr)} hint="Deal value at conversion" to={ifAny(summary.converted_value_inr, { stage: 'converted' })} />
            {/* Two targets in one tile (all events / the unmatched ones), so links inside instead of a linked tile. */}
            <Tile
              label="Outcome events"
              value={<DrillLink to={ifAny(summary.events, {})}>{summary.events}</DrillLink>}
              hint={<DrillLink to={ifAny(summary.unmatched, { match: 'unmatched' })}>{summary.unmatched} not matched to a Reach lead</DrillLink>}
            />
          </div>
          <OutcomeBreakdownTable title="By campaign" rows={summary.by_campaign} labelOf={campaignLabel} drillOf={rowDrill(campaignFilter)} />
          <OutcomeBreakdownTable title="By source" rows={summary.by_source} labelOf={sourceLabel} drillOf={rowDrill(sourceFilter)} />
          {summary.by_content?.length > 0 && (
            <OutcomeBreakdownTable
              title="By content" rows={summary.by_content} labelOf={contentLabel} drillOf={rowDrill(contentFilter)}
              labelTo={(r) => `/intelligence/content/${r.content_identity_id}`}
            />
          )}
          {summary.by_seller?.length > 0 && <OutcomeBreakdownTable title="By seller" rows={summary.by_seller} labelOf={sellerLabel} drillOf={rowDrill(sellerFilter)} />}
        </div>
      )}
    </Card>
  );
}
