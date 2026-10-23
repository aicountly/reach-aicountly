import { ChevronRight, X } from 'lucide-react';

/**
 * The trail of a drill-down: root › step › step. Each step goes back to that
 * level; ✕ drops just that step. Pair with useDrillFilters().
 *
 * @param {{
 *   drill: ReturnType<import('./useDrillFilters').useDrillFilters>,
 *   rootLabel?: string,
 *   labels?: Record<string, string>,
 *   format?: (key: string, value: string) => string,
 *   onRoot?: () => void,
 * }} props
 */
export function DrillPath({ drill, rootLabel = 'All', labels = {}, format, onRoot }) {
  const { steps, upTo, clear } = drill;
  const show = (key, value) => (format ? format(key, value) : value);

  return (
    <nav className="drill-path" aria-label="Drill-down path">
      {steps.length === 0 ? (
        <span className="drill-path__step drill-path__step--current" aria-current="location">{rootLabel}</span>
      ) : (
        <button type="button" className="drill-path__step" onClick={onRoot || (() => upTo(-1))}>{rootLabel}</button>
      )}
      {steps.map((step, i) => {
        const last = i === steps.length - 1;
        const label = `${labels[step.key] || step.key}: ${show(step.key, step.value)}`;
        return (
          <span key={step.key} className="drill-path__item">
            <ChevronRight size={14} aria-hidden="true" className="drill-path__sep" />
            {last ? (
              <span className="drill-path__step drill-path__step--current" aria-current="location">{label}</span>
            ) : (
              <button type="button" className="drill-path__step" onClick={() => upTo(i)}>{label}</button>
            )}
            <button
              type="button"
              className="drill-path__remove"
              onClick={() => clear(step.key)}
              aria-label={`Remove ${labels[step.key] || step.key} filter`}
              title="Remove this filter"
            >
              <X size={12} aria-hidden="true" />
            </button>
          </span>
        );
      })}
    </nav>
  );
}
