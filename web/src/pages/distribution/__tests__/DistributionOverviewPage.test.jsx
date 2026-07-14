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
    api.get.mockResolvedValue({ data: { data: { total: 0 } } });
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
});
