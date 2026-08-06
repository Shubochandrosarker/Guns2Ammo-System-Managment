// Tests for the post-sign-in redirect guard (src/lib/redirect.ts).
//
// This function decides where navigate() goes right after credentials are
// accepted, from a value stored in history state. Getting it wrong is an open
// redirect on the login screen of an admin dashboard, so the rejection cases
// below matter more than the happy path.

import assert from 'node:assert/strict'
import { safeRedirect } from './redirect'

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

console.log('\nsafeRedirect: legitimate in-app destinations are preserved')

const allowed: Array<[string, string]> = [
  ['a plain route', '/waivers'],
  ['a route with a query', '/leads?status=new&page=2'],
  ['a route with a hash', '/system-health#cron'],
  ['a nested path', '/reports/weekly'],
  ['the root itself', '/'],
]

for (const [label, path] of allowed) {
  check(`${label}: ${path}`, () => {
    assert.equal(safeRedirect({ from: path }), path)
  })
}

console.log('\nsafeRedirect: off-origin destinations are refused')

const refused: Array<[string, unknown]> = [
  ['an absolute https URL', 'https://guns2ammo.evil.example/login'],
  ['an absolute http URL', 'http://evil.example'],
  ['a protocol-relative URL', '//evil.example/harvest'],
  ['a backslash protocol-relative URL', '/\\evil.example/harvest'],
  ['a javascript: URL', 'javascript:alert(1)'],
  ['a bare host', 'evil.example'],
  ['a relative path with no leading slash', 'waivers'],
]

for (const [label, value] of refused) {
  check(`${label} falls back to /`, () => {
    assert.equal(safeRedirect({ from: value }), '/')
  })
}

console.log('\nsafeRedirect: malformed and looping state falls back to /')

const fallbacks: Array<[string, unknown]> = [
  ['no state at all', null],
  ['undefined state', undefined],
  ['state with no `from`', { other: '/waivers' }],
  ['a non-string `from`', { from: 42 }],
  ['an object `from`', { from: { pathname: '/waivers' } }],
  ['an empty `from`', { from: '' }],
  ['/login itself (would loop)', { from: '/login' }],
  ['/login with a query (would loop)', { from: '/login?next=/waivers' }],
]

for (const [label, state] of fallbacks) {
  check(`${label} → /`, () => {
    assert.equal(safeRedirect(state), '/')
  })
}

check('a route that merely starts with the letters "login" is still allowed', () => {
  // Guarding with a bare startsWith('/login') would have blocked this.
  assert.equal(safeRedirect({ from: '/login-history' }), '/login-history')
})

console.log(`\n${passed} passed, ${failed} failed`)
if (failed > 0) process.exit(1)
