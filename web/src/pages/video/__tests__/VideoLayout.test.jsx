import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import VideoLayout from '../VideoLayout';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'admin' },
    permissions: ['*'],
  },
};

describe('VideoLayout', () => {
  it('renders Video Automation chrome and horizontal sub-nav', () => {
    renderWithAuth(<VideoLayout />, ctx);
    expect(screen.getByText('Video Automation')).toBeInTheDocument();
    expect(document.querySelector('.page-layout--flush')).toBeTruthy();
    expect(document.querySelector('.sub-nav')).toBeTruthy();
    expect(screen.getByText('Projects')).toBeInTheDocument();
    expect(screen.getByText('YT Connections')).toBeInTheDocument();
  });
});
