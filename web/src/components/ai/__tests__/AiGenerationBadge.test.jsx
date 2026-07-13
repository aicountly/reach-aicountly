import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import AiGenerationBadge from '../AiGenerationBadge.jsx';

describe('AiGenerationBadge', () => {
  it('renders completed status', () => {
    render(<AiGenerationBadge status="completed" />);
    expect(screen.getByText('Completed')).toBeTruthy();
  });

  it('renders failed status', () => {
    render(<AiGenerationBadge status="failed" />);
    expect(screen.getByText('Failed')).toBeTruthy();
  });

  it('renders unknown status as-is', () => {
    render(<AiGenerationBadge status="unknown_status" />);
    expect(screen.getByText('unknown_status')).toBeTruthy();
  });

  it('shows pulse indicator for active statuses', () => {
    const { container } = render(<AiGenerationBadge status="processing" />);
    expect(container.querySelector('.animate-pulse')).toBeTruthy();
  });

  it('does not show pulse for completed status', () => {
    const { container } = render(<AiGenerationBadge status="completed" />);
    expect(container.querySelector('.animate-pulse')).toBeNull();
  });
});
