import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import SmsOverviewPage from '../SmsOverviewPage';

describe('SmsOverviewPage', () => {
  it('renders the page title', () => {
    render(<MemoryRouter><SmsOverviewPage /></MemoryRouter>);
    expect(screen.getByText('SMS Channel')).toBeTruthy();
  });

  it('mentions DLT compliance', () => {
    render(<MemoryRouter><SmsOverviewPage /></MemoryRouter>);
    expect(screen.getAllByText(/DLT/i).length).toBeGreaterThan(0);
  });
});
