/**
 * Pure helpers behind Settings → AI & Automation: how the API's flat list of
 * settings (GET v1/settings/automation) becomes navigable sections, which
 * fields belong together inside a section, what each section's status is, and
 * which edits are real changes worth sending back.
 *
 * Secrets are write-only: the API returns `value: null` plus `is_set` /
 * `source`, so the only secret value that can ever be sent is one somebody
 * typed into the (empty) field on this page.
 */
import {
  Bot, Eye, FileBarChart, Image, KeyRound, MessageCircle, Newspaper, Send, Settings2, Share2, ShieldCheck, TrendingUp, Video,
} from 'lucide-react';
import { jsonObjectError } from '../../../utils/jsonObject';
import { formatDateTime } from '../../../utils/formatDate';

/** Live-check target (POST v1/settings/automation/live-check) → display name. */
export const CHECK_LABEL = {
  gemini: 'Gemini',
  openai: 'OpenAI',
  dataforseo: 'DataForSEO',
  shotstack: 'Shotstack',
  youtube: 'YouTube',
  tts: 'OpenAI TTS',
  social_api: 'social-api.ai',
};

/**
 * Registry group (ReachConfig `group`) → section. `checks` are the live checks
 * whose results belong next to the section (and turn its dot red on failure);
 * `tests` are the per-provider "Test …" buttons (POST …/automation/test).
 */
export const SECTION_META = {
  'AI providers': {
    id: 'ai-providers', icon: KeyRound, checks: ['gemini', 'openai'], tests: ['gemini', 'openai', 'perplexity'],
    description: 'API keys for Gemini, OpenAI and Perplexity — vetting, image generation and AI-visibility checks use them.',
  },
  'AI vetting': {
    id: 'ai-vetting', icon: ShieldCheck,
    description: 'The model that makes the final publish decision for each content type. Its decision is final.',
  },
  'AI visibility & insights': {
    id: 'ai-visibility', icon: Eye,
    description: 'Daily checks of how AI assistants mention AICOUNTLY, and the weekly AI Insights report.',
  },
  Images: {
    id: 'images', icon: Image,
    description: 'The OpenAI image model and output settings used for generated covers.',
  },
  Automation: {
    id: 'automation', icon: Bot,
    description: 'On/off switches and daily volumes for each content routine.',
  },
  'Blog & KB routines': {
    id: 'blog-kb', icon: Newspaper,
    description: 'Auto-publishing, covers, author and the compliance floor for routine blogs and help articles.',
  },
  Reports: {
    id: 'reports', icon: FileBarChart,
    description: 'The daily analytics report and who receives it.',
  },
  'SEO data': {
    id: 'seo', icon: TrendingUp, checks: ['dataforseo'], tests: ['dataforseo'],
    description: 'DataForSEO credentials and the daily SEO Tracker SERP checks.',
  },
  Social: {
    id: 'social', icon: Share2, checks: ['social_api'],
    description: 'The social-api.ai connection, how and with what visibility videos reach YouTube, and per-network accounts, platform_data and target options.',
  },
  'Social inbox': {
    id: 'social-inbox', icon: MessageCircle, checks: ['social_api'],
    liveChecks: [{ targets: ['social_api'], label: 'Check social-api.ai (incl. inbox)' }],
    description: 'The bot that reads comments and DMs, answers with vetted replies, lodges complaints in Flow and records sales leads — caps, confidence floor, the assumed inbox paths and the Reach → Flow key.',
  },
  Distribution: {
    id: 'distribution', icon: Send,
    description: 'Batch sizes, send caps and compliance for email, SMS and WhatsApp campaign sends.',
  },
  Video: {
    id: 'video', icon: Video, checks: ['shotstack', 'youtube', 'tts'],
    liveChecks: [{ targets: ['shotstack', 'youtube', 'tts'], label: 'Check video services', requiresManage: true }],
    description: 'The automated video pipeline: volumes, voice-over, Shotstack rendering and direct YouTube upload.',
  },
};

const NETWORK_LABEL = {
  youtube: 'YouTube', instagram: 'Instagram', facebook: 'Facebook', linkedin: 'LinkedIn', x: 'X', tiktok: 'TikTok',
};

/** The network a per-network Social key is about (`…account.linkedin` → LinkedIn). */
export function networkOf(key) {
  const last = String(key).split('.').pop();
  return NETWORK_LABEL[last] ?? last;
}

/**
 * Sub-groups inside a section. A row that matches none lands in "More settings".
 * `liveCheck` puts a live-check button inside that sub-group.
 */
