(() => {
  'use strict';
  async function readResponse(response) {
    let body;
    try { body = await response.json(); }
    catch (_) {
      console.warn('Donation Box request failed', { status: response.status, code: 'invalid_response' });
      throw new Error(window.DonationBoxI18n.connection);
    }
    if (!response.ok) {
      // Never log form data, credentials, tokens or raw server responses.
      console.warn('Donation Box request failed', { status: response.status, code: body?.code || 'request_failed' });
      throw new Error(body?.message || window.DonationBoxI18n.connection);
    }
    return body;
  }
  async function checkoutToken(endpoint) {
    const response = await fetch(endpoint, { method: 'POST', credentials: 'omit', cache: 'no-store' });
    const body = await readResponse(response);
    if (!body?.nonce || typeof body.nonce !== 'string') throw new Error(window.DonationBoxI18n.connection);
    return body.nonce;
  }
  function tokenEndpoint(form) {
    if (form.dataset.tokenEndpoint) return form.dataset.tokenEndpoint;
    // Support older form HTML still held by a page cache, and plain permalinks.
    const url = new URL(form.dataset.endpoint, window.location.href);
    if (url.searchParams.has('rest_route')) {
      url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/checkout$/, '/checkout-token'));
    } else { url.pathname = url.pathname.replace(/\/checkout$/, '/checkout-token'); }
    return url.href;
  }
  async function send(endpoint, nonce, data, portal = false) {
    const response = await fetch(endpoint, {
      // Public checkout must use the same anonymous identity as checkout-token.
      // Portal access continues to use authenticated WordPress cookies/nonces.
      method: 'POST', credentials: portal ? 'same-origin' : 'omit', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', [portal ? 'X-WP-Nonce' : 'X-Donation-Box-Nonce']: nonce },
      body: JSON.stringify(data),
    });
    const body = await readResponse(response);
    if (!body?.url) throw new Error(window.DonationBoxI18n.connection);
    const url = new URL(body.url);
    if (url.protocol !== 'https:' || url.hostname !== (portal ? 'billing.stripe.com' : 'checkout.stripe.com')) throw new Error(window.DonationBoxI18n.redirect);
    window.location.assign(url.href);
  }
  document.querySelectorAll('.donation-box .dbox-form').forEach(form => {
    const amount = form.elements.amount;
    const error = form.querySelector('.dbox-error');
    const submit = form.querySelector('[type="submit"]');
    const setStep = number => {
      form.querySelectorAll('[data-step]').forEach(step => {
        step.hidden = step.dataset.step !== String(number);
        step.querySelectorAll('input').forEach(input => { input.disabled = step.hidden; });
      });
      form.querySelectorAll('[data-step-label]').forEach(label => {
        if (label.dataset.stepLabel === String(number)) label.setAttribute('aria-current', 'step');
        else label.removeAttribute('aria-current');
      });
      // Hidden step inputs are disabled; submission reads amount/frequency explicitly.
      if (number === 2) form.querySelector('[name="first_name"]').focus();
      else amount.focus();
    };
    form.querySelectorAll('[data-amount]').forEach(button => button.addEventListener('click', () => {
      amount.value = button.dataset.amount.replace(',', '.');
      form.querySelectorAll('[data-amount]').forEach(other => other.setAttribute('aria-pressed', String(other === button)));
    }));
    amount.addEventListener('input', () => form.querySelectorAll('[data-amount]').forEach(button => button.setAttribute('aria-pressed', String(Number(button.dataset.amount.replace(',', '.')) === Number(amount.value)))));
    form.querySelector('[data-next]').addEventListener('click', () => {
      if (amount.reportValidity()) {
        form.querySelector('.dbox-monthly').hidden = form.querySelector('[name="frequency"]:checked').value !== 'monthly';
        setStep(2);
      }
    });
    form.querySelector('[data-back]').addEventListener('click', () => setStep(1));
    form.addEventListener('submit', async event => {
      event.preventDefault();
      error.textContent = ''; submit.disabled = true; form.setAttribute('aria-busy', 'true');
      const data = Object.fromEntries(new FormData(form));
      // Step one controls are disabled to avoid hidden invalid-control browser errors.
      data.amount = amount.value;
      data.frequency = form.querySelector('[name="frequency"]:checked').value;
      data.return_url = window.location.href;
      try {
        const nonce = await checkoutToken(tokenEndpoint(form));
        await send(form.dataset.endpoint, nonce, data);
      }
      catch (e) { error.textContent = e.message; error.focus(); submit.disabled = false; form.removeAttribute('aria-busy'); }
    });
  });
  document.querySelectorAll('.donation-box .dbox-portal').forEach(button => button.addEventListener('click', async () => {
    button.disabled = true;
    const error = button.closest('.dbox-account').querySelector('.dbox-error');
    error.textContent = '';
    try { await send(button.dataset.endpoint, button.dataset.nonce, { customer_id: button.dataset.customer, return_url: window.location.href }, true); }
    catch (e) { error.textContent = e.message; button.disabled = false; }
  }));
})();
