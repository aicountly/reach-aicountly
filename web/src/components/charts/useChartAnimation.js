import { useEffect, useRef, useState } from 'react';

/** Default run time for a chart entry/update animation, in milliseconds. */
export const CHART_ANIM_MS = 650;

/** Honour the OS "reduce motion" setting — charts settle instantly instead of tweening. */
export function prefersReducedMotion() {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

const easeOutCubic = (t) => 1 - (1 - t) ** 3;

const now = () =>
  (typeof performance !== 'undefined' && typeof performance.now === 'function'
    ? performance.now()
    : Date.now());

/**
 * Tween a numeric series towards `target`.
 *
 * The first run animates from zero (the "data loaded" sweep); every later change
 * animates from whatever is currently on screen to the new numbers, so a chart
 * morphs instead of snapping when the stream or date range changes.
 *
 * Only geometry is tweened — labels, legends and tooltips always read the real
 * data, so a mid-flight frame never shows a number that was not in the payload.
 *
 * @param {number[]} target
 * @param {number} [duration] milliseconds; 0 disables the animation
 * @returns {number[]} the current frame of the series
 */
export function useTweenedSeries(target, duration = CHART_ANIM_MS) {
  // A value signature keeps the effect from restarting on every parent render,
  // which a fresh array identity would otherwise do.
  const signature = target.join(',');
  const [values, setValues] = useState(() => target.map(() => 0));
  const currentRef = useRef(null);
  if (currentRef.current === null) currentRef.current = target.map(() => 0);

  useEffect(() => {
    const to = signature === '' ? [] : signature.split(',').map(Number);
    const from = to.map((_, i) => currentRef.current[i] ?? 0);

    const settle = () => {
      currentRef.current = to;
      setValues(to);
    };

    if (duration <= 0 || prefersReducedMotion() || typeof requestAnimationFrame !== 'function') {
      settle();
      return undefined;
    }

    const started = now();
    let frame = requestAnimationFrame(function step() {
      const t = Math.min(1, (now() - started) / duration);
      if (t >= 1) {
        settle();
        return;
      }
      const eased = easeOutCubic(t);
      const next = to.map((v, i) => from[i] + (v - from[i]) * eased);
      currentRef.current = next;
      setValues(next);
      frame = requestAnimationFrame(step);
    });

    return () => cancelAnimationFrame(frame);
  }, [signature, duration]);

  return values;
}
