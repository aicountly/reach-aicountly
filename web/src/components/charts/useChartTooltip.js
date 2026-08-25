import { useCallback, useRef, useState } from 'react';

/**
 * State behind the one hover/focus readout shared by every chart in the portal.
 *
 * `showTooltip(event, { label, value, hint, color })` places the readout at the
 * pointer, or — when the event carries no pointer coordinates, i.e. keyboard
 * focus — at the centre of the mark that was focused. Charts pass values
 * straight from their data, never from the current animation frame.
 *
 * Pair with `<ChartFrame>`, which supplies the positioning context and renders
 * the readout.
 */
export function useChartTooltip() {
  const containerRef = useRef(null);
  const [tooltip, setTooltip] = useState(null);

  const showTooltip = useCallback((event, content) => {
    const container = containerRef.current;
    if (!container || !content) return;

    const bounds = container.getBoundingClientRect();
    const mark = event?.currentTarget?.getBoundingClientRect?.();
    const hasPointer = Number.isFinite(event?.clientX) && Number.isFinite(event?.clientY);

    let x;
    let y;
    if (hasPointer) {
      x = event.clientX - bounds.left;
      y = event.clientY - bounds.top;
    } else if (mark) {
      x = mark.left + mark.width / 2 - bounds.left;
      y = mark.top - bounds.top;
    } else {
      x = bounds.width / 2;
      y = 0;
    }

    // Keep the readout inside the chart frame: cards clip their overflow, so a
    // tooltip hanging past an edge would simply be cut in half.
    const EDGE = 72;

    setTooltip({
      ...content,
      align: x < EDGE ? 'start' : x > bounds.width - EDGE ? 'end' : 'center',
      // Near the top there is no room above the pointer, so flip underneath it.
      flip: y < 52,
      x: Math.max(0, Math.min(x, bounds.width)),
      y: Math.max(0, Math.min(y, bounds.height)),
    });
  }, []);

  const hideTooltip = useCallback(() => setTooltip(null), []);

  return { containerRef, tooltip, showTooltip, hideTooltip };
}
