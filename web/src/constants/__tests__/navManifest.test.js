import { describe, it, expect } from 'vitest';
import {
  buildNavSections, buildPaletteEntries, canSee, LEGACY_REDIRECTS, PLANNED_ROUTES, searchPalette,
  settingEntries, visibleNavSections,
} from '../navManifest';
import { ROUTES } from '../routes';

const all = () => true;
const only = (...perms) => (p) => perms.includes(p);

describe('navManifest', () => {
  it('groups the sidebar by the owner mental model', () => {
    expect(buildNavSections().map((s) => s.title)).toEqual(['Overview', 'Content', 'Channels', 'Growth', 'Bot & system']);
    const channels = buildNavSections().find((s) => s.id === 'channels').items.map((i) => i.label);
    expect(channels).toEqual(['Social & Ads', 'Social inbox', 'Email & campaigns', 'Landing pages']);
  });

  it('has unique ids and paths and an icon + permission on every item', () => {
    const items = buildNavSections().flatMap((s) => s.items);
    expect(new Set(items.map((i) => i.id)).size).toBe(items.length);
    expect(new Set(items.map((i) => i.path)).size).toBe(items.length);
    for (const i of items) {
      expect(i.icon, i.id).toBeTruthy();
      expect(i.requires, i.id).toBeTruthy();
    }
  });

  it('never links the sidebar to a route that only redirects', () => {
    const legacy = new Set(LEGACY_REDIRECTS.map((r) => r.from));
    for (const i of buildNavSections().flatMap((s) => s.items)) {
      expect(legacy.has(i.path), i.path).toBe(false);
    }
  });

  it('canSee accepts one permission or any of a list', () => {
    expect(canSee({}, only())).toBe(true);
    expect(canSee({ requires: 'a' }, only('a'))).toBe(true);
    expect(canSee({ requires: 'a' }, only('b'))).toBe(false);
    expect(canSee({ requires: ['a', 'b'] }, only('b'))).toBe(true);
    expect(canSee({ requires: ['a', 'b'] }, only('c'))).toBe(false);
  });

  it('drops denied items and empty groups', () => {
    const v = visibleNavSections(only('lead.view'));
    expect(v.map((s) => s.id)).toEqual(['growth']);
    expect(v[0].items.map((i) => i.id)).toEqual(['leads']);
  });

  it('redirects every retired route to a live destination (no chains, no loops)', () => {
    const from = new Set(LEGACY_REDIRECTS.map((r) => r.from));
    expect(from.size).toBe(LEGACY_REDIRECTS.length);
    for (const r of LEGACY_REDIRECTS) {
      expect(from.has(r.to), `${r.from} → ${r.to}`).toBe(false);
      expect(r.to.startsWith('/')).toBe(true);
    }
    const map = Object.fromEntries(LEGACY_REDIRECTS.map((r) => [r.from, r.to]));
    expect(map['/calendar']).toBe('/publishing/calendar');
    expect(map['/content/daily-pack']).toBe(ROUTES.BOT_PLAN);
    expect(map['/social/queue']).toBe(ROUTES.SOCIAL_CALENDAR);
    expect(map['/distribution/social']).toBe(ROUTES.SOCIAL_CALENDAR);
    expect(map['/blog-seo/search-console']).toBe('/seo-centre/search');
    expect(map['/blog-command-centre/seo']).toBe('/seo-centre/search');
    expect(map['/admin/local-bot-reports']).toBe('/bot/reports');
  });

  it('has no placeholder left for pages that have landed', () => {
    const paths = PLANNED_ROUTES.map((r) => r.path);
    // Bot plan, Run now, Ask the bot and Social inbox all have real pages now.
    for (const p of ['/bot/plan', '/bot/activities', '/bot/ask', '/social/inbox']) expect(paths).not.toContain(p);
    const navPaths = new Set(buildNavSections().flatMap((s) => s.items).map((i) => i.path));
    for (const p of paths) expect(navPaths.has(p), p).toBe(true);
  });
});

describe('command palette entries', () => {
  it('includes sidebar items, sub-pages and AI & Automation sections for an admin', () => {
    const e = buildPaletteEntries(all);
    const labels = e.map((x) => x.label);
    expect(labels).toEqual(expect.arrayContaining(['Social & Ads', 'Social calendar & queue', 'Daily reports', 'Social settings']));
    expect(e.find((x) => x.label === 'Social settings').path).toBe('/admin/ai-automation#social');
    expect(new Set(e.map((x) => x.path)).size).toBe(e.length);
  });

  it('respects permissions (no settings without settings.view)', () => {
    const e = buildPaletteEntries(only('lead.view'));
    expect(e.map((x) => x.label)).toEqual(['Leads & Engage', 'Engage lead push', 'Lead outcomes']);
  });

  it('ranks label-prefix matches first and matches old names via keywords', () => {
    const e = buildPaletteEntries(all);
    expect(searchPalette(e, 'jobs')[0].label).toBe('Jobs');
    expect(searchPalette(e, 'blog seo').map((x) => x.label)).toContain('SEO & Search Console');
    expect(searchPalette(e, 'meta ads').map((x) => x.label)).toContain('Ads');
    expect(searchPalette(e, 'zzzz-nothing')).toEqual([]);
    expect(searchPalette(e, '  ')).toBe(e);
  });

  it('turns automation settings rows into deep links that pre-fill the search', () => {
    const [s] = settingEntries([{ key: 'social.socialapi.api_key', label: 'social-api.ai API key', group: 'Social' }]);
    expect(s.path).toBe('/admin/ai-automation?q=social.socialapi.api_key#social');
    expect(s.group).toBe('Settings');
  });
});