const SUBGROUPS = {
  Social: [
    {
      id: 'connection', title: 'Connection',
      description: 'Where Reach reaches social-api.ai and the key it uses.',
      match: (k) => k === 'social.socialapi.base_url' || k === 'social.socialapi.api_key',
      liveCheck: { targets: ['social_api'], label: 'Check social-api.ai' },
    },
    {
      id: 'youtube-publisher', title: 'YouTube publisher',
      description: 'Whether vlogs and Shorts go out through social-api.ai or Reach’s own Google OAuth upload (Video section), and the visibility social-api.ai uploads them with.',
      match: (k) => k === 'video.youtube.publisher' || k === 'social.socialapi.youtube.visibility',
    },
    {
      id: 'accounts', title: 'Per-network accounts',
      description: 'Blank = the single active account of that network in social-api.ai. The live check lists the ids.',
      match: (k) => k.startsWith('social.socialapi.account.'),
      compact: true,
    },
    {
      id: 'platform-data', title: 'Per-network platform_data & target options',
      description: 'JSON objects per network: platform_data is merged as-is into every post’s platform_data, target options into that network’s entry of targets[]. Blank = none.',
      match: (k) => k.startsWith('social.socialapi.platform_data.') || k.startsWith('social.socialapi.target_options.'),
      // Pair each network's platform_data with its target options.
      sort: (a, b) => networkRank(a.key) - networkRank(b.key),
      pairs: true, // two columns: platform_data | target options, one network per row
    },
  ],
  Video: [
    {
      id: 'pipeline', title: 'Pipeline',
      match: (k) => ['automation.video.enabled', 'video.daily.vlogs', 'video.daily.shorts', 'ai.vetting.model.video_script'].includes(k),
    },
    { id: 'voice', title: 'Voice-over (OpenAI TTS)', match: (k) => k.startsWith('video.tts.') },
    {
      id: 'rendering', title: 'Rendering (Shotstack)',
      match: (k) => k.startsWith('video.shotstack.') || k === 'video.music.url' || k === 'video.brand.logo_url',
    },
    {
      id: 'youtube-direct', title: 'YouTube direct upload',
      description: 'Only used when Social → “How videos reach YouTube” is direct.',
      match: (k) => k.startsWith('video.youtube.'),
    },
  ],
  'SEO data': [
    { id: 'dataforseo', title: 'DataForSEO credentials', match: (k) => k.startsWith('seo.dataforseo.') },
    { id: 'tracker', title: 'SEO Tracker', match: (k) => k.startsWith('seo.tracker.') },
  ],
};

const NETWORK_ORDER = Object.keys(NETWORK_LABEL);
function networkRank(key) {
  const i = NETWORK_ORDER.indexOf(String(key).split('.').pop());
  return i === -1 ? NETWORK_ORDER.length : i;
}

export function slugify(name) {
  return String(name).toLowerCase().replace(/&/g, ' ').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'section';
}

/** Rows (API order) → sections (first-seen group order) with their metadata. */
export function buildSections(rows) {
  const sections = [];
  const byName = new Map();
  for (const row of rows) {
    let s = byName.get(row.group);
    if (!s) {
      const meta = SECTION_META[row.group] ?? {};
      s = {
        name: row.group,
        id: meta.id ?? slugify(row.group),
        slug: slugify(row.group),
        icon: meta.icon ?? Settings2,
        description: meta.description ?? null,
        checks: meta.checks ?? [],
        tests: meta.tests ?? [],
        liveChecks: meta.liveChecks ?? [],
        rows: [],
      };
      byName.set(row.group, s);
      sections.push(s);
    }
    s.rows.push(row);
  }
  return sections;
}

