import { describe, it, expect, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { ContentVersionsPage } from '../ContentVersionsPage';

vi.mock('../../../services/contentService', () => ({
  contentService: {
    listVersions: vi.fn().mockResolvedValue({ versions: [] }),
    compareVersions: vi.fn().mockResolvedValue({ version_a: null, version_b: null, fields_changed: [] }),
  },
}));

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual('react-router-dom');
  return {
    ...actual,
    useParams: () => ({ id: '1' }),
  };
});

const ctx = {
  auth: {
    user: { id: 1, email: 'reviewer@test.com', role: 'content_reviewer' },
    permissions: ['content.view', 'content_version.view'],
  },
};

describe('ContentVersionsPage', () => {
  it('renders Version History heading', async () => {
    renderWithAuth(<ContentVersionsPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Version History/i)).toBeInTheDocument());
  });

  it('shows All Versions section', async () => {
    renderWithAuth(<ContentVersionsPage />, ctx);
    await waitFor(() => expect(screen.getByText(/All Versions/i)).toBeInTheDocument());
  });
});
