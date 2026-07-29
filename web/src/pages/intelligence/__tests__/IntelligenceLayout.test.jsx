import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import IntelligenceLayout from '../IntelligenceLayout';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['intelligence.read', 'sitemap.submit'],
  },
};

describe('IntelligenceLayout', () => {
  it('renders Intelligence title and IndexNow in horizontal sub-nav', () => {
    renderWithAuth(<IntelligenceLayout />, ctx);
    expect(screen.getByText('Intelligence')).toBeInTheDocument();
    expect(screen.getByText('IndexNow')).toBeInTheDocument();
    expect(screen.getByText('Search')).toBeInTheDocument();
  });

  it('uses page-layout with horizontal sub-nav (no nested section aside)', () => {
    renderWithAuth(<IntelligenceLayout />, ctx);
    expect(document.querySelector('.page-layout')).toBeTruthy();
    expect(document.querySelector('.page-layout--flush')).toBeTruthy();
    expect(document.querySelector('.sub-nav')).toBeTruthy();
    expect(document.querySelector('.page-layout__body')).toBeTruthy();
    expect(document.querySelectorAll('.sub-nav__link').length).toBeGreaterThan(0);
    // Old nested section labels must not appear as sidebar headings
    expect(screen.queryByText('SITEMAPS & INDEX')).not.toBeInTheDocument();
    expect(screen.queryByText('Sitemaps & Index')).not.toBeInTheDocument();
  });

  it('links IndexNow to /intelligence/indexnow', () => {
    renderWithAuth(<IntelligenceLayout />, ctx);
    expect(screen.getByText('IndexNow').closest('a')).toHaveAttribute('href', '/intelligence/indexnow');
  });
});
