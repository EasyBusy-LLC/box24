function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

export function createCrmUi(t) {
  const overlay = document.getElementById('crm-dialog');
  const panel = overlay?.querySelector('[data-crm-dialog-panel]');
  const titleNode = overlay?.querySelector('[data-crm-dialog-title]');
  const body = overlay?.querySelector('[data-crm-dialog-body]');
  const footer = overlay?.querySelector('[data-crm-dialog-footer]');
  const closeButton = overlay?.querySelector('[data-crm-dialog-close]');
  if (!overlay || !panel || !titleNode || !body || !footer || !closeButton) throw new Error('CRM dialog markup is missing.');
  let cancelCurrent = null;

  function close(value = null) {
    overlay.hidden = true;
    body.replaceChildren();
    footer.replaceChildren();
    const resolve = cancelCurrent;
    cancelCurrent = null;
    if (resolve) resolve(value);
  }

  closeButton.addEventListener('click', () => close(null));
  overlay.addEventListener('click', (event) => { if (event.target === overlay) close(null); });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !overlay.hidden) close(null); });

  function open(title) {
    if (cancelCurrent) close(null);
    titleNode.textContent = title;
    body.replaceChildren();
    footer.replaceChildren();
    overlay.hidden = false;
  }

  function button(label, primary = false) {
    const node = element('button', `eb-dialog__button${primary ? ' eb-dialog__button--primary' : ''}`, label);
    node.type = 'button';
    return node;
  }

  function showError(container, error) {
    let errorNode = container.querySelector('.eb-dialog__error');
    if (!errorNode) {
      errorNode = element('div', 'eb-dialog__error');
      container.prepend(errorNode);
    }
    errorNode.textContent = error?.message || t('operationFailed');
  }

  function form({ title, fields, submitLabel = t('save'), submit }) {
    open(title);
    return new Promise((resolve) => {
      cancelCurrent = resolve;
      const formNode = element('form', 'eb-dialog__form');
      const controls = {};
      fields.forEach((field) => {
        const label = element('label', 'eb-dialog__field');
        label.append(element('span', 'eb-dialog__label', field.label));
        const input = document.createElement('input');
        input.className = 'eb-dialog__input';
        input.name = field.name;
        input.type = field.type || 'text';
        input.value = field.value || '';
        input.maxLength = field.maxLength || 255;
        input.required = Boolean(field.required);
        label.append(input);
        formNode.append(label);
        controls[field.name] = input;
      });
      body.append(formNode);
      const cancel = button(t('cancel'));
      cancel.addEventListener('click', () => close(null));
      const save = button(submitLabel, true);
      footer.append(cancel, save);
      const run = async () => {
        if (!formNode.reportValidity()) return;
        save.disabled = true;
        const values = Object.fromEntries(Object.entries(controls).map(([name, input]) => [name, input.value.trim()]));
        try {
          const result = submit ? await submit(values) : values;
          close(result === undefined ? values : result);
        } catch (error) {
          save.disabled = false;
          showError(body, error);
        }
      };
      save.addEventListener('click', run);
      formNode.addEventListener('submit', (event) => { event.preventDefault(); run(); });
      Object.values(controls)[0]?.focus();
    });
  }

  function picker({ title, search, label, detail, initialItems = [], minLength = 2 }) {
    open(title);
    return new Promise((resolve) => {
      cancelCurrent = resolve;
      const searchInput = document.createElement('input');
      searchInput.className = 'eb-dialog__input';
      searchInput.type = 'search';
      searchInput.placeholder = t('search');
      const status = element('div', 'eb-dialog__status');
      const list = element('div', 'eb-dialog__list');
      body.append(searchInput, status, list);
      const cancel = button(t('cancel'));
      cancel.addEventListener('click', () => close(null));
      footer.append(cancel);
      let timer = 0;
      let revision = 0;

      function render(items) {
        list.replaceChildren();
        status.textContent = items.length ? '' : t('noResults');
        items.forEach((item) => {
          const row = button('', false);
          row.className = 'eb-dialog__list-item';
          const content = element('span', 'eb-dialog__list-copy');
          content.append(element('strong', '', label(item)));
          const secondary = detail?.(item);
          if (secondary) content.append(element('small', '', secondary));
          row.append(content);
          row.addEventListener('click', () => close(item));
          list.append(row);
        });
      }
      render(initialItems);
      searchInput.addEventListener('input', () => {
        window.clearTimeout(timer);
        const query = searchInput.value.trim();
        if (query.length < minLength) {
          status.textContent = query ? `${minLength}+` : '';
          list.replaceChildren();
          return;
        }
        const current = ++revision;
        status.textContent = '…';
        timer = window.setTimeout(async () => {
          try {
            const items = await search(query);
            if (current === revision) render(Array.isArray(items) ? items : []);
          } catch (error) {
            if (current === revision) showError(body, error);
          }
        }, 300);
      });
      searchInput.focus();
    });
  }

  function dealManager({ context, search, openDeal, linkDeal, unlinkDeal }) {
    open(t('deals'));
    const searchInput = document.createElement('input');
    searchInput.className = 'eb-dialog__input';
    searchInput.type = 'search';
    searchInput.placeholder = t('searchDeals');
    const status = element('div', 'eb-dialog__status');
    const linkedTitle = element('h3', 'eb-dialog__subheading', t('deals'));
    const linked = element('div', 'eb-dialog__list');
    const results = element('div', 'eb-dialog__list');
    body.append(searchInput, status, linkedTitle, linked, results);
    const cancel = button(t('cancel'));
    cancel.addEventListener('click', () => close(null));
    footer.append(cancel);

    function dealRow(deal, linkedDeal) {
      const row = element('div', 'eb-dialog__deal');
      const copy = element('span', 'eb-dialog__list-copy');
      copy.append(element('strong', '', deal.title || `#${deal.id}`));
      copy.append(element('small', '', `#${deal.id}`));
      const controls = element('span', 'eb-dialog__deal-controls');
      const open = button(t('openDeal'));
      open.addEventListener('click', () => openDeal(deal));
      controls.append(open);
      const mutate = button(linkedDeal ? t('unlinkDeal') : t('linkDeal'), !linkedDeal);
      mutate.addEventListener('click', async () => {
        mutate.disabled = true;
        try {
          if (linkedDeal) {
            if (!window.confirm(t('confirmUnlinkDeal'))) return;
            await unlinkDeal(deal);
            row.remove();
          } else {
            await linkDeal(deal);
            results.replaceChildren();
            renderLinked();
          }
        } catch (error) {
          showError(body, error);
        } finally {
          mutate.disabled = false;
        }
      });
      controls.append(mutate);
      row.append(copy, controls);
      return row;
    }

    function renderLinked() {
      linked.replaceChildren();
      const deals = context().deals || [];
      if (!deals.length) linked.append(element('div', 'eb-dialog__status', t('noResults')));
      deals.forEach((deal) => linked.append(dealRow(deal, true)));
    }
    renderLinked();
    let timer = 0;
    searchInput.addEventListener('input', () => {
      window.clearTimeout(timer);
      const query = searchInput.value.trim();
      if (query.length < 2) { results.replaceChildren(); status.textContent = ''; return; }
      status.textContent = '…';
      timer = window.setTimeout(async () => {
        try {
          const items = await search(query);
          results.replaceChildren();
          status.textContent = items.length ? '' : t('noResults');
          const linkedIds = new Set((context().deals || []).map((deal) => Number(deal.id)));
          items.filter((deal) => !linkedIds.has(Number(deal.id))).forEach((deal) => results.append(dealRow(deal, false)));
        } catch (error) { showError(body, error); }
      }, 300);
    });
    searchInput.focus();
  }

  function notify(message, kind = 'error') {
    const node = element('div', `eb-toast eb-toast--${kind}`, message);
    document.body.append(node);
    window.setTimeout(() => node.remove(), 4500);
  }

  return { form, picker, dealManager, notify, close };
}
