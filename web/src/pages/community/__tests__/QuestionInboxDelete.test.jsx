import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn(), delete: vi.fn() },
}));
import api from '../../../services/api';
import QuestionInboxPage from '../QuestionInboxPage';

const QUESTION = {
  id: 1,
  external_id: 'q-uuid-1',
  title: 'How to file GST?',
  status: 'new',
  space_slug: 'gst',
};

const auth = (permissions) => ({
  auth: { user: { id: 1, email: 'admin@aicountly.com', role: 'reach_admin' }, permissions },
});

beforeEach(() => {
  api.get.mockReset();
  api.delete.mockReset();
  api.get.mockResolvedValue({ data: { data: [QUESTION], meta: { last_page: 1 } } });
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  vi.spyOn(window, 'alert').mockImplementation(() => {});
});

afterEach(() => { vi.restoreAllMocks(); });

describe('QuestionInboxPage delete', () => {
  it('hides the delete button without community_question.delete', async () => {
    renderWithAuth(<QuestionInboxPage />, auth(['community.view']));

    await waitFor(() => expect(screen.getByText('How to file GST?')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
  });

  it('deletes the question by uuid and reloads the inbox', async () => {
    api.delete.mockResolvedValue({ deleted: true, answers_deleted: 2 });
    renderWithAuth(<QuestionInboxPage />, auth(['community.view', 'community_question.delete']));

    await waitFor(() => expect(screen.getByText('How to file GST?')).toBeInTheDocument());
    const callsBefore = api.get.mock.calls.length;

    fireEvent.click(screen.getByRole('button', { name: /delete/i }));

    await waitFor(() => {
      expect(api.delete).toHaveBeenCalledWith(
        'v1/community/questions/q-uuid-1',
        expect.objectContaining({ force: false }),
      );
      expect(api.get.mock.calls.length).toBeGreaterThan(callsBefore);
    });
  });

  it('reports the failure instead of pretending the question is gone', async () => {
    api.delete.mockRejectedValue(new Error('Question is locked by moderation.'));
    renderWithAuth(<QuestionInboxPage />, auth(['community.view', 'community_question.delete']));

    await waitFor(() => expect(screen.getByText('How to file GST?')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /delete/i }));

    await waitFor(() => expect(window.alert).toHaveBeenCalledWith('Question is locked by moderation.'));
    expect(api.delete).toHaveBeenCalledTimes(1);
  });
});
