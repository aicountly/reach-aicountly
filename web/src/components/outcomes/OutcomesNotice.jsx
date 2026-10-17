import { Alert } from '../common/Alert';
import { formatDateTime } from '../../utils/formatDate';

/** Says why an outcome view may be empty — never implies data that did not arrive. */
export function OutcomesNotice({ summary }) {
  if (!summary) return null;
  if (!summary.inbound_configured) {
    return (
      <Alert variant="warning">
        Engage outcomes are not being received: REACH_INBOUND_ENGAGE_TOKEN is not set (or is shorter than 32 characters) on the Reach API.
        Conversions and revenue below only include events received before that.
      </Alert>
    );
  }
  return (
    <p className="text-xs text-muted">
      {summary.last_received_at
        ? `Last outcome received from Engage ${formatDateTime(summary.last_received_at)}.`
        : 'No outcome has been received from Engage yet.'}
    </p>
  );
}
