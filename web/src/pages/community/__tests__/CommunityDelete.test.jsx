import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), delete: vi.fn() },
}));
import api from '../../../services/api';
import QuestionInboxPage from '../QuestionInboxPage';
import OfficialAnswerListPage from '../OfficialAnswerListPage';

const question = {
  id: 1,
  uuid: 'q-uuid-1',
  title: 'How to file GST?',
  status: 'new',
  triage_score: 45,
  space_slug: 'gst',
};

const answer = (status = 'draft_generated') => ({
  id: 7,
  uuid: 'a-uuid-7',
  status,
  risk_classification: 'low',
  ai_assisted: true,
  human_reviewed: false,
  updated_at: '2026-07-11T10:00:00Z',
});

const withPerms = (permissions) => ({
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions,
  },
});

beforeEach(() => {
  api.get.mockReset();
  api.delete.mockReset();
  vi.spyOn(window, 'confirm').mockReturnValue(true);
});

afterEach(() => { vi.restoreAllMocks(); });

describe('QuestionInboxPage delete', () => {
  it('deletes a question with its answers after confirmation', async () => {
    api.get.mockResolvedValue({ data: { data: [question], meta: { last_page: 1 } } });
    api.delete.mockResolvedValue({ data: { deleted: true } });

    renderWithAuth(<QuestionInboxPage />, withPerms(['community.view', 'community_question.moderate']));
    await waitFor(() => expect(screen.getByText('How to file GST?')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /delete/i }));

    await waitFor(() => expect(api.delete).toHaveBeenCalledWith(
      'v1/community/questions/q-uuid-1',
      { with_answers: true },
    ));
  });

  it('hides the delete action without the moderate permission', async () => {
    api.get.mockResolvedValue({ data: { data: [question], meta: { last_page: 1 } } });

    renderWithAuth(<QuestionInboxPage />, withPerms(['community.view']));
    await waitFor(() => expect(screen.getByText('How to file GST?')).toBeInTheDocument());

    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
  });

  it('surfaces the API error when the delete fails', async () => {
    api.get.mockResolvedValue({ data: { data: [question], meta: { last_page: 1 } } });
    api.delete.mockRejectedValue(new Error('question is referenced'));

    renderWithAuth(<QuestionInboxPage />, withPerms(['community.view', 'community_question.moderate']));
    await waitFor(() => expect(screen.getByText('How to file GST?')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /delete/i }));
    await waitFor(() => expect(screen.getByText(/question is referenced/i)).toBeInTheDocument());
  });
});

describe('OfficialAnswerListPage delete', () => {
  it('deletes an answer after confirmation', async () => {
    api.get.mockResolvedValue({ data: { data: [answer()], meta: { last_page: 1 } } });
    api.delete.mockResolvedValue({ data: { deleted: true } });

    renderWithAuth(<OfficialAnswerListPage />, withPerms(['community.view', 'community_answer.withdraw']));
    await waitFor(() => expect(screen.getByText('draft_generated')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /delete/i }));

    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('v1/community/answers/a-uuid-7'));
  });

  it('disables delete for a published answer', async () => {
    api.get.mockResolvedValue({ data: { data: [answer('published')], meta: { last_page: 1 } } });

    renderWithAuth(<OfficialAnswerListPage />, withPerms(['community.view', 'community_answer.withdraw']));
    await waitFor(() => expect(screen.getByText('published')).toBeInTheDocument());

    expect(screen.getByRole('button', { name: /delete/i })).toBeDisabled();
  });
});
