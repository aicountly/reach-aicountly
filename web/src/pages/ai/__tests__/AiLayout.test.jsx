import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import AiLayout from '../AiLayout';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@test.com', role: 'admin' },
    permissions: ['ai.view', 'ai.generate', 'ai_provider.manage', 'ai_prompt.view', 'ai_prompt.approve'],
  },
};

describe('AiLayout', () => {
  it('renders AI Control Centre header', () => {
    renderWithAuth(<AiLayout />, ctx);
    expect(screen.getByText('AI Control Centre')).toBeInTheDocument();
  });

  it('shows Phase 3 badge', () => {
    renderWithAuth(<AiLayout />, ctx);
    expect(screen.getByText('Phase 3')).toBeInTheDocument();
  });

  it('shows navigation links for permitted user', () => {
    renderWithAuth(<AiLayout />, ctx);
    expect(screen.getByText('Dashboard')).toBeInTheDocument();
    expect(screen.getByText('Generations')).toBeInTheDocument();
  });
});
