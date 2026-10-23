import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { AutomationSettingsPage } from '../AutomationSettingsPage';
import { jsonObjectError } from '../../../utils/jsonObject';
import { adminService } from '../../../services/adminService';
import {
  COLLAPSE_STORAGE_KEY, buildPayload, buildSections, isDirty, matchesQuery, resolveSectionId, sectionStatus, subgroupsOf,
} from '../automationSettings/model';

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

function LocationProbe() {
  const location = useLocation();
  return <span data-testid="location-hash">{location.hash}</span>;
}

function renderPage(entry = '/admin/ai-automation') {
  return render(
    <MemoryRouter initialEntries={[entry]}>
      <AutomationSettingsPage />
      <LocationProbe />
    </MemoryRouter>,
  );
}

const ROWS = [
  { key: 'ai.gemini.api_key', group: 'AI providers', label: 'Gemini API key', type: 'secret', is_set: true, source: 'env', value: null },
  { key: 'ai.vetting.model.default', group: 'AI vetting', label: 'Default vetting model', type: 'model', value: 'gemini-2.5-flash', source: 'default' },
  { key: 'automation.blog.daily_limit', group: 'Automation', label: 'Blogs per day', type: 'int', value: 6, source: 'default' },
];

const SOCIAL = [
  { key: 'social.socialapi.base_url', group: 'Social', label: 'social-api.ai API base URL', type: 'string', value: 'https://api.social-api.ai/v1', source: 'default' },
  { key: 'social.socialapi.api_key', group: 'Social', label: 'social-api.ai API key', type: 'secret', is_set: false, source: 'unset', value: null },
  { key: 'video.youtube.publisher', group: 'Social', label: 'How videos reach YouTube', type: 'choice', options: ['social_api', 'direct'], value: 'social_api', source: 'default' },
  { key: 'social.socialapi.account.youtube', group: 'Social', label: 'social-api.ai account id: YouTube', type: 'string', value: '', source: 'unset' },
  { key: 'social.socialapi.platform_data.youtube', group: 'Social', label: 'platform_data for YouTube posts (JSON)', type: 'json_object', value: '', source: 'default',
    help: 'Copy the exact keys from docs.social-api.ai → Platforms → YouTube.' },
];

/** A realistic slice of every kind of section. */
const FULL = [
  { key: 'ai.gemini.api_key', group: 'AI providers', label: 'Gemini API key', type: 'secret', is_set: true, source: 'env', value: null, help: 'Google AI Studio key.' },
  { key: 'ai.openai.api_key', group: 'AI providers', label: 'OpenAI API key', type: 'secret', is_set: true, source: 'settings', value: null, help: 'Image generation.' },
  { key: 'ai.perplexity.api_key', group: 'AI providers', label: 'Perplexity API key', type: 'secret', is_set: false, source: 'unset', value: null, help: 'Optional. AI-visibility checks.' },
  { key: 'automation.blog.enabled', group: 'Automation', label: 'Blog automation', type: 'bool', value: true, source: 'default' },
  { key: 'automation.blog.daily_limit', group: 'Automation', label: 'Blogs per day', type: 'int', value: 6, source: 'default' },
  ...SOCIAL,
  { key: 'social.socialapi.youtube.visibility', group: 'Social', label: 'YouTube visibility', type: 'choice', options: ['public', 'unlisted', 'private'], value: 'public', source: 'default' },
  { key: 'social.socialapi.target_options.youtube', group: 'Social', label: 'Per-target options for YouTube posts (JSON)', type: 'json_object', value: '', source: 'default' },
  { key: 'video.tts.voice', group: 'Video', label: 'Voice', type: 'string', value: 'coral', source: 'default', help: 'OpenAI voice name: alloy, coral, nova.' },
  { key: 'video.shotstack.api_key', group: 'Video', label: 'Shotstack API key', type: 'secret', is_set: false, source: 'unset', value: null, help: 'Shotstack dashboard → API keys.' },
  { key: 'video.youtube.client_secret', group: 'Video', label: 'YouTube OAuth client secret', type: 'secret', is_set: false, source: 'unset', value: null },
];

