import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { drillHref, useDrillFilters, DrillPath } from '..';
import { DataTable } from '../../common/DataTable';
import { KPICard } from '../../charts/KPICard';
import { BarChart } from '../../charts/BarChart';

function Where() {
  return <output data-testid="where">{useLocation().search}</output>;
}

function Explorer() {
  const drill = useDrillFilters(['status', 'source']);
  return (
    <>
      <DrillPath drill={drill} rootLabel="All leads" labels={{ status: 'Status', source: 'Source' }} />
      <button type="button" onClick={() => drill.drill({ status: 'failed' })}>failed</button>
      <button type="button" onClick={() => drill.drill({ source: 'blog' })}>blog</button>
      <span data-testid="filters">{JSON.stringify(drill.filters)}</span>
      <Where />
    </>
  );
}

describe('drillHref', () => {
  it('adds params and drops empty ones', () => {
    expect(drillHref('/leads', { status: 'failed', source: '', page: null })).toBe('/leads?status=failed');
    expect(drillHref('/jobs?queue=a', { stalled: true })).toBe('/jobs?queue=a&stalled=1');
    expect(drillHref('/x')).toBe('/x');
  });
});

describe('useDrillFilters + DrillPath', () => {
  it('records steps in order, goes back up the trail and drops single steps', async () => {
    const user = userEvent.setup();
    render(<MemoryRouter initialEntries={['/leads']}><Explorer /></MemoryRouter>);

    await user.click(screen.getByRole('button', { name: 'failed' }));
    await user.click(screen.getByRole('button', { name: 'blog' }));
    expect(screen.getByTestId('where').textContent).toBe('?status=failed&source=blog');
    expect(screen.getByText('Source: blog')).toHaveAttribute('aria-current', 'location');

    await user.click(screen.getByRole('button', { name: 'Status: failed' }));
    expect(screen.getByTestId('where').textContent).toBe('?status=failed');

    await user.click(screen.getByRole('button', { name: 'Remove Status filter' }));
    expect(screen.getByTestId('where').textContent).toBe('');
    expect(screen.getByText('All leads')).toHaveAttribute('aria-current', 'location');
  });
});

describe('DataTable drill', () => {
  it('turns a column into links and makes clickable rows keyboard reachable', async () => {
    const onRowClick = vi.fn();
    const user = userEvent.setup();
    render(
      <MemoryRouter>
        <DataTable
          rows={[{ id: 1, source: 'blog', count: 3 }, { id: 2, source: 'ads', count: 0 }]}
          columns={[
            { key: 'source', label: 'Source' },
            { key: 'count', label: 'Leads', drill: (r) => (r.count ? `/leads?source=${r.source}` : null) },
          ]}
          onRowClick={onRowClick}
        />
      </MemoryRouter>,
    );

    expect(screen.getByRole('link', { name: '3' })).toHaveAttribute('href', '/leads?source=blog');
    expect(screen.queryByRole('link', { name: '0' })).toBeNull();

    const row = screen.getByText('ads').closest('tr');
    row.focus();
    await user.keyboard('{Enter}');
    expect(onRowClick).toHaveBeenCalledWith(expect.objectContaining({ id: 2 }));
  });
});

describe('KPICard and charts', () => {
  it('links a KPI card to its records', () => {
    render(<MemoryRouter><KPICard title="Failed pushes" value={4} to="/leads?status=failed" /></MemoryRouter>);
    expect(screen.getByRole('link')).toHaveAttribute('href', '/leads?status=failed');
    expect(screen.getByText(/view records/i)).toBeInTheDocument();
  });

  it('lets a bar be selected by click and keyboard', async () => {
    const onSelect = vi.fn();
    const user = userEvent.setup();
    render(<BarChart data={[{ label: 'Blog', value: 3 }]} onSelect={onSelect} />);
    const bar = screen.getByRole('button', { name: /blog: 3 — view records/i });
    await user.click(bar);
    bar.focus();
    await user.keyboard('{Enter}');
    expect(onSelect).toHaveBeenCalledTimes(2);
    expect(onSelect).toHaveBeenCalledWith({ label: 'Blog', value: 3 }, 0);
  });
});
