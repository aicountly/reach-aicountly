import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/contentBaseService', () => ({
  contentBaseService: { overview: vi.fn(), blog: vi.fn() },
}));

import { contentBaseService } from '../../../services/contentBaseService';
import { ContentBasePage } from '../ContentBasePage';

const health = (routine, hours, state = 'ok') => ({
  routine, cadence: 'daily', enabled: state !== 'off', last_activity_at: hours === null ? null : '2026-10-18T09:00:00+00:00',
  hours_since: hours, threshold_hours: 36, stale: state === 'stale' || state === 'never_seen', state,
});

const overview = {
  available: true,
  base_path: '/home/app/api/content-base',
  base_markdown: '# Blog strategy base',
  last_sync: {
    file_key: 'seed_blog', ran_at: '2026-10-18T11:19:00+00:00',
    created_count: 1, updated_count: 0, retired_count: 0, entries_seen: 9,
  },
  stale: ['No knowledge base routine activity has ever reached Reach (reach-kb) — check that the routine exists in claude.ai → Routines.'],
  routines: {},
  surfaces: {
    blog: {
      label: 'Blog',
      routine: health('reach-blog', 3),
      activity: { last_proposal_at: '2026-10-18T08:00:00+00:00' },
      entries: [{
        key: 'gst-itc-mismatch-2b-resolution',
        title: 'GSTR-2B vs purchase register mismatch',
        status: 'published', origin: 'seed', target_date: '2026-08-06', stream: 'marketing',
        prompt: 'Walk through the reconciliation.', status_reason: null,
      }, {
        key: 'blog-20261018-gstr-9-checklist',
        title: 'GSTR-9 annual return checklist',
        status: 'planned', origin: 'routine', target_date: '', stream: 'marketing',
        prompt: 'Annual return walkthrough.', status_reason: null,
      }],
      counts: { total: 2, planned: 1, queued: 0, produced: 0, published: 1, retired: 0 },
    },
    knowledge_base: {
      label: 'Knowledge base',
      routine: health('reach-kb', null, 'never_seen'),
      activity: {},
      products: [{
        product_slug: 'books', tier: 'big', daily_quota: 4, enabled: true,
        topics: [{
          key: 'kb-books-getting-started', title: 'Getting started with Books',
          status: 'queued', origin: 'seed', target_date: '', stream: 'how_to', prompt: '',
          status_reason: 'Submitted; Reach is vetting it.',
        }],
      }, {
        product_slug: 'buddy', tier: 'small', daily_quota: 0, enabled: false, topics: [],
      }],
      entries: [{ key: 'kb-books-getting-started', status: 'queued' }],
      counts: { total: 1, planned: 0, queued: 1, produced: 0, published: 0, retired: 0 },
    },
    community: {
      label: 'Community Q&A',
      routine: health('reach-community-qa', 2),
      activity: {},
      entries: [{
        key: 'q-gst-late-fee-waiver', title: 'Is the GST late fee waiver available?',
        status: 'retired', origin: 'seed', target_date: '', stream: 'gst', prompt: 'Context here.',
        status_reason: 'Routine: the waiver scheme ended.',
      }],
      counts: { total: 1, planned: 0, queued: 0, produced: 0, published: 0, retired: 1 },
    },
  },
  totals: { entries: 4, published: 1, in_progress: 1, planned: 1 },
};

beforeEach(() => {
  vi.clearAllMocks();
  contentBaseService.overview.mockResolvedValue(overview);
});

describe('Quality Centre content base', () => {
  it('shows all three surfaces and the living totals', async () => {
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByRole('tab', { name: /Blog \(2\)/ })).toBeInTheDocument());
    expect(screen.getByRole('tab', { name: /Knowledge base \(1\)/ })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /Community Q&A \(1\)/ })).toBeInTheDocument();
    expect(screen.getByText(/4 topics: 1 published, 1 in progress, 1 planned/)).toBeInTheDocument();
  });

  it('says the routines update it through the API, not git', async () => {
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByText(/Updated by the routines daily through the API/)).toBeInTheDocument());
    expect(screen.queryByText(/read-only here/)).not.toBeInTheDocument();
    expect(screen.getByText('/home/app/api/content-base')).toBeInTheDocument();
  });

  it('shows statuses, origin and the routine activity on the blog surface', async () => {
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByText('GSTR-2B vs purchase register mismatch')).toBeInTheDocument());
    expect(screen.getByText('Published')).toBeInTheDocument();
    expect(screen.getByText('Planned')).toBeInTheDocument();
    expect(screen.getByText('Routine')).toBeInTheDocument();
    expect(screen.getByText('06-08-2026')).toBeInTheDocument();
    expect(screen.getByTestId('routine-line')).toHaveTextContent(/reach-blog.*3 h ago/);
  });

  it('flags quiet routines as information', async () => {
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByText(/Quiet routines/)).toBeInTheDocument());
    expect(screen.getByText(/reach-kb\) — check that the routine exists/)).toBeInTheDocument();
  });

  it('groups knowledge base topics by product with cadence and the never-seen routine', async () => {
    const user = userEvent.setup();
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByRole('tab', { name: /Knowledge base/ })).toBeInTheDocument());
    await user.click(screen.getByRole('tab', { name: /Knowledge base/ }));

    expect(await screen.findByRole('heading', { name: 'books' })).toBeInTheDocument();
    expect(screen.getByText(/big tier · 4\/day/)).toBeInTheDocument();
    expect(screen.getByText(/no enabled KB cadence/)).toBeInTheDocument();
    expect(screen.getByText('Queued')).toBeInTheDocument();
    expect(screen.getByTestId('routine-line')).toHaveTextContent(/reach-kb.*never.*quiet/);
  });

  it('shows community seeds with the retirement reason', async () => {
    const user = userEvent.setup();
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByRole('tab', { name: /Community/ })).toBeInTheDocument());
    await user.click(screen.getByRole('tab', { name: /Community/ }));

    expect(await screen.findByText('Is the GST late fee waiver available?')).toBeInTheDocument();
    expect(screen.getByText('Retired')).toBeInTheDocument();
    expect(screen.getByText('Routine: the waiver scheme ended.')).toBeInTheDocument();
  });

  it('says when seeds were never imported', async () => {
    contentBaseService.overview.mockResolvedValue({ ...overview, last_sync: null });
    renderWithAuth(<ContentBasePage />);

    await waitFor(() => expect(screen.getByText(/Seeds not imported yet/)).toBeInTheDocument());
  });
});
