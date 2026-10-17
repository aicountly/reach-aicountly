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
  },
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
});
