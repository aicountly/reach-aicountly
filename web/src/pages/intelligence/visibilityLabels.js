export const PROVIDER_LABEL = { gemini: 'Gemini', openai: 'ChatGPT (OpenAI API)', perplexity: 'Perplexity' };

export const RUNS = '/intelligence/visibility/runs';
export const OBSERVATIONS = '/intelligence/visibility/observations';

export const BRAND_FACET_LABEL = { named: 'brand named', not_named: 'brand not named', cited: 'brand site cited' };

/** Chip labels for the run list's URL filters. */
export const RUN_FILTER_LABELS = {
  days: 'Period', status: 'Status', provider: 'Assistant', prompt_id: 'Question', market: 'Market',
  batch_id: 'Pass', q: 'Question contains', entity: 'Names', competitor_id: 'Competitor', brand: 'Brand',
  gap: 'Gap', cited_domain: 'Cites',
};

export function formatRunFilter(key, value) {
  if (key === 'days') return `last ${value} days`;
  if (key === 'provider') return PROVIDER_LABEL[value] || value;
  if (key === 'brand') return BRAND_FACET_LABEL[value] || value;
  if (key === 'gap') return 'competitors named, brand not';
  if (key === 'prompt_id' || key === 'batch_id' || key === 'competitor_id') return `#${value}`;
  return value;
}

export const pct = (v) => (v == null ? '—' : `${Math.round(Number(v) * 1000) / 10}%`);
