import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/intelligenceService.js', () => ({
  getAttributionOverview: vi.fn(),
  listAttributionConversions: vi.fn(),
}));

import * as intel from '../../../services/intelligenceService.js';
import AttributionConversionsPage from '../AttributionConversionsPage';
import AttributionOverviewPage from '../AttributionOverviewPage';
import UnattributedLeadsPage from '../UnattributedLeadsPage';

const row = {
  id: 5, lead_id: 41, lead_name: 'Asha Rao', lead_email: 'asha@example.test', conversion_type: 'lead', attributed: true,
  first_touch_channel: 'social', first_utm_source: 'linkedin', last_touch_channel: 'search',
  matching_method: 'last_touch', confidence_state: 'inferred', converted_at: '2026-10-20T10:00:00Z',
};

beforeEach(() => {
  vi.clearAllMocks();
  intel.listAttributionConversions.mockResolvedValue({ items: [row, { ...row, id: 6, lead_id: null, attributed: false, first_touch_channel: null }], total: 2 });
  intel.getAttributionOverview.mockResolvedValue({
    stats: { total_conversions: 8, attributed: 5, unattributed: 3, attribution_rate: 62.5 },
    first_touch_breakdown: [{ channel: 'social', conversions: 2, pct: 50 }, { channel: 'Unknown', conversions: 1, pct: 25 }],
  });
});

describe('Attribution overview drills', () => {
  it('each tile and first-touch bar opens the conversions it counted', async () => {
    renderWithAuth(<AttributionOverviewPage />);
    expect(await screen.findByRole('link', { name: 'Total Conversions: 8' })).toHaveAttribute('href', '/intelligence/attribution/conversions');
    expect(screen.getByRole('link', { name: 'Attributed: 5' })).toHaveAttribute('href', '/intelligence/attribution/conversions?attributed=1');
    expect(screen.getByRole('link', { name: 'Unattributed: 3' })).toHaveAttribute('href', '/intelligence/attribution/unattributed');
    expect(screen.getByRole('link', { name: 'Attribution Rate: 62.5%' })).toHaveAttribute('href', '/intelligence/attribution/conversions?attributed=1');
    expect(screen.getByRole('link', { name: 'social: 2 conversions' })).toHaveAttribute('href', '/intelligence/attribution/conversions?attributed=1&channel=social');
    expect(screen.getByRole('link', { name: 'Unknown: 1 conversions' })).toHaveAttribute('href', '/intelligence/attribution/conversions?attributed=1&channel=Unknown');
  });

  it('a zero tile is not a link', async () => {
    intel.getAttributionOverview.mockResolvedValue({ stats: { total_conversions: 2, attributed: 0, unattributed: 2, attribution_rate: 0 }, first_touch_breakdown: [] });
    renderWithAuth(<AttributionOverviewPage />);
    expect(await screen.findByRole('link', { name: 'Total Conversions: 2' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /^Attributed: 0/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Attribution Rate/ })).not.toBeInTheDocument();
  });
});

describe('AttributionConversionsPage', () => {
  it('sends every URL filter to the API and shows the trail', async () => {
    renderWithAuth(<AttributionConversionsPage />, { route: '/intelligence/attribution/conversions?attributed=1&channel=social&model=last_touch&from=2026-10-01&to=2026-10-14' });
    await waitFor(() => expect(intel.listAttributionConversions).toHaveBeenCalledWith(expect.objectContaining({
      attributed: '1', channel: 'social', model: 'last_touch', from: '2026-10-01', to: '2026-10-14', page: 1,
    })));
    expect(await screen.findByRole('heading', { name: 'Attributed conversions' })).toBeInTheDocument();
    expect(screen.getByLabelText('Model')).toHaveValue('last_touch');
    expect(screen.getByRole('link', { name: /clear first touch filter/i }))
      .toHaveAttribute('href', '/intelligence/attribution/conversions?attributed=1&model=last_touch&from=2026-10-01&to=2026-10-14');
    expect(screen.getByText('2 conversions')).toBeInTheDocument();
  });

  it('rows open the lead and drill by their values', async () => {
    renderWithAuth(<AttributionConversionsPage />, { route: '/intelligence/attribution/conversions' });
    expect(await screen.findByRole('link', { name: '#41 · Asha Rao' })).toHaveAttribute('href', '/leads/41');
    expect(screen.getByText('No lead')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /^social/ })).toHaveAttribute('href', '/intelligence/attribution/conversions?channel=social');
    expect(screen.getAllByRole('link', { name: 'no' })[0]).toHaveAttribute('href', '/intelligence/attribution/conversions?attributed=0');
  });

  it('keeps a changed filter in the query', async () => {
    renderWithAuth(<AttributionConversionsPage />, { route: '/intelligence/attribution/conversions' });
    await waitFor(() => expect(intel.listAttributionConversions).toHaveBeenCalled());
    await userEvent.selectOptions(screen.getByLabelText('Attributed'), '0');
    await waitFor(() => expect(intel.listAttributionConversions).toHaveBeenLastCalledWith(expect.objectContaining({ attributed: '0' })));
    expect(await screen.findByRole('heading', { name: 'Unattributed conversions' })).toBeInTheDocument();
  });

  it('the unattributed page is the list with attributed=0, filtered on the server', async () => {
    renderWithAuth(
      <Routes>
        <Route path="/intelligence/attribution/unattributed" element={<UnattributedLeadsPage />} />
        <Route path="/intelligence/attribution/conversions" element={<AttributionConversionsPage />} />
      </Routes>,
      { route: '/intelligence/attribution/unattributed?model=manual' },
    );
    await waitFor(() => expect(intel.listAttributionConversions).toHaveBeenCalledWith(expect.objectContaining({ attributed: '0', model: 'manual' })));
    expect(await screen.findByRole('heading', { name: 'Unattributed conversions' })).toBeInTheDocument();
  });
});
