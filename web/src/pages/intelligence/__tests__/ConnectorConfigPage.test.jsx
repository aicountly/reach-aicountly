import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithAuth } from '../../../test/renderWithAuth';
import ConnectorConfigPage from '../ConnectorConfigPage';

vi.mock('../../../services/intelligenceService.js', () => ({
  listConnectors: vi.fn(async () => []),
  upsertConnector: vi.fn(async () => ({ id: 1, provider: 'gsc' })),
  healthCheckConnector: vi.fn(async () => ({ status: 'healthy' })),
  disableConnector: vi.fn(async () => ({ message: 'disabled' })),
  enableConnector: vi.fn(async () => ({ enabled: true })),
}));

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['connector.read', 'connector.manage'],
  },
};

describe('ConnectorConfigPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('opens a configure dialog when Configure is clicked', async () => {
    const user = userEvent.setup();
    renderWithAuth(<ConnectorConfigPage />, ctx);

    await waitFor(() => {
      expect(screen.getByText('Google Search Console')).toBeInTheDocument();
    });

    const buttons = screen.getAllByRole('button', { name: 'Configure' });
    expect(buttons.length).toBe(3);
    expect(buttons[0]).not.toBeDisabled();

    await user.click(buttons[0]);

    expect(await screen.findByRole('dialog')).toBeInTheDocument();
    expect(screen.getByText('Configure Google Search Console')).toBeInTheDocument();
    expect(screen.getByLabelText(/Credential env reference/i)).toBeInTheDocument();
  });
});
