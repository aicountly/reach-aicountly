import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import PublishingLayout from '../PublishingLayout';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['*'],
  },
};

describe('PublishingLayout navigation', () => {
  it('has Blogs link', () => {
    renderWithAuth(<PublishingLayout />, ctx);
    const link = screen.getByText('Blogs');
    expect(link.closest('a')).toHaveAttribute('href', '/publishing/blogs');
  });

  it('has Knowledge Base link', () => {
    renderWithAuth(<PublishingLayout />, ctx);
    const link = screen.getByText('Knowledge Base');
    expect(link.closest('a')).toHaveAttribute('href', '/publishing/knowledge-bases');
  });

  it('has Calendar link', () => {
    renderWithAuth(<PublishingLayout />, ctx);
    const link = screen.getByText('Calendar');
    expect(link.closest('a')).toHaveAttribute('href', '/publishing/calendar');
  });

  it('has Deployments link', () => {
    renderWithAuth(<PublishingLayout />, ctx);
    const link = screen.getByText('Deployments');
    expect(link.closest('a')).toHaveAttribute('href', '/publishing/deployments');
  });

  it('has Verifications link', () => {
    renderWithAuth(<PublishingLayout />, ctx);
    const link = screen.getByText('Verifications');
    expect(link.closest('a')).toHaveAttribute('href', '/publishing/verifications');
  });

  it('has Connections link', () => {
    renderWithAuth(<PublishingLayout />, ctx);
    const link = screen.getByText('Connections');
    expect(link.closest('a')).toHaveAttribute('href', '/publishing/connections');
  });

  it('has Readiness link', () => {
    renderWithAuth(<PublishingLayout />, ctx);
    const link = screen.getByText('Readiness');
    expect(link.closest('a')).toHaveAttribute('href', '/publishing/readiness');
  });

  it('all nav links have sub-nav__link class', () => {
    renderWithAuth(<PublishingLayout />, ctx);
    const links = document.querySelectorAll('.sub-nav__link');
    expect(links.length).toBeGreaterThanOrEqual(7);
  });

  it('has page-layout__body container for content', () => {
    renderWithAuth(<PublishingLayout />, ctx);
    expect(document.querySelector('.page-layout__body')).toBeTruthy();
  });
});
