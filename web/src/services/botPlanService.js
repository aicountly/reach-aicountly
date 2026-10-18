import { api } from './api';

/** Bot → Plan & Performance (permission bot.view). Dates are IST. */
export const botPlanService = {
  /** @param {'today'|'tomorrow'|'this_week'|'next_week'|'this_month'|'next_month'} period */
  plan: (period) => api.get('v1/bot/plan', { period }),
  /** @param {'yesterday'|'last_7_days'|'last_30_days'|'this_week'|'last_week'|'this_month'|'last_month'} range */
  performance: (range) => api.get('v1/bot/performance', { range }),
};
