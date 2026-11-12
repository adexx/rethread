// Favorite toggles without a page reload (falls back to a normal POST without JS).
document.addEventListener('submit', async (e) => {
  const form = e.target;
  if (!form.classList.contains('fav-form')) return;
  e.preventDefault();
  const btn = form.querySelector('.fav');
  try {
    const res = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' } });
    if (res.redirected && res.url.includes('login.php')) { location.href = res.url; return; }
    const data = await res.json();
    if (data.login) { location.href = data.login; return; }
    btn.classList.toggle('on', data.favorited);
    btn.setAttribute('aria-pressed', data.favorited ? 'true' : 'false');
    btn.querySelector('span').textContent = data.count;
  } catch (_) { form.submit(); }
});

// Image gallery dots
document.querySelectorAll('.gallery').forEach((g) => {
  const track = g.querySelector('.gallery-track');
  const dots = g.querySelectorAll('.gallery-dots span');
  if (!track || !dots.length) return;
  track.addEventListener('scroll', () => {
    const i = Math.round(track.scrollLeft / track.clientWidth);
    dots.forEach((d, n) => d.classList.toggle('on', n === i));
  }, { passive: true });
});

// Dialog openers: <button data-open="dialogId">
document.addEventListener('click', (e) => {
  const opener = e.target.closest('[data-open]');
  if (opener) { const d = document.getElementById(opener.dataset.open); if (d) d.showModal(); }
  const closer = e.target.closest('[data-close]');
  if (closer) closer.closest('dialog').close();
});

// "Show more": fetch the next page and append its items (without JS the link opens that page).
document.addEventListener('click', async (e) => {
  const link = e.target.closest('a[data-more]');
  if (!link) return;
  e.preventDefault();
  if (link.getAttribute('aria-busy') === 'true') return;
  link.setAttribute('aria-busy', 'true');
  try {
    const res = await fetch(link.href, { headers: { 'X-Requested-With': 'fetch' } });
    if (!res.ok) throw new Error(res.status);
    const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
    const grid = link.closest('.pager').previousElementSibling;
    doc.querySelectorAll('.grid > *').forEach((card) => grid.appendChild(document.adoptNode(card)));
    const next = doc.querySelector('a[data-more]');
    if (next) { link.href = next.href; link.removeAttribute('aria-busy'); } else { link.closest('.pager').remove(); }
  } catch (_) { location.href = link.href; }
});

// Auto-submit selects/radios marked data-autosubmit
document.addEventListener('change', (e) => {
  if (e.target.matches('[data-autosubmit]')) e.target.form.submit();
});

// Sell form: photo previews
const photoInput = document.getElementById('photos');
if (photoInput) {
  photoInput.addEventListener('change', () => {
    const wrap = document.getElementById('new-previews');
    wrap.innerHTML = '';
    [...photoInput.files].slice(0, 8).forEach((f) => {
      const fig = document.createElement('figure');
      const img = document.createElement('img');
      img.src = URL.createObjectURL(f);
      img.alt = '';
      fig.appendChild(img);
      wrap.appendChild(fig);
    });
  });
}

// Checkout: live totals when delivery choice changes
const co = document.getElementById('checkout');
if (co) {
  const base = parseFloat(co.dataset.base);
  const fmt = (n) => '$' + n.toFixed(2);
  const update = () => {
    const type = co.querySelector('input[name=delivery]:checked')?.value || 'pickup';
    let ship;
    if (type === 'pickup') {
      ship = parseFloat(co.dataset.pickup);
    } else {
      const c = co.querySelector('input[name=carrier]:checked');
      ship = parseFloat(c ? c.dataset.price : co.dataset.home);
    }
    document.querySelectorAll('[data-show]').forEach((el) => { el.hidden = el.dataset.show !== type; });
    document.querySelectorAll('.js-ship').forEach((el) => { el.textContent = fmt(ship); });
    document.querySelectorAll('.js-total').forEach((el) => { el.textContent = fmt(base + ship); });
  };
  co.addEventListener('change', update);
  update();
}

// Confirm buttons
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-confirm]');
  if (b && !confirm(b.dataset.confirm)) e.preventDefault();
});
