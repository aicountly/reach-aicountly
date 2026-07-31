function parseBool(value, defaultValue) {
  if (value === undefined || value === null || value === '') return defaultValue;
  const normalized = String(value).trim().toLowerCase();
  if (['1', 'true', 'yes', 'on'].includes(normalized)) return true;
  if (['0', 'false', 'no', 'off'].includes(normalized)) return false;
  return defaultValue;
}

export const blogFeatureFlags = {
  commandCentreEnabled: parseBool(import.meta.env.VITE_BLOG_COMMAND_CENTRE_ENABLED, true),
  legacyCreateDisabled: parseBool(import.meta.env.VITE_BLOG_LEGACY_CREATE_DISABLED, true),
  legacyRedirectEnabled: parseBool(import.meta.env.VITE_BLOG_LEGACY_REDIRECT_ENABLED, true),
  automationEnabled: parseBool(import.meta.env.VITE_BLOG_AUTOMATION_ENABLED, false),
  dbBodyFallbackEnabled: parseBool(import.meta.env.VITE_BLOG_DB_BODY_FALLBACK_ENABLED, true),
};

export function isBlogCommandCentreEnabled() {
  return blogFeatureFlags.commandCentreEnabled;
}

export function isBlogLegacyCreateDisabled() {
  return blogFeatureFlags.legacyCreateDisabled;
}

export function isBlogLegacyRedirectEnabled() {
  return blogFeatureFlags.legacyRedirectEnabled;
}

export function isBlogAutomationEnabled() {
  return blogFeatureFlags.automationEnabled;
}

export function isBlogDbBodyFallbackEnabled() {
  return blogFeatureFlags.dbBodyFallbackEnabled;
}
