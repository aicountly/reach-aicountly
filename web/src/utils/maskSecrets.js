/**
 * Phase 3 — Frontend secret masking utility.
 *
 * Strips known sensitive patterns from strings before they are
 * displayed in the UI or logged. This is a defence-in-depth
 * control for the rare case where a backend response inadvertently
 * includes sensitive data.
 *
 * Provider API keys are NEVER stored in the database or returned by
 * the API, so this is a secondary safety net only.
 */

const SECRET_PATTERNS = [
  // OpenAI API key
  [/sk-[A-Za-z0-9]{48}/g, '[OPENAI_KEY]'],
  // Bearer tokens / JWTs
  [/Bearer\s+[A-Za-z0-9_\-.]{20,}/g, 'Bearer [TOKEN]'],
  [/eyJ[A-Za-z0-9_\-.]{20,}\.[A-Za-z0-9_\-.]{20,}\.[A-Za-z0-9_\-.]{20,}/g, '[JWT]'],
  // AWS access keys
  [/AKIA[0-9A-Z]{16}/g, '[AWS_KEY]'],
  // Generic API key patterns
  [/(api[_-]?key|apikey|client_secret)\s*[:=]\s*["']?[A-Za-z0-9_\-]{16,}["']?/gi, '$1: [REDACTED]'],
  // Private key blocks
  [/-----BEGIN\s+(?:RSA\s+)?PRIVATE\s+KEY-----[\s\S]*?-----END\s+(?:RSA\s+)?PRIVATE\s+KEY-----/g, '[PRIVATE_KEY]'],
];

/**
 * Replace known secret patterns in a string.
 * @param {string} text
 * @returns {string}
 */
export function maskSecrets(text) {
  if (typeof text !== 'string') return text;
  let result = text;
  for (const [pattern, replacement] of SECRET_PATTERNS) {
    result = result.replace(pattern, replacement);
  }
  return result;
}

/**
 * Recursively mask secrets in an object's string values.
 * @param {unknown} value
 * @returns {unknown}
 */
export function maskSecretsDeep(value) {
  if (typeof value === 'string') return maskSecrets(value);
  if (Array.isArray(value)) return value.map(maskSecretsDeep);
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value).map(([k, v]) => [k, maskSecretsDeep(v)])
    );
  }
  return value;
}

export default maskSecrets;
