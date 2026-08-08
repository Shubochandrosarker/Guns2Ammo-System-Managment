// Contract tests for the canonical envelope transport.
//
// These are the rules the whole dashboard depends on and that a UI bug would
// otherwise hide: a malformed or failed envelope must surface as an error the
// pages render as an ERROR state, never as zeros that look like real data.

import { api, ApiError } from './api'

let pass = 0
let fail = 0
function check(name: string, cond: boolean, detail = '') {
  if (cond) { pass++; console.log(`  ok   ${name}`) }
  else { fail++; console.log(`  FAIL ${name} ${detail}`) }
}

interface StubResponse { status?: number; body: unknown; headers?: Record<string, string> }

function stubFetch({ status = 200, body, headers = {} }: StubResponse) {
  ;(globalThis as unknown as { fetch: unknown }).fetch = async () =>
    ({
      ok: status >= 200 && status < 300,
      status,
      statusText: `HTTP ${status}`,
      json: async () => body,
      text: async () => (typeof body === 'string' ? body : JSON.stringify(body)),
      headers: { get: (k: string) => headers[k] ?? null },
    }) as unknown as Response
}

async function expectApiError(fn: () => Promise<unknown>): Promise<ApiError | null> {
  try {
    await fn()
    return null
  } catch (e) {
    return e instanceof ApiError ? e : null
  }
}

async function main() {
  console.log('\nenvelope: success')
  stubFetch({
    body: {
      success: true,
      data: { marker: 1234 },
      meta: { requestId: 'req_1', generatedAt: '2026-07-31T00:00:00Z', source: ['woocommerce'] },
    },
  })
  const ok = await api.dashboard.overview()
  check('data is unwrapped', (ok.data as unknown as { marker: number }).marker === 1234)
  check('meta is preserved for the UI', ok.meta?.requestId === 'req_1')

  console.log('\nenvelope: a 200 that says success:false is still a failure')
  stubFetch({ body: { success: false, error: { code: 'g2aba_source_missing', message: 'Booking engine inactive' } } })
  const failed = await expectApiError(() => api.dashboard.overview())
  check('throws ApiError', failed !== null)
  check('keeps the server error code', failed?.code === 'g2aba_source_missing', String(failed?.code))
  check('keeps the server message', failed?.message === 'Booking engine inactive', String(failed?.message))
  check('reported as 502, not 200', failed?.status === 502, String(failed?.status))

  console.log('\nenvelope: a 200 that is not an envelope at all')
  stubFetch({ body: { revenue: 999 } })
  const malformed = await expectApiError(() => api.dashboard.overview())
  check('throws rather than rendering the bare body', malformed !== null)
  check('flagged as a bad envelope', malformed?.code === 'g2a_bad_envelope', String(malformed?.code))
  check('the raw number never reaches a caller', !String(malformed?.message).includes('999'))

  console.log('\nenvelope: structured error recovered from a non-2xx body')
  stubFetch({
    status: 500,
    body: { success: false, error: { code: 'g2aba_upstream', message: 'Upstream timed out', requestId: 'req_9' } },
  })
  const upstream = await expectApiError(() => api.dashboard.overview())
  check('code comes from the envelope, not the HTTP status', upstream?.code === 'g2aba_upstream', String(upstream?.code))
  check('requestId is surfaced for support', String(upstream?.message).includes('req_9'), String(upstream?.message))
  check('HTTP status is preserved', upstream?.status === 500, String(upstream?.status))

  console.log('\ncontent list: pagination headers are read off the response')
  stubFetch({ body: [{ id: 1 }, { id: 2 }], headers: { 'X-WP-Total': '57', 'X-WP-TotalPages': '3' } })
  const page = await api.content.posts({ perPage: 2 })
  check('items pass through', page.items.length === 2)
  check('total comes from X-WP-Total', page.total === 57, String(page.total))
  check('totalPages comes from X-WP-TotalPages', page.totalPages === 3, String(page.totalPages))

  console.log('\ncontent list: missing pagination headers fall back sanely')
  stubFetch({ body: [{ id: 1 }] })
  const noHeaders = await api.content.posts({})
  check('total falls back to the item count', noHeaders.total === 1, String(noHeaders.total))
  check('totalPages falls back to 1', noHeaders.totalPages === 1, String(noHeaders.totalPages))

  console.log(`\n${pass} passed, ${fail} failed\n`)
  // Node's types are not in this app's tsconfig — it targets the browser — so
  // reach the runtime through globalThis rather than pulling @types/node in
  // purely to exit a test process.
  if (fail) (globalThis as unknown as { process: { exit: (code: number) => void } }).process.exit(1)
}

void main()
