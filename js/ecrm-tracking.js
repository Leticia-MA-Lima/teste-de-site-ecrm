/* Coleta centralizada. A chave do CRM nunca deve estar neste arquivo. */
(() => {
  'use strict';
  const config = window.ECRMTrackingConfig || {};
  const script = document.currentScript;
  const endpoint = config.endpoint || new URL('../tracking/collect.php', script.src).href;
  const site = config.site || 'ecrm-static';
  const key = `ecrm:${site}:visitor`;
  const newId = () => crypto.randomUUID();
  const storage = (getStore, key, create) => {
    try { const store = getStore(); let value = store.getItem(key); if (!value) { value = create(); store.setItem(key, value); } return value; }
    catch { return create(); }
  };
  const visitor = storage(() => window.localStorage, key, newId);
  const session = storage(() => window.sessionStorage, `${key}:session`, newId);
  let chain = Promise.resolve();
  const sent = new Set();
  function send(kind, target, extra = {}, id = newId()) {
    const payload = { id, visitor, session, site, kind, target, ...extra };
    const job = chain.then(async () => {
      for (let attempt = 0; attempt < 3; attempt++) {
        try {
          const controller = new AbortController();
          const timeout = setTimeout(() => controller.abort(), 12000);
          let response, result;
          try {
            response = await fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload), credentials: 'omit', signal: controller.signal });
            try { result = await response.json(); }
            catch {
              const error = new Error('O serviço de contato retornou uma resposta inválida. Tente novamente em instantes.');
              error.userMessage = true;
              error.permanent = response.status >= 400 && response.status < 500;
              throw error;
            }
          } finally { clearTimeout(timeout); }
          if (!response.ok) {
            const error = new Error(result.error || 'Não foi possível registrar o envio.');
            error.userMessage = true;
            error.permanent = response.status < 500;
            throw error;
          }
          if (!result || result.accepted !== true) {
            const error = new Error('O serviço de contato não confirmou o recebimento. Tente novamente.');
            error.userMessage = true;
            throw error;
          }
          window.dispatchEvent(new CustomEvent('ecrm:tracked', { detail: { kind, target, ...result } }));
          return result;
        } catch (error) {
          if (error.permanent || attempt === 2) throw error;
          await new Promise(resolve => setTimeout(resolve, 500 * (attempt + 1)));
        }
      }
    });
    chain = job.catch(() => {});
    return job;
  }
  const track = (kind, target) => {
    const tag = `${kind}:${target}`;
    if (sent.has(tag)) return;
    sent.add(tag);
    send(kind, target).catch(() => sent.delete(tag));
  };
  const formName = form => form.dataset.ecrmForm || 'contact';
  const fields = ['nome', 'empresa', 'email', 'whatsapp', 'segmento', 'organizacao'];
  function setupForm(form) {
    if (form.dataset.ecrmBound) return;
    form.dataset.ecrmBound = '1';
    form.removeAttribute('novalidate');
    form.querySelectorAll('[aria-required="true"]').forEach(field => { field.required = true; });
    const limits = { nome: 160, empresa: 200, email: 254, whatsapp: 32, segmento: 100, organizacao: 2000 };
    fields.forEach(name => { const field = form.elements.namedItem(name); if (field && field.tagName !== 'SELECT') field.maxLength = limits[name]; });
    const input = document.createElement('input');
    input.name = 'website'; input.tabIndex = -1; input.autocomplete = 'off'; input.setAttribute('aria-hidden', 'true');
    input.style.cssText = 'position:absolute;left:-10000px;width:1px;height:1px';
    form.append(input);
    form.addEventListener('focusin', () => track('form_start', formName(form)));
    const fill = event => {
      const field = event.target;
      if (fields.includes(field.name) && !field.disabled && field.value.trim()) track('field_filled', `${formName(form)}:${field.name}`);
    };
    form.addEventListener('input', fill); form.addEventListener('change', fill);
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (form.dataset.sending || !form.reportValidity()) return;
      const data = new FormData(form); const contact = {};
      fields.forEach(name => { contact[name] = String(data.get(name) || '').trim(); });
      let output = form.querySelector('.wpcf7-response-output, [data-ecrm-status]');
      if (!output) { output = document.createElement('p'); output.dataset.ecrmStatus = ''; form.append(output); }
      output.setAttribute('role', 'status'); output.setAttribute('aria-live', 'polite'); output.removeAttribute('aria-hidden');
      output.style.display = 'block'; output.textContent = 'Enviando seu contato…';
      const button = form.querySelector('[type=submit]');
      form.dataset.sending = '1'; if (button) button.disabled = true;
      // Reutilizar a identificação se a resposta se perder: não duplicar o envio.
      const signature = JSON.stringify(contact);
      if (form._ecrmSignature !== signature) { form._ecrmSignature = signature; form._ecrmSubmitId = newId(); }
      try {
        const result = await send('form_submit', formName(form), { contact, website: String(data.get('website') || '') }, form._ecrmSubmitId);
        output.textContent = 'Contato recebido! Obrigado. Nossa equipe dará continuidade ao atendimento.';
        form.dataset.status = 'sent';
        form.dispatchEvent(new CustomEvent('ecrm:formaccepted', { bubbles: true, detail: result }));
        if (formName(form) === 'whatsapp' && config.whatsappNumber) {
          const link = document.createElement('a');
          link.href = `https://wa.me/${config.whatsappNumber.replace(/\D/g, '')}?text=${encodeURIComponent('Olá! Preenchi o contato no site e gostaria de conversar.')}`;
          link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = 'Continuar no WhatsApp';
          output.append(document.createElement('br'), link);
        }
      } catch (error) { output.textContent = error.userMessage ? error.message : 'Não foi possível conectar ao serviço de contato. Confira sua conexão e tente novamente.'; }
      finally { delete form.dataset.sending; if (button) button.disabled = false; }
    });
  }
  document.querySelectorAll('.wpcf7-form, form[data-ecrm-form]').forEach(setupForm);
  document.addEventListener('click', event => {
    const element = event.target.closest('a, button, input[type=submit]');
    if (!element) return;
    const href = element.getAttribute('href') || '';
    let target = element.dataset.ecrmClick;
    if (!target) {
      if (/calendar\.google\.com|calendly\.com/.test(href)) target = 'schedule';
      else if (/wa\.me|api\.whatsapp\.com/.test(href)) target = 'whatsapp';
      else if (element.closest('form') || /contato|contact|formulario/i.test(href)) target = 'contact';
      else if (element.closest('nav')) target = 'navigation';
      else if (element.matches('.elementor-button')) target = 'cta';
    }
    if (target) track('click', target);
  });
  // Bolha local: o iframe do plugin WordPress não funciona nesta exportação estática.
  if (config.whatsappNumber) {
    const container = document.createElement('div'); container.className = 'ecrm-whatsapp';
    container.innerHTML = '<button type="button" data-ecrm-click="whatsapp" aria-expanded="false" aria-controls="ecrm-wa-panel">WhatsApp</button><section id="ecrm-wa-panel" hidden><button type="button" class="ecrm-wa-close" aria-label="Fechar contato">×</button><h2>Fale com nossa equipe</h2><form data-ecrm-form="whatsapp"><label>Nome<input name="nome" required maxlength="160" autocomplete="name"></label><label>E-mail<input name="email" type="email" required maxlength="254" autocomplete="email"></label><label>WhatsApp<input name="whatsapp" type="tel" required maxlength="32" autocomplete="tel"></label><button type="submit">Enviar contato</button><p data-ecrm-status role="status"></p></form></section>';
    document.body.append(container);
    const toggle = container.firstElementChild; const panel = container.querySelector('section');
    const close = () => { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); };
    toggle.addEventListener('click', () => { panel.hidden = !panel.hidden; toggle.setAttribute('aria-expanded', String(!panel.hidden)); if (!panel.hidden) panel.querySelector('input').focus(); });
    container.querySelector('.ecrm-wa-close').addEventListener('click', close);
    container.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    setupForm(panel.querySelector('form'));
  }
  track('visit', 'page');
})();
