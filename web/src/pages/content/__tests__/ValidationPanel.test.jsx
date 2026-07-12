import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { ValidationPanel } from '../../../components/content/ValidationPanel';

const ctx = {
  auth: {
    user: { id: 1, email: 'reviewer@test.com', role: 'content_reviewer' },
    permissions: ['content_validation.view', 'content_validation.waive'],
  },
};

const validations = [
  { id: 1, validation_type: 'seo', validation_status: 'passed', score: 90, message: 'SEO looks good' },
  { id: 2, validation_type: 'brand', validation_status: 'failed', score: 40, message: 'Brand tone mismatch' },
];

describe('ValidationPanel', () => {
  it('renders validation results', () => {
    renderWithAuth(<ValidationPanel validations={validations} onWaive={vi.fn()} canWaive />, ctx);
    const seoEls = screen.getAllByText(/seo/i);
    expect(seoEls.length).toBeGreaterThan(0);
    const brandEls = screen.getAllByText(/brand/i);
    expect(brandEls.length).toBeGreaterThan(0);
  });

  it('renders empty state when no validations', () => {
    renderWithAuth(<ValidationPanel validations={[]} onWaive={vi.fn()} />, ctx);
    expect(screen.getByText(/no validation/i)).toBeInTheDocument();
  });
});
