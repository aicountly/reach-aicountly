import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';

const commands = vi.fn();
const sendCommand = vi.fn();
let keyCounter = 0;
vi.mock('../../../services/botActionsService', () => ({
  newIdempotencyKey: (p) => `${p}-key-${++keyCounter}`,
  botActionsService: {
    commands: (...a) => commands(...a),
    sendCommand: (...a) => sendCommand(...a),
  },
}));

import { BotAskPage } from '../BotAskPage';

const EXAMPLE = 'Post a 30-second soft-launch reel announcing AICOUNTLY on all connected social platforms today.';
const channels = [
  { channel: 'youtube', label: 'YouTube', ready: true, account: 'AICOUNTLY', reason: null },
  { channel: 'instagram', label: 'Instagram', ready: true, account: 'aicountly', reason: null },
  { channel: 'linkedin', label: 'LinkedIn', ready: false, account: null, reason: 'No active LinkedIn account is connected in social-api.ai.' },
];
const base = { configured: true, configuration: { configured: true, problems: [] }, setup: [], channels, examples: [EXAMPLE], limits: { instruction_chars: 4000 }, commands: [] };

describe('BotAskPage', () => {
  beforeEach(() => { commands.mockReset(); sendCommand.mockReset(); keyCounter = 0; });

  it('explains the setup and disables sending when the routine is not configured', async () => {
    commands.mockResolvedValue({ ...base, configured: false, configuration: { configured: false, problems: ['bot.command.routine_fire_url is not set.'] }, setup: ['In claude.ai → Code → Routines, create a routine named "reach-command".', 'Open the routine → Add another trigger → API.'] });
    renderWithAuth(<BotAskPage />, { auth: { user: { id: 1 }, permissions: ['*'] } });

    expect(await screen.findByText(/not set up yet/)).toBeInTheDocument();
    expect(screen.getByText(/create a routine named "reach-command"/)).toBeInTheDocument();
    expect(screen.getByText(/routine_fire_url is not set/)).toBeInTheDocument();
    await userEvent.setup().type(screen.getByLabelText(/What should the bot do/), 'Do something now');
    expect(screen.getByRole('button', { name: /Send to the bot/ })).toBeDisabled();
  });

  it('sends the example with the connected channels and one idempotency key per draft', async () => {
    commands.mockResolvedValue(base);
    sendCommand.mockResolvedValue({ replayed: false, command: { uuid: 'c1', status: 'submitted', session_url: 'https://claude.ai/code/session_01XYZ' } });
    const user = userEvent.setup();
    renderWithAuth(<BotAskPage />, { auth: { user: { id: 1 }, permissions: ['bot.command'] } });

    await user.click(await screen.findByRole('button', { name: new RegExp('soft-launch reel') }));
    expect(screen.getByLabelText(/What should the bot do/)).toHaveValue(EXAMPLE);
    expect(screen.getByRole('checkbox', { name: /YouTube/ })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: /Instagram/ })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: /LinkedIn/ })).toBeDisabled();
    expect(screen.getByText(/not connected: No active LinkedIn account/)).toBeInTheDocument();

    await user.type(screen.getByLabelText(/Attachments/), 'https://aicountly.com/logo.png');
    await user.click(screen.getByRole('button', { name: /Send to the bot/ }));

    expect(sendCommand).toHaveBeenCalledWith({
      instruction: EXAMPLE,
      channels: ['youtube', 'instagram'],
      attachments: ['https://aicountly.com/logo.png'],
      idempotencyKey: 'cmd-key-1',
    });
    expect(await screen.findByText(/Sent\. The bot is working on it/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open the session' })).toHaveAttribute('href', 'https://claude.ai/code/session_01XYZ');
    await waitFor(() => expect(commands).toHaveBeenCalledTimes(2));
  });

  it('shows history with session links, honest statuses, the report and live artifacts', async () => {
    commands.mockResolvedValue({
      ...base,
      commands: [
        {
          uuid: 'c2', kind: 'command', instruction: EXAMPLE, channels: ['youtube', 'instagram'], status: 'completed', created_at: '2026-10-18T10:00:00Z', created_by: 'Owner',
          session_url: 'https://claude.ai/code/session_A', report_summary: 'Submitted the soft-launch Short; Reach approved it.', report_links: [{ label: 'Video status', url: 'https://reach.aicountly.org/api/v1/automation/videos/v1' }],
          artifacts: [
            { type: 'video', ref: 'v1', title: 'AICOUNTLY is here', format: 'short', status: 'in_progress', step: 'render_poll', link: '/video' },
            { type: 'social_post', ref: '9', channel: 'instagram', status: 'manual_queue', error: null, link: '/social/queue' },
          ],
        },
        { uuid: 'c3', kind: 'command', instruction: 'Another', channels: [], status: 'failed', fire_error: 'The routine token was refused (401).', created_at: '2026-10-18T09:00:00Z', artifacts: [] },
        { uuid: 'c4', kind: 'command', instruction: 'Third', channels: [], status: 'outcome_unknown', fire_error: 'A session may or may not have started — check claude.ai.', created_at: '2026-10-18T08:00:00Z', artifacts: [] },
      ],
    });
    renderWithAuth(<BotAskPage />, { auth: { user: { id: 1 }, permissions: ['bot.view'] } });

    const done = await screen.findByTestId('command-c2');
    expect(within(done).getByText('COMPLETED')).toBeInTheDocument();
    expect(within(done).getByRole('link', { name: 'Claude session' })).toHaveAttribute('href', 'https://claude.ai/code/session_A');
    expect(within(done).getByText(/Reach approved it/)).toBeInTheDocument();
    expect(within(done).getByRole('link', { name: 'Video status' })).toBeInTheDocument();
    expect(within(done).getByText('in_progress')).toBeInTheDocument();
    expect(within(done).getByText('manual_queue')).toBeInTheDocument();

    expect(within(screen.getByTestId('command-c3')).getByText(/token was refused/)).toBeInTheDocument();
    expect(within(screen.getByTestId('command-c4')).getByText('OUTCOME UNKNOWN')).toBeInTheDocument();
    // No bot.command permission: history only, no form.
    expect(screen.queryByRole('button', { name: /Send to the bot/ })).not.toBeInTheDocument();
  });

  it('keeps the draft key when a send fails before reaching the server, so a retry cannot double-fire', async () => {
    commands.mockResolvedValue(base);
    sendCommand.mockRejectedValueOnce(Object.assign(new Error('Network error contacting Reach API.'), { status: undefined }));
    sendCommand.mockResolvedValueOnce({ replayed: true, command: { uuid: 'c1', status: 'submitted', session_url: null } });
    const user = userEvent.setup();
    renderWithAuth(<BotAskPage />, { auth: { user: { id: 1 }, permissions: ['*'] } });

    await user.type(await screen.findByLabelText(/What should the bot do/), 'Run the blog routine now');
    await user.click(screen.getByRole('button', { name: /Send to the bot/ }));
    expect(await screen.findByText(/Network error/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Send to the bot/ }));
    await waitFor(() => expect(sendCommand).toHaveBeenCalledTimes(2));
    expect(sendCommand.mock.calls[0][0].idempotencyKey).toBe(sendCommand.mock.calls[1][0].idempotencyKey);
  });
});
