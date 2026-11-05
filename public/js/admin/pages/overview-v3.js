(() => {
  'use strict';
  const root = document.getElementById('overview-page');
  if (!root) return;
  const drawer = document.getElementById('ov3-drawer');
  const backdrop = document.getElementById('ov3-backdrop');
  const title = document.getElementById('ov3-drawer-title');
  const description = document.getElementById('ov3-drawer-description');
  const status = document.getElementById('ov3-drawer-status');
  const link = document.getElementById('ov3-drawer-link');
  let previousFocus = null;
  function close() {
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    backdrop.hidden = true;
    if (previousFocus) previousFocus.focus();
  }
  root.querySelectorAll('.ov3-inspect').forEach(button => {
    button.addEventListener('click', () => {
      previousFocus = document.activeElement;
      title.textContent = button.dataset.title || 'Details';
      description.textContent = button.dataset.description || '';
      status.textContent = button.dataset.status || '';
      link.href = button.dataset.link || '#';
      link.textContent = (button.dataset.linkLabel || 'Open record') + ' →';
      drawer.classList.add('open');
      drawer.setAttribute('aria-hidden', 'false');
      backdrop.hidden = false;
      document.getElementById('ov3-close').focus();
    });
  });
  document.getElementById('ov3-close').addEventListener('click', close);
  document.getElementById('ov3-dismiss').addEventListener('click', close);
  backdrop.addEventListener('click', close);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && drawer.classList.contains('open')) close(); });
  const filters = root.querySelectorAll('[data-event-filter]');
  const events = root.querySelectorAll('.ov3-event');
  const empty = document.getElementById('ov3-no-match');
  filters.forEach(button => button.addEventListener('click', () => {
    const selected = button.dataset.eventFilter;
    filters.forEach(b => { b.classList.toggle('selected', b === button); b.setAttribute('aria-pressed', String(b === button)); });
    let visible = 0;
    events.forEach(event => {
      const matches = selected === 'all' || event.dataset.kind === selected;
      event.hidden = !matches;
      if (matches) visible++;
    });
    empty.hidden = visible > 0 || events.length === 0;
  }));
})();
