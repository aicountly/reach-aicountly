import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { AutomationSettingsPage } from '../AutomationSettingsPage';
import { jsonObjectError } from '../../../utils/jsonObject';
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

  describe('Social (social-api.ai)', () => {
    const SOCIAL = [
      { key: 'social.socialapi.base_url', group: 'Social', label: 'social-api.ai API base URL', type: 'string', value: 'https://api.social-api.ai/v1', source: 'default' },
      { key: 'social.socialapi.api_key', group: 'Social', label: 'social-api.ai API key', type: 'secret', is_set: false, source: 'unset', value: null },
      { key: 'video.youtube.publisher', group: 'Social', label: 'How videos reach YouTube', type: 'choice', options: ['social_api', 'direct'], value: 'social_api', source: 'default' },
      { key: 'social.socialapi.account.youtube', group: 'Social', label: 'social-api.ai account id: YouTube', type: 'string', value: '', source: 'unset' },
      { key: 'social.socialapi.platform_data.youtube', group: 'Social', label: 'platform_data for YouTube posts (JSON)', type: 'json_object', value: '', source: 'default' },
    ];

    beforeEach(() => {
      adminService.automationSettings.mockResolvedValue(SOCIAL);
      adminService.updateAutomationSettings.mockResolvedValue(SOCIAL);
    });

    it('shows the default base URL, a write-only key and the YouTube publisher choice', async () => {
      render(<AutomationSettingsPage />);
      expect(await screen.findByLabelText(/social-api\.ai API base URL/)).toHaveValue('https://api.social-api.ai/v1');
      expect(screen.getByLabelText(/social-api\.ai API key/)).toHaveAttribute('type', 'password');
      const select = screen.getByLabelText(/How videos reach YouTube/);
      expect(select.tagName).toBe('SELECT');
      expect(select).toHaveValue('social_api');
      expect(screen.getAllByRole('option').map((o) => o.value)).toEqual(['social_api', 'direct']);
    });

    it('refuses to save platform_data that is not a JSON object', async () => {
      render(<AutomationSettingsPage />);
      const json = await screen.findByLabelText(/platform_data for YouTube/);
      fireEvent.change(json, { target: { value: '[1, 2]' } });
      expect(screen.getByRole('alert')).toHaveTextContent('Must be a JSON object');
      fireEvent.click(screen.getByRole('button', { name: /Save Social/ }));
      expect(await screen.findByText(/platform_data for YouTube posts \(JSON\): Must be a JSON object/)).toBeInTheDocument();
      expect(adminService.updateAutomationSettings).not.toHaveBeenCalled();

      fireEvent.change(json, { target: { value: '{"youtube": {"privacy": "unlisted"}}' } });
      fireEvent.change(screen.getByLabelText(/How videos reach YouTube/), { target: { value: 'direct' } });
      fireEvent.click(screen.getByRole('button', { name: /Save Social/ }));
      await waitFor(() => expect(adminService.updateAutomationSettings).toHaveBeenCalledWith({
        'video.youtube.publisher': 'direct',
        'social.socialapi.platform_data.youtube': '{"youtube": {"privacy": "unlisted"}}',
      }));
    });

    it('runs only the social-api.ai live check from the Social card', async () => {
      adminService.runLiveChecks.mockResolvedValue({
        ok: true, passed: 1, failed: 0, ran_at: '2026-10-18T09:00:00+00:00',
        results: [{ target: 'social_api', ok: true, message: 'Key accepted; 1 connected account(s): youtube 1. Ready to post to: youtube. Nothing was posted.', detail: { targets: { youtube: 'posts go to acc_yt' } }, duration_ms: 3 }],
      });
      render(<AutomationSettingsPage />);
      fireEvent.click(await screen.findByRole('button', { name: /Check social-api\.ai/ }));
      expect(await screen.findByText(/Key accepted; 1 connected account/)).toBeInTheDocument();
      expect(adminService.runLiveChecks).toHaveBeenCalledWith(['social_api']);
      expect(screen.getByText('targets.youtube')).toBeInTheDocument();
    });
  });

  it('validates JSON objects', () => {
    expect(jsonObjectError('')).toBeNull();
    expect(jsonObjectError('{}')).toBeNull();
    expect(jsonObjectError('{"a":1}')).toBeNull();
    expect(jsonObjectError('[]')).toMatch(/JSON object/);
    expect(jsonObjectError('"x"')).toMatch(/JSON object/);
    expect(jsonObjectError('{oops')).toMatch(/Not valid JSON/);
  });
});
