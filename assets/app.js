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

// ---- Installable app (service worker) ----
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
  navigator.serviceWorker.register('sw.js').catch(() => {});
}

// "Install the app" banner on the home page (browsers that support the install prompt).
let installEvent = null;
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  installEvent = e;
  const slot = document.getElementById('install-slot');
  let dismissed = false;
  try { dismissed = localStorage.getItem('rt-install-dismissed') === '1'; } catch (_) {}
  if (!slot || dismissed) return;
  slot.innerHTML = '<div class="install-banner"><img src="assets/icons/icon-192.png" alt="" width="40" height="40" style="border-radius:10px">'
    + '<span class="grow"><strong>Get the app</strong><br>Faster, works offline, and alerts you about messages.</span>'
    + '<button class="btn btn-primary btn-sm" data-install>Install</button><button class="iconbtn" data-install-close aria-label="Dismiss">✕</button></div>';
});
document.addEventListener('click', async (e) => {
  if (e.target.closest('[data-install]') && installEvent) {
    installEvent.prompt();
    await installEvent.userChoice;
    installEvent = null;
    document.getElementById('install-slot').innerHTML = '';
  }
  if (e.target.closest('[data-install-close]')) {
    try { localStorage.setItem('rt-install-dismissed', '1'); } catch (_) {}
    document.getElementById('install-slot').innerHTML = '';
  }
});

// ---- Push notifications toggle (Settings) ----
const pushBox = document.getElementById('push-box');
if (pushBox) {
  const status = document.getElementById('push-status');
  const btn = document.getElementById('push-toggle');
  const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const keyBytes = (b64) => {
    const s = atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - b64.length % 4) % 4));
    return Uint8Array.from(s, (c) => c.charCodeAt(0));
  };
  const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const post = (data) => fetch('push_subscribe.php', { method: 'POST', body: new URLSearchParams({ csrf: pushBox.dataset.csrf, ...data }) });
  const render = (sub) => {
    btn.hidden = false;
    btn.textContent = sub ? 'Turn off for this device' : 'Turn on for this device';
    btn.dataset.on = sub ? '1' : '';
    if (sub) status.textContent = 'Push notifications are on for this device.';
  };
  if (!supported) {
    status.textContent = 'This browser doesn\'t support push notifications. On iPhone, add ' + document.title.split('·').pop().trim() + ' to your Home Screen first.';
  } else if (Notification.permission === 'denied') {
    status.textContent = 'Notifications are blocked for this site. Allow them in your browser settings, then reload.';
  } else {
    navigator.serviceWorker.ready.then((reg) => reg.pushManager.getSubscription()).then(render);
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        const reg = await navigator.serviceWorker.ready;
        let sub = await reg.pushManager.getSubscription();
        if (btn.dataset.on) {
          if (sub) { await post({ action: 'remove', endpoint: sub.endpoint }); await sub.unsubscribe(); }
          status.textContent = 'Push notifications are off for this device.';
          render(null);
        } else {
          if (await Notification.requestPermission() !== 'granted') { status.textContent = 'Notifications weren\'t allowed.'; return; }
          sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(pushBox.dataset.vapid) });
          const res = await post({ endpoint: sub.endpoint, p256dh: b64(sub.getKey('p256dh')), auth: b64(sub.getKey('auth')) });
          if (!res.ok) throw new Error('save failed');
          render(sub);
        }
      } catch (_) {
        status.textContent = 'Something went wrong. Please try again.';
      } finally {
        btn.disabled = false;
      }
    });
  }
}
