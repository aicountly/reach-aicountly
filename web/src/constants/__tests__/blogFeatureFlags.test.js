import { describe, it, expect } from 'vitest';
import {
  blogFeatureFlags,
  isBlogCommandCentreEnabled,
  isBlogLegacyCreateDisabled,
  isBlogLegacyRedirectEnabled,
  isBlogAutomationEnabled,
  isBlogDbBodyFallbackEnabled,
} from '../blogFeatureFlags';

describe('blogFeatureFlags', () => {
  it('exports helper functions that mirror blogFeatureFlags object', () => {
    expect(isBlogCommandCentreEnabled()).toBe(blogFeatureFlags.commandCentreEnabled);
    expect(isBlogLegacyCreateDisabled()).toBe(blogFeatureFlags.legacyCreateDisabled);
    expect(isBlogLegacyRedirectEnabled()).toBe(blogFeatureFlags.legacyRedirectEnabled);
    expect(isBlogAutomationEnabled()).toBe(blogFeatureFlags.automationEnabled);
    expect(isBlogDbBodyFallbackEnabled()).toBe(blogFeatureFlags.dbBodyFallbackEnabled);
  });

  it('exports expected flag keys with boolean values', () => {
    expect(blogFeatureFlags).toMatchObject({
      commandCentreEnabled: expect.any(Boolean),
      legacyCreateDisabled: expect.any(Boolean),
      legacyRedirectEnabled: expect.any(Boolean),
      automationEnabled: expect.any(Boolean),
      dbBodyFallbackEnabled: expect.any(Boolean),
    });
  });

  it('defaults automation to false when env unset', () => {
    expect(blogFeatureFlags.automationEnabled).toBe(false);
  });
});
