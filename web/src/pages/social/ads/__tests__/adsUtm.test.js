import { describe, it, expect } from 'vitest';
import { buildAdsLink, landingProblem, ADS_PLATFORMS, DEFAULT_CAMPAIGN, isCreativeId } from '../adsUtm';

const L = 'https://aicountly.com/free-accounting-software';

// Same examples as server-php/tests/Unit/Ads/AdsConventionsTest.php::links().
describe('buildAdsLink', () => {
  it.each([
    ['google_search', { creativeId: 'gs-rsa-01' }, `${L}?utm_source=google&utm_medium=cpc&utm_campaign=soft-launch-2026-10&utm_content=gs-rsa-01`],
    ['youtube', { creativeId: 'yt-reel-01' }, `${L}?utm_source=youtube&utm_medium=video&utm_campaign=soft-launch-2026-10&utm_content=yt-reel-01`],
    ['meta', { creativeId: 'fb-reel-01' }, `${L}?utm_source=meta&utm_medium=paid_social&utm_campaign=soft-launch-2026-10&utm_content=fb-reel-01`],
    ['linkedin', { utmCampaign: 'ca-firms-2026-11', creativeId: 'li-single-01' }, `${L}?utm_source=linkedin&utm_medium=paid_social&utm_campaign=ca-firms-2026-11&utm_content=li-single-01`],
  ])('%s', (platform, extra, expected) => {
    expect(buildAdsLink({ landingUrl: L, platform, ...extra })).toBe(expected);
  });

  it('keeps the page query and fragment and drops old tracking', () => {
    expect(buildAdsLink({ landingUrl: 'https://aicountly.com/pricing?plan=free&utm_source=old&gclid=X#faqs', platform: 'meta' }))
      .toBe('https://aicountly.com/pricing?plan=free&utm_source=meta&utm_medium=paid_social&utm_campaign=soft-launch-2026-10#faqs');
  });

  it('refuses what breaks the conventions', () => {
    expect(() => buildAdsLink({ landingUrl: 'http://aicountly.com/x', platform: 'meta' })).toThrow(/https/);
    expect(() => buildAdsLink({ landingUrl: 'https://aicountly.com.evil.example/', platform: 'meta' })).toThrow(/aicountly\.com/);
    expect(() => buildAdsLink({ landingUrl: L, platform: 'tiktok' })).toThrow(/platform/);
    expect(() => buildAdsLink({ landingUrl: L, platform: 'meta', creativeId: 'Reel 01' })).toThrow(/creative id/);
    expect(() => buildAdsLink({ landingUrl: L, platform: 'meta', utmCampaign: 'Soft Launch' })).toThrow(/slug/);
    expect(landingProblem('https://www.aicountly.com/free-accounting-software')).toBeNull();
  });

  it('carries the owner launch split as suggestions only', () => {
    expect(Object.values(ADS_PLATFORMS).map((p) => p.suggestedBudget)).toEqual([600, 300, 300, 800]);
    expect(DEFAULT_CAMPAIGN).toBe('soft-launch-2026-10');
    expect(isCreativeId('gs-rsa-01')).toBe(true);
    expect(isCreativeId('GS RSA')).toBe(false);
  });
});
