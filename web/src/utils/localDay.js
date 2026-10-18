import { toDateInputValue } from './formatDate';

/**
 * YYYY-MM-DD of an instant in the display zone (IST, `ui.timezone`) — the
 * day the API's date-range params mean (LeadOutcomeController reads them as
 * Asia/Kolkata dates), whatever zone the browser is in.
 */
export function localDay(d) {
  return toDateInputValue(d);
}
