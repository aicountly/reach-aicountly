import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import SmsOverviewPage from '../SmsOverviewPage';

describe('SmsOverviewPage', () => {
  it('renders the page title and stacked subtitle', () => {
    render(<MemoryRouter><SmsOverviewPage /></MemoryRouter>);
    expect(screen.getByText('SMS Channel')).toBeTruthy();
    expect(screen.getByText(/DLT compliance and suppression controls/i)).toBeTruthy();
    expect(document.querySelector('.page-header--stack')).toBeTruthy();
  });

  it('renders the three SMS action cards', () => {
    render(<MemoryRouter><SmsOverviewPage /></MemoryRouter>);
    expect(screen.getByText('SMS Dispatch')).toBeTruthy();
    expect(screen.getByText('Suppression List')).toBeTruthy();
    expect(screen.getByText('DLT Compliance')).toBeTruthy();
    expect(screen.getByText('Open Dispatch').closest('a')).toHaveAttribute(
      'href',
      '/distribution/sms/dispatch',
    );
  });

  it('mentions DLT compliance', () => {
    render(<MemoryRouter><SmsOverviewPage /></MemoryRouter>);
    expect(screen.getAllByText(/DLT/i).length).toBeGreaterThan(0);
  });
});
