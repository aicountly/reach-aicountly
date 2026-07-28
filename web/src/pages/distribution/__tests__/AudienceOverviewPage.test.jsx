import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import AudienceOverviewPage from '../AudienceOverviewPage';
import api from '../../../services/api';

vi.mock('../../../services/api', () => ({ default: { get: vi.fn() } }));

function renderPage() {
  return render(
    <MemoryRouter>
      <AudienceOverviewPage />
    </MemoryRouter>
  );
}

describe('AudienceOverviewPage', () => {
  beforeEach(() => {
    api.get.mockImplementation((path) => {
      if (path === 'v1/distribution/segments') {
        return Promise.resolve({ data: [{ id: 1 }, { id: 2 }] });
      }
      if (path === 'v1/distribution/suppressions') {
        return Promise.resolve({ data: [], total: 4 });
      }
      if (path === 'v1/distribution/consents') {
        return Promise.resolve({ data: [], total: 7 });
      }
      return Promise.resolve(null);
    });
  });

  it('renders the page title and section cards', async () => {
    renderPage();
    expect(screen.getByText('Audience Management')).toBeTruthy();
    expect(screen.getByText('Audience Segments')).toBeTruthy();
    expect(screen.getByText('Suppression List')).toBeTruthy();
    expect(screen.getByText('Channel Consents')).toBeTruthy();
  });

  it('loads counts from v1 distribution endpoints', async () => {
    renderPage();
    await waitFor(() => {
      expect(screen.getByText('2')).toBeTruthy();
      expect(screen.getByText('4')).toBeTruthy();
      expect(screen.getByText('7')).toBeTruthy();
    });
    expect(api.get).toHaveBeenCalledWith('v1/distribution/segments');
    expect(api.get).toHaveBeenCalledWith('v1/distribution/suppressions', { page: 1, per_page: 1 });
    expect(api.get).toHaveBeenCalledWith('v1/distribution/consents', { page: 1, per_page: 1 });
  });

  it('links View Segments to the audience segments route', () => {
    renderPage();
    const link = screen.getByText('View Segments').closest('a');
    expect(link?.getAttribute('href')).toBe('/distribution/audience/segments');
  });
});
