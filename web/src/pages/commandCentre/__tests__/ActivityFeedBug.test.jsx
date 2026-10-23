import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ActivityFeed } from '../Sections';

// The live API sends TIMESTAMPTZ values the way PostgreSQL prints them.
const pg = (msAgo) => new Date(Date.now() - msAgo).toISOString().replace('T', ' ').replace(/\.\d+Z$/, '+00');

it('shows events whose timestamps carry PostgreSQL\'s "+00" offset', () => {
  const section = {
    status: 'ok', built_at: new Date().toISOString(),
    counters: { blog_published: { label: 'Blog published', h24: 1, d7: 1, d30: 1 } },
    events: [{ kind: 'blog_published', label: 'Blog published', title: 'GST e-invoicing explained', at: pg(3600e3), link: '/content/7', external: false, tone: 'positive' }],
  };
  render(<MemoryRouter><ActivityFeed section={section} /></MemoryRouter>);
  expect(screen.queryByText('Nothing in this window.')).not.toBeInTheDocument();
  expect(screen.getByText('GST e-invoicing explained')).toBeInTheDocument();
});
