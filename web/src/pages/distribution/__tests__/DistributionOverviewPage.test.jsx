import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import DistributionOverviewPage from '../DistributionOverviewPage';
import api from '../../../services/api';

vi.mock('../../../services/api', () => ({ default: { get: vi.fn() } }));

function renderPage() {
  return render(
    <MemoryRouter>
      <DistributionOverviewPage />
    </MemoryRouter>
  );
}

describe('DistributionOverviewPage', () => {
  beforeEach(() => {
    api.get.mockResolvedValue({ total: 0, data: [] });
  });

  it('renders the page title', async () => {
    renderPage();
    expect(screen.getByText('Distribution Hub')).toBeTruthy();
  });

  it('renders all channel section cards', async () => {
    renderPage();
    expect(screen.getByText('Social Dispatch')).toBeTruthy();
    expect(screen.getByText('Email Dispatch')).toBeTruthy();
    expect(screen.getByText('WhatsApp')).toBeTruthy();
    expect(screen.getByText('SMS')).toBeTruthy();
  });

  it('requests campaigns and dispatches under v1/', async () => {
    renderPage();
    expect(api.get).toHaveBeenCalledWith('v1/campaigns', { per_page: 1, limit: 1 });
    expect(api.get).toHaveBeenCalledWith('v1/distribution/dispatches', { per_page: 1 });
  });
});
