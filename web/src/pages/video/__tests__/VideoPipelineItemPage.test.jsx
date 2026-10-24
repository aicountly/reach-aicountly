import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { Route, Routes } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));
vi.mock('../../../services/videoService', () => ({
  default: {
    pipeline: vi.fn(), pipelineItem: vi.fn(), retry: vi.fn(), status: vi.fn(),
    youtubeStatus: vi.fn(), youtubeConnect: vi.fn(), youtubeDisconnect: vi.fn(),
  },
}));
import api from '../../../services/api';
import videoService from '../../../services/videoService';
import VideoPipelineItemPage from '../VideoPipelineItemPage';
import VideoPipelinePage from '../VideoPipelinePage';
import VideoOperationsDashboardPage from '../VideoOperationsDashboardPage';

const ctx = { auth: { user: { id: 1, role: 'super_admin' }, permissions: ['*'] } };

const item = {
  uuid: 'u-1', title: 'GST in 60 seconds', format: 'short', status: 'failed', step: 'tts', step_detail: 'OpenAI 429',
  last_error: 'Voice-over failed', youtube_url: 'https://youtu.be/abc', created_at: '2026-10-20T00:00:00Z',
  jobs: [
    { id: 70, step: 'vet', status: 'completed', attempts: 1, max_attempts: 5 },
    { id: 71, step: 'tts', status: 'dead_letter', attempts: 5, max_attempts: 5, error_message: '429' },
  ],
  history: [{ id: 3, action: 'video.pipeline.retry', new_value: { status: 'failed', step: 'tts' }, created_at: '2026-10-21T00:00:00Z' }],
  assets: [],
};

beforeEach(() => {
  vi.clearAllMocks();
  videoService.pipelineItem.mockResolvedValue(item);
  videoService.pipeline.mockResolvedValue({ items: [item], total: 1 });
  videoService.status.mockResolvedValue({});
  videoService.youtubeStatus.mockResolvedValue({});
});

const renderItem = () => renderWithAuth(
  <Routes><Route path="/video/pipeline/:uuid" element={<VideoPipelineItemPage />} /></Routes>,
  { ...ctx, route: '/video/pipeline/u-1' },
);

describe('video item detail', () => {
  it('shows each step with the jobs that ran it, linked to the job detail', async () => {
    renderItem();
    expect(await screen.findByRole('heading', { name: /GST in 60 seconds/ })).toBeInTheDocument();
    expect(videoService.pipelineItem).toHaveBeenCalledWith('u-1');
    const jobLinks = screen.getAllByRole('link', { name: '#71' });
    expect(jobLinks[0]).toHaveAttribute('href', '/admin/jobs/71');
    expect(screen.getByRole('link', { name: /Watch on YouTube/ })).toHaveAttribute('href', 'https://youtu.be/abc');
    expect(screen.getByText('video.pipeline.retry')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Short' })).toHaveAttribute('href', '/video?format=short');
  });

  it('retries a failed item', async () => {
    videoService.retry.mockResolvedValue({});
    renderItem();
    fireEvent.click(await screen.findByRole('button', { name: /Retry/ }));
    await waitFor(() => expect(videoService.retry).toHaveBeenCalledWith('u-1'));
  });
});

describe('pipeline list and ops counts', () => {
  it('a pipeline row title opens the item page', async () => {
    renderWithAuth(<VideoPipelinePage />, ctx);
    expect(await screen.findByRole('link', { name: 'GST in 60 seconds' })).toHaveAttribute('href', '/video/pipeline/u-1');
  });

  it('ops status counts open the pipeline filtered the same way; zero is plain', async () => {
    api.get.mockResolvedValue({
      status: 'ready',
      pipeline_counts: { total: 3, by_status: { failed: { total: 2, vlog: 2, short: 0 }, published: { total: 1, vlog: 0, short: 1 } } },
    });
    renderWithAuth(<VideoOperationsDashboardPage />, ctx);
    const twos = await screen.findAllByRole('link', { name: '2' });
    expect(twos.map((a) => a.getAttribute('href'))).toEqual(['/video?status=failed&format=vlog', '/video?status=failed']);
    expect(screen.getAllByRole('link', { name: '1' }).map((a) => a.getAttribute('href'))).toEqual(['/video?status=published&format=short', '/video?status=published']);
    expect(screen.queryByRole('link', { name: '0' })).not.toBeInTheDocument();
  });
});
