import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/adminService', () => ({ adminService: { auditLogs: vi.fn() } }));
import { adminService } from '../../../services/adminService';
import { AuditLogsPage } from '../AuditLogsPage';

const rows = [
  { id: 1, created_at: '2026-10-20 10:00:00', user_id: 3, user_email: 'ops@aicountly.org', action: 'campaign.update', entity_type: 'campaign', entity_id: 12 },
  { id: 2, created_at: '2026-10-20 09:00:00', user_id: 3, action: 'lead.create', entity_type: 'lead', entity_id: 88 },
  { id: 3, created_at: '2026-10-20 08:00:00', user_id: 4, action: 'settings.update', entity_type: 'settings', entity_id: 1 },
];

beforeEach(() => {
  vi.clearAllMocks();
  adminService.auditLogs.mockResolvedValue({ items: rows, total: 3 });
});

describe('AuditLogsPage drill-down', () => {
  it('applies action, entity_type and user_id from the URL', async () => {
    renderWithAuth(<AuditLogsPage />, { route: '/admin/audit-logs?action=lead.create&entity_type=lead&user_id=3' });

    await waitFor(() => expect(adminService.auditLogs).toHaveBeenCalledWith(
      expect.objectContaining({ action: 'lead.create', entity_type: 'lead', user_id: '3', page: 1 }),
    ));
    expect(screen.getByLabelText('Action')).toHaveValue('lead.create');
    expect(screen.getByRole('button', { name: /clear user filter/i })).toHaveTextContent('User #3');
  });

  it('opens the audited entity where a detail page exists', async () => {
    renderWithAuth(<AuditLogsPage />, { route: '/admin/audit-logs' });

    expect(await screen.findByRole('link', { name: '12' })).toHaveAttribute('href', '/campaigns/12');
    expect(screen.getByRole('link', { name: '88' })).toHaveAttribute('href', '/leads?lead_id=88');
    // settings has no detail page.
    expect(screen.queryByRole('link', { name: '1' })).not.toBeInTheDocument();
  });

  it('an action cell narrows to that action', async () => {
    renderWithAuth(<AuditLogsPage />, { route: '/admin/audit-logs' });

    fireEvent.click(await screen.findByRole('button', { name: 'settings.update' }));
    await waitFor(() => expect(adminService.auditLogs).toHaveBeenLastCalledWith(expect.objectContaining({ action: 'settings.update' })));
  });
});
