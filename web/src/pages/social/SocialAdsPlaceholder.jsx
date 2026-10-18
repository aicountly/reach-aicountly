import { Info } from 'lucide-react';
import { ADS_PLATFORMS } from './socialAdsModel';

/**
 * Placeholder for /social/ads until the paid-ads register (another stream:
 * manual campaigns, UTM builder, CSV results import, GET v1/ads/summary)
 * lands at this route. Replace the route element at merge.
 */
export default function SocialAdsPlaceholder() {
  return (
    <section aria-labelledby="sa-ads-title" data-testid="ads-placeholder">
      <h2 id="sa-ads-title" className="sa-h2">Ads</h2>
      <p className="sa-info" data-testid="ads-not-connected">
        <Info size={15} aria-hidden="true" />
        <span>
          {ADS_PLATFORMS.map((p) => p.label.replace(/ \(.*\)$/, '')).join(', ')}: API not connected — campaigns are
          placed manually in each ads manager. Reach does not place, pause or read ads.
        </span>
      </p>
      <div className="as-empty">
        <p><strong>The ads register is coming in this release.</strong></p>
        <p className="text-sm">It will record manually placed campaigns, build UTM links and import results from CSV.</p>
      </div>
      <details className="sa-details">
        <summary>What an API connection would need</summary>
        {ADS_PLATFORMS.map((p) => (
          <div key={p.key} className="sa-details__block">
            <h3 className="sa-details__title">{p.label}</h3>
            <ul>{p.needs.map((n) => <li key={n}>{n}</li>)}</ul>
          </div>
        ))}
      </details>
    </section>
  );
}
