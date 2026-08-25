import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { LineChart } from '../LineChart';
import { PieChart } from '../PieChart';
import { BarChart } from '../BarChart';
import { CompletenessGauge } from '../../knowledge/CompletenessGauge';

const TREND = [
  { label: '01/08', value: 12 },
  { label: '02/08', value: 30 },
  { label: '03/08', value: 7 },
];

const SOURCES = [
  { label: 'Direct', value: 56 },
  { label: 'Unassigned', value: 2 },
];

const EVENTS = [
  { name: 'Contact form submits', open_count: 4 },
  { name: 'Blog CTA clicks', open_count: 11 },
];

/** The marks a pointer can land on, in document order. */
const hitTargets = (container) => [...container.querySelectorAll('.chart-hit')];

const withReducedMotion = (matches) => {
  window.matchMedia = () => ({
    matches,
    media: '(prefers-reduced-motion: reduce)',
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  });
};

afterEach(() => {
  withReducedMotion(false);
});

describe('LineChart', () => {
  it('falls back to a message below two points', () => {
    render(<LineChart data={[{ label: 'a', value: 1 }]} />);
    expect(screen.getByText('Not enough data')).toBeInTheDocument();
  });

  it('shows the label and value of the hovered step', () => {
    const { container } = render(<LineChart data={TREND} />);
    fireEvent.mouseMove(hitTargets(container)[1]);

    const tip = screen.getByRole('tooltip');
    expect(tip).toHaveTextContent('02/08');
    expect(tip).toHaveTextContent('30');
  });

  it('reads out every step, not only the one under a dot', () => {
    const { container } = render(<LineChart data={TREND} />);
    const bands = hitTargets(container);
    expect(bands).toHaveLength(TREND.length);

    fireEvent.mouseMove(bands[2]);
    expect(screen.getByRole('tooltip')).toHaveTextContent('03/08');
  });

  it('hides the readout when the pointer leaves the plot', () => {
    const { container } = render(<LineChart data={TREND} />);
    fireEvent.mouseMove(hitTargets(container)[0]);
    expect(screen.getByRole('tooltip')).toBeInTheDocument();

    fireEvent.mouseLeave(container.querySelector('svg'));
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });

  it('animates the line into place and settles on the real values', async () => {
    const { container } = render(<LineChart data={TREND} />);
    const peak = () => container.querySelectorAll('circle.chart-line__dot')[1];

    // The peak sits on the top gridline (y = padding) once the tween finishes.
    await waitFor(() => expect(peak()).toHaveAttribute('cy', '40'));
  });

  it('draws final geometry immediately when the OS asks for reduced motion', () => {
    withReducedMotion(true);
    const { container } = render(<LineChart data={TREND} />);
    expect(container.querySelectorAll('circle.chart-line__dot')[1]).toHaveAttribute('cy', '40');
  });
});

describe('PieChart', () => {
  it('shows value and share for the hovered slice', async () => {
    const { container } = render(<PieChart data={SOURCES} />);
    await waitFor(() => expect(container.querySelectorAll('path.chart-slice')).toHaveLength(2));

    fireEvent.mouseMove(container.querySelectorAll('path.chart-slice')[0]);
    const tip = screen.getByRole('tooltip');
    expect(tip).toHaveTextContent('Direct');
    expect(tip).toHaveTextContent('56');
    expect(tip).toHaveTextContent('96.6%');
  });

  it('shows the same readout from the legend row', () => {
    const { container } = render(<PieChart data={SOURCES} />);
    fireEvent.mouseMove(container.querySelectorAll('.chart-legend-item')[1]);

    const tip = screen.getByRole('tooltip');
    expect(tip).toHaveTextContent('Unassigned');
    expect(tip).toHaveTextContent('2');
  });

  it('renders a single 100% category as a full circle', async () => {
    const { container } = render(<PieChart data={[{ label: 'Direct', value: 9 }]} />);
    await waitFor(() => expect(container.querySelector('circle.chart-slice')).toBeInTheDocument());
  });

  it('reports no data when every category is zero', () => {
    render(<PieChart data={[{ label: 'Direct', value: 0 }]} />);
    expect(screen.getByText('No data')).toBeInTheDocument();
  });
});

describe('BarChart', () => {
  it('shows the counter for the hovered bar', () => {
    const { container } = render(<BarChart data={EVENTS} labelKey="name" valueKey="open_count" />);
    fireEvent.mouseMove(hitTargets(container)[1]);

    const tip = screen.getByRole('tooltip');
    expect(tip).toHaveTextContent('Blog CTA clicks');
    expect(tip).toHaveTextContent('11');
  });

  it('grows the bars to their final width', async () => {
    const { container } = render(<BarChart data={EVENTS} labelKey="name" valueKey="open_count" />);
    const widest = () => container.querySelectorAll('.chart-bar-fill')[1];

    await waitFor(() => expect(widest()).toHaveStyle({ width: '100%' }));
  });
});

describe('CompletenessGauge', () => {
  it('keeps showing the real percentage while the arc animates', () => {
    const { container } = render(<CompletenessGauge percent={72} />);
    expect(screen.getByText('72%')).toBeInTheDocument();
    expect(container.querySelector('svg')).toHaveAttribute('aria-label', 'Completeness: 72%');
  });

  it('shows the percentage in the shared readout on hover', () => {
    const { container } = render(<CompletenessGauge percent={72} />);
    fireEvent.mouseMove(container.querySelector('svg'));

    expect(screen.getByRole('tooltip')).toHaveTextContent('72%');
  });
});
