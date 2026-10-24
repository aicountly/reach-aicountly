import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

const svc = vi.hoisted(() => ({
  getBacklinkSnapshot: vi.fn(),
  listIndexNowSubmissions: vi.fn(),
  getIndexNowSubmission: vi.fn(),
  listBlogIndexing: vi.fn(),
  listSitemapEntries: vi.fn(),
}));
vi.mock('../../../services/seoService', () => ({ seoService: svc }));
vi.mock('../../../services/api', () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../../services/intelligenceService.js', () => ({
  submitIndexNowUrl: vi.fn(), retryIndexNowPending: vi.fn(), getLatestSitemap: vi.fn(), generateSitemapSnapshot: vi.fn(),
}));

import { api } from '../../../services/api';
import * as intel from '../../../services/intelligenceService.js';
import { SeoOverviewPage } from '../SeoOverviewPage';
import { BacklinksPage } from '../BacklinksPage';
import { BacklinkSnapshotPage } from '../BacklinkSnapshotPage';
import { BlogIndexingPage } from '../BlogIndexingPage';
import { IndexNowSubmissionsPage } from '../IndexNowSubmissionsPage';
import { IndexNowSubmissionDetailPage } from '../IndexNowSubmissionDetailPage';
import { SitemapEntriesPage } from '../SitemapEntriesPage';
import IndexNowOperationsPage from '../../intelligence/IndexNowOperationsPage';
import SitemapOverviewPage from '../../intelligence/SitemapOverviewPage';

const page = (rows, total = rows.length) => ({ rows, total, page: 1, per_page: 50, filters: {} });

beforeEach(() => {
  vi.clearAllMocks();
});

describe('SEO overview counts open their records', () => {
  it('links indexing, IndexNow, backlink and visibility figures; zero stays text', async () => {
    api.get.mockResolvedValue({
      search_28d: {}, connections: { search_console: true },
      visibility_28d: { runs: 6 },
      indexing_status: [{ indexing_status: 'indexed', c: 4 }, { indexing_status: 'not_submitted', c: 0 }],
      indexnow_status: [{ status: 'failed', c: 2 }],
      backlinks: { id: 9, provider: 'internal_signals', internal_links: 12, published_urls: 0, total_backlinks: null },
    });
    renderWithAuth(<SeoOverviewPage />);
    expect(await screen.findByRole('link', { name: 'indexed: 4 blog posts' })).toHaveAttribute('href', '/seo-centre/indexing?status=indexed');
    expect(screen.queryByRole('link', { name: /not submitted: 0/ })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'failed: 2 IndexNow submissions' })).toHaveAttribute('href', '/seo-centre/indexnow/submissions?status=failed');
    expect(screen.getByRole('link', { name: 'Internal links: 12' })).toHaveAttribute('href', '/seo-centre/backlinks/9?kind=internal_links');
    expect(screen.queryByRole('link', { name: 'Published URLs: 0' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /AI visibility runs \(28d\): 6/ })).toHaveAttribute('href', '/intelligence/visibility/runs?days=28');
  });

  it('backlink snapshot rows open the snapshot with the counted records', async () => {
    api.get.mockResolvedValue({ snapshots: [{ id: 3, snapshot_date: '2026-10-20', provider: 'internal_signals', internal_links: 5, published_urls: 2 }] });
    renderWithAuth(<BacklinksPage />);
    expect(await screen.findByRole('link', { name: '5' })).toHaveAttribute('href', '/seo-centre/backlinks/3?kind=internal_links');
    expect(screen.getByRole('link', { name: '2' })).toHaveAttribute('href', '/seo-centre/backlinks/3?kind=published_urls');
  });
});

