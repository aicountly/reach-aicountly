import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/mediaGalleryService', () => ({
  mediaGalleryService: { list: vi.fn(), deficit: vi.fn(), upload: vi.fn(), update: vi.fn() },
}));

import { mediaGalleryService } from '../../../services/mediaGalleryService';
import { MediaGalleryPage } from '../MediaGalleryPage';

const asset = (over = {}) => ({
  id: 1,
  status: 'active',
  kind: 'gallery_upload',
  category_tags: '["gst","accounting"]',
  portfolio_stream: 'marketing',
  times_used: 0,
  last_used_at: null,
  prompt_used: 'A very long generated prompt that is not alt text.',
  public_url: 'https://reach.aicountly.org/api/v1/public/media/abc.webp?sig=deadbeef',
  file_missing: false,
  ...over,
});

const deficit = {
  deficit: 2, needed: 3, available: 1, untagged: 0, lookahead_days: 14,
  upcoming: [{ key: 'gst-itc', title: 'GSTR-2B mismatch', target_date: '2026-08-06', cover_prompt: 'Two document stacks' }],
};

beforeEach(() => {
  vi.clearAllMocks();
  mediaGalleryService.list.mockResolvedValue({
    assets: [asset()],
    signing_key_configured: true,
    files_missing: 0,
    storage_path: '/home/reachaicountly/cover_images/',
    storage_writable: true,
    storage_outside_deploy: true,
  });
  mediaGalleryService.deficit.mockResolvedValue(deficit);
});

describe('Cover gallery', () => {
  it('separates deficit, upload and gallery into tabs', async () => {
    const user = userEvent.setup();
    renderWithAuth(<MediaGalleryPage />);

    await waitFor(() => expect(screen.getByRole('tab', { name: /Deficit \(2\)/ })).toBeInTheDocument());

    // Deficit is the landing tab; the other two are not rendered yet.
    expect(screen.getByText(/2 cover image\(s\) missing/)).toBeInTheDocument();
    expect(screen.queryByText('Upload cover image')).not.toBeInTheDocument();
    expect(screen.queryByRole('img')).not.toBeInTheDocument();

    await user.click(screen.getByRole('tab', { name: /Upload/ }));
    expect(await screen.findByText('Upload cover image')).toBeInTheDocument();
    expect(screen.queryByText(/cover image\(s\) missing/)).not.toBeInTheDocument();

    await user.click(screen.getByRole('tab', { name: /Gallery \(1\)/ }));
    expect(await screen.findByRole('img')).toBeInTheDocument();
  });

  it('explains why images cannot be served when the signing key is unset', async () => {
    mediaGalleryService.list.mockResolvedValue({
      assets: [asset()], signing_key_configured: false, files_missing: 0,
    });
    const user = userEvent.setup();
    renderWithAuth(<MediaGalleryPage />);

    await waitFor(() => expect(screen.getByText(/Cover images cannot be served/)).toBeInTheDocument());
    expect(screen.getByText(/openssl rand -hex 32/)).toBeInTheDocument();

    // No broken <img>: the tile says what is wrong instead.
    await user.click(screen.getByRole('tab', { name: /Gallery/ }));
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    expect(await screen.findByText(/Cannot be served until/)).toBeInTheDocument();
  });

  it('flags assets whose file vanished from disk', async () => {
    mediaGalleryService.list.mockResolvedValue({
      assets: [asset({ file_missing: true })], signing_key_configured: true, files_missing: 1,
    });
    const user = userEvent.setup();
    renderWithAuth(<MediaGalleryPage />);

    await waitFor(() => expect(screen.getByText(/1 asset\(s\) have a database row but no file/)).toBeInTheDocument());
    await user.click(screen.getByRole('tab', { name: /Gallery/ }));
    expect(await screen.findByText(/Image file missing on the server/)).toBeInTheDocument();
  });

  it('counts only uploads that can actually be assigned as rotation-ready', async () => {
    mediaGalleryService.list.mockResolvedValue({
      assets: [
        asset({ id: 1 }),
        asset({ id: 2, kind: 'ai_generated' }),
        asset({ id: 3, category_tags: '[]', portfolio_stream: null }),
      ],
      signing_key_configured: true,
      files_missing: 0,
    });
    renderWithAuth(<MediaGalleryPage />);

    // 3 assets, but only the tagged gallery_upload can be matched to an article.
    await waitFor(() => expect(screen.getByText(/1 of 3 are rotation-ready/)).toBeInTheDocument());
  });

  it('warns when the cover directory is unwritable, because uploads will fail', async () => {
    mediaGalleryService.list.mockResolvedValue({
      assets: [], signing_key_configured: true, files_missing: 0,
      storage_path: '/home/reachaicountly/cover_images/',
      storage_writable: false, storage_outside_deploy: true,
    });
    renderWithAuth(<MediaGalleryPage />);

    await waitFor(() => expect(screen.getByText(/Uploads will fail/)).toBeInTheDocument());
    expect(screen.getByText('/home/reachaicountly/cover_images/')).toBeInTheDocument();
  });

  it('warns while covers still sit inside the rsynced deploy tree', async () => {
    mediaGalleryService.list.mockResolvedValue({
      assets: [], signing_key_configured: true, files_missing: 0,
      storage_path: '/home/app/public_html/api/writable/uploads/media/covers/',
      storage_writable: true, storage_outside_deploy: false,
    });
    renderWithAuth(<MediaGalleryPage />);

    await waitFor(() => expect(screen.getByText(/inside the deployed API directory/)).toBeInTheDocument());
    expect(screen.getByText('MEDIA_STORAGE_PATH')).toBeInTheDocument();
  });

  it('stays quiet when storage is outside the deploy tree and writable', async () => {
    renderWithAuth(<MediaGalleryPage />);

    await waitFor(() => expect(screen.getByRole('tab', { name: /Deficit/ })).toBeInTheDocument());
    expect(screen.queryByText(/Uploads will fail/)).not.toBeInTheDocument();
    expect(screen.queryByText(/inside the deployed API directory/)).not.toBeInTheDocument();
  });

  it('uses descriptive alt text rather than the stored generation prompt', async () => {
    const user = userEvent.setup();
    renderWithAuth(<MediaGalleryPage />);

    await waitFor(() => expect(screen.getByRole('tab', { name: /Gallery/ })).toBeInTheDocument());
    await user.click(screen.getByRole('tab', { name: /Gallery/ }));

    const img = await screen.findByRole('img');
    expect(img).toHaveAttribute('alt', 'Cover image tagged gst, accounting');
  });
});
