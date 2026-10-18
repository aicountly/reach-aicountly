/**
 * Reach's paid-campaign UTM conventions — the browser copy of
 * server-php/app/Libraries/Ads/AdsConventions.php (the server validates again;
 * both test suites pin the same example links).
 *
 *   google_search → utm_source=google   utm_medium=cpc
 *   youtube       → utm_source=youtube  utm_medium=video
 *   meta          → utm_source=meta     utm_medium=paid_social  (Facebook + Instagram)
 *   linkedin      → utm_source=linkedin utm_medium=paid_social
 *   utm_campaign  = soft-launch-2026-10 by default; utm_content = the creative id
 */
export const DEFAULT_CAMPAIGN = 'soft-launch-2026-10';
export const DEFAULT_LANDING = 'https://aicountly.com/free-accounting-software';

export const ADS_PLATFORM_LIST = [
  { key: 'google_search', label: 'Google Ads — Search', utmSource: 'google', utmMedium: 'cpc', prefix: 'gs', manager: 'Google Ads', csv: 'google_ads', suggestedBudget: 600 },
  { key: 'youtube', label: 'Google Ads — YouTube', utmSource: 'youtube', utmMedium: 'video', prefix: 'yt', manager: 'Google Ads', csv: 'google_ads', suggestedBudget: 300 },
  { key: 'meta', label: 'Meta — Facebook & Instagram', utmSource: 'meta', utmMedium: 'paid_social', prefix: 'fb', manager: 'Meta Ads Manager', csv: 'meta', suggestedBudget: 300 },
  { key: 'linkedin', label: 'LinkedIn Ads', utmSource: 'linkedin', utmMedium: 'paid_social', prefix: 'li', manager: 'LinkedIn Campaign Manager', csv: 'linkedin', suggestedBudget: 800 },
];
export const ADS_PLATFORMS = Object.fromEntries(ADS_PLATFORM_LIST.map((p) => [p.key, p]));

const TRACKING_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'li_fat_id'];
const SLUG = /^[a-z0-9][a-z0-9._-]*$/;
const CREATIVE = /^[a-z0-9][a-z0-9-]{0,63}$/;

export function isCreativeId(id) {
  return CREATIVE.test(String(id || ''));
}

/** Why a landing URL is refused, or null. */
export function landingProblem(url) {
  let u;
  try { u = new URL(String(url || '')); } catch { return 'Landing URL must be a full https:// address.'; }
  if (u.protocol !== 'https:') return 'Landing URL must use https.';
  const host = u.hostname.toLowerCase();
  if (host !== 'aicountly.com' && !host.endsWith('.aicountly.com')) {
    return 'Ads must land on aicountly.com (or a *.aicountly.com page) so sign-up keeps the campaign.';
  }
  if (u.username || u.password) return 'Landing URL must not carry credentials.';
  return null;
}

/**
 * The tracking link for a platform and creative. Throws Error with a message
 * fit for the user when an input breaks the conventions.
 */
export function buildAdsLink({ landingUrl = DEFAULT_LANDING, platform, utmCampaign = '', creativeId = '', utmTerm = '' }) {
  const p = ADS_PLATFORMS[platform];
  if (!p) throw new Error('Choose a platform.');
  const problem = landingProblem(landingUrl);
  if (problem) throw new Error(problem);
  const campaign = String(utmCampaign || '').trim() || DEFAULT_CAMPAIGN;
  if (campaign.length > 240 || !SLUG.test(campaign)) throw new Error('utm_campaign must be a lower-case slug (e.g. soft-launch-2026-10).');
  const content = String(creativeId || '').trim();
  if (content && !CREATIVE.test(content)) throw new Error('utm_content is the creative id: lower-case letters, digits and dashes.');
  const term = String(utmTerm || '').trim();
  if (term && (term.length > 240 || !SLUG.test(term))) throw new Error('utm_term must be a lower-case slug.');

  const u = new URL(landingUrl);
  TRACKING_KEYS.forEach((k) => u.searchParams.delete(k));
  u.searchParams.append('utm_source', p.utmSource);
  u.searchParams.append('utm_medium', p.utmMedium);
  u.searchParams.append('utm_campaign', campaign);
  if (content) u.searchParams.append('utm_content', content);
  if (term) u.searchParams.append('utm_term', term);
  u.hostname = u.hostname.toLowerCase();
  return u.toString();
}
