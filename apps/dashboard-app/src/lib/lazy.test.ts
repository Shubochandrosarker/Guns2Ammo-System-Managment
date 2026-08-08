// Tests for the stale-chunk recovery decision (src/lib/lazy.ts).
//
// The reload itself needs a browser, but the decision — "is this the
// after-a-deploy missing-chunk failure, and have we already tried once?" — is
// a pure function, and it is the part that must not get this wrong in either
// direction:
//   * too lax  → real errors get swallowed by an endless reload loop;
//   * too strict → the owner's open tab white-screens after every deploy.

import assert from 'node:assert/strict'
import { isChunkLoadError, shouldReloadForChunkError } from './lazy'

let passed = 0
let failed = 0

function check(name: string, fn: () => void) {
  try {
    fn()
    passed++
    console.log(`  ok   ${name}`)
  } catch (err) {
    failed++
    console.error(`  FAIL ${name}`)
    console.error(`       ${(err as Error).message}`)
  }
}

console.log('\nisChunkLoadError: real browser wordings are recognised')

// Verbatim messages from the browsers this app has to run on. If a browser
// changes its wording, this suite is where that gets noticed.
const chunkFailures: Array<[string, string]> = [
  ['Chrome / Edge', 'Failed to fetch dynamically imported module: https://app.guns2ammo.com/assets/Leads-DdNfEM30.js'],
  ['Firefox', 'error loading dynamically imported module'],
  ['Safari', 'Importing a module script failed.'],
  ['Vite CSS preload', 'Unable to preload CSS for /assets/index-D9-UwtJB.css'],
  ['webpack-style', 'ChunkLoadError: Loading chunk 12 failed.'],
]

for (const [browser, message] of chunkFailures) {
  check(`${browser}: "${message.slice(0, 46)}…"`, () => {
    assert.equal(isChunkLoadError(new Error(message)), true)
  })
}

check('a plain string is accepted too (some rejections are not Errors)', () => {
  assert.equal(isChunkLoadError('Failed to fetch dynamically imported module: /assets/x.js'), true)
})

console.log('\nisChunkLoadError: ordinary application errors are NOT chunk failures')

// This is the important direction. Treating an application bug as a stale
// chunk would reload the page on every render error and hide the bug behind
// what looks like a flaky dashboard.
const notChunkFailures: Array<[string, unknown]> = [
  ['a TypeError from a bad API shape', new TypeError("Cannot read properties of undefined (reading 'total')")],
  ['an ApiError-shaped failure', new Error('502 Malformed API envelope from /dashboard/overview')],
  ['a network failure', new Error('Failed to fetch')],
  ['null', null],
  ['undefined', undefined],
  ['a number', 42],
  ['an empty message', new Error('')],
]

for (const [label, err] of notChunkFailures) {
  check(`${label} is not treated as a chunk failure`, () => {
    assert.equal(isChunkLoadError(err), false)
  })
}

console.log('\nshouldReloadForChunkError: reloads exactly once')

check('a chunk failure with no prior attempt reloads', () => {
  assert.equal(shouldReloadForChunkError(new Error('Failed to fetch dynamically imported module: /a.js'), false), true)
})

check('the SAME failure after a reload does not reload again', () => {
  // Without this the tab would spin forever on a genuinely broken deploy.
  assert.equal(shouldReloadForChunkError(new Error('Failed to fetch dynamically imported module: /a.js'), true), false)
})

check('a non-chunk error never reloads, attempted or not', () => {
  assert.equal(shouldReloadForChunkError(new TypeError('x is not a function'), false), false)
  assert.equal(shouldReloadForChunkError(new TypeError('x is not a function'), true), false)
})

console.log(`\n${passed} passed, ${failed} failed`)
if (failed > 0) process.exit(1)
