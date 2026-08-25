import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

/**
 * The guard behind `npm run check:encoding`.
 *
 * The portal once shipped "Live GA4 data <mojibake> All sites" because a source
 * file was round-tripped through a DOS code page: the em dash was persisted as
 * three junk characters and a BOM was prepended. These tests pin both halves —
 * the repo is clean now, and the guard still fails on a file that regresses.
 */
const REPO_ROOT = path.resolve(__dirname, '../../..');
const GUARD = path.join(REPO_ROOT, 'scripts', 'check-encoding.mjs');

// Built from code points so this test file cannot itself trip the guard.
const CP437_EM_DASH = String.fromCharCode(0x0393, 0x00c7, 0x00f6);
const BOM = String.fromCharCode(0xfeff);

const runGuard = (root) =>
  spawnSync(process.execPath, [GUARD, ...(root ? [root] : [])], { encoding: 'utf8' });

describe('encoding guard', () => {
  it('reports every tracked file in this repository as clean', () => {
    const result = runGuard();
    expect(result.stderr).toBe('');
    expect(result.status).toBe(0);
  });

  describe('against a corrupted checkout', () => {
    let fixture;

    beforeAll(() => {
      fixture = mkdtempSync(path.join(tmpdir(), 'reach-encoding-'));
      mkdirSync(path.join(fixture, 'src'));
      writeFileSync(
        path.join(fixture, 'src', 'Broken.jsx'),
        `${BOM}export const note = 'Live GA4 data ${CP437_EM_DASH} All sites';\n`,
        'utf8',
      );
      execFileSync('git', ['init', '-q'], { cwd: fixture });
      execFileSync('git', ['add', '-A'], { cwd: fixture });
    });

    afterAll(() => {
      if (fixture) rmSync(fixture, { recursive: true, force: true });
    });

    it('fails the build', () => {
      expect(runGuard(fixture).status).toBe(1);
    });

    it('names the file, the corrupted sequence and the character it should be', () => {
      const { stderr } = runGuard(fixture);
      expect(stderr).toContain('src/Broken.jsx');
      expect(stderr).toContain('em dash');
      expect(stderr).toContain('byte order mark');
    });
  });
});
