import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { VersionDiff } from '../../../components/content/VersionDiff';

const ctx = {
  auth: {
    user: { id: 1, email: 'reviewer@test.com', role: 'content_reviewer' },
    permissions: ['content_version.view'],
  },
};

describe('VersionDiff', () => {
  it('renders version numbers', () => {
    const vA = { id: 1, version_number: 1, body_plain_text: 'Original text.', created_at: '2026-07-10T09:00:00Z' };
    const vB = { id: 2, version_number: 2, body_plain_text: 'Revised text.', created_at: '2026-07-11T09:00:00Z' };
    renderWithAuth(<VersionDiff versionA={vA} versionB={vB} />, ctx);
    expect(screen.getByText(/v1/i)).toBeInTheDocument();
    expect(screen.getByText(/v2/i)).toBeInTheDocument();
  });

  it('renders nothing when no versions selected', () => {
    const { container } = renderWithAuth(<VersionDiff />, ctx);
    // Component returns null when no versions given
    expect(container.firstChild).toBeNull();
  });
});