describe('backlink snapshot detail', () => {
  it('shows details_json lists as rows and the records behind the count', async () => {
    svc.getBacklinkSnapshot.mockResolvedValue({
      snapshot: { id: 3, snapshot_date: '2026-10-20', provider: 'dataforseo', internal_links: 1, published_urls: 1, total_backlinks: 7, referring_domains: 1 },
      detail_facts: { note: 'from provider' },
      detail_lists: [{ key: 'referring_domains', rows: [{ domain: 'example.org', backlinks: 7 }] }],
      current_count: { internal_links: 1, published_urls: 1 },
      kind: 'published_urls',
      records: page([{ profile_id: 1, content_item_id: 44, title: 'GST guide', workflow_status: 'live', indexing_status: 'indexed' }]),
    });
    renderWithAuth(
      <Routes><Route path="/seo-centre/backlinks/:id" element={<BacklinkSnapshotPage />} /></Routes>,
      { route: '/seo-centre/backlinks/3?kind=published_urls' },
    );
    expect(await screen.findByText('example.org')).toBeInTheDocument();
    expect(screen.getByText('from provider')).toBeInTheDocument();
    expect(svc.getBacklinkSnapshot).toHaveBeenCalledWith('3', expect.objectContaining({ kind: 'published_urls', page: 1 }));
    expect(screen.getByRole('link', { name: 'GST guide' })).toHaveAttribute('href', '/content/44');
    expect(screen.getByRole('link', { name: 'indexed' })).toHaveAttribute('href', '/seo-centre/indexing?status=indexed');
    fireEvent.click(screen.getByRole('button', { name: 'Internal links: 1' }));
    await waitFor(() => expect(svc.getBacklinkSnapshot).toHaveBeenLastCalledWith('3', expect.objectContaining({ kind: 'internal_links' })));
  });
});

describe('blog indexing list', () => {
  it('applies ?status= and each post opens its content', async () => {
    svc.listBlogIndexing.mockResolvedValue({
      posts: [{ profile_id: 1, content_item_id: 7, title: 'E-invoicing', indexing_status: 'indexed', public_url: 'https://aicountly.com/blog/e' }],
      counts: { indexed: 1, not_submitted: 3 }, meta: { total: 1 },
    });
    renderWithAuth(<BlogIndexingPage />, { route: '/seo-centre/indexing?status=indexed' });
    expect(await screen.findByRole('link', { name: 'E-invoicing' })).toHaveAttribute('href', '/content/7');
    expect(svc.listBlogIndexing).toHaveBeenCalledWith(expect.objectContaining({ status: 'indexed', page: 1 }));
    fireEvent.click(screen.getByRole('button', { name: /clear status filter/i }));
    await waitFor(() => expect(svc.listBlogIndexing).toHaveBeenLastCalledWith(expect.objectContaining({ status: '' })));
  });
});

