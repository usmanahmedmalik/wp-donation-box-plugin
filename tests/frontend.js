'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/donation-box.js'), 'utf8');
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function harness(responses, { cached = false, plain = false } = {}) {
  const listeners = {}, calls = [], warnings = [], redirects = [];
  const element = () => ({ disabled: false, value: '', textContent: '', addEventListener() {}, focus() {} });
  const error = element(), submit = element(), amount = { ...element(), value: '25.00' };
  const endpoint = plain ? 'https://example.test/?rest_route=/donation-box/v1/checkout' : 'https://example.test/wp-json/donation-box/v1/checkout';
  const form = {
    dataset: { endpoint, ...(cached ? { nonce: 'expired-logged-in-nonce' } : { tokenEndpoint: 'https://example.test/wp-json/donation-box/v1/checkout-token' }) },
    elements: { amount },
    querySelector(selector) {
      if (selector === '.dbox-error') return error;
      if (selector === '[type="submit"]') return submit;
      if (selector === '[name="frequency"]:checked') return { value: 'monthly' };
      return element();
    },
    querySelectorAll() { return []; },
    addEventListener(event, callback) { listeners[event] = callback; },
    setAttribute() {}, removeAttribute() {},
  };
  const portalListeners = {};
  const portal = {
    dataset: { endpoint: 'https://example.test/wp-json/donation-box/v1/portal', nonce: 'wp-rest-nonce', customer: 'cus_owner' },
    addEventListener(event, callback) { portalListeners[event] = callback; },
    closest() { return { querySelector() { return error; } }; },
  };
  const context = {
    URL, FormData: class { *[Symbol.iterator]() { yield ['email', 'donor@example.test']; yield ['consent', '1']; } },
    document: { querySelectorAll(selector) { return selector.endsWith('.dbox-form') ? [form] : [portal]; } },
    window: { DonationBoxI18n: { connection: 'Connection failed', redirect: 'Invalid redirect' }, location: { href: 'https://example.test/donate/', assign(url) { redirects.push(url); } } },
    console: { warn(...args) { warnings.push(args); } },
    async fetch(url, options) {
      calls.push({ url, options });
      const next = responses.shift();
      assert.ok(next, 'unexpected request');
      return { ok: next.status < 400, status: next.status, async json() { if (next.html) throw new SyntaxError('HTML'); return next.body; } };
    },
  };
  vm.runInNewContext(source, context);
  return { calls, warnings, redirects, error, submit, submitForm: () => listeners.submit({ preventDefault() {} }), openPortal: () => portalListeners.click() };
}
(async () => {
  for (const options of [{}, { cached: true }, { cached: true, plain: true }]) {
    const h = harness([{ status: 200, body: { nonce: 'fresh-guest-nonce' } }, { status: 200, body: { url: 'https://checkout.stripe.com/c/pay/test' } }], options);
    await h.submitForm();
    check(h.calls.length === 2, 'fresh token precedes checkout');
    check(new URL(h.calls[0].url).pathname.endsWith('/checkout-token') || new URL(h.calls[0].url).searchParams.get('rest_route') === '/donation-box/v1/checkout-token', 'token URL works with cached markup and plain permalinks');
    check(h.calls.every(call => call.options.credentials === 'omit' && call.options.cache === 'no-store' && call.options.method === 'POST'), 'both requests omit logged-in cookies and bypass caches');
    check(h.calls[1].options.headers['X-Donation-Box-Nonce'] === 'fresh-guest-nonce', 'checkout uses fresh guest token instead of cached user nonce');
    check(!h.calls[1].options.headers['X-WP-Nonce'], 'public checkout does not authenticate a WordPress session');
    const data = JSON.parse(h.calls[1].options.body);
    check(data.amount === '25.00' && data.frequency === 'monthly' && data.return_url === 'https://example.test/donate/', 'amount, frequency and donor return page preserved');
    check(h.redirects[0] === 'https://checkout.stripe.com/c/pay/test', 'successful checkout redirects to Stripe');
  }
  const denied = harness([{ status: 403, body: { code: 'blocked', message: 'Request blocked' } }]);
  await denied.submitForm();
  check(denied.calls.length === 1 && denied.redirects.length === 0, 'failed token fetch never creates checkout');
  check(denied.error.textContent === 'Request blocked' && !denied.submit.disabled, 'token failure displays error and permits retry');
  const expired = harness([{ status: 200, body: { nonce: 'fresh-secret-nonce' } }, { status: 403, body: { code: 'nonce', message: 'Refresh the page and try again.' } }]);
  await expired.submitForm();
  check(expired.warnings[0][1].status === 403 && expired.warnings[0][1].code === 'nonce', 'diagnostics include HTTP status and server error code');
  check(!JSON.stringify(expired.warnings).includes('donor@example.test') && !JSON.stringify(expired.warnings).includes('fresh-secret-nonce'), 'diagnostics omit donor data and tokens');
  const html = harness([{ status: 502, html: true }]);
  await html.submitForm();
  check(html.error.textContent === 'Connection failed' && html.warnings[0][1].code === 'invalid_response', 'HTML proxy errors yield useful message and diagnostic');
  const portal = harness([{ status: 200, body: { url: 'https://billing.stripe.com/p/session/test' } }]);
  await portal.openPortal();
  check(portal.calls[0].options.credentials === 'same-origin' && portal.calls[0].options.headers['X-WP-Nonce'] === 'wp-rest-nonce', 'portal preserves authenticated cookie/nonce ownership check');
  check(portal.redirects[0] === 'https://billing.stripe.com/p/session/test', 'portal redirect preserved');
  console.log(`Passed ${checks} frontend assertions.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
