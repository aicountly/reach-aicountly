import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import ValidationFindings from '../ValidationFindings.jsx';

const FINDINGS = [
  {
    id: 1,
    validator_type: 'title_length',
    status: 'failed',
    severity: 'high',
    title: 'Title too short',
    message: 'Title must be at least 10 characters.',
    affected_field: 'title',
    suggested_fix: 'Expand the title.',
    is_ai_assisted: false,
    details: { min: 10 },
  },
  {
    id: 2,
    validator_type: 'structured_output',
    status: 'passed',
    severity: 'info',
    title: 'Schema valid',
    message: 'Output matches schema.',
    is_ai_assisted: false,
    details: null,
  },
];

describe('ValidationFindings', () => {
  it('renders nothing when no findings or status', () => {
    const { container } = render(<ValidationFindings />);
    expect(container.firstChild).toBeNull();
  });

  it('shows failed finding title and message', () => {
    render(<ValidationFindings findings={FINDINGS} />);
    expect(screen.getByText('Title too short')).toBeTruthy();
    expect(screen.getByText(/Title must be at least/)).toBeTruthy();
  });

  it('shows suggested fix', () => {
    render(<ValidationFindings findings={FINDINGS} />);
    expect(screen.getByText(/Expand the title/)).toBeTruthy();
  });

  it('shows Details button for findings with details', () => {
    render(<ValidationFindings findings={FINDINGS} />);
    expect(screen.getByRole('button', { name: 'Details' })).toBeTruthy();
  });

  it('expands details on button click', () => {
    render(<ValidationFindings findings={FINDINGS} />);
    const btn = screen.getByRole('button', { name: 'Details' });
    fireEvent.click(btn);
    expect(screen.getByText(/min/)).toBeTruthy();
  });

  it('shows run status summary', () => {
    render(<ValidationFindings findings={FINDINGS} runStatus="completed" />);
    expect(screen.getByText('completed')).toBeTruthy();
  });

  it('shows Waive button when onWaive provided for failed finding', () => {
    const onWaive = vi.fn();
    render(<ValidationFindings findings={FINDINGS} onWaive={onWaive} />);
    expect(screen.getByRole('button', { name: 'Waive' })).toBeTruthy();
  });

  it('calls onWaive with the finding when waive button clicked', () => {
    const onWaive = vi.fn();
    render(<ValidationFindings findings={FINDINGS} onWaive={onWaive} />);
    fireEvent.click(screen.getByRole('button', { name: 'Waive' }));
    expect(onWaive).toHaveBeenCalledWith(FINDINGS[0]);
  });

  it('shows AI assisted badge for AI validator', () => {
    const aiFindings = [{ ...FINDINGS[0], is_ai_assisted: true }];
    render(<ValidationFindings findings={aiFindings} />);
    expect(screen.getByText('AI assisted')).toBeTruthy();
  });
});
