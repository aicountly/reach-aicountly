import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/knowledgeService', () => ({
  knowledgeService: {
    listPersonas: vi.fn(),
    deletePersona: vi.fn(),
  },
}));

import { knowledgeService } from '../../../services/knowledgeService';
import { PersonaListPage } from '../PersonaListPage';

const PERSONA = {
  id: 7,
  name: 'Finance Manager',
  slug: 'finance-manager',
  knowledge_status: 'approved',
  updated_at: '2026-07-01T00:00:00Z',
};

const auth = (permissions) => ({
  auth: { user: { id: 1, email: 'admin@aicountly.org', role: 'reach_admin' }, permissions },
});

beforeEach(() => {
  knowledgeService.listPersonas.mockReset();
  knowledgeService.deletePersona.mockReset();
  knowledgeService.listPersonas.mockResolvedValue({ items: [PERSONA], total: 1 });
  vi.spyOn(window, 'confirm').mockReturnValue(true);
});

afterEach(() => { vi.restoreAllMocks(); });

describe('Knowledge list delete', () => {
  it('hides the delete button without the manage permission', async () => {
    renderWithAuth(<PersonaListPage />, auth(['knowledge.view', 'persona.view']));

    await waitFor(() => expect(screen.getByText('Finance Manager')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
  });

  it('deletes the persona and reloads', async () => {
    knowledgeService.deletePersona.mockResolvedValue({ deleted: true });
    renderWithAuth(<PersonaListPage />, auth(['knowledge.view', 'persona.manage']));

    await waitFor(() => expect(screen.getByText('Finance Manager')).toBeInTheDocument());
    const callsBefore = knowledgeService.listPersonas.mock.calls.length;

    fireEvent.click(screen.getByRole('button', { name: /delete/i }));

    await waitFor(() => {
      expect(knowledgeService.deletePersona).toHaveBeenCalledWith(7);
      expect(knowledgeService.listPersonas.mock.calls.length).toBeGreaterThan(callsBefore);
    });
  });

  it('leaves the row and shows the error when the API refuses', async () => {
    knowledgeService.deletePersona.mockRejectedValue(new Error('Persona is referenced by 3 content items.'));
    renderWithAuth(<PersonaListPage />, auth(['knowledge.view', 'persona.manage']));

    await waitFor(() => expect(screen.getByText('Finance Manager')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /delete/i }));

    await waitFor(() => expect(screen.getByText(/referenced by 3 content items/i)).toBeInTheDocument());
    expect(screen.getByText('Finance Manager')).toBeInTheDocument();
  });

  it('does nothing when the confirm dialog is dismissed', async () => {
    window.confirm.mockReturnValue(false);
    renderWithAuth(<PersonaListPage />, auth(['knowledge.view', 'persona.manage']));

    await waitFor(() => expect(screen.getByText('Finance Manager')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /delete/i }));

    expect(knowledgeService.deletePersona).not.toHaveBeenCalled();
  });
});
