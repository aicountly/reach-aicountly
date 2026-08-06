import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/contentBaseService', () => ({
  contentBaseService: { overview: vi.fn(), blog: vi.fn() },
}));

import { contentBaseService } from '../../../services/contentBaseService';
import { ContentBasePage } from '../ContentBasePage';

const overview = {
  base_path: '/home/app/api/content-base',
  base_markdown: '# Blog strategy base',
  last_sync: {
    ran_at: '2026-08-06T11:19:00+00:00',
    created_count: 0, updated_count: 1, retired_count: 0, entries_seen: 8,
  },
  surfaces: {
    blog: {
      label: 'Blog',
      source_file: 'blog/index.json',
      meta: { version: 1, updated_at: '2026-08-05' },
      entries: [{
        key: 'gst-itc-mismatch-2b-resolution',
        title: 'GSTR-2B vs purchase register mismatch',
        status: 'planned', target_date: '2026-08-06', stream: 'marketing',
        prompt: 'Walk through the reconciliation.',
        sync: { state: 'queued', candidate_status: 'pinned' },
      }],
      counts: { total: 1, produced: 0, queued: 1, pending_sync: 0, retired: 0 },
    },
    knowledge_base: {
      label: 'Knowledge base',
      source_file: 'knowledge-base/index.json',
      meta: { updated_at: '2026-08-05', notes: 'Claude-only route.' },
      products: [{
        product_slug: 'books', tier: 'big', daily_quota: 4, enabled: true,
        topics: [{
          key: 'kb-books-getting-started', title: 'Getting started with Books',
          status: 'planned', target_date: '', stream: 'books', prompt: 'Explain setup.',
          sync: { state: 'produced', content_item_id: 12, workflow_status: 'draft' },
        }],
        counts: { total: 1, produced: 1, queued: 0, pending_sync: 0, retired: 0 },
      }],
      entries: [{ key: 'kb-books-getting-started', sync: { state: 'produced' } }],
      counts: { total: 1, produced: 1, queued: 0, pending_sync: 0, retired: 0 },
    },
    community: {
      label: 'Community Q&A',
      source_file: 'community/question-seeds.json',
      meta: { updated_at: '2026-08-05' },
      entries: [{
        key: 'q-gst-late-fee-waiver', title: 'Is the GST late fee waiver available?',
        status: 'planned', target_date: '', stream: 'gst', prompt: 'Context here.',
        sync: { state: 'pending_sync' },
      }],
      counts: { total: 1, produced: 0, queued: 0, pending_sync: 1, retired: 0 },
    },
  },
  totals: { entries: 3, pending: 2, done: 1 },
};

beforeEach(() => {
  vi.clearAllMocks();
  contentBaseService.overview.mockResolvedValue(overview);
});

describe('Quality Centre content base', () => {
  it('shows all three surfaces, not just the blog', async () => {
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByRole('tab', { name: /Blog \(1\)/ })).toBeInTheDocument());
    expect(screen.getByRole('tab', { name: /Knowledge base \(1\)/ })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /Community Q&A \(1\)/ })).toBeInTheDocument();
    expect(screen.getByText(/3 entries, 1 produced, 2 outstanding/)).toBeInTheDocument();
  });

  it('opens on the blog surface with its sync state', async () => {
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByText('GSTR-2B vs purchase register mismatch')).toBeInTheDocument());
    expect(screen.getByText('Queued')).toBeInTheDocument();
    // dd-mm-yyyy, per the house date format.
    expect(screen.getByText('06-08-2026')).toBeInTheDocument();
  });

  it('groups knowledge base topics by product and shows the cadence', async () => {
    const user = userEvent.setup();
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByRole('tab', { name: /Knowledge base/ })).toBeInTheDocument());
    await user.click(screen.getByRole('tab', { name: /Knowledge base/ }));

    // 'books' is both the product heading and the topic's stream cell.
    expect(await screen.findByRole('heading', { name: 'books' })).toBeInTheDocument();
    expect(screen.getByText(/big tier · 4\/day/)).toBeInTheDocument();
    expect(screen.getByText('Getting started with Books')).toBeInTheDocument();
    expect(screen.getByText('Produced')).toBeInTheDocument();
  });

  it('shows community question seeds', async () => {
    const user = userEvent.setup();
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByRole('tab', { name: /Community/ })).toBeInTheDocument());
    await user.click(screen.getByRole('tab', { name: /Community/ }));

    expect(await screen.findByText('Is the GST late fee waiver available?')).toBeInTheDocument();
    expect(screen.getByText('Pending sync')).toBeInTheDocument();
  });

  it('states that the base is git-owned and read-only', async () => {
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByText(/read-only here/)).toBeInTheDocument());
    expect(screen.getByText('/home/app/api/content-base')).toBeInTheDocument();
  });

  it('warns when the base has never been synced', async () => {
    contentBaseService.overview.mockResolvedValue({ ...overview, last_sync: null });
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByText(/Never synced/)).toBeInTheDocument());
  });
});
