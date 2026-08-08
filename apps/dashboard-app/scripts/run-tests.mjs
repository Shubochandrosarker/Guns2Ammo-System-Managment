#!/usr/bin/env node
// Minimal test runner: bundle each src/**/*.test.ts with esbuild and run it.
//
// No test framework is installed on purpose. esbuild already ships as a Vite
// dependency, so this adds nothing to the dependency tree, and the tests
// themselves are plain assertions that exit non-zero on failure. It exists so
// `npm test` — which deploy.sh already invokes — actually runs something.

import { execFileSync } from 'node:child_process';
import { mkdtempSync, readdirSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));
const esbuild = join(root, 'node_modules', '.bin', 'esbuild');

function findTests(dir) {
  const out = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) out.push(...findTests(full));
    else if (entry.endsWith('.test.ts') || entry.endsWith('.test.tsx')) out.push(full);
  }
  return out;
}

const tests = findTests(join(root, 'src')).sort();
if (tests.length === 0) {
  console.error('No *.test.ts files found under src/. Refusing to report success for zero tests.');
  process.exit(1);
}

const work = mkdtempSync(join(tmpdir(), 'g2a-pos-tests-'));
let failed = 0;

try {
  for (const test of tests) {
    const name = relative(root, test);
    const bundle = join(work, name.replace(/[/\\]/g, '_') + '.mjs');
    console.log(`\n── ${name}`);
    try {
      execFileSync(
        esbuild,
        [
          test,
          '--bundle',
          '--platform=node',
          '--format=esm',
          // env.ts reads these at module load and throws in a production build
          // when the API base is empty; give it a fixed value so URLs in
          // assertions are predictable. DEV:true keeps the mock branch
          // reachable so its behaviour is testable too.
          '--define:import.meta.env={"VITE_G2A_API_BASE":"https://api.test/wp-json/g2a/v1","VITE_G2A_USE_MOCKS":"0","DEV":true,"PROD":false}',
          `--alias:@=${join(root, 'src')}`,
          `--outfile=${bundle}`,
          '--log-level=error',
        ],
        { stdio: 'inherit' },
      );
      execFileSync(process.execPath, [bundle], { stdio: 'inherit' });
    } catch {
      failed++;
    }
  }
} finally {
  rmSync(work, { recursive: true, force: true });
}

if (failed > 0) {
  console.error(`\n${failed} test file(s) failed.`);
  process.exit(1);
}
console.log(`\nAll ${tests.length} test file(s) passed.`);
