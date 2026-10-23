import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent, within } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/videoService', () => ({
  default: {
    pipeline: vi.fn(),
    pipelineItem: vi.fn(),
    retry: vi.fn(),
    status: vi.fn(),
    youtubeStatus: vi.fn(),
    youtubeConnect: vi.fn(),
    youtubeDisconnect: vi.fn(),
  },
}));
import videoService from '../../../services/videoService';
import VideoPipelinePage from '../VideoPipelinePage';

const admin = { auth: { user: { id: 1, email: 'a@test', role: 'super_admin' }, permissions: ['*'] } };
const viewer = { auth: { user: { id: 2, email: 'v@test', role: 'viewer' }, permissions: ['video.read'] } };

const item = {
  uuid: '11111111-2222-4333-8444-555555555555', title: 'GST returns explained', format: 'vlog',
  status: 'blocked', step: 'youtube_upload', step_detail: 'Blocked: No YouTube channel is connected',
  youtube_url: null, created_at: '2026-10-17T01:30:00Z',
};

beforeEach(() => {
  Object.values(videoService).forEach((fn) => fn.mockReset());
  videoService.pipeline.mockResolvedValue({ items: [item], total: 1 });
  videoService.status.mockResolvedValue({
    ready: false, missing: ['youtube_channel'], render_provider: 'shotstack:stage',
    checks: { rendering: true, youtube_channel: false }, tts: { model: 'gpt-4o-mini-tts', voice: 'coral' }, daily: { vlog: 1, short: 1 }, notes: [],
  });
  videoService.youtubeStatus.mockResolvedValue({
    connected: false, status: 'disconnected', client_configured: true, privacy: 'public',
    redirect_uri: 'https://reach.aicountly.org/api/v1/public/video/youtube/oauth-callback',
  });
});

describe('VideoPipelinePage', () => {
  it('lists pipeline items with their step and honest readiness', async () => {
    renderWithAuth(<VideoPipelinePage />, admin);
    expect(await screen.findByText('GST returns explained')).toBeInTheDocument();
    expect(within(screen.getByRole('table')).getByText('blocked')).toBeInTheDocument();
    expect(screen.getByText('YouTube upload')).toBeInTheDocument();
    expect(screen.getByText('Blocked: No YouTube channel is connected')).toBeInTheDocument();
    expect(screen.getByText('not ready')).toBeInTheDocument();
    expect(screen.getByText('https://reach.aicountly.org/api/v1/public/video/youtube/oauth-callback')).toBeInTheDocument();
  });

  it('offers Connect YouTube channel to admins only', async () => {
    renderWithAuth(<VideoPipelinePage />, admin);
    expect(await screen.findByRole('button', { name: 'Connect YouTube channel' })).toBeEnabled();
  });

  it('does not offer Connect to read-only users', async () => {
    renderWithAuth(<VideoPipelinePage />, viewer);
    await screen.findByText('GST returns explained');
    expect(screen.queryByRole('button', { name: 'Connect YouTube channel' })).toBeNull();
  });

  it('shows vetting reasons and retries a blocked item', async () => {
    videoService.pipelineItem.mockResolvedValue({
      ...item, vetting_decision: 'approve', vetting_model: 'gemini-2.5-flash', vetting_reasons: ['Softened a due date.'],
      warnings: ['Scene 2: Screenshot unavailable, title card used.'], last_error: 'No YouTube channel is connected', assets: [],
    });
    videoService.retry.mockResolvedValue({});
    renderWithAuth(<VideoPipelinePage />, admin);
    fireEvent.click(await screen.findByRole('button', { name: 'Details for GST returns explained' }));
    expect(await screen.findByText('Softened a due date.')).toBeInTheDocument();
    expect(screen.getByText('Scene 2: Screenshot unavailable, title card used.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Retry/ }));
    await waitFor(() => expect(videoService.retry).toHaveBeenCalledWith(item.uuid));
  });

  it('reports the OAuth callback result from the URL', async () => {
    renderWithAuth(<VideoPipelinePage />, { ...admin, route: '/video?youtube=error&reason=invalid_state' });
    expect(await screen.findByText(/link expired or was already used/)).toBeInTheDocument();
  });

  it('never links a non-https (mock) YouTube URL', async () => {
    videoService.pipeline.mockResolvedValue({ items: [{ ...item, status: 'published', youtube_url: 'mock://youtube/MOCK1' }], total: 1 });
    renderWithAuth(<VideoPipelinePage />, admin);
    await screen.findByText('GST returns explained');
    expect(screen.queryByRole('link', { name: 'Open' })).toBeNull();
  });
});
