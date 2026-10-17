import { useEffect, useState } from 'react';
import { analyticsService } from '../../services/analyticsService';
import { Card } from '../common/Card';
import { Loader } from '../common/Loader';
import { Alert } from '../common/Alert';
import { formatInr } from '../../utils/formatInr';
import { OutcomesNotice } from './OutcomesNotice';
import { OutcomeBreakdownTable } from './OutcomeBreakdownTable';
import { campaignLabel, sourceLabel, contentLabel, sellerLabel } from './outcomeLabels';
import { localDay } from '../../utils/localDay';

const WINDOWS = [7, 30, 90];

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
            <div className="stat-tile"><div className="stat-tile__label">Conversions</div><div className="stat-tile__value">{summary.conversions}</div></div>
            <div className="stat-tile"><div className="stat-tile__label">Revenue (payments)</div><div className="stat-tile__value">{formatInr(summary.revenue_inr)}</div></div>
            <div className="stat-tile"><div className="stat-tile__label">Converted value</div><div className="stat-tile__value">{formatInr(summary.converted_value_inr)}</div><div className="stat-tile__hint">Deal value at conversion</div></div>
            <div className="stat-tile"><div className="stat-tile__label">Outcome events</div><div className="stat-tile__value">{summary.events}</div><div className="stat-tile__hint">{summary.unmatched} not matched to a Reach lead</div></div>
          </div>
          <OutcomeBreakdownTable title="By campaign" rows={summary.by_campaign} labelOf={campaignLabel} />
          <OutcomeBreakdownTable title="By source" rows={summary.by_source} labelOf={sourceLabel} />
          {summary.by_content?.length > 0 && <OutcomeBreakdownTable title="By content" rows={summary.by_content} labelOf={contentLabel} />}
          {summary.by_seller?.length > 0 && <OutcomeBreakdownTable title="By seller" rows={summary.by_seller} labelOf={sellerLabel} />}
        </div>
      )}
    </Card>
  );
}
