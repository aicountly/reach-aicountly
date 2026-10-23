import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/adminService', () => ({ adminService: { consoleSync: vi.fn() } }));
import { adminService } from '../../../services/adminService';
import { ConsoleSyncPage } from '../ConsoleSyncPage';

const data = {
  configured: true, ok_last_hour: 4, errors_last_hour: 2, last_ok_at: '2026-10-20 10:00:00',
  recent_events: [{ id: 1, attempted_at: '2026-10-20 10:00:00', event_type: 'audit', response_status: 500, ok: false, error_message: 'boom' }],
  events_total: 2,
};

beforeEach(() => {
  vi.clearAllMocks();
  adminService.consoleSync.mockResolvedValue(data);
});

describe('ConsoleSyncPage drill-down', () => {
  it('the errors tile filters the events table to what it counted', async () => {
    renderWithAuth(<ConsoleSyncPage />, { route: '/admin/console-sync' });
    await waitFor(() => expect(adminService.consoleSync).toHaveBeenCalledWith({ result: '', since_hours: '' }));

    fireEvent.click(await screen.findByRole('button', { name: /errors \(last hour\)/i }));
    await waitFor(() => expect(adminService.consoleSync).toHaveBeenLastCalledWith({ result: 'error', since_hours: '1' }));
    expect(await screen.findByRole('button', { name: /clear error filter/i })).toBeInTheDocument();
  });

  it('reads the filter from the URL', async () => {
    renderWithAuth(<ConsoleSyncPage />, { route: '/admin/console-sync?result=ok&since_hours=1' });
    await waitFor(() => expect(adminService.consoleSync).toHaveBeenCalledWith({ result: 'ok', since_hours: '1' }));
    expect(await screen.findByRole('button', { name: /ok \(last hour\)/i })).toHaveAttribute('aria-pressed', 'true');
  });

  it('a zero tile is not a drill', async () => {
    adminService.consoleSync.mockResolvedValue({ ...data, errors_last_hour: 0 });
    renderWithAuth(<ConsoleSyncPage />, { route: '/admin/console-sync' });
    await screen.findByText('Errors (last hour)');
    expect(screen.queryByRole('button', { name: /errors \(last hour\)/i })).not.toBeInTheDocument();
  });
});
