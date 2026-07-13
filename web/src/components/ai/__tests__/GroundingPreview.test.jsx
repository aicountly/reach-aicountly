import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import GroundingPreview from '../GroundingPreview.jsx';

const CONTEXT = {
  product: { id: 1, name: 'Aicountly', tagline: 'Smart accounting software' },
  features: [
    { id: 10, slug: 'invoicing', name: 'Invoicing', availability: 'available' },
    { id: 11, slug: 'reports', name: 'Reports', availability: 'beta' },
  ],
  claims: [
    { id: 1, claim_text: 'Save 4 hours per week on bookkeeping' },
  ],
  brand_rules: [
    { id: 1, rule_type: 'forbidden_phrase', rule_value: 'spam' },
  ],
  content_policies: [],
  __token_estimate: 1200,
  __conflicts: [],
};

describe('GroundingPreview', () => {
  it('shows "No grounding context" when context is null', () => {
    render(<GroundingPreview groundingContext={null} />);
    expect(screen.getByText(/No grounding context/i)).toBeTruthy();
  });

  it('shows product name in overview', () => {
    render(<GroundingPreview groundingContext={CONTEXT} />);
    expect(screen.getByText('Aicountly')).toBeTruthy();
  });

  it('shows token estimate', () => {
    render(<GroundingPreview groundingContext={CONTEXT} />);
    expect(screen.getByText(/1,200 tokens/)).toBeTruthy();
  });

  it('switches to features tab', () => {
    render(<GroundingPreview groundingContext={CONTEXT} />);
    fireEvent.click(screen.getByText(/Features \(2\)/));
    expect(screen.getByText('Invoicing')).toBeTruthy();
    expect(screen.getByText('Reports')).toBeTruthy();
  });

  it('switches to claims tab', () => {
    render(<GroundingPreview groundingContext={CONTEXT} />);
    fireEvent.click(screen.getByText(/Claims \(1\)/));
    expect(screen.getByText(/Save 4 hours/)).toBeTruthy();
  });

  it('shows conflict warning when conflicts present', () => {
    const ctxWithConflicts = { ...CONTEXT, __conflicts: [{ type: 'claim_conflict', message: 'Conflicting claims' }] };
    render(<GroundingPreview groundingContext={ctxWithConflicts} />);
    expect(screen.getByText(/1 conflict/)).toBeTruthy();
  });
});
