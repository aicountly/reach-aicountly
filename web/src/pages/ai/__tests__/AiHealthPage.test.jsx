import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import AiHealthPage from '../AiHealthPage';

vi.mock('../../../services/aiService.js', () => ({
  getAiHealth: vi.fn(),
  default: {},
}));

import { getAiHealth } from '../../../services/aiService.js';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'admin' },
    permissions: ['ai_provider.manage'],
  },
};

beforeEach(() => {
  vi.clearAllMocks();
  getAiHealth.mockResolvedValue({
    overall_status: 'healthy',
    providers: [
      { provider_key: 'mock', healthy: true, response_time_ms: 12 },
    ],
    checked_at: '2026-07-13T06:00:00Z',
  });
});

describe('AiHealthPage', () => {
  it('shows overall healthy status', async () => {
    renderWithAuth(<AiHealthPage />, ctx);
    await waitFor(() => expect(screen.getByText(/System Status: healthy/i)).toBeInTheDocument());
  });

  it('renders provider health entry', async () => {
    renderWithAuth(<AiHealthPage />, ctx);
    await waitFor(() => expect(screen.getByText('mock')).toBeInTheDocument());
    expect(screen.getByText('Healthy')).toBeInTheDocument();
  });
});