describe('AutomationSettingsPage', () => {
  beforeAll(() => {
    Element.prototype.scrollIntoView = vi.fn();
  });

  beforeEach(() => {
    vi.clearAllMocks();
    adminService.automationSettings.mockResolvedValue(ROWS);
    adminService.geminiModels.mockResolvedValue([{ id: 'gemini-2.5-flash', label: 'Gemini 2.5 Flash' }]);
    adminService.updateAutomationSettings.mockResolvedValue(ROWS);
    permission.allowed = true;
  });

  it('never shows a secret and says where it comes from', async () => {
    renderPage();
    const input = await screen.findByLabelText(/Gemini API key/);
    expect(input).toHaveAttribute('type', 'password');
    expect(input).toHaveValue('');
    expect(screen.getByText(/set \(from server \.env\)/)).toBeInTheDocument();
  });

  it('saves only the fields that changed, and skips an untouched secret', async () => {
    renderPage();
    const limit = await screen.findByLabelText(/Blogs per day/);
    fireEvent.change(limit, { target: { value: '8' } });
    fireEvent.click(screen.getByRole('button', { name: /Save Automation/ }));
    await waitFor(() => expect(adminService.updateAutomationSettings).toHaveBeenCalledWith({ 'automation.blog.daily_limit': '8' }));

    fireEvent.click(screen.getByRole('button', { name: /Save AI providers/ }));
    expect(adminService.updateAutomationSettings).toHaveBeenCalledTimes(1);
    expect(await screen.findByText('Nothing changed in AI providers.')).toBeInTheDocument();
  });

  it('runs a provider test and shows the result', async () => {
    adminService.testAutomationProvider.mockResolvedValue({ ok: true, message: 'Connected.' });
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: /Test gemini/ }));
    expect(await screen.findByText(/gemini: Connected\./)).toBeInTheDocument();
  });

  it('runs the live checks and shows OK / FAIL per integration with details', async () => {
    adminService.runLiveChecks.mockResolvedValue({
      ok: false, passed: 1, failed: 1, ran_at: '2026-10-18T09:00:00+00:00',
      results: [
        { target: 'youtube', ok: true, message: 'Token refresh OK; channel “Aicountly” (UC1).', detail: { long_uploads: 'allowed', render: { id: 'x' } }, duration_ms: 10 },
        { target: 'shotstack', ok: false, message: 'Shotstack rejected the key for the stage environment (HTTP 403).', detail: {}, duration_ms: 5 },
      ],
    });
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: /Run live checks/ }));
    expect(await screen.findByText(/1 passed, 1 failed/)).toBeInTheDocument();
    expect(adminService.runLiveChecks).toHaveBeenCalledWith();
    expect(screen.getByText('OK')).toBeInTheDocument();
    expect(screen.getByText('FAIL')).toBeInTheDocument();
    expect(screen.getByText(/Shotstack rejected the key/)).toBeInTheDocument();
    expect(screen.getByText('long_uploads')).toBeInTheDocument();
    expect(screen.getByText('render.id')).toBeInTheDocument();
    expect(screen.getByTestId('summary-checks')).toHaveTextContent('1 OK · 1 FAIL');
  });

  it('shows the error when the live checks cannot run', async () => {
    adminService.runLiveChecks.mockRejectedValue(new Error('Too many requests'));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: /Run live checks/ }));
    expect(await screen.findByText('Too many requests')).toBeInTheDocument();
  });

  it('hides the live checks from users who cannot manage settings', async () => {
    permission.allowed = false;
    renderPage();
    await screen.findByLabelText(/Gemini API key/);
    expect(screen.queryByRole('button', { name: /Run live checks/ })).not.toBeInTheDocument();
  });

  describe('Social (social-api.ai)', () => {
    beforeEach(() => {
      adminService.automationSettings.mockResolvedValue(SOCIAL);
      adminService.updateAutomationSettings.mockResolvedValue(SOCIAL);
    });

    it('shows the default base URL, a write-only key and the YouTube publisher choice', async () => {
      renderPage();
      expect(await screen.findByLabelText(/social-api\.ai API base URL/)).toHaveValue('https://api.social-api.ai/v1');
      expect(screen.getByLabelText(/social-api\.ai API key/)).toHaveAttribute('type', 'password');
      const select = screen.getByLabelText(/How videos reach YouTube/);
      expect(select.tagName).toBe('SELECT');
      expect(select).toHaveValue('social_api');
      expect(screen.getAllByRole('option').map((o) => o.value)).toEqual(['social_api', 'direct']);
    });

    it('groups Social into connection, YouTube publisher, accounts and platform_data', async () => {
      renderPage();
      const social = await screen.findByTestId('section-social');
      expect(within(social).getAllByRole('heading', { level: 3 }).map((h) => h.textContent)).toEqual([
        'Connection', 'YouTube publisher', 'Per-network accounts', 'Per-network platform_data & target options',
      ]);
      const connection = screen.getByTestId('subgroup-social-connection');
      expect(within(connection).getByLabelText(/social-api\.ai API key/)).toBeInTheDocument();
      expect(within(connection).getByRole('button', { name: /Check social-api\.ai/ })).toBeInTheDocument();
      const platformData = screen.getByTestId('subgroup-social-platform-data');
      expect(within(platformData).getByRole('link', { name: /docs\.social-api\.ai → Platforms → YouTube/ }))
        .toHaveAttribute('href', 'https://docs.social-api.ai');
    });

    it('refuses to save platform_data that is not a JSON object', async () => {
      renderPage();
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

    it('formats platform_data JSON', async () => {
      renderPage();
      const json = await screen.findByLabelText(/platform_data for YouTube/);
      const format = within(screen.getByTestId('setting-social.socialapi.platform_data.youtube')).getByRole('button', { name: 'Format JSON' });
      expect(format).toBeDisabled();
      fireEvent.change(json, { target: { value: '{"privacy":"unlisted","made_for_kids":false}' } });
      expect(format).toBeEnabled();
      fireEvent.click(format);
      expect(json).toHaveValue('{\n  "privacy": "unlisted",\n  "made_for_kids": false\n}');
    });

    it('runs only the social-api.ai live check from the Social card', async () => {
      adminService.runLiveChecks.mockResolvedValue({
        ok: true, passed: 1, failed: 0, ran_at: '2026-10-18T09:00:00+00:00',
        results: [{ target: 'social_api', ok: true, message: 'Key accepted; 1 connected account(s): youtube 1. Ready to post to: youtube. Nothing was posted.', detail: { targets: { youtube: 'posts go to acc_yt' } }, duration_ms: 3 }],
      });
      renderPage();
      fireEvent.click(await screen.findByRole('button', { name: /Check social-api\.ai/ }));
      expect(await screen.findByText(/Key accepted; 1 connected account/)).toBeInTheDocument();
      expect(adminService.runLiveChecks).toHaveBeenCalledWith(['social_api']);
      expect(screen.getByText('targets.youtube')).toBeInTheDocument();
      expect(within(screen.getByTestId('section-social')).getByTestId('live-result-social_api')).toHaveTextContent('OK');
    });
  });

  describe('navigation, search and unsaved changes', () => {
    beforeEach(() => {
      adminService.automationSettings.mockResolvedValue(FULL);
      adminService.updateAutomationSettings.mockResolvedValue(FULL);
    });

    it('puts YouTube visibility with the publisher and target options next to platform_data', async () => {
      renderPage();
      const publisher = await screen.findByTestId('subgroup-social-youtube-publisher');
      const visibility = within(publisher).getByLabelText(/YouTube visibility/);
      expect(visibility).toHaveValue('public');
      expect(within(visibility).getAllByRole('option').map((o) => o.value)).toEqual(['public', 'unlisted', 'private']);

      const json = screen.getByTestId('subgroup-social-platform-data');
      expect(within(json).getAllByRole('textbox').map((t) => t.id)).toEqual([
        'setting-social.socialapi.platform_data.youtube', 'setting-social.socialapi.target_options.youtube',
      ]);
      const options = within(screen.getByTestId('setting-social.socialapi.target_options.youtube'));
      fireEvent.change(screen.getByLabelText(/Per-target options for YouTube/), { target: { value: '[]' } });
      expect(options.getByRole('alert')).toHaveTextContent('Must be a JSON object');
      fireEvent.change(screen.getByLabelText(/Per-target options for YouTube/), { target: { value: '{"visibility":"unlisted"}' } });
      fireEvent.click(options.getByRole('button', { name: 'Format JSON' }));
      fireEvent.change(visibility, { target: { value: 'unlisted' } });
      fireEvent.click(screen.getByRole('button', { name: /Save Social/ }));
      await waitFor(() => expect(adminService.updateAutomationSettings).toHaveBeenCalledWith({
        'social.socialapi.youtube.visibility': 'unlisted',
        'social.socialapi.target_options.youtube': '{\n  "visibility": "unlisted"\n}',
      }));
    });

    it('renders a choice setting such as the image quality as a select', async () => {
      adminService.automationSettings.mockResolvedValue([...FULL,
        { key: 'ai.image.quality', group: 'Images', label: 'Image quality', type: 'choice', options: ['low', 'medium', 'high', 'auto'], value: 'medium', source: 'default' }]);
      renderPage();
      const images = await screen.findByTestId('section-images');
      const select = within(images).getByLabelText(/Image quality/);
      expect(select.tagName).toBe('SELECT');
      expect(within(select).getAllByRole('option').map((o) => o.value)).toEqual(['low', 'medium', 'high', 'auto']);
      fireEvent.change(select, { target: { value: 'high' } });
      fireEvent.click(within(images).getByRole('button', { name: /Save Images/ }));
      await waitFor(() => expect(adminService.updateAutomationSettings).toHaveBeenCalledWith({ 'ai.image.quality': 'high' }));
    });

    it('lists every section in the navigator with its count and status', async () => {
      renderPage();
      const nav = await screen.findByRole('navigation', { name: 'Settings sections' });
      const links = within(nav).getAllByRole('link');
      expect(links.map((a) => a.getAttribute('href'))).toEqual(['#ai-providers', '#automation', '#social', '#video']);
      expect(within(screen.getByTestId('nav-ai-providers')).getByText('3')).toBeInTheDocument();
      expect(within(screen.getByTestId('nav-social')).getByText('7')).toBeInTheDocument();
      // Perplexity is optional, so AI providers is all set; Social has no key, Video has no Shotstack key.
      expect(screen.getByTestId('nav-ai-providers').querySelector('[data-status]')).toHaveAttribute('data-status', 'ok');
      expect(screen.getByTestId('nav-social').querySelector('[data-status]')).toHaveAttribute('data-status', 'warning');
      expect(screen.getByTestId('nav-video').querySelector('[data-status]')).toHaveAttribute('data-status', 'warning');
      expect(links[0]).toHaveAttribute('aria-current', 'location');
      expect(screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)).toEqual(
        expect.arrayContaining(['AI providers', 'Automation', 'Social', 'Video']),
      );
      expect(screen.getByTestId('summary-keys')).toHaveTextContent('2/6');
    });

    it('offers quick links to the sections that need attention', async () => {
      renderPage();
      const summary = await screen.findByTestId('automation-summary');
      const quick = within(summary).getAllByRole('link').map((a) => a.getAttribute('href'));
      expect(quick).toEqual(['#social', '#video']);
      fireEvent.click(within(summary).getByRole('link', { name: /Video/ }));
      expect(screen.getByTestId('location-hash')).toHaveTextContent('#video');
    });

    it('opens a deep link (#social): expands it, scrolls to it and focuses its heading', async () => {
      try { localStorage.setItem(COLLAPSE_STORAGE_KEY, JSON.stringify(['social'])); } catch { /* ignore */ }
      renderPage('/admin/ai-automation#social');
      const social = await screen.findByTestId('section-social');
      const toggle = within(social).getByRole('button', { name: 'Social' });
      await waitFor(() => expect(toggle).toHaveAttribute('aria-expanded', 'true'));
      await waitFor(() => expect(Element.prototype.scrollIntoView).toHaveBeenCalled());
      const scrolled = Element.prototype.scrollIntoView.mock.contexts;
      expect(scrolled[scrolled.length - 1]).toBe(social);
      expect(toggle).toHaveFocus();
      expect(screen.getByTestId('nav-social')).toHaveAttribute('aria-current', 'location');
    });

    it('jumps to a section from the navigator and puts it in the URL hash', async () => {
      renderPage();
      fireEvent.click(await screen.findByTestId('nav-video'));
      const video = screen.getByTestId('section-video');
      await waitFor(() => expect(Element.prototype.scrollIntoView.mock.contexts.at(-1)).toBe(video));
      expect(Element.prototype.scrollIntoView).toHaveBeenLastCalledWith(expect.objectContaining({ block: 'start' }));
      expect(screen.getByTestId('location-hash')).toHaveTextContent('#video');
      expect(screen.getByTestId('nav-video')).toHaveAttribute('aria-current', 'location');
      expect(within(video).getByRole('button', { name: 'Video' })).toHaveFocus();
    });

    it('collapses sections, remembers it, and expands / collapses all', async () => {
      renderPage();
      const automation = await screen.findByTestId('section-automation');
      const toggle = within(automation).getByRole('button', { name: 'Automation' });
      fireEvent.click(toggle);
      expect(toggle).toHaveAttribute('aria-expanded', 'false');
      expect(document.getElementById('automation-body')).not.toBeVisible();
      expect(JSON.parse(localStorage.getItem(COLLAPSE_STORAGE_KEY))).toEqual(['automation']);

      fireEvent.click(screen.getByRole('button', { name: 'Collapse all sections' }));
      expect(JSON.parse(localStorage.getItem(COLLAPSE_STORAGE_KEY)).sort()).toEqual(['ai-providers', 'automation', 'social', 'video']);
      fireEvent.click(screen.getByRole('button', { name: 'Expand all sections' }));
      expect(localStorage.getItem(COLLAPSE_STORAGE_KEY)).toBe('[]');
      expect(toggle).toHaveAttribute('aria-expanded', 'true');
    });

    it('still renders when the browser refuses storage', async () => {
      const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied'); });
      vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('denied'); });
      renderPage();
      const toggle = within(await screen.findByTestId('section-video')).getByRole('button', { name: 'Video' });
      fireEvent.click(toggle);
      expect(toggle).toHaveAttribute('aria-expanded', 'false');
      expect(spy).toHaveBeenCalled();
    });

    it('filters settings by label, key or help text across sections', async () => {
      renderPage();
      const search = await screen.findByRole('searchbox', { name: 'Search settings' });

      fireEvent.change(search, { target: { value: 'shotstack' } });
      expect(screen.getByLabelText(/Shotstack API key/)).toBeInTheDocument();
      expect(screen.queryByLabelText(/Blogs per day/)).not.toBeInTheDocument();
      expect(screen.queryByTestId('section-social')).not.toBeInTheDocument();
      expect(screen.getByRole('status')).toHaveTextContent('1 setting in 1 section match “shotstack”.');
      expect(within(screen.getByTestId('nav-social')).getByText('0')).toBeInTheDocument();

      fireEvent.change(search, { target: { value: 'coral' } }); // help text
      expect(screen.getByLabelText(/^Voice$/)).toBeInTheDocument();

      fireEvent.change(search, { target: { value: 'account.youtube' } }); // key
      expect(screen.getByLabelText(/account id: YouTube/)).toBeInTheDocument();
      expect(screen.queryByLabelText(/social-api\.ai API key/)).not.toBeInTheDocument();

      fireEvent.change(search, { target: { value: 'no-such-setting' } });
      expect(screen.getByTestId('search-empty')).toHaveTextContent('No settings match “no-such-setting”.');
      fireEvent.click(within(screen.getByTestId('search-empty')).getByRole('button', { name: 'Clear search' }));
      expect(search).toHaveValue('');
      expect(screen.getByLabelText(/Blogs per day/)).toBeInTheDocument();
    });

    it('jumping to a section hidden by the search clears the search', async () => {
      renderPage();
      const search = await screen.findByRole('searchbox', { name: 'Search settings' });
      fireEvent.change(search, { target: { value: 'shotstack' } });
      fireEvent.click(screen.getByTestId('nav-social'));
      expect(search).toHaveValue('');
      expect(screen.getByTestId('section-social')).toBeInTheDocument();
    });

    it('tracks unsaved changes, warns before leaving, and Discard resets them', async () => {
      renderPage();
      const limit = await screen.findByLabelText(/Blogs per day/);
      expect(screen.queryByTestId('save-bar')).not.toBeInTheDocument();

      fireEvent.change(limit, { target: { value: '9' } });
      fireEvent.click(screen.getByLabelText(/Blog automation/));
      const bar = screen.getByTestId('save-bar');
      expect(bar).toHaveTextContent('2 unsaved changes in Automation');
      expect(screen.getByTestId('dirty-automation')).toHaveTextContent('2 unsaved');

      const evt = new Event('beforeunload', { cancelable: true });
      window.dispatchEvent(evt);
      expect(evt.defaultPrevented).toBe(true);

      // Typing a value back to what is saved is not a change.
      fireEvent.change(limit, { target: { value: '6' } });
      expect(bar).toHaveTextContent('1 unsaved change in Automation');

      fireEvent.click(within(bar).getByRole('button', { name: /Discard/ }));
      expect(screen.queryByTestId('save-bar')).not.toBeInTheDocument();
      expect(limit).toHaveValue(6);
      expect(screen.getByLabelText(/Blog automation/)).toBeChecked();
      const after = new Event('beforeunload', { cancelable: true });
      window.dispatchEvent(after);
      expect(after.defaultPrevented).toBe(false);
    });

    it('saves every section in one PUT and never re-sends a secret nobody typed', async () => {
      renderPage();
      fireEvent.change(await screen.findByLabelText(/Blogs per day/), { target: { value: '7' } });
      fireEvent.change(screen.getByLabelText(/^Voice$/), { target: { value: 'nova' } });
      const shotstack = screen.getByLabelText(/Shotstack API key/);
      fireEvent.change(shotstack, { target: { value: 'sk_live_typed' } });
      // Typed into a secret, then emptied again: nothing to send.
      const openai = screen.getByLabelText(/OpenAI API key/);
      fireEvent.change(openai, { target: { value: 'x' } });
      fireEvent.change(openai, { target: { value: '' } });

      const bar = screen.getByTestId('save-bar');
      expect(bar).toHaveTextContent('3 unsaved changes in Automation, Video');
      fireEvent.click(within(bar).getByRole('button', { name: /Save all changes/ }));
      await waitFor(() => expect(adminService.updateAutomationSettings).toHaveBeenCalledTimes(1));
      const body = adminService.updateAutomationSettings.mock.calls[0][0];
      expect(body).toEqual({
        'automation.blog.daily_limit': '7',
        'video.tts.voice': 'nova',
        'video.shotstack.api_key': 'sk_live_typed',
      });
      for (const key of ['ai.gemini.api_key', 'ai.openai.api_key', 'ai.perplexity.api_key', 'social.socialapi.api_key', 'video.youtube.client_secret']) {
        expect(body).not.toHaveProperty(key);
      }
      expect(await screen.findByText('Saved 3 changes.')).toBeInTheDocument();
      expect(screen.queryByTestId('save-bar')).not.toBeInTheDocument();
      expect(shotstack).toHaveValue('');
    });

    it('shows each live-check result inside its provider section', async () => {
      adminService.runLiveChecks.mockResolvedValue({
        ok: false, passed: 2, failed: 2, ran_at: '2026-10-18T09:00:00+00:00',
        results: [
          { target: 'gemini', ok: true, message: 'Gemini answered.', detail: {}, duration_ms: 3 },
          { target: 'shotstack', ok: false, message: 'Shotstack rejected the key (HTTP 403).', detail: { env: 'stage' }, duration_ms: 5 },
          { target: 'youtube', ok: false, message: 'YouTube OAuth client is not configured.', detail: {}, duration_ms: 1 },
          { target: 'social_api', ok: true, message: 'Key accepted.', detail: {}, duration_ms: 2 },
        ],
      });
      renderPage();
      fireEvent.click(await screen.findByRole('button', { name: /Run live checks/ }));
      await screen.findByText(/2 passed, 2 failed/);

      const video = screen.getByTestId('section-video');
      expect(within(video).getByTestId('live-result-shotstack')).toHaveTextContent('Shotstack rejected the key (HTTP 403).');
      expect(within(video).getByTestId('live-result-shotstack')).toHaveTextContent('FAIL');
      // Videos go through social-api.ai, so Reach's own YouTube OAuth failing is only informational.
      expect(within(video).getByTestId('live-result-youtube')).toHaveTextContent('only needed for direct upload');
      expect(within(screen.getByTestId('section-ai-providers')).getByTestId('live-result-gemini')).toHaveTextContent('Gemini answered.');
      expect(within(screen.getByTestId('section-social')).getByTestId('live-result-social_api')).toHaveTextContent('Key accepted.');
      expect(screen.getByTestId('nav-video').querySelector('[data-status]')).toHaveAttribute('data-status', 'error');
      expect(screen.getByTestId('summary-checks')).toHaveTextContent('2 OK · 2 FAIL');
    });

    it('runs the video checks from the Video section (settings.manage only)', async () => {
      adminService.runLiveChecks.mockResolvedValue({ ok: true, passed: 1, failed: 0, ran_at: '2026-10-18T09:00:00+00:00',
        results: [{ target: 'tts', ok: true, message: 'Voice sample generated.', detail: {}, duration_ms: 900 }] });
      renderPage();
      fireEvent.click(await screen.findByRole('button', { name: /Check video services/ }));
      await waitFor(() => expect(adminService.runLiveChecks).toHaveBeenCalledWith(['shotstack', 'youtube', 'tts']));
      expect(await within(screen.getByTestId('section-video')).findByText('Voice sample generated.')).toBeInTheDocument();
    });

    it('does not offer the video checks without settings.manage', async () => {
      permission.allowed = false;
      renderPage();
      await screen.findByTestId('section-video');
      expect(screen.queryByRole('button', { name: /Check video services/ })).not.toBeInTheDocument();
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

describe('automation settings model', () => {
  const sections = buildSections(FULL);

  it('turns registry groups into sections with stable ids', () => {
    expect(sections.map((s) => [s.name, s.id])).toEqual([
      ['AI providers', 'ai-providers'], ['Automation', 'automation'], ['Social', 'social'], ['Video', 'video'],
    ]);
    expect(buildSections([{ key: 'x.y', group: 'Brand new & shiny', label: 'X', type: 'string' }])[0].id).toBe('brand-new-shiny');
  });

  it('resolves hashes by id or by the group name', () => {
    const seo = buildSections([{ key: 'seo.tracker.enabled', group: 'SEO data', label: 'SEO', type: 'bool' }]);
    expect(resolveSectionId('#seo', seo)).toBe('seo');
    expect(resolveSectionId('#seo-data', seo)).toBe('seo');
    expect(resolveSectionId('#nope', seo)).toBeNull();
    expect(resolveSectionId('', seo)).toBeNull();
  });

  it('puts unmatched rows of a sub-grouped section under "More settings"', () => {
    const social = buildSections([...SOCIAL, { key: 'social.socialapi.future', group: 'Social', label: 'Future', type: 'string' }])[0];
    expect(subgroupsOf(social).map((g) => g.title)).toEqual([
      'Connection', 'YouTube publisher', 'Per-network accounts', 'Per-network platform_data & target options', 'More settings',
    ]);
  });

  it('matches every word of a search against label, key and help', () => {
    const row = FULL.find((r) => r.key === 'video.tts.voice');
    expect(matchesQuery(row, 'voice nova')).toBe(true);
    expect(matchesQuery(row, 'VIDEO.TTS')).toBe(true);
    expect(matchesQuery(row, 'voice shimmer')).toBe(false);
    expect(matchesQuery(row, '  ')).toBe(true);
  });

  it('only counts real changes and never includes an empty secret', () => {
    const secret = FULL.find((r) => r.key === 'ai.gemini.api_key');
    const bool = FULL.find((r) => r.key === 'automation.blog.enabled');
    expect(isDirty(secret, '')).toBe(false);
    expect(isDirty(secret, 'k')).toBe(true);
    expect(isDirty(bool, true)).toBe(false);
    expect(isDirty(bool, false)).toBe(true);
    expect(buildPayload(FULL, { 'ai.gemini.api_key': '', 'automation.blog.enabled': true, 'automation.blog.daily_limit': '6', 'video.tts.voice': 'ash' }))
      .toEqual({ 'video.tts.voice': 'ash' });
  });

  it('marks a section red only for live checks that matter', () => {
    const byKey = Object.fromEntries(FULL.map((r) => [r.key, r]));
    const video = sections.find((s) => s.id === 'video');
    expect(sectionStatus(video, byKey, { youtube: { ok: false } }).level).toBe('warning'); // Shotstack key missing
    expect(sectionStatus(video, byKey, { shotstack: { ok: false } }).level).toBe('error');
    const direct = { ...byKey, 'video.youtube.publisher': { ...byKey['video.youtube.publisher'], value: 'direct' } };
    expect(sectionStatus(video, direct, { youtube: { ok: false } }).level).toBe('error');
    expect(sectionStatus(video, direct, {}).reasons).toContain('YouTube OAuth client secret not set');
  });
});
