import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import AiProvidersPage from '../AiProvidersPage';

vi.mock('../../../services/aiService.js', () => ({
  listAiProviders: vi.fn(),
  default: {},
}));

import { listAiProviders } from '../../../services/aiService.js';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'admin' },
    permissions: ['ai_provider.manage'],
  },
};

beforeEach(() => {
  vi.clearAllMocks();
  listAiProviders.mockResolvedValue({
    providers: [
      { id: 1, provider_key: 'openai', display_name: 'OpenAI', status: 'enabled', configuration_status: 'configured', last_health_status: 'healthy' },
      { id: 2, provider_key: 'mock', display_name: 'Mock Provider', status: 'enabled', configuration_status: 'configured', last_health_status: 'healthy' },
    ],
    total: 2,
  });
});

describe('AiProvidersPage', () => {
  it('renders providers table', async () => {
    renderWithAuth(<AiProvidersPage />, ctx);
    await waitFor(() => expect(screen.getByText('openai')).toBeInTheDocument());
    expect(screen.getByText('OpenAI')).toBeInTheDocument();
  });

  it('shows API key security notice', async () => {
    renderWithAuth(<AiProvidersPage />, ctx);
    await waitFor(() => expect(screen.getByText(/environment variables/i)).toBeInTheDocument());
  });

  it('renders View links', async () => {
    renderWithAuth(<AiProvidersPage />, ctx);
    await waitFor(() => {
      const links = screen.getAllByText('View');
      expect(links.length).toBe(2);
    });
  });
});