/** A URL hash (`#social`, `#seo-data`, `social`) → the section id it names, or null. */
export function resolveSectionId(hash, sections) {
  const h = decodeURIComponent(String(hash ?? '')).replace(/^#/, '').trim().toLowerCase();
  if (!h) return null;
  const hit = sections.find((s) => s.id === h || s.slug === h);
  return hit ? hit.id : null;
}

/** A section's rows split into its sub-groups (empty ones dropped). */
export function subgroupsOf(section, rows = section.rows) {
  const defs = SUBGROUPS[section.name];
  if (!defs) return [{ id: 'all', title: null, rows }];
  const out = defs.map((d) => ({ ...d, rows: [] }));
  const rest = { id: 'more', title: 'More settings', rows: [] };
  for (const row of rows) {
    const g = out.find((d) => d.match(row.key));
    (g ?? rest).rows.push(row);
  }
  for (const g of out) if (g.sort) g.rows.sort(g.sort); // Array#sort is stable: same-network rows keep API order
  return [...out, rest].filter((g) => g.rows.length > 0);
}

/** Lower-case words of the query; a row matches when every word is in its label, key or help. */
export function matchesQuery(row, query) {
  const words = String(query ?? '').toLowerCase().split(/\s+/).filter(Boolean);
  if (words.length === 0) return true;
  const hay = `${row.label ?? ''} ${row.key ?? ''} ${row.help ?? ''}`.toLowerCase();
  return words.every((w) => hay.includes(w));
}

export function displayValue(row) {
  if (row.type === 'bool') return Boolean(row.value);
  return row.value == null ? '' : String(row.value);
}

/** Whether an edit differs from what the server has (an empty secret field is never a change). */
export function isDirty(row, edit) {
  if (edit === undefined) return false;
  if (row.type === 'secret') return typeof edit === 'string' && edit !== '';
  if (row.type === 'bool') return Boolean(edit) !== Boolean(row.value);
  return String(edit ?? '') !== displayValue(row);
}

/**
 * The PUT body for these rows: only real changes. A secret is only ever sent
 * when someone typed a new value — the API never gives us one to send back.
 */
export function buildPayload(rows, edits) {
  const body = {};
  for (const row of rows) {
    if (!(row.key in edits)) continue;
    const edit = edits[row.key];
    if (!isDirty(row, edit)) continue;
    if (row.type === 'secret' && (typeof edit !== 'string' || edit === '')) continue;
    body[row.key] = edit;
  }
  return body;
}

/** The first invalid JSON field among the changes, as "Label: reason", or null. */
export function payloadJsonError(rows, body) {
  for (const row of rows) {
    if (row.type !== 'json_object' || !(row.key in body)) continue;
    const why = jsonObjectError(body[row.key]);
    if (why) return `${row.label}: ${why}`;
  }
  return null;
}

function isSet(row) {
  if (row.type === 'secret') return Boolean(row.is_set);
  if (row.source === 'unset') return false;
  return String(row.value ?? '').trim() !== '';
}

const DIRECT_YOUTUBE_KEYS = ['video.youtube.client_id', 'video.youtube.client_secret', 'video.youtube.refresh_token'];
const REQUIRED_VALUES = ['seo.dataforseo.login'];

/**
 * Whether the page should flag this row when it is not set. Secrets are
 * needed unless their help says "Optional"; Reach's own YouTube OAuth
 * credentials only matter when videos are uploaded directly.
 */
export function isRequired(row, rowsByKey) {
  if (DIRECT_YOUTUBE_KEYS.includes(row.key)) {
    const publisher = rowsByKey?.['video.youtube.publisher'];
    return publisher ? publisher.value === 'direct' : row.type === 'secret';
  }
  if (row.type === 'secret') return !/^optional\b/i.test(String(row.help ?? '').trim());
  return REQUIRED_VALUES.includes(row.key);
}

export function rowIsMissing(row, rowsByKey) {
  return isRequired(row, rowsByKey) && !isSet(row);
}

/**
 * Whether a live check's failure should flag its section. Reach's own YouTube
 * OAuth upload is only used when videos are published directly, so with the
 * social-api.ai publisher a failing `youtube` check is informational.
 */
export function checkMatters(target, rowsByKey) {
  if (target !== 'youtube') return true;
  const publisher = rowsByKey?.['video.youtube.publisher'];
  return !publisher || publisher.value === 'direct';
}

/**
 * A section's dot: `error` when a live check of its provider failed, `warning`
 * when something it needs is not set, `ok` otherwise. `reasons` say why.
 */
export function sectionStatus(section, rowsByKey, results) {
  const failed = section.checks.filter((t) => checkMatters(t, rowsByKey) && results?.[t] && results[t].ok === false);
  const missing = section.rows.filter((r) => rowIsMissing(r, rowsByKey));
  const reasons = [
    ...failed.map((t) => `${CHECK_LABEL[t] ?? t} live check failed`),
    ...missing.map((r) => `${r.label} not set`),
  ];
  if (failed.length) return { level: 'error', reasons };
  if (missing.length) return { level: 'warning', reasons };
  const passed = section.checks.filter((t) => results?.[t]?.ok === true && !results[t].skipped);
  return { level: 'ok', reasons: passed.map((t) => `${CHECK_LABEL[t] ?? t} live check passed`) };
}

export const STATUS_TEXT = { ok: 'All set', warning: 'Needs attention', error: 'Live check failed' };

/** Flatten a live-check `detail` object into ["a.b", "value"] pairs. */
export function detailEntries(detail, prefix = '') {
  if (!detail || typeof detail !== 'object') return [];
  return Object.entries(detail).flatMap(([k, v]) => {
    if (v && typeof v === 'object') return detailEntries(v, `${prefix}${k}.`);
    if (v === null || v === undefined) return [];
    return [[`${prefix}${k}`, typeof v === 'boolean' ? (v ? 'yes' : 'no') : String(v)]];
  });
}

/** A live-check `ran_at` as dd-mm-yyyy HH:mm IST ('' when missing or unreadable). */
export function formatRanAt(iso) {
  return formatDateTime(iso, '');
}

/** Pretty-printed JSON text, or null when the text is blank or not JSON. */
export function formatJson(text) {
  const t = String(text ?? '').trim();
  if (t === '') return null;
  try {
    return JSON.stringify(JSON.parse(t), null, 2);
  } catch {
    return null;
  }
}

const COLLAPSE_KEY = 'reach.aiAutomation.collapsedSections';

/** Collapsed section ids remembered for this browser (empty when storage is unavailable). */
export function readCollapsed() {
  try {
    const raw = window.localStorage.getItem(COLLAPSE_KEY);
    const ids = raw ? JSON.parse(raw) : [];
    return new Set(Array.isArray(ids) ? ids.filter((x) => typeof x === 'string') : []);
  } catch {
    return new Set();
  }
}

export function writeCollapsed(ids) {
  try {
    window.localStorage.setItem(COLLAPSE_KEY, JSON.stringify([...ids]));
  } catch {
    // Private mode / blocked storage: collapsing still works for this visit.
  }
}

export const COLLAPSE_STORAGE_KEY = COLLAPSE_KEY;
