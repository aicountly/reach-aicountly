import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { render } from '@testing-library/react';
import { Breadcrumbs } from '../Breadcrumbs';

describe('Breadcrumbs', () => {
  it('renders linked items and current page without link', () => {
    render(
      <MemoryRouter>
        <Breadcrumbs
          items={[
            { label: 'Blog Command Centre', to: '/blog-command-centre' },
            { label: 'Roadmap', to: '/blog-command-centre/roadmap' },
            { label: 'Candidates' },
          ]}
        />
      </MemoryRouter>,
    );
    expect(screen.getByRole('navigation', { name: 'Breadcrumb' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Blog Command Centre' })).toHaveAttribute('href', '/blog-command-centre');
    expect(screen.getByRole('link', { name: 'Roadmap' })).toHaveAttribute('href', '/blog-command-centre/roadmap');
    expect(screen.getByText('Candidates')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Candidates' })).not.toBeInTheDocument();
  });

  it('renders nothing when items empty', () => {
    const { container } = render(<Breadcrumbs items={[]} />);
    expect(container.firstChild).toBeNull();
  });
});