describe('IndexNow', () => {
  it('operations tiles show stored counts and open the filtered submissions', async () => {
    svc.listIndexNowSubmissions.mockResolvedValue({
      submissions: [{ id: 5, url: 'https://aicountly.com/a', status: 'failed', attempt_count: 3 }],
      counts: { total: 6, submitted: 3, retrying: 1, pending: 1, failed: 1, cancelled: 0 },
    });
    renderWithAuth(<IndexNowOperationsPage />);
    expect(await screen.findByRole('link', { name: 'Pending / retrying: 2' })).toHaveAttribute('href', '/seo-centre/indexnow/submissions?status=retrying%2Cpending');
    expect(screen.getByRole('link', { name: 'Failed: 1' })).toHaveAttribute('href', '/seo-centre/indexnow/submissions?status=failed');
    expect(screen.getByRole('link', { name: 'All submissions: 6' })).toHaveAttribute('href', '/seo-centre/indexnow/submissions');
    expect(intel.submitIndexNowUrl).not.toHaveBeenCalled();
  });

  it('the submissions list applies status + url and a row opens its attempts', async () => {
    svc.listIndexNowSubmissions.mockResolvedValue({
      submissions: [{ id: 5, url: 'https://aicountly.com/a', status: 'failed', attempt_count: 3, max_attempts: 3, last_error: 'HTTP 429', created_at: '2026-10-20T00:00:00Z' }],
      counts: { total: 1, failed: 1 }, meta: { total: 1 },
    });
    svc.getIndexNowSubmission.mockResolvedValue({
      id: 5, url: 'https://aicountly.com/a', status: 'failed', attempt_count: 1, max_attempts: 3, provider_endpoint: 'https://api.indexnow.org',
      content: { id: 2, content_item_id: 44 },
      attempts: [{ id: 1, attempt_number: 1, http_status: 429, error_message: 'Too many requests', succeeded: false, attempted_at: '2026-10-20T00:00:00Z' }],
    });
    renderWithAuth(
      <Routes>
        <Route path="/seo-centre/indexnow/submissions" element={<IndexNowSubmissionsPage />} />
        <Route path="/seo-centre/indexnow/submissions/:id" element={<IndexNowSubmissionDetailPage />} />
      </Routes>,
      { route: '/seo-centre/indexnow/submissions?status=failed&url=%2Fa' },
    );
    fireEvent.click(await screen.findByRole('row', { name: /Open submission https:\/\/aicountly.com\/a/ }));
    expect(svc.listIndexNowSubmissions).toHaveBeenCalledWith(expect.objectContaining({ status: 'failed', url: '/a' }));
    expect(await screen.findByText('Too many requests')).toBeInTheDocument();
    expect(svc.getIndexNowSubmission).toHaveBeenCalledWith('5');
    expect(screen.getByRole('link', { name: 'Open content ›' })).toHaveAttribute('href', '/content/44');
  });
});

describe('sitemap', () => {
  it('snapshot counts open the entries with the same predicate', async () => {
    intel.getLatestSitemap.mockResolvedValue({ id: 8, status: 'generated', total_entries: 5, included_entries: 3, excluded_noindex: 1, excluded_withdrawn: 0 });
    renderWithAuth(<SitemapOverviewPage />);
    expect(await screen.findByRole('link', { name: 'Included: 3' })).toHaveAttribute('href', '/seo-centre/sitemap/8/entries?state=included');
    expect(screen.getByRole('link', { name: 'Excluded (noindex): 1' })).toHaveAttribute('href', '/seo-centre/sitemap/8/entries?reason=noindex');
    expect(screen.getByRole('link', { name: 'Total Entries: 5' })).toHaveAttribute('href', '/seo-centre/sitemap/8/entries');
    expect(screen.queryByRole('link', { name: /withdrawn/ })).not.toBeInTheDocument();
  });

  it('entries apply ?reason= and link to the public URL and the content', async () => {
    svc.listSitemapEntries.mockResolvedValue({
      data: [{ id: 1, url: 'https://aicountly.com/blog/x', included: false, exclusion_reason: 'noindex', content_item_id: 12, content_identity_id: 3 }],
      meta: { total: 1, snapshot: { id: 8, total_entries: 5, generated_at: '2026-10-20T00:00:00Z' }, facets: { all: 4, included: 3, excluded: 1, noindex: 1 }, unlisted: 1 },
    });
    renderWithAuth(
      <Routes><Route path="/seo-centre/sitemap/:snapshotId/entries" element={<SitemapEntriesPage />} /></Routes>,
      { route: '/seo-centre/sitemap/8/entries?reason=noindex' },
    );
    expect(await screen.findByRole('link', { name: 'https://aicountly.com/blog/x' })).toHaveAttribute('href', 'https://aicountly.com/blog/x');
    expect(svc.listSitemapEntries).toHaveBeenCalledWith('8', expect.objectContaining({ reason: 'noindex', page: 1 }));
    expect(screen.getByRole('link', { name: 'Open content ›' })).toHaveAttribute('href', '/content/12');
    expect(screen.getByText(/1 had no canonical URL/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Included: 3' }));
    await waitFor(() => expect(svc.listSitemapEntries).toHaveBeenLastCalledWith('8', expect.objectContaining({ state: 'included', reason: '' })));
  });
});
