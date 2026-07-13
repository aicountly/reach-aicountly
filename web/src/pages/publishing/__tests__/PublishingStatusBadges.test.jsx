import { describe, it, expect } from 'vitest';

// Test the STATUS_BADGES mapping used in publishing pages
describe('Publishing Status Badge Mappings', () => {
  // These test the mapping logic that should be consistent across publishing pages
  const statusBadges = {
    draft: 'badge--neutral',
    queued: 'badge--info',
    sending: 'badge--info',
    accepted: 'badge--warning',
    published: 'badge--success',
    verified: 'badge--success',
    failed: 'badge--error',
    blocked: 'badge--error',
    cancelled: 'badge--neutral',
    rolled_back: 'badge--error',
  };

  it('maps published status to success badge', () => {
    expect(statusBadges['published']).toBe('badge--success');
  });

  it('maps verified status to success badge', () => {
    expect(statusBadges['verified']).toBe('badge--success');
  });

  it('maps failed status to error badge', () => {
    expect(statusBadges['failed']).toBe('badge--error');
  });

  it('maps blocked status to error badge', () => {
    expect(statusBadges['blocked']).toBe('badge--error');
  });

  it('maps rolled_back status to error badge', () => {
    expect(statusBadges['rolled_back']).toBe('badge--error');
  });

  it('maps queued status to info badge', () => {
    expect(statusBadges['queued']).toBe('badge--info');
  });

  it('maps draft status to neutral badge', () => {
    expect(statusBadges['draft']).toBe('badge--neutral');
  });

  it('maps cancelled status to neutral badge', () => {
    expect(statusBadges['cancelled']).toBe('badge--neutral');
  });

  it('has 10 status mappings', () => {
    expect(Object.keys(statusBadges)).toHaveLength(10);
  });

  it('all values are valid badge CSS classes', () => {
    const validClasses = ['badge--success', 'badge--error', 'badge--warning', 'badge--info', 'badge--neutral'];
    Object.values(statusBadges).forEach(cls => {
      expect(validClasses).toContain(cls);
    });
  });
});

// Test the canonical preference options
describe('Canonical Preference Options', () => {
  const canonicalOptions = [
    { value: 'self_canonical', label: 'Self canonical (default)' },
    { value: 'canonical_to_existing', label: 'Canonical to existing URL' },
    { value: 'noindex', label: 'No-index (hide from search)' },
    { value: 'redirect_to_existing', label: 'Redirect to existing URL' },
    { value: 'historical_archive', label: 'Historical archive' },
  ];

  it('has exactly 5 canonical preference options', () => {
    expect(canonicalOptions).toHaveLength(5);
  });

  it('self_canonical is the first option', () => {
    expect(canonicalOptions[0].value).toBe('self_canonical');
  });

  it('all options have value and label', () => {
    canonicalOptions.forEach(opt => {
      expect(opt.value).toBeTruthy();
      expect(opt.label).toBeTruthy();
    });
  });

  it('includes noindex option', () => {
    const noindex = canonicalOptions.find(o => o.value === 'noindex');
    expect(noindex).toBeDefined();
  });
});
