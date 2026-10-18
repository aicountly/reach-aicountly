import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/contentService', () => ({
  contentService: { listSchedules: vi.fn(), listTargets: vi.fn(), createSchedule: vi.fn(), cancelSchedule: vi.fn() },
}));
vi.mock('../../../hooks/usePermission', () => ({ usePermission: () => ({ has: () => true }) }));
import { contentService } from '../../../services/contentService';
import { ContentSchedulePage } from '../ContentSchedulePage';

const render = () => renderWithAuth(
  <Routes><Route path="/content/:id/schedule" element={<ContentSchedulePage />} /></Routes>,
  { auth: { user: { id: 1 } }, route: '/content/5/schedule' },
);

beforeEach(() => {
  contentService.listSchedules.mockResolvedValue({
    schedules: [{ id: 1, scheduled_at: '2026-10-18 04:30:00+00', timezone: 'UTC', publication_target_id: 2, schedule_status: 'pending', cancelled_at: null }],
  });
  contentService.listTargets.mockResolvedValue({ targets: [{ id: 2, name: 'aicountly.com', channel: 'blog' }] });
  contentService.createSchedule.mockResolvedValue({});
});

describe('ContentSchedulePage', () => {
  it('shows existing schedules in IST', async () => {
    render();
    expect(await screen.findByText('18-10-2026 10:00 IST')).toBeInTheDocument();
  });

  it('reads the typed time as IST and sends it with the +05:30 offset', async () => {
    const { container } = render();
    await screen.findByText('Schedule Publication');
    fireEvent.change(container.querySelector('select'), { target: { value: '2' } });
    fireEvent.change(container.querySelector('input[type="datetime-local"]'), { target: { value: '2026-10-19T09:15' } });
    fireEvent.click(screen.getByRole('button', { name: 'Schedule' }));
    await waitFor(() => expect(contentService.createSchedule).toHaveBeenCalled());
    expect(contentService.createSchedule.mock.calls[0][1]).toMatchObject({
      publication_target_id: '2',
      scheduled_at: '2026-10-19T09:15:00+05:30',
      timezone: 'Asia/Kolkata',
    });
    expect(screen.getByText('Scheduled At (IST)')).toBeInTheDocument();
  });
});
