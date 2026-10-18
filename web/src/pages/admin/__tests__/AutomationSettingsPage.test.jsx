import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { AutomationSettingsPage } from '../AutomationSettingsPage';
import { adminService } from '../../../services/adminService';

vi.mock('../../../services/adminService', () => ({
  adminService: {
    automationSettings: vi.fn(),
    updateAutomationSettings: vi.fn(),
    testAutomationProvider: vi.fn(),
    geminiModels: vi.fn(),
    runLiveChecks: vi.fn(),
  },
}));

const permission = { allowed: true };
vi.mock('../../../hooks/usePermission', () => ({
  usePermission: () => ({ has: () => permission.allowed }),
}));

const ROWS = [
  { key: 'ai.gemini.api_key', group: 'AI providers', label: 'Gemini API key', type: 'secret', is_set: true, source: 'env', value: null },
  { key: 'ai.vetting.model.default', group: 'AI vetting', label: 'Default vetting model', type: 'model', value: 'gemini-2.5-flash', source: 'default' },
  { key: 'automation.blog.daily_limit', group: 'Automation', label: 'Blogs per day', type: 'int', value: 6, source: 'default' },
];

describe('AutomationSettingsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    adminService.automationSettings.mockResolvedValue(ROWS);
    adminService.geminiModels.mockResolvedValue([{ id: 'gemini-2.5-flash', label: 'Gemini 2.5 Flash' }]);
    adminService.updateAutomationSettings.mockResolvedValue(ROWS);
    permission.allowed = true;
  });

  it('never shows a secret and says where it comes from', async () => {
    render(<AutomationSettingsPage />);
    const input = await screen.findByLabelText(/Gemini API key/);
    expect(input).toHaveAttribute('type', 'password');
    expect(input).toHaveValue('');
    expect(screen.getByText(/set \(from server \.env\)/)).toBeInTheDocument();
  });

  it('saves only the fields that changed, and skips an untouched secret', async () => {
    render(<AutomationSettingsPage />);
    const limit = await screen.findByLabelText(/Blogs per day/);
    fireEvent.change(limit, { target: { value: '8' } });
    fireEvent.click(screen.getByRole('button', { name: /Save Automation/ }));
    await waitFor(() => expect(adminService.updateAutomationSettings).toHaveBeenCalledWith({ 'automation.blog.daily_limit': '8' }));

    fireEvent.click(screen.getByRole('button', { name: /Save AI providers/ }));
    expect(adminService.updateAutomationSettings).toHaveBeenCalledTimes(1);
  });

  it('runs a provider test and shows the result', async () => {
    adminService.testAutomationProvider.mockResolvedValue({ ok: true, message: 'Connected.' });
    render(<AutomationSettingsPage />);
    fireEvent.click(await screen.findByRole('button', { name: /Test gemini/ }));
    expect(await screen.findByText(/gemini: Connected\./)).toBeInTheDocument();
  });

  it('runs the live checks and shows OK / FAIL per integration with details', async () => {
    adminService.runLiveChecks.mockResolvedValue({
      ok: false, passed: 1, failed: 1, ran_at: '2026-10-18T09:00:00+00:00',
      results: [
        { target: 'youtube', ok: true, message: 'Token refresh OK; channel “AICOUNTLY” (UC1).', detail: { long_uploads: 'allowed', render: { id: 'x' } }, duration_ms: 10 },
        { target: 'shotstack', ok: false, message: 'Shotstack rejected the key for the stage environment (HTTP 403).', detail: {}, duration_ms: 5 },
      ],
    });
    render(<AutomationSettingsPage />);
    fireEvent.click(await screen.findByRole('button', { name: /Run live checks/ }));
    expect(await screen.findByText(/1 passed, 1 failed/)).toBeInTheDocument();
    expect(adminService.runLiveChecks).toHaveBeenCalledWith();
    expect(screen.getByText('OK')).toBeInTheDocument();
    expect(screen.getByText('FAIL')).toBeInTheDocument();
    expect(screen.getByText(/Shotstack rejected the key/)).toBeInTheDocument();
    expect(screen.getByText('long_uploads')).toBeInTheDocument();
    expect(screen.getByText('render.id')).toBeInTheDocument();
  });

  it('shows the error when the live checks cannot run', async () => {
    adminService.runLiveChecks.mockRejectedValue(new Error('Too many requests'));
    render(<AutomationSettingsPage />);
    fireEvent.click(await screen.findByRole('button', { name: /Run live checks/ }));
    expect(await screen.findByText('Too many requests')).toBeInTheDocument();
  });

  it('hides the live checks from users who cannot manage settings', async () => {
    permission.allowed = false;
    render(<AutomationSettingsPage />);
    await screen.findByLabelText(/Gemini API key/);
    expect(screen.queryByRole('button', { name: /Run live checks/ })).not.toBeInTheDocument();
  });
});
