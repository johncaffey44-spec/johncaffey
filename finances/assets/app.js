/* Boussole — finances du foyer · interface
   JavaScript sans dépendance. Toutes les données passent par api.php (même origine, cookie de session
   + jeton anti-CSRF). Les textes venant des relevés sont TOUJOURS échappés avant affichage. */
'use strict';

/* ============================================================== outils */
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const MOIS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const MOIS_C = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const eurF = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
const eurF0 = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
const E = (c, sign) => { const s = eurF.format((c || 0) / 100).replace('-', '−'); return sign && c > 0 ? '+' + s : s; };
const E0 = c => eurF0.format(Math.round((c || 0) / 100)).replace('-', '−');
const amt = (c, cls = '', sign = false) => `<span class="amt ${cls}">${esc(E(c, sign))}</span>`;
const fd = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4) : '';
const fdc = d => d ? (+d.slice(8, 10)) + ' ' + MOIS_C[+d.slice(5, 7)] : '';
const fm = m => m ? MOIS[+m.slice(5, 7)] + ' ' + m.slice(0, 4) : '';
const fmc = m => m ? MOIS_C[+m.slice(5, 7)] + ' ' + m.slice(2, 4) : '';
const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const toCents = v => { if (v == null) return null; let s = String(v).trim().replace(/\s| | |€/g, ''); if (s === '') return null; if (s.includes(',') && s.includes('.')) s = s.lastIndexOf(',') > s.lastIndexOf('.') ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, ''); else s = s.replace(',', '.'); const n = Number(s); return Number.isFinite(n) ? Math.round(n * 100) : NaN; };
const centsIn = c => c == null ? '' : (c / 100).toFixed(2).replace('.', ',');
const pl = (n, s, p) => n + ' ' + (n > 1 ? (p || s + 's') : s);
const store = { get(k, d) { try { const v = localStorage.getItem('boussole.' + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem('boussole.' + k, JSON.stringify(v)); } catch (e) { /* stockage indisponible : réglage non mémorisé */ } } };
const sleep = ms => new Promise(r => setTimeout(r, ms));

const S = { status: null, me: null, csrf: null, cats: {}, catList: [], route: 'accueil', q: {}, tok: 0, months: [], lastMonth: null, imp: null };
/* personnes du foyer */
const membres = () => (S.me && S.me.membres) || [];
const autres = () => membres().filter(m => !m.moi);
const autresActifs = () => autres().filter(m => m.active);
const nomMembre = id => { const m = membres().find(x => x.id === id); return m ? m.nom : 'Autre personne'; };
const listeNoms = l => l.length <= 1 ? (l[0] || '') : l.slice(0, -1).join(', ') + ' et ' + l[l.length - 1];

/* ============================================================== icônes */
const ICONS = {
  home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h5v-6h4v6h5V9.5"/>',
  upload: '<path d="M12 15V4"/><path d="m7 9 5-5 5 5"/><path d="M4 15v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
  bell: '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.3 20a1.9 1.9 0 0 0 3.4 0"/>',
  bank: '<path d="M3 10h18L12 4z"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8"/><path d="M3 21h18"/>',
  repeat: '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
  lock: '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
  users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 14a6.5 6.5 0 0 1 3 6"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
  scale: '<path d="M12 3v18M6 21h12M5 7h14"/><path d="m5 7-3 7a3.5 3.5 0 0 0 6 0zM19 7l-3 7a3.5 3.5 0 0 0 6 0z"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 0 1-4 0v-.1A1.6 1.6 0 0 0 6.5 19.4l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 2.6 14H2.5a2 2 0 0 1 0-4h.1A1.6 1.6 0 0 0 4.6 6.5l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 10 2.6V2.5a2 2 0 0 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.6 1.6 0 0 0 21.4 10h.1a2 2 0 0 1 0 4h-.1a1.6 1.6 0 0 0-2 1z"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
  moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
  eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  eyeoff: '<path d="M9.9 4.2A10.6 10.6 0 0 1 12 4c6.5 0 10 7 10 7a17 17 0 0 1-2.2 3.2M6.6 6.6A17.5 17.5 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2M2 2l20 20"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  x: '<path d="M18 6 6 18M6 6l12 12"/>',
  check: '<path d="m5 12.5 4.5 4.5L19 7"/>',
  alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
  octagon: '<path d="M7.9 2h8.2L22 7.9v8.2L16.1 22H7.9L2 16.1V7.9z"/><path d="M12 8v4M12 16h.01"/>',
  info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
  star: '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9z"/>',
  file: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
  download: '<path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M4 20h16"/>',
  trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
  edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4z"/>',
  copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  compass: '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5z"/>',
  bulb: '<path d="M9 18h6M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.3h6c0-1 .4-1.8 1-2.3A7 7 0 0 0 12 2z"/>',
  shield: '<path d="M12 22s8-3.5 8-10V5l-8-3-8 3v7c0 6.5 8 10 8 10z"/>',
};
const ic = (n, cls = '') => `<svg class="ic ${cls}" viewBox="0 0 24 24" aria-hidden="true">${ICONS[n] || ''}</svg>`;
const LOGO = '<span class="logo"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.2" fill="none" stroke="#fff" stroke-width="1.6"/><path d="M12 4.6 14.6 12H9.4z" fill="#fff"/><path d="M12 19.4 14.6 12H9.4z" fill="#ffffff" fill-opacity=".45"/><circle cx="12" cy="12" r="1.3" fill="#8e0a18"/></svg></span>';
const LEVEL_IC = { critique: 'octagon', attention: 'alert', info: 'info', bravo: 'star' };
const LEVEL_TXT = { critique: 'Critique', attention: 'À vérifier', info: 'Conseil', bravo: 'Bravo' };

/* ============================================================== API */
async function api(action, { method = 'GET', body = null, query = null, form = null } = {}) {
  let url = 'api.php?a=' + encodeURIComponent(action);
  if (query) for (const [k, v] of Object.entries(query)) if (v !== '' && v != null) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
  const opt = { method, credentials: 'same-origin', cache: 'no-store', headers: {} };
  if (method === 'POST') {
    opt.headers['X-CSRF'] = S.csrf || '';
    if (form) opt.body = form;
    else { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body || {}); }
  }
  let res;
  try { res = await fetch(url, opt); } catch (e) { throw new Error('Serveur injoignable : vérifiez votre connexion.'); }
  const ct = res.headers.get('Content-Type') || '';
  if (!ct.includes('application/json')) {
    if (res.ok) return res;
    throw new Error('Erreur ' + res.status + ' : api.php ne répond pas correctement (PHP est-il actif sur ce serveur ?).');
  }
  const data = await res.json();
  if (res.status === 401 && data.code === 'auth') { S.me = null; renderLogin(data.error); throw new Error(data.error); }
  if (!res.ok) { const err = new Error(data.error || ('Erreur ' + res.status)); err.code = data.code || ''; throw err; }
  return data;
}

/* ============================================================== notifications, fenêtres, infobulles */
function toast(msg, ok = true) {
  const t = document.createElement('div');
  t.className = 'toast' + (ok ? ' ok' : '');
  t.textContent = msg;
  $('#toasts').appendChild(t);
  setTimeout(() => t.remove(), ok ? Math.max(3800, msg.length * 45) : Math.max(6500, msg.length * 60));
}
function modal({ title, html, wide = false, actions = [], onOpen = null }) {
  const o = document.createElement('div');
  o.className = 'ovl';
  o.innerHTML = `<div class="mdl ${wide ? 'wide' : ''}" role="dialog" aria-modal="true" aria-label="${esc(title)}"><div class="mdl-h"><h2 class="grow">${esc(title)}</h2><button class="btn ghost sm" data-close aria-label="Fermer">${ic('x')}</button></div><div class="mdl-b">${html}</div>${actions.length ? '<div class="mdl-f"></div>' : ''}</div>`;
  const prev = document.activeElement;
  const close = () => { o.remove(); document.removeEventListener('keydown', onKey); if (prev && prev.focus) prev.focus(); };
  const onKey = e => { if (e.key === 'Escape') close(); };
  o.addEventListener('mousedown', e => { if (e.target === o) close(); });
  o.addEventListener('click', e => { if (e.target.closest('[data-close]')) close(); });
  for (const a of actions) {
    const b = document.createElement('button');
    b.className = 'btn ' + (a.kind || '');
    b.textContent = a.label;
    b.addEventListener('click', async () => {
      if (!a.onClick) return close();
      b.disabled = true;
      try { const r = await a.onClick(o, b); if (r !== false) close(); } catch (e) { toast(e.message, false); } finally { b.disabled = false; }
    });
    o.querySelector('.mdl-f').appendChild(b);
  }
  document.body.appendChild(o);
  document.addEventListener('keydown', onKey);
  if (onOpen) onOpen(o, close);
  const f = o.querySelector('[autofocus]') || o.querySelector('.mdl-b input:not([type=checkbox]), .mdl-b select, .mdl-b textarea') || o.querySelector('.mdl-f .btn');
  if (f) f.focus();
  return { el: o, close };
}
function confirmBox(title, text, okLabel = 'Confirmer', danger = false) {
  return new Promise(res => {
    let done = false;
    const m = modal({ title, html: `<p>${esc(text)}</p>`, actions: [{ label: 'Annuler', onClick: () => { done = true; res(false); } }, { label: okLabel, kind: danger ? 'primary' : 'primary', onClick: () => { done = true; res(true); } }] });
    const obs = new MutationObserver(() => { if (!document.body.contains(m.el)) { obs.disconnect(); if (!done) res(false); } });
    obs.observe(document.body, { childList: true });
  });
}
function showTip(lines, x, y) {
  const t = $('#tip');
  t.replaceChildren();
  lines.forEach((l, i) => {
    const d = document.createElement('div');
    if (typeof l === 'object' && l) {
      d.className = 'tr';
      const k = document.createElement('i'); k.className = 'lk'; k.style.background = l.color || 'var(--ink-3)';
      const n = document.createElement('span'); n.textContent = l.name;
      const b = document.createElement('b'); b.textContent = l.value;
      d.append(k, n, b);
    } else { d.className = i === 0 ? 't0' : 'tr'; d.textContent = l; }
    t.appendChild(d);
  });
  t.classList.add('on');
  const r = t.getBoundingClientRect();
  let left = x + 14, top = y + 14;
  if (left + r.width > innerWidth - 8) left = x - r.width - 14;
  if (top + r.height > innerHeight - 8) top = y - r.height - 14;
  t.style.left = Math.max(8, left) + 'px';
  t.style.top = Math.max(8, top) + 'px';
}
function hideTip() { $('#tip').classList.remove('on'); }
document.addEventListener('pointermove', e => {
  const el = e.target.closest && e.target.closest('[data-tip]');
  if (el) showTip(el.dataset.tip.split('\n'), e.clientX, e.clientY);
  else if (!e.target.closest || !e.target.closest('.chart')) hideTip();
});
document.addEventListener('focusin', e => {
  const el = e.target.closest && e.target.closest('[data-tip]');
  if (el) { const r = el.getBoundingClientRect(); showTip(el.dataset.tip.split('\n'), r.left + r.width / 2, r.bottom); }
});
document.addEventListener('focusout', hideTip);

async function copyText(txt) {
  try { await navigator.clipboard.writeText(txt); toast('Copié dans le presse-papiers.'); return; } catch (e) { /* repli ci-dessous */ }
  const ta = document.createElement('textarea');
  ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); toast('Copié dans le presse-papiers.'); } catch (e) { toast('Copie impossible : sélectionnez le texte à la main.', false); }
  ta.remove();
}

/* ============================================================== thème, mode discret */
function applyTheme() {
  const t = store.get('theme', 'auto');
  if (t === 'auto') document.documentElement.removeAttribute('data-theme'); else document.documentElement.setAttribute('data-theme', t);
  document.body.classList.toggle('discret', !!store.get('discret', false));
}
function isDark() { const a = document.documentElement.getAttribute('data-theme'); return a ? a === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches; }

/* ============================================================== démarrage */
document.addEventListener('DOMContentLoaded', boot);
async function boot() {
  applyTheme();
  try { S.status = await api('status'); }
  catch (e) { $('#root').innerHTML = `<div class="auth"><div class="auth-card"><div class="sb-brand">${LOGO}<div><b>Boussole</b><small>finances du foyer</small></div></div><h1>${e.code === 'reseau' ? 'Accès réservé à la maison' : 'Démarrage impossible'}</h1><p class="err">${esc(e.message)}</p>${e.code === 'reseau' ? '' : '<p class="sec small">Ouvrez l’application par son adresse http(s)://… sur un serveur avec PHP 7.4 ou plus, et non en double-cliquant sur le fichier.</p>'}</div></div>`; return; }
  if (!S.status.installe) return renderSetup();
  if (location.hash.startsWith('#/reinit')) { const j = new URLSearchParams(location.hash.split('?')[1] || '').get('jeton'); if (j) return renderReset(j); }
  if (location.hash.startsWith('#/activer') && !S.status.moi) { const c = new URLSearchParams(location.hash.split('?')[1] || '').get('code'); history.replaceState(null, '', location.pathname); return renderActivate(c || ''); }
  if (!S.status.moi) return renderLogin();
  S.csrf = S.status.csrf;
  await startApp();
}
async function startApp() {
  try { S.me = await api('me'); } catch (e) { return; }
  S.cats = {}; S.catList = S.me.categories;
  for (const c of S.me.categories) S.cats[c.id] = c;
  renderShell();
  route();
  refreshBadge();
  probeData();
}
window.addEventListener('hashchange', () => { if (S.me) route(); });

/* ============================================================== écrans de connexion */
function authFrame(inner) {
  $('#root').innerHTML = `<div class="auth"><div class="auth-card"><div class="sb-brand">${LOGO}<div><b>Boussole</b><small>${esc(S.status && S.status.foyer ? S.status.foyer : 'finances du foyer')}</small></div></div>${inner}</div></div>`;
  $$('.pw button').forEach(b => b.addEventListener('click', () => { const i = b.previousElementSibling; i.type = i.type === 'password' ? 'text' : 'password'; b.innerHTML = ic(i.type === 'password' ? 'eye' : 'eyeoff'); }));
}
const pwPair = (id, label) => pwField(id, label, 'new-password') + pwField(id + '2', 'Confirmez le mot de passe', 'new-password') + `<div class="help" id="${id}h" style="margin:-6px 0 10px"></div>`;
function bindPwHint(id) {
  const a = $('#' + id), b = $('#' + id + '2'), h = $('#' + id + 'h'), min = (S.status && S.status.min_password) || 10;
  const upd = () => { const n = [...a.value].length; h.textContent = n < min ? n + ' / ' + min + ' caractères au moins — une courte phrase est plus facile à retenir et plus solide.' : (b.value && b.value !== a.value ? 'Les deux saisies ne correspondent pas encore.' : (b.value ? '✓ Mots de passe identiques.' : '✓ Longueur suffisante.')); };
  a.addEventListener('input', upd); b.addEventListener('input', upd); upd();
}
function pwCheck(id) { const a = $('#' + id).value; if (a !== $('#' + id + '2').value) throw new Error('Les deux mots de passe ne correspondent pas.'); return a; }
const pwField = (id, label, auto = 'current-password') => `<div class="field"><label class="f" for="${id}">${label}</label><div class="pw"><input class="in" id="${id}" type="password" autocomplete="${auto}" required><button type="button" aria-label="Afficher ou masquer">${ic('eye')}</button></div></div>`;

function renderLogin(msg = '') {
  const pending = (S.status && S.status.invitation_en_attente) || [];
  authFrame(`<h1>Connexion</h1><p class="sec small">Chacun a son propre accès : vos opérations restent privées tant que vous ne choisissez pas de les partager.</p>
    <form id="fl"><div class="field"><label class="f" for="ln">Adresse e-mail</label><input class="in" id="ln" type="email" inputmode="email" autocomplete="username" required placeholder="prenom.nom@exemple.fr"></div>${pwField('lp', 'Mot de passe')}
    <p class="err" id="le">${esc(msg)}</p><button class="btn primary" style="width:100%">Se connecter</button></form>
    <div class="links"><button type="button" id="goinv">${pending.length ? 'Activer mon compte (code d’invitation)' : 'J’ai un code d’invitation'}</button><button type="button" id="gorec">Mot de passe oublié</button></div>`);
  $('#fl').addEventListener('submit', async e => {
    e.preventDefault();
    try { const r = await api('login', { method: 'POST', body: { identifiant: $('#ln').value, mot_de_passe: $('#lp').value } }); S.csrf = r.csrf; await startApp(); }
    catch (err) { $('#le').textContent = err.message; }
  });
  $('#goinv').addEventListener('click', () => renderActivate());
  $('#gorec').addEventListener('click', renderForgot);
  $('#ln').focus();
}
function renderActivate(code = '') {
  authFrame(`<h1>Activer mon compte</h1><p class="sec small">Saisissez le code d’invitation reçu, puis choisissez VOTRE mot de passe (personne d’autre ne le connaîtra).</p>
    <form id="fa"><div class="field"><label class="f" for="ac">Code d’invitation</label><input class="in" id="ac" autocomplete="one-time-code" required placeholder="XXXX-XXXX-XXXX" value="${esc(typeof code === 'string' ? code : '')}"></div>
    <div class="field"><label class="f" for="aem">Votre adresse e-mail</label><input class="in" id="aem" type="email" inputmode="email" autocomplete="username" required placeholder="prenom.nom@exemple.fr"><div class="help">Ce sera votre identifiant ; vos alertes y seront envoyées.</div></div>
    ${pwPair('ap', 'Créez votre mot de passe (' + (S.status.min_password || 10) + ' caractères au moins)')}
    <p class="err" id="ae"></p><button class="btn primary" style="width:100%">Activer</button></form><div class="links"><button type="button" id="back">← Retour à la connexion</button></div>`);
  $('#back').addEventListener('click', () => renderLogin());
  bindPwHint('ap');
  $('#fa').addEventListener('submit', async e => {
    e.preventDefault();
    try { const pw = pwCheck('ap'); const r = await api('activate', { method: 'POST', body: { code: $('#ac').value, email: $('#aem').value, mot_de_passe: pw, confirmation: pw } }); S.csrf = r.csrf; await startApp(); showRecovery(r.code_secours, 'Bienvenue ' + r.nom + ' !'); }
    catch (err) { $('#ae').textContent = err.message; }
  });
}
function renderForgot() {
  if (!S.status.mail) return renderRecover('L’envoi d’e-mails n’est pas encore configuré sur ce serveur : utilisez votre code de secours.');
  authFrame(`<h1>Mot de passe oublié</h1><p class="sec small">Indiquez votre adresse : vous recevrez un lien valable 30 minutes pour choisir un nouveau mot de passe.</p>
    <form id="ff"><div class="field"><label class="f" for="fe">Adresse e-mail</label><input class="in" id="fe" type="email" inputmode="email" autocomplete="username" required></div><p class="err" id="fer"></p><div class="note ok hide" id="fok" style="margin-bottom:10px"></div><button class="btn primary" style="width:100%">Recevoir le lien</button></form>
    <div class="links"><button type="button" id="back">← Retour à la connexion</button><button type="button" id="gorec">J’ai mon code de secours</button></div>`);
  $('#back').addEventListener('click', () => renderLogin());
  $('#gorec').addEventListener('click', () => renderRecover());
  $('#ff').addEventListener('submit', async e => {
    e.preventDefault();
    $('#fer').textContent = '';
    try { const r = await api('forgot', { method: 'POST', body: { identifiant: $('#fe').value } }); const ok = $('#fok'); ok.textContent = r.message; ok.classList.remove('hide'); }
    catch (err) { $('#fer').textContent = err.message; }
  });
  $('#fe').focus();
}
function renderReset(jeton) {
  authFrame(`<h1>Nouveau mot de passe</h1><p class="sec small">Choisissez votre nouveau mot de passe. Ce lien ne sert qu’une fois.</p>
    <form id="frs">${pwPair('np', 'Nouveau mot de passe')}<p class="err" id="rse"></p><button class="btn primary" style="width:100%">Enregistrer et me connecter</button></form>
    <div class="links"><button type="button" id="back">← Retour à la connexion</button></div>`);
  bindPwHint('np');
  $('#back').addEventListener('click', () => { history.replaceState(null, '', location.pathname + location.search); renderLogin(); });
  $('#frs').addEventListener('submit', async e => {
    e.preventDefault();
    try { const pw = pwCheck('np'); const r = await api('reset', { method: 'POST', body: { jeton, mot_de_passe: pw, confirmation: pw } }); S.csrf = r.csrf; history.replaceState(null, '', location.pathname + location.search + '#/accueil'); await startApp(); toast('Mot de passe changé : vous êtes connecté(e).'); }
    catch (err) { $('#rse').textContent = err.message; }
  });
}
function renderRecover(note = '') {
  authFrame(`<h1>Code de secours</h1><p class="sec small">${note ? esc(note) + ' ' : ''}Utilisez le code de secours qui vous a été donné à la création de votre accès.</p>
    <form id="fr"><div class="field"><label class="f" for="rn">Adresse e-mail</label><input class="in" id="rn" type="email" inputmode="email" autocomplete="username" required></div><div class="field"><label class="f" for="rc">Code de secours</label><input class="in" id="rc" required placeholder="XXXX-XXXX-XXXX-XXXX"></div>${pwPair('rp', 'Nouveau mot de passe')}
    <p class="err" id="re"></p><button class="btn primary" style="width:100%">Choisir ce mot de passe</button></form><div class="links"><button type="button" id="back">← Retour à la connexion</button></div>`);
  $('#back').addEventListener('click', () => renderLogin());
  bindPwHint('rp');
  $('#fr').addEventListener('submit', async e => {
    e.preventDefault();
    try { const pw = pwCheck('rp'); const r = await api('recover', { method: 'POST', body: { identifiant: $('#rn').value, code: $('#rc').value, mot_de_passe: pw, confirmation: pw } }); S.csrf = r.csrf; await startApp(); showRecovery(r.code_secours, 'Mot de passe changé'); }
    catch (err) { $('#re').textContent = err.message; }
  });
}
function renderSetup() {
  const st = S.status;
  const checks = [
    [st.https || ['localhost', '127.0.0.1'].includes(location.hostname), st.https ? 'Connexion chiffrée (https)' : 'Connexion non chiffrée : acceptable chez vous en réseau local, indispensable à corriger avant tout accès par Internet'],
    [!!st.chiffrement, st.chiffrement ? 'Documents chiffrés avec ' + st.chiffrement : 'Aucun module de chiffrement dans PHP (sodium ou openssl)'],
    [true, 'Taille maximale d’un fichier : ' + Math.round(st.max_upload / 1048576 * 10) / 10 + ' Mo'],
  ];
  authFrame(`<h1>Première mise en service</h1><p class="sec small">Deux minutes : vous créez votre accès, puis, si vous voulez, un code d’invitation pour une autre personne du foyer, qui choisira elle-même son mot de passe. Vous pourrez ajouter d’autres personnes ensuite (page Foyer).</p>
    <div class="note" style="margin:10px 0 14px">${checks.map(([ok, t]) => `<div class="row" style="align-items:flex-start;margin:3px 0"><span class="${ok ? 'pos' : ''}" style="${ok ? '' : 'color:var(--warn)'}">${ic(ok ? 'check' : 'alert', 'sm')}</span><span>${esc(t)}</span></div>`).join('')}</div>
    <form id="fs"><div class="field"><label class="f" for="sf">Nom du foyer</label><input class="in" id="sf" placeholder="Notre foyer"></div>
    <div class="field"><label class="f" for="sn">Votre prénom</label><input class="in" id="sn" required autocomplete="given-name"></div>
    <div class="field"><label class="f" for="semail">Votre adresse e-mail</label><input class="in" id="semail" type="email" inputmode="email" autocomplete="username" required placeholder="prenom.nom@exemple.fr"><div class="help">C’est votre identifiant de connexion ; vos alertes y seront envoyées.</div></div>
    ${pwPair('sp', 'Créez votre mot de passe (' + (st.min_password || 10) + ' caractères au moins)')}
    <div class="field"><label class="f" for="s2">Prénom de votre conjoint(e) (facultatif)</label><input class="in" id="s2"><div class="help">Cette personne recevra un code d’invitation à usage unique et sera, comme vous, administratrice du foyer.</div></div>
    <p class="err" id="se"></p><button class="btn primary" style="width:100%">Créer mon accès</button></form>`);
  bindPwHint('sp');
  $('#fs').addEventListener('submit', async e => {
    e.preventDefault();
    try {
      const pw = pwCheck('sp');
      const r = await api('setup', { method: 'POST', body: { foyer: $('#sf').value, nom: $('#sn').value, email: $('#semail').value, mot_de_passe: pw, confirmation: pw, nom_partenaire: $('#s2').value } });
      S.csrf = r.csrf;
      await startApp();
      showRecovery(r.code_secours, 'Accès créé', r.invitation ? { code: r.invitation, nom: r.partenaire } : null, r.email);
    } catch (err) { $('#se').textContent = err.message; }
  });
}
function showRecovery(code, title, invite = null, email = '') {
  modal({ title, wide: !!invite, html: `
    <p><b>Votre code de secours</b> — notez-le sur papier ou dans un gestionnaire de mots de passe. C’est la seule façon de retrouver l’accès si vous oubliez votre mot de passe ; il ne sera plus jamais affiché.</p>
    <div class="code">${esc(code)}</div>
    ${email ? `<p class="small sec" style="margin-top:12px">Votre identifiant : <b>${esc(email)}</b>. Pour y recevoir vos alertes, réglez l’envoi des e-mails dans <a href="#/reglages" data-close>Réglages › Envoi des e-mails</a>.</p>` : ''}
    ${invite ? `<p style="margin-top:18px"><b>Code d’invitation pour ${esc(invite.nom)}</b> — valable 30 jours, à usage unique. Transmettez-le de vive voix ou par message : ${esc(invite.nom)} ouvrira l’application, cliquera sur « J’ai un code d’invitation » et choisira son propre mot de passe.</p><div class="code">${esc(invite.code)}</div>` : ''}`,
    actions: [{ label: 'J’ai noté ' + (invite ? 'les codes' : 'le code'), kind: 'primary' }] });
}

/* ============================================================== coquille : menu latéral */
const ROUTES = {
  accueil: { t: 'Tableau de bord', i: 'home', r: viewHome, sec: 'Suivi' },
  importer: { t: 'Importer un relevé', i: 'upload', r: viewImport },
  operations: { t: 'Opérations', i: 'list', r: viewTx },
  alertes: { t: 'Alertes', i: 'bell', r: viewAlerts },
  frais: { t: 'Frais bancaires', i: 'bank', r: viewFees, sec: 'Agir' },
  abonnements: { t: 'Abonnements', i: 'repeat', r: viewSubs },
  courriers: { t: 'Courriers', i: 'mail', r: viewLetters },
  droits: { t: 'Vos droits', i: 'scale', r: viewRights },
  coffre: { t: 'Coffre-fort', i: 'lock', r: viewVault, sec: 'Foyer' },
  foyer: { t: 'Foyer & équité', i: 'users', r: viewHousehold },
  reglages: { t: 'Réglages', i: 'settings', r: viewSettings },
};
function renderShell() {
  const me = S.me.moi;
  const nav = Object.entries(ROUTES).map(([k, r]) => (r.sec ? `<div class="sb-sec">${r.sec}</div>` : '') + `<a href="#/${k}" data-r="${k}">${ic(r.i)}<span>${esc(r.t)}</span>${k === 'alertes' ? '<span class="badge hide" id="abadge"></span>' : ''}</a>`).join('');
  $('#root').innerHTML = `<div class="app">
    <aside class="sb" id="sb" aria-label="Menu principal">
      <div class="sb-brand">${LOGO}<div><b>Boussole</b><small>${esc(S.me.foyer.nom)}</small></div></div>
      <nav class="sb-nav">${nav}</nav>
      <div class="sb-foot">
        <div class="sb-user"><span class="avatar">${esc(me.nom.slice(0, 1).toUpperCase())}</span><div class="grow"><b>${esc(me.nom)}</b><small>${esc(S.me.moi.admin ? 'administrateur' : 'membre')}${autresActifs().length ? ' · ' + pl(autresActifs().length + 1, 'personne') : ''}</small></div></div>
        <div class="sb-tools">
          <button id="tdisc" title="Mode discret : floute les montants" aria-pressed="${store.get('discret', false)}">${ic(store.get('discret', false) ? 'eyeoff' : 'eye')}</button>
          <button id="ttheme" title="Thème clair / sombre">${ic(isDark() ? 'sun' : 'moon')}</button>
          <button id="tout" title="Se déconnecter">${ic('logout')}</button>
        </div>
      </div>
    </aside>
    <div class="main">
      <header class="top"><button class="btn ghost burger" id="burger" aria-label="Ouvrir le menu">${ic('menu')}</button><div id="ptitle" class="ptitle grow" aria-hidden="true"></div><a class="btn primary sm hide-m" href="#/importer">${ic('upload', 'sm')}Importer</a></header>
      <div id="banners"></div>
      <main id="view" tabindex="-1"></main>
    </div>
  </div>`;
  $('#burger').addEventListener('click', () => { $('#sb').classList.add('open'); const s = document.createElement('div'); s.className = 'scrim'; s.id = 'scrim'; s.addEventListener('click', closeSb); document.body.appendChild(s); });
  $('#tdisc').addEventListener('click', e => { const v = !store.get('discret', false); store.set('discret', v); applyTheme(); e.currentTarget.setAttribute('aria-pressed', v); e.currentTarget.innerHTML = ic(v ? 'eyeoff' : 'eye'); toast(v ? 'Mode discret : survolez un montant pour le voir.' : 'Montants visibles.'); });
  $('#ttheme').addEventListener('click', e => { store.set('theme', isDark() ? 'light' : 'dark'); applyTheme(); e.currentTarget.innerHTML = ic(isDark() ? 'sun' : 'moon'); route(); });
  $('#tout').addEventListener('click', async () => { try { await api('logout', { method: 'POST' }); } catch (e) { /* déjà déconnecté */ } S.me = null; S.csrf = null; S.status = await api('status'); renderLogin('Vous êtes déconnecté(e).'); });
}
function closeSb() { const sb = $('#sb'); if (sb) sb.classList.remove('open'); const s = $('#scrim'); if (s) s.remove(); }
function parseHash() {
  const h = location.hash.replace(/^#\/?/, '');
  const i = h.indexOf('?');
  const path = i >= 0 ? h.slice(0, i) : h;
  const q = {};
  if (i >= 0) new URLSearchParams(h.slice(i + 1)).forEach((v, k) => { q[k] = v; });
  return { r: ROUTES[path] ? path : 'accueil', q };
}
function go(r, q = {}) { const qs = new URLSearchParams(Object.entries(q).filter(([, v]) => v !== '' && v != null)).toString(); location.hash = '#/' + r + (qs ? '?' + qs : ''); }
async function route() {
  if (!$('#view')) return;
  const { r, q } = parseHash();
  S.route = r; S.q = q;
  const tok = ++S.tok;
  $$('.sb-nav a').forEach(a => a.classList.toggle('on', a.dataset.r === r));
  closeSb(); hideTip();
  $('#ptitle').textContent = ROUTES[r].t;
  document.title = ROUTES[r].t + ' · Boussole';
  const v = $('#view');
  v.innerHTML = '<div class="mut" style="padding:40px 4px">Chargement…</div>';
  try { await ROUTES[r].r(v, q, tok); }
  catch (e) { if (tok === S.tok && S.me) v.innerHTML = `<div class="note bad">${esc(e.message)}</div>`; }
}
const live = tok => tok === S.tok;
async function refreshBadge() {
  try {
    const r = await api('alerts');
    const crit = r.alertes.filter(a => a.niveau === 'critique').length;
    const att = r.alertes.filter(a => a.niveau === 'attention').length;
    const b = $('#abadge');
    if (!b) return;
    const n = crit + att;
    b.textContent = n; b.classList.toggle('hide', n === 0); b.classList.toggle('calm', crit === 0);
    b.title = crit + ' critique(s), ' + att + ' à vérifier';
  } catch (e) { /* badge non essentiel */ }
}
async function probeData() {
  const bn = $('#banners'); if (!bn) return;
  let html = '';
  let checked = false;
  try { checked = sessionStorage.getItem('boussole.probe') === 'ok'; } catch (e) { /* stockage indisponible : on vérifie à chaque fois */ }
  // Sonde de sécurité : un 404 dans la console est le BON résultat (dossier data inaccessible). Une fois par session.
  if (!checked) try {
    const r = await fetch('data/probe.txt', { cache: 'no-store', credentials: 'omit' });
    const exposed = r.ok && (await r.text()).includes('boussole-data-exposed');
    if (!exposed) { try { sessionStorage.setItem('boussole.probe', 'ok'); } catch (e) { /* sans importance */ } }
    if (exposed) html += `<div class="banner crit">${ic('octagon')}<div><b>Le dossier « data » est lisible depuis le navigateur.</b>N’importe qui pourrait télécharger votre base de données. Le serveur ignore le fichier .htaccess (nginx, serveur intégré de PHP…) : placez le dossier data hors de la racine web ($DATA_DIR dans api.php) ou interdisez-le dans la configuration du serveur.</div></div>`;
  } catch (e) { /* injoignable = protégé */ }
  if (!S.status.https && !['localhost', '127.0.0.1', '::1'].includes(location.hostname) && !/^(192\.168|10\.|172\.(1[6-9]|2\d|3[01]))\./.test(location.hostname)) html += `<div class="banner warn">${ic('alert')}<div><b>Connexion non chiffrée (http).</b>Vos mots de passe et vos relevés circulent en clair. Activez https (certificat gratuit Let’s Encrypt) puis $REQUIRE_HTTPS = true dans api.php.</div></div>`;
  bn.innerHTML = html;
}

/* ============================================================== graphiques */
function niceTicks(min, max, n = 4) {
  const span = max - min || 1;
  const raw = span / n;
  const pow = Math.pow(10, Math.floor(Math.log10(raw)));
  const step = [1, 2, 2.5, 5, 10].map(m => m * pow).find(s => s >= raw) || 10 * pow;
  const lo = Math.floor(min / step) * step, hi = Math.ceil(max / step) * step;
  const out = [];
  for (let v = lo; v <= hi + step / 2; v += step) out.push(Math.round(v));
  return out;
}
function fmtAxis(c) { const v = c / 100; return Math.abs(v) >= 10000 ? (v / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' k€' : Math.round(v).toLocaleString('fr-FR') + ' €'; }

/** Courbes (2 px), réticule, infobulle listant toutes les séries, vue en tableau. series : [{name, color, values}] */
function lineChart(el, { labels, series, tipLabels = null, height = 230, area = false, zero = false, marks = [] }) {
  const W = Math.max(320, Math.round(el.clientWidth || 640)), H = height, m = { l: 58, r: 18, t: 14, b: 28 };
  const all = series.flatMap(s => s.values).filter(v => v != null);
  if (!all.length) { el.innerHTML = '<p class="mut small">Pas encore de données.</p>'; return; }
  const ticks = niceTicks(Math.min(0, ...all), Math.max(0, ...all), 4);
  const min = ticks[0], max = ticks[ticks.length - 1] === min ? min + 100 : ticks[ticks.length - 1];
  const n = labels.length;
  const X = i => m.l + (n <= 1 ? (W - m.l - m.r) / 2 : i * (W - m.l - m.r) / (n - 1));
  const Y = v => m.t + (max - v) * (H - m.t - m.b) / (max - min);
  let g = '';
  for (const t of ticks) g += `<line class="${t === 0 && min < 0 ? 'zero' : 'gl'}" x1="${m.l}" x2="${W - m.r}" y1="${Y(t)}" y2="${Y(t)}"/><text x="${m.l - 8}" y="${Y(t) + 4}" text-anchor="end">${esc(fmtAxis(t))}</text>`;
  const every = Math.max(1, Math.ceil(n / 7));
  labels.forEach((lb, i) => { if (i % every === 0 || i === n - 1) g += `<text x="${X(i)}" y="${H - 8}" text-anchor="${i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}">${esc(lb)}</text>`; });
  let paths = '';
  series.forEach(s => {
    const pts = s.values.map((v, i) => v == null ? null : [X(i), Y(v)]).filter(Boolean);
    if (!pts.length) return;
    const d = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join('');
    if (area && series.length === 1) paths += `<path d="${d}L${pts[pts.length - 1][0]},${Y(Math.max(min, 0))}L${pts[0][0]},${Y(Math.max(min, 0))}Z" fill="var(--wash)" stroke="none"/>`;
    paths += `<path d="${d}" fill="none" stroke="${s.color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>`;
    const last = pts[pts.length - 1];
    paths += `<circle cx="${last[0]}" cy="${last[1]}" r="4" fill="${s.color}" stroke="var(--surface)" stroke-width="2"/>`;
  });
  for (const mk of marks) paths += `<circle cx="${X(mk.i)}" cy="${Y(mk.v)}" r="4.5" fill="${mk.color}" stroke="var(--surface)" stroke-width="2"/><text x="${X(mk.i)}" y="${Y(mk.v) + (mk.below ? 18 : -10)}" text-anchor="middle" style="fill:var(--ink-2);font-weight:600">${esc(mk.label)}</text>`;
  const legend = series.length > 1 ? `<div class="legend">${series.map(s => `<span><i class="lk" style="background:${s.color}"></i>${esc(s.name)}</span>`).join('')}</div>` : '';
  const rows = labels.map((lb, i) => `<tr><td>${esc((tipLabels || labels)[i])}</td>${series.map(s => `<td class="r num">${s.values[i] == null ? '—' : esc(E(s.values[i]))}</td>`).join('')}</tr>`).join('');
  el.innerHTML = `${legend}<div class="chart"><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(series.map(s => s.name).join(', '))}">${g}${paths}<line class="xh" y1="${m.t}" y2="${H - m.b}" x1="0" x2="0" visibility="hidden"/><rect class="hit" x="${m.l - 6}" y="0" width="${W - m.l - m.r + 12}" height="${H}" fill="transparent" tabindex="0"/></svg></div>
    <details class="tablev"><summary>Voir en tableau</summary><div class="tbl-wrap"><table class="tbl"><thead><tr><th></th>${series.map(s => `<th class="r">${esc(s.name)}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div></details>`;
  const svg = el.querySelector('svg'), xh = el.querySelector('.xh'), hit = el.querySelector('.hit');
  let cur = -1;
  const show = (i, cx, cy) => {
    cur = i;
    xh.setAttribute('x1', X(i)); xh.setAttribute('x2', X(i)); xh.setAttribute('visibility', 'visible');
    showTip([(tipLabels || labels)[i], ...series.map(s => ({ name: s.name, value: s.values[i] == null ? '—' : E(s.values[i]), color: s.color }))], cx, cy);
  };
  hit.addEventListener('pointermove', e => {
    const r = svg.getBoundingClientRect();
    const px = (e.clientX - r.left) * W / r.width;
    let best = 0, bd = Infinity;
    for (let i = 0; i < n; i++) { const d = Math.abs(X(i) - px); if (d < bd) { bd = d; best = i; } }
    show(best, e.clientX, e.clientY);
  });
  hit.addEventListener('pointerleave', () => { hideTip(); xh.setAttribute('visibility', 'hidden'); });
  hit.addEventListener('keydown', e => {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    e.preventDefault();
    const i = Math.min(n - 1, Math.max(0, (cur < 0 ? n - 1 : cur) + (e.key === 'ArrowRight' ? 1 : -1)));
    const r = svg.getBoundingClientRect();
    show(i, r.left + X(i) * r.width / W, r.top + 30);
  });
  hit.addEventListener('blur', () => { hideTip(); xh.setAttribute('visibility', 'hidden'); });
}

/** Colonnes empilées (≤ 24 px, bout arrondi, 2 px d'écart), une infobulle par colonne. */
function stackChart(el, { labels, series, height = 220 }) {
  const W = Math.max(320, Math.round(el.clientWidth || 640)), H = height, m = { l: 52, r: 10, t: 12, b: 28 };
  const totals = labels.map((_, i) => series.reduce((a, s) => a + (s.values[i] || 0), 0));
  const ticks = niceTicks(0, Math.max(100, ...totals), 4), max = ticks[ticks.length - 1];
  const n = labels.length, band = (W - m.l - m.r) / Math.max(1, n), bw = Math.min(24, band * 0.6);
  const Y = v => m.t + (max - v) * (H - m.t - m.b) / max;
  let g = '';
  for (const t of ticks) g += `<line class="${t === 0 ? 'ax' : 'gl'}" x1="${m.l}" x2="${W - m.r}" y1="${Y(t)}" y2="${Y(t)}"/><text x="${m.l - 8}" y="${Y(t) + 4}" text-anchor="end">${esc(fmtAxis(t))}</text>`;
  let bars = '';
  const every = Math.max(1, Math.ceil(n / 8));
  labels.forEach((lb, i) => {
    const x = m.l + band * i + (band - bw) / 2;
    let base = 0;
    const segs = series.map(s => ({ s, v: s.values[i] || 0 })).filter(o => o.v > 0);
    segs.forEach((o, k) => {
      const y0 = Y(base), y1 = Y(base + o.v);
      const top = k === segs.length - 1;
      const h = Math.max(1, y0 - y1 - (k > 0 ? 2 : 0));
      const yy = y0 - h - (k > 0 ? 2 : 0);
      const r = top ? Math.min(4, h / 2) : 0;
      bars += `<path d="M${x},${yy + h}V${yy + r}Q${x},${yy} ${x + r},${yy}H${x + bw - r}Q${x + bw},${yy} ${x + bw},${yy + r}V${yy + h}Z" fill="${o.s.color}"/>`;
      base += o.v;
    });
    const tip = [fm(labels[i]) || lb, ...series.map(s => s.name + ' : ' + E(s.values[i] || 0)), 'Total : ' + E(totals[i])].join('\n');
    bars += `<rect x="${m.l + band * i}" y="${m.t}" width="${band}" height="${H - m.t - m.b}" fill="transparent" data-tip="${esc(tip)}" tabindex="0"/>`;
    if (i % every === 0 || i === n - 1) bars += `<text x="${x + bw / 2}" y="${H - 8}" text-anchor="middle">${esc(fmc(lb))}</text>`;
  });
  el.innerHTML = `<div class="legend">${series.map(s => `<span><i class="key" style="background:${s.color}"></i>${esc(s.name)}</span>`).join('')}</div><div class="chart"><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Frais par mois">${g}${bars}</svg></div>`;
}

/** Barre segmentée haute (une répartition d'un tout, ≤ 6 parts). */
function segBar(parts, total) {
  const vis = parts.filter(p => p.v > 0);
  if (!total) return '<p class="mut small">Aucune dépense ce mois-ci.</p>';
  return `<div class="segbar" role="img" aria-label="${esc(vis.map(p => p.name + ' ' + Math.round(p.v / total * 100) + ' %').join(', '))}">${vis.map(p => { const pc = p.v / total * 100; return `<div style="flex:${p.v} 1 0;background:${p.color};color:${p.ink || '#fff'}" data-tip="${esc(E(p.v) + '\n' + p.name + ' · ' + Math.round(pc) + ' %')}">${pc >= 11 ? Math.round(pc) + ' %' : ''}</div>`; }).join('')}</div>
    <div class="legend">${vis.map(p => `<span><i class="key" style="background:${p.color}"></i>${esc(p.name)}</span>`).join('')}</div>`;
}
function hbars(items, color = 'var(--s1)') {
  const max = Math.max(1, ...items.map(i => i.v));
  return `<div class="hbars">${items.map(i => `<div class="lb" title="${esc(i.name)}">${esc(i.icon ? i.icon + ' ' : '')}${esc(i.name)}</div><div class="tr"><div class="bar" style="width:${Math.max(1, i.v / max * 78)}%;background:${color}" data-tip="${esc(E(i.v) + '\n' + i.name + (i.sub ? '\n' + i.sub : ''))}"></div><span class="vv amt">${esc(E0(i.v))}</span></div>`).join('')}</div>`;
}
const NAT_COL = { essentiel: 'var(--s1)', plaisir: 'var(--s2)', evitable: 'var(--s3)', inconnu: 'var(--s-other)' };
const natLabel = n => (S.me.natures[n] || [n])[0];
const natDot = n => `<span class="row nowrap" style="gap:6px"><i class="dot n-${esc(n)}"></i>${esc(natLabel(n))}</span>`;

/* ============================================================== courriers */
async function openLetter(id, source) {
  let L;
  try { L = await api('letter', { method: 'POST', body: Object.assign({ id }, source || {}) }); } catch (e) { return toast(e.message, false); }
  const profile = S.me.profil;
  const missing = !profile.nom_complet || !profile.adresse || !profile.compte;
  const nomFichier = (L.objet || 'courrier').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 80) || 'courrier';
  modal({ title: L.titre, wide: true, html: `
    ${missing ? `<div class="note warn" style="margin-bottom:12px">${ic('info', 'sm')} Complétez votre nom, adresse et n° de compte dans <a href="#/reglages" data-close>Réglages</a> : ils seront ajoutés automatiquement aux courriers.</div>` : ''}
    <div class="field"><label class="f" for="lobj">Objet</label><div class="row"><input class="in" id="lobj" value="${esc(L.objet)}"><button class="btn sm" id="cobj">${ic('copy', 'sm')}Copier</button></div></div>
    <label class="f" for="lbody">Texte (destinataire : ${esc(L.destinataire)}) — modifiable</label><textarea class="in letter" id="lbody" spellcheck="true" style="width:100%;min-height:340px">${esc(L.corps)}</textarea>
    <p class="help">Complétez les passages entre [crochets] directement ici : la copie, l’e-mail, le fichier Word et l’impression reprennent votre texte. Références vérifiées le ${esc(fd(L.verifie))}.</p>
    <div class="row wrap" style="margin-top:8px"><button class="btn sm" id="ldocx">${ic('download', 'sm')}Word (.docx)</button><button class="btn sm" id="ltxt">${ic('download', 'sm')}Texte (.txt)</button><button class="btn sm" id="lprint">${ic('file', 'sm')}Imprimer ou PDF</button></div>
    ${L.conseils.length ? `<h3 style="margin-top:14px">Conseils d’envoi</h3><ul class="small sec" style="margin:6px 0 0;padding-left:18px">${L.conseils.map(c => `<li>${esc(c)}</li>`).join('')}</ul>` : ''}
    ${L.refs.length ? `<div class="refs"><b>Textes cités</b>${L.refs.map(r => `<details><summary>${esc(r.titre)}</summary><p>${esc(r.texte)}</p></details>`).join('')}</div>` : ''}`,
    actions: [{ label: 'Ouvrir dans ma messagerie', onClick: o => { location.href = 'mailto:?subject=' + encodeURIComponent($('#lobj', o).value) + '&body=' + encodeURIComponent($('#lbody', o).value); return false; } },
      { label: 'Copier le texte', kind: 'primary', onClick: o => { copyText($('#lbody', o).value); return false; } }],
    onOpen: o => {
      const texte = () => $('#lbody', o).value;
      $('#cobj', o).addEventListener('click', () => copyText($('#lobj', o).value));
      $('#ltxt', o).addEventListener('click', () => saveBlob(new Blob(['\ufeff' + texte().replace(/\r?\n/g, '\r\n')], { type: 'text/plain;charset=utf-8' }), nomFichier + '.txt'));
      $('#ldocx', o).addEventListener('click', async e => {
        const b = e.currentTarget; b.disabled = true;
        try { saveBlob(await apiBlob('letter.docx', { objet: $('#lobj', o).value, corps: texte() }), nomFichier + '.docx'); } catch (err) { toast(err.message, false); }
        b.disabled = false;
      });
      $('#lprint', o).addEventListener('click', () => printText(texte()));
    } });
}
/** Enregistre un fichier produit dans la page (courrier .txt, .docx). */
function saveBlob(blob, name) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 4000);
}
/** Appel POST qui renvoie un fichier (et non du JSON). */
async function apiBlob(action, body) {
  const res = await fetch('api.php?a=' + encodeURIComponent(action), { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-CSRF': S.csrf || '' }, body: JSON.stringify(body) });
  if (!res.ok) { let m = 'Erreur ' + res.status; try { m = (await res.json()).error || m; } catch (e) { /* réponse sans JSON */ } throw new Error(m); }
  return res.blob();
}
/** Imprime un texte seul (courrier) : le reste de la page est masqué pendant l'impression. Avec « Enregistrer au format PDF », on obtient un PDF. */
function printText(text) {
  const z = document.createElement('div');
  z.id = 'printzone';
  z.textContent = text;
  document.body.appendChild(z);
  document.body.classList.add('imprime-texte');
  const fin = () => { document.body.classList.remove('imprime-texte'); z.remove(); window.removeEventListener('afterprint', fin); };
  window.addEventListener('afterprint', fin);
  window.print();
  setTimeout(fin, 60000);
}
function actionButtons(actions, alertId) {
  return (actions || []).map((a, i) => a.type === 'courrier'
    ? `<button class="btn sm ${i === 0 ? 'primary' : ''}" data-letter="${esc(a.id)}" data-alert="${alertId}">${ic('mail', 'sm')}${esc(a.label)}</button>`
    : `<a class="btn sm" href="${esc(a.href)}">${esc(a.label)}</a>`).join('');
}
function bindLetterButtons(root) {
  $$('[data-letter]', root).forEach(b => b.addEventListener('click', () => {
    const src = b.dataset.alert ? { alerte: +b.dataset.alert } : b.dataset.opp ? { opportunite: b.dataset.opp } : b.dataset.ctx ? { ctx: JSON.parse(b.dataset.ctx) } : {};
    openLetter(b.dataset.letter, src);
  }));
}
function alertCard(a, compact = false) {
  return `<div class="al k-${esc(a.niveau)} ${a.statut !== 'ouverte' ? 'done' : ''}" data-id="${a.id}">
    <span class="ai" title="${esc(LEVEL_TXT[a.niveau] || '')}">${ic(LEVEL_IC[a.niveau] || 'info')}</span>
    <div><div class="row wrap"><h3 class="grow">${esc(a.titre)}</h3>${a.mois ? `<span class="chip">${esc(fm(a.mois))}</span>` : ''}</div>
      <p>${esc(a.texte)}</p>
      <div class="acts">${actionButtons(a.actions, a.id)}${!compact && a.tx_ids && a.tx_ids.length ? `<button class="btn sm ghost" data-txs="${esc(a.tx_ids.join(','))}">Voir les opérations</button>` : ''}
        ${compact ? '' : (a.statut === 'ouverte' ? `<button class="btn sm ghost" data-st="traitee">${ic('check', 'sm')}Traitée</button><button class="btn sm ghost" data-st="ignoree">Ignorer</button>` : `<button class="btn sm ghost" data-st="ouverte">Rouvrir</button>`)}</div>
      <div class="txs"></div></div></div>`;
}

/* ============================================================== Tableau de bord */
async function viewHome(v, q, tok) {
  const vue = q.vue === 'foyer' ? 'foyer' : 'moi';
  const d = await api('dashboard', { query: { vue, mois: q.mois || '' } });
  if (!live(tok)) return;
  S.months = d.mois_disponibles; S.lastMonth = d.mois_disponibles[d.mois_disponibles.length - 1] || null;
  const me = S.me.moi, autresF = autresActifs();
  const head = `<div class="page-head"><div class="grow"><h1>Bonjour ${esc(me.nom)}</h1><p>${d.mois_disponibles.length ? 'Voici où ' + (vue === 'foyer' ? 'en est le foyer' : 'vous en êtes') + ' en ' + esc(fm(d.mois)) + '.' : 'Commençons par vos relevés.'}</p></div></div>`;
  if (!d.mois_disponibles.length) {
    v.innerHTML = head + `<div class="card empty"><div class="big">🧭</div><h2>Importez votre premier relevé</h2><p style="max-width:560px;margin:8px auto 18px">PDF, CSV, OFX ou QIF : Boussole calcule ce qui entre et sort, classe chaque dépense, vérifie les frais bancaires au regard des plafonds légaux et vous prévient en cas de prélèvement suspect.</p><a class="btn primary" href="#/importer">${ic('upload', 'sm')}Importer un relevé</a>
      <p class="small mut" style="margin-top:18px">Astuce : l’export CSV ou OFX de votre espace bancaire est plus fiable que le PDF (rubrique « Télécharger » ou « Exporter » de vos comptes).</p></div>`;
    return;
  }
  const s = d.resume, p = d.precedent, sc = d.score;
  const filters = `<div class="filters">${autresF.length ? `<div class="seg" role="group" aria-label="Vue"><button data-vue="moi" class="${vue === 'moi' ? 'on' : ''}">Moi</button><button data-vue="foyer" class="${vue === 'foyer' ? 'on' : ''}">Foyer</button></div>` : ''}
    <select class="in sm" id="hmois" style="width:auto" aria-label="Mois">${d.mois_disponibles.slice().reverse().map(m => `<option value="${m}" ${m === d.mois ? 'selected' : ''}>${esc(fm(m))}</option>`).join('')}</select>
    ${vue === 'foyer' && !d.partage.moi_partage ? `<span class="chip">Partagez vos totaux (Réglages › Confidentialité) pour voir ceux du foyer</span>` : vue === 'foyer' && d.partage.prives.length ? `<span class="chip">${esc(listeNoms(d.partage.prives))} ${d.partage.prives.length > 1 ? 'ne partagent pas leurs' : 'ne partage pas ses'} totaux</span>` : ''}</div>`;
  const sv = S.me.sauvegarde;
  const sauvAlerte = sv && ((sv.echec && (!sv.derniere || sv.echec.t > sv.derniere)) ? 'La dernière sauvegarde automatique a échoué : ' + sv.echec.erreur : (sv.derniere && Date.now() / 1000 - sv.derniere > 8 * 86400 ? 'Aucune sauvegarde réussie depuis le ' + new Date(sv.derniere * 1000).toLocaleDateString('fr-FR') + ' : le PC était-il éteint à l’heure prévue ?' : ''));
  const lvlColor = sc.total >= 80 ? 'st-good' : sc.total >= 60 ? '' : sc.total >= 40 ? 'st-warn' : 'st-crit';
  const delta = (cur, prev, upGood) => { if (!prev) return '<div class="dl">—</div>'; const dv = cur - prev; if (!dv) return '<div class="dl">= mois précédent</div>'; const good = upGood ? dv > 0 : dv < 0; return `<div class="dl ${good ? 'good' : 'bad'}">${dv > 0 ? '▲' : '▼'} ${esc(E0(Math.abs(dv)))} vs ${esc(MOIS_C[+month(-1, d.mois).slice(5, 7)])}</div>`; };
  const fraisM = (s.frais.incident || 0) + (s.frais.agios || 0) + (s.frais.saisie || 0);
  const fraisP = (p.frais.incident || 0) + (p.frais.agios || 0) + (p.frais.saisie || 0);
  const fc = d.projection;
  const proj = vue === 'foyer' ? `<p class="sec small">La projection est personnelle : basculez sur « Moi ».</p>` : !fc ? `<p class="sec">Indiquez votre solde du jour pour savoir combien vous pouvez dépenser chaque jour jusqu’à la paie.</p>` : `
    <div class="lab sec small">Jusqu’à la paie${fc.jour_paie ? ' du ' + esc(fdc(fc.fin)) : ''} · ${pl(fc.jours, 'jour')}</div>
    <div style="font-size:30px;font-weight:700;letter-spacing:-.02em;margin:2px 0" class="${fc.par_jour < 0 ? '' : ''}">${amt(fc.par_jour)} <span class="mut" style="font-size:15px;font-weight:600">par jour</span></div>
    <p class="small sec">${fc.par_jour < 0 ? 'Les charges prévues dépassent le solde : décalez un prélèvement ou prévoyez un virement.' : 'Ce qui reste après les prélèvements habituels à venir (' + esc(E0(fc.charges_fixes)) + '), solde estimé aujourd’hui ' + esc(E0(fc.solde_estime)) + '.'}</p>
    ${fc.premier_negatif ? `<div class="note bad small" style="margin:6px 0">${ic('alert', 'sm')} Découvert prévu le ${esc(fd(fc.premier_negatif))} (point bas ${esc(E0(fc.point_bas))}).</div>` : ''}
    ${fc.ancien ? `<div class="note warn small" style="margin:6px 0">Projection calculée depuis le ${esc(fd(fc.depart.date))} : mettez votre solde à jour.</div>` : ''}`;
  v.innerHTML = head + filters + (sauvAlerte ? `<div class="note warn" style="margin-bottom:14px">${ic('alert', 'sm')} ${esc(sauvAlerte)} <a href="#/reglages">Voir</a></div>` : '') + `
  <div class="grid g-hero">
    <section class="card"><div class="card-head"><div class="grow"><h2>Bien-être financier</h2><p>${esc(fm(d.mois))} · 5 critères notés sur 20</p></div><span class="chip ${sc.total >= 60 ? 'k-bravo' : sc.total >= 40 ? 'k-attention' : 'k-critique'}">${ic(sc.total >= 60 ? 'star' : 'alert', 'sm')}${esc(sc.niveau)}</span></div>
      <div class="hero"><span class="v">${sc.total}</span><span class="u">/ 100</span></div>
      <div class="meter lg ${lvlColor}" style="margin:12px 0 6px" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${sc.total}"><i style="width:${sc.total}%"></i></div>
      ${sc.composantes.map(c => `<div class="comp"><span class="t">${esc(c.label)} <span class="mut" style="font-weight:500">· ${esc(c.valeur)}</span></span><span class="p">${c.points}/${c.max}</span><div class="meter" style="grid-column:1/-1"><i style="width:${c.points / c.max * 100}%"></i></div><span class="c">${esc(c.conseil)}</span></div>`).join('')}
    </section>
    <div class="stack">
      <div class="next">${ic('bulb')}<div><small>Prochaine meilleure action</small><b>${esc(d.prochaine_action.titre)}</b><a href="${esc(d.prochaine_action.lien)}">J’y vais</a></div></div>
      <section class="card">${proj}
        ${vue === 'moi' ? `<form id="fsolde" class="row wrap" style="margin-top:10px"><label class="f grow" for="isolde" style="margin:0">Mon solde aujourd’hui</label><input class="in sm" id="isolde" inputmode="decimal" placeholder="ex. 412,50" style="width:130px"><button class="btn sm">Mettre à jour</button></form>` : ''}
      </section>
      <section class="card"><div class="card-head"><h2 class="grow">Alertes prioritaires</h2><a class="small" href="#/alertes">Tout voir</a></div>
        ${d.alertes.length ? d.alertes.slice(0, 3).map(a => alertCard(a, true)).join('') : '<p class="sec small">Aucune alerte ouverte. 👍</p>'}</section>
    </div>
  </div>
  <div class="grid g4" style="margin-top:16px">
    <div class="card tile"><div class="lab">Revenus nets</div><div class="val">${amt(s.revenus)}</div>${delta(s.revenus, p.revenus, true)}</div>
    <div class="card tile"><div class="lab">Dépenses</div><div class="val">${amt(s.depenses)}</div>${delta(s.depenses, p.depenses, false)}</div>
    <div class="card tile"><div class="lab">Reste du mois</div><div class="val">${amt(s.reste)}</div><div class="dl">${s.taux_epargne == null ? 'revenus non identifiés' : esc(String(s.taux_epargne).replace('.', ',')) + ' % des revenus'}${s.epargne > 0 ? ' · ' + esc(E0(s.epargne)) + ' épargnés' : ''}</div></div>
    <div class="card tile"><div class="lab">Frais d’incidents & agios</div><div class="val">${amt(fraisM)}</div>${d.frais.a_reclamer > 0 ? `<div class="dl bad"><a href="#/frais">${esc(E(d.frais.a_reclamer))} récupérables de droit</a></div>` : delta(fraisM, fraisP, false)}</div>
  </div>
  <div class="grid g2" style="margin-top:16px">
    <section class="card"><div class="card-head"><div class="grow"><h2>Où va l’argent</h2><p>${esc(E(s.depenses))} de dépenses en ${esc(fm(d.mois))}${s.a_classer ? ' · ' + pl(s.a_classer, 'opération') + ' à classer' : ''}</p></div><a class="small" href="#/operations?mois=${esc(d.mois)}">Détail</a></div>
      ${segBar([{ name: 'Essentiel', v: s.nature.essentiel, color: NAT_COL.essentiel }, { name: 'Plaisir & confort', v: s.nature.plaisir, color: NAT_COL.plaisir }, { name: 'Évitable', v: s.nature.evitable, color: NAT_COL.evitable, ink: '#0b0b0b' }, { name: 'À classer', v: s.nature.inconnu, color: NAT_COL.inconnu, ink: '#0b0b0b' }], s.depenses)}
      <div class="tbl-wrap" style="margin:6px 0 14px"><table class="tbl"><tbody>${['essentiel', 'plaisir', 'evitable', 'inconnu'].map(n => `<tr><td>${natDot(n)}</td><td class="r num">${amt(s.nature[n])}</td><td class="r mut num">${s.depenses ? Math.round(s.nature[n] / s.depenses * 100) : 0} %</td></tr>`).join('')}</tbody></table></div>
      <h3 style="margin-bottom:8px">Principales catégories</h3>
      ${hbars(Object.entries(Array.isArray(s.cats) ? {} : s.cats).slice(0, 8).map(([c, o]) => ({ name: (S.cats[c] || { label: c }).label, icon: (S.cats[c] || {}).icone, v: o.montant, sub: pl(o.n, 'opération') })))}
    </section>
    <section class="card"><div class="card-head"><div class="grow"><h2>Revenus et dépenses</h2><p>${d.tendance.length} derniers mois</p></div></div><div id="ctrend"></div>
      ${fc && vue === 'moi' ? `<h3 style="margin:18px 0 6px">Solde prévu jusqu’à la paie</h3><div id="cproj"></div>${fc.echeances.length ? `<div class="tbl-wrap" style="margin-top:10px"><table class="tbl"><tbody>${fc.echeances.slice(0, 8).map(e => `<tr><td class="nowrap">${esc(fdc(e.date))}</td><td>${esc(e.label)}</td><td class="r num">${amt(e.montant, e.montant > 0 ? 'pos' : '', true)}</td></tr>`).join('')}</tbody></table></div>` : ''}` : ''}
    </section>
  </div>
  ${d.budgets.length ? `<section class="card" style="margin-top:16px"><div class="card-head"><h2 class="grow">Enveloppes du mois</h2><a class="small" href="#/reglages">Modifier</a></div><div class="grid g3">${d.budgets.map(b => { const pc = b.limite ? b.depense / b.limite * 100 : 0; return `<div><div class="row"><span class="grow">${esc(b.icone)} ${esc(b.label)}</span><span class="num small">${amt(b.depense)} / ${esc(E0(b.limite))}</span></div><div class="meter ${pc >= 100 ? 'st-crit' : pc >= 80 ? 'st-warn' : ''}" style="margin-top:6px"><i style="width:${Math.min(100, pc)}%"></i></div><div class="small ${pc >= 100 ? '' : 'mut'}" style="margin-top:3px">${pc >= 100 ? ic('alert', 'sm') + ' dépassée de ' + esc(E0(b.depense - b.limite)) : esc(E0(Math.max(0, b.limite - b.depense))) + ' restants'}</div></div>`; }).join('')}</div></section>` : ''}
  ${d.equite ? equityCard(d.equite) : ''}`;

  $$('[data-vue]', v).forEach(b => b.addEventListener('click', () => go('accueil', { vue: b.dataset.vue, mois: d.mois })));
  $('#hmois', v).addEventListener('change', e => go('accueil', { vue, mois: e.target.value }));
  const fs = $('#fsolde', v);
  if (fs) fs.addEventListener('submit', async e => {
    e.preventDefault();
    const c = toCents($('#isolde').value);
    if (c == null || Number.isNaN(c)) return toast('Montant invalide (exemple : 412,50 ou -35,20).', false);
    try { await api('me.save', { method: 'POST', body: { solde: c, solde_date: today() } }); S.me.profil.solde = c; toast('Solde enregistré : projection mise à jour.'); route(); refreshBadge(); } catch (err) { toast(err.message, false); }
  });
  bindLetterButtons(v);
  lineChart($('#ctrend', v), { labels: d.tendance.map(t => fmc(t.mois)), tipLabels: d.tendance.map(t => fm(t.mois)), series: [{ name: 'Revenus', color: 'var(--s1)', values: d.tendance.map(t => t.revenus) }, { name: 'Dépenses', color: 'var(--s2)', values: d.tendance.map(t => t.depenses) }] });
  if (fc && vue === 'moi' && $('#cproj', v)) {
    const pts = fc.courbe;
    const iLow = pts.findIndex(pt => pt.date === fc.date_point_bas);
    lineChart($('#cproj', v), { labels: pts.map(pt => fdc(pt.date)), tipLabels: pts.map(pt => fd(pt.date)), series: [{ name: 'Solde prévu', color: 'var(--s1)', values: pts.map(pt => pt.solde) }], area: true, height: 200,
      marks: iLow >= 0 ? [{ i: iLow, v: pts[iLow].solde, color: fc.point_bas < 0 ? 'var(--crit-mark)' : 'var(--s1)', label: 'point bas ' + E0(fc.point_bas), below: true }] : [] });
  }
}
function month(delta, ym) { const y = +ym.slice(0, 4), m = +ym.slice(5, 7) - 1 + delta; const d = new Date(y, m, 1); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'); }
function equityCard(eq) {
  const ps = eq.personnes, vir = eq.virements || [];
  return `<section class="card" style="margin-top:16px"><div class="card-head"><div class="grow"><h2>Équité des dépenses communes</h2><p>${eq.mode === 'prorata' ? 'Chacun participe en proportion de ses revenus' : 'Partage à parts égales'} · ${esc(E(eq.total_communes))} de dépenses communes</p></div><a class="small" href="#/foyer">Régler</a></div>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th></th><th class="r">Revenus</th><th class="r">Part attendue</th><th class="r">A payé</th><th class="r">Cible</th><th class="r">Écart</th></tr></thead><tbody>
    ${ps.map(x => `<tr><td><b>${esc(x.nom)}</b></td><td class="r num">${amt(x.revenus)}</td><td class="r num">${esc(String(x.part).replace('.', ','))} %</td><td class="r num">${amt(x.contribution)}</td><td class="r num">${amt(x.cible)}</td><td class="r num">${amt(x.ecart, '', true)}</td></tr>`).join('')}</tbody></table></div>
    ${vir.length ? `<p style="margin-top:10px">Pour équilibrer ce mois-ci :</p><ul style="margin:4px 0 0 18px">${vir.map(x => `<li><b>${esc(x.de)}</b> verse <b>${amt(x.montant)}</b> à <b>${esc(x.a)}</b></li>`).join('')}</ul>` : '<p style="margin-top:10px">Les dépenses communes sont équilibrées ce mois-ci.</p>'}
    <p class="small mut" style="margin-top:8px">Un repère pour en parler, pas un verdict : ce qui compte, c’est que la règle choisie convienne à chacun. « A payé » inclut les virements entre vous.</p></section>`;
}

/* ============================================================== Import */
async function viewImport(v, q, tok) {
  const r = await api('statements');
  if (!live(tok)) return;
  const maxMo = Math.round(S.status.max_upload / 1048576 * 10) / 10;
  v.innerHTML = `<div class="page-head"><div class="grow"><h1>Importer un relevé</h1><p>Chacun importe ses propres relevés. Rien n’est enregistré avant votre validation.</p></div></div>
  <div class="grid g2">
    <section class="card"><div class="drop" id="drop" tabindex="0" role="button" aria-label="Choisir un relevé">${ic('upload')}<h2>Déposez votre relevé ici</h2><p class="sec">ou touchez pour choisir un fichier</p><p class="small mut">PDF · CSV · Excel (.xlsx, .xls) · OpenDocument (.ods) · OFX · QFX · QIF · XML ISO 20022 (camt.053) · MT940 · TXT — ${maxMo} Mo au plus</p><input type="file" id="ifile" accept=".pdf,.csv,.tsv,.txt,.xlsx,.xls,.ods,.ofx,.qfx,.qif,.xml,.sta,.940,.mt940,.htm,.html" hidden></div>
      <details style="margin-top:14px"><summary class="sec" style="cursor:pointer">Ou coller le texte du relevé</summary><textarea class="in" id="itext" style="margin-top:8px" placeholder="Copiez les lignes depuis votre relevé PDF ou votre site bancaire, puis collez-les ici."></textarea><button class="btn" id="btext" style="margin-top:8px">Analyser le texte</button></details>
    </section>
    <section class="card"><h2>Le bon format</h2><ul class="sec" style="padding-left:18px;margin:8px 0 0">
      <li><b>Le plus fiable : CSV ou OFX</b> depuis votre espace bancaire (Comptes › Télécharger / Exporter les opérations).</li>
      <li><b>PDF</b> : lu dans votre navigateur ; Boussole repère les colonnes Débit et Crédit puis vérifie que solde initial + mouvements = solde final.</li>
      <li>Importer deux fois le même relevé ne crée pas de doublon.</li>
      <li>Pour un relevé qui contient plusieurs comptes (courant + livret), décochez les lignes du livret.</li></ul></section>
  </div>
  <div id="iprev" style="margin-top:16px">${S.importDone ? importDoneCard(S.importDone) : ''}</div>
  <section class="card" style="margin-top:16px"><h2 style="margin-bottom:8px">Relevés importés</h2>${r.releves.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Période</th><th>Banque</th><th class="r">Nouvelles</th><th class="r">Déjà présentes</th><th>Contrôle</th><th></th></tr></thead><tbody>${r.releves.map(x => `<tr><td class="nowrap">${esc(fd(x.period_start))} → ${esc(fd(x.period_end))}</td><td>${esc(x.bank || '—')}</td><td class="r num">${+x.n_new}</td><td class="r num">${+x.n_dup}</td><td>${x.check_status === 'ok' ? '<span class="chip k-bravo">complet</span>' : x.check_status === 'ecart' ? '<span class="chip k-critique">écart</span>' : '<span class="chip">non vérifiable</span>'}</td><td class="r"><button class="btn sm ghost danger" data-del="${+x.id}" title="Supprimer ce relevé et ses opérations">${ic('trash', 'sm')}</button></td></tr>`).join('')}</tbody></table></div>` : '<p class="sec small">Aucun relevé pour l’instant.</p>'}</section>`;
  const drop = $('#drop', v), inp = $('#ifile', v);
  drop.addEventListener('click', () => inp.click());
  drop.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inp.click(); } });
  ['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => { const f = e.dataTransfer.files[0]; if (f) parseFile(f); });
  inp.addEventListener('change', () => { if (inp.files[0]) parseFile(inp.files[0]); inp.value = ''; });
  $('#btext', v).addEventListener('click', () => { const t = $('#itext').value.trim(); if (!t) return toast('Collez d’abord le texte du relevé.', false); const fd2 = new FormData(); fd2.append('payload', JSON.stringify({ format: 'texte', texte: t })); sendParse(fd2, 'texte collé'); });
  $$('[data-del]', v).forEach(b => b.addEventListener('click', async () => {
    if (!await confirmBox('Supprimer ce relevé ?', 'Ses opérations seront retirées des calculs. Le fichier conservé dans le coffre-fort, s’il existe, reste disponible.', 'Supprimer', true)) return;
    try { await api('statement.delete', { method: 'POST', body: { id: +b.dataset.del } }); toast('Relevé supprimé.'); route(); refreshBadge(); } catch (e) { toast(e.message, false); }
  }));
}
function importDoneCard(res) {
  S.importDone = null;
  return `<section class="card"><h2>${ic('check')} Relevé enregistré</h2><p style="margin-top:8px">${pl(res.nouvelles, 'nouvelle opération')}${res.doublons ? ', ' + pl(res.doublons, 'déjà présente') : ''}${res.document ? ' · fichier rangé dans le coffre-fort' : ''}.</p>
    ${res.alertes_critiques ? `<div class="note bad" style="margin:10px 0">${ic('octagon', 'sm')} ${pl(res.alertes_critiques, 'alerte critique')} : de l’argent à récupérer ou un risque immédiat.</div>` : ''}
    <div class="row wrap"><a class="btn primary" href="#/accueil">Voir le tableau de bord</a><a class="btn" href="#/alertes">Voir les alertes</a></div></section>`;
}
async function parseFile(file) {
  const box = $('#iprev');
  if (file.size > S.status.max_upload && !/\.pdf$/i.test(file.name)) return toast('Fichier trop lourd pour ce serveur (' + Math.round(S.status.max_upload / 1048576) + ' Mo au plus).', false);
  box.innerHTML = `<div class="card"><p class="sec">Lecture de « ${esc(file.name)} »…</p></div>`;
  const form = new FormData();
  try {
    if (/\.pdf$/i.test(file.name) || file.type === 'application/pdf') {
      const mod = await import('./pdf-import.js');
      const lines = await mod.extractPdfLines(file, retry => new Promise(res => {
        let done = false;
        const m = modal({ title: 'PDF protégé', html: `<p>${retry ? 'Mot de passe incorrect. ' : ''}Ce relevé est protégé par un mot de passe (souvent votre date de naissance ou votre identifiant client, selon la banque).</p><div class="field"><input class="in" id="pdfpw" type="password" autocomplete="off" autofocus></div>`,
          actions: [{ label: 'Annuler', onClick: () => { done = true; res(null); } }, { label: 'Ouvrir', kind: 'primary', onClick: o => { done = true; res(o.querySelector('#pdfpw').value); } }] });
        const obs = new MutationObserver(() => { if (!document.body.contains(m.el)) { obs.disconnect(); if (!done) res(null); } });
        obs.observe(document.body, { childList: true });
      }));
      form.append('payload', JSON.stringify({ format: 'lignes', lignes: lines }));
      if (file.size <= S.status.max_upload) form.append('file', file);
    } else form.append('file', file);
  } catch (e) { box.innerHTML = `<div class="note bad">${esc(e.message)}</div>`; return; }
  sendParse(form, file.name);
}
async function sendParse(form, name) {
  const box = $('#iprev');
  box.innerHTML = `<div class="card"><p class="sec">Analyse de « ${esc(name)} »…</p></div>`;
  try {
    const r = await api('import.parse', { method: 'POST', form });
    r.rows.forEach(x => { x.include = !x.doublon; x.orig = x.categorie; });
    S.imp = r;
    renderPreview();
  } catch (e) { box.innerHTML = `<div class="note bad">${esc(e.message)}</div>`; }
}
function catOptions(sel, credit) {
  const groups = {};
  for (const c of S.catList) (groups[c.nature] = groups[c.nature] || []).push(c);
  const order = credit ? ['revenu', 'entree', 'transfert', 'epargne', 'essentiel', 'plaisir', 'evitable', 'inconnu'] : ['essentiel', 'plaisir', 'evitable', 'inconnu', 'epargne', 'transfert', 'revenu', 'entree'];
  return order.filter(n => groups[n]).map(n => `<optgroup label="${esc(natLabel(n))}">${groups[n].map(c => `<option value="${c.id}" ${c.id === sel ? 'selected' : ''}>${esc(c.icone + ' ' + c.label)}</option>`).join('')}</optgroup>`).join('');
}
function renderPreview() {
  const r = S.imp, box = $('#iprev');
  if (!r.rows.length) { box.innerHTML = `<div class="note bad">Aucune opération reconnue.${r.warnings.length ? '<br>' + r.warnings.map(esc).join('<br>') : ''}</div>`; return; }
  const inc = r.rows.filter(x => x.include);
  const deb = inc.filter(x => x.amount < 0).reduce((a, x) => a - x.amount, 0), cre = inc.filter(x => x.amount > 0).reduce((a, x) => a + x.amount, 0);
  const chk = r.check || { status: 'inconnu', detail: '' };
  const m = r.meta || {};
  box.innerHTML = `<section class="card"><div class="card-head"><div class="grow"><h2>Vérifiez avant d’enregistrer</h2><p>${esc(r.fichier || r.format)} · ${esc(fd(m.period_start))} → ${esc(fd(m.period_end))}${m.bank ? ' · ' + esc(m.bank) : ''}${m.account ? ' · ' + esc(m.account) : ''}</p></div></div>
    <div class="note ${chk.status === 'ok' ? 'ok' : chk.status === 'ecart' ? 'bad' : 'warn'}">${ic(chk.status === 'ok' ? 'check' : 'alert', 'sm')} ${esc(chk.detail)}</div>
    ${r.warnings.map(w => `<div class="note warn" style="margin-top:8px">${esc(w)}</div>`).join('')}
    <div class="row wrap" style="margin:12px 0"><span class="chip">${pl(inc.length, 'opération')} retenue${inc.length > 1 ? 's' : ''}</span><span class="chip">Débits ${amt(deb)}</span><span class="chip">Crédits ${amt(cre)}</span>${r.rows.some(x => x.doublon) ? `<span class="chip k-attention">${r.rows.filter(x => x.doublon).length} déjà importée(s), décochée(s)</span>` : ''}</div>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th><input type="checkbox" id="iall" aria-label="Tout cocher" ${inc.length === r.rows.length ? 'checked' : ''}></th><th>Date</th><th>Libellé</th><th class="r">Montant</th><th>Catégorie</th></tr></thead><tbody>
    ${r.rows.map((x, i) => `<tr class="${x.include ? '' : 'off'} ${x.sure ? '' : 'unsure'}" data-i="${i}"><td><input type="checkbox" data-inc ${x.include ? 'checked' : ''} aria-label="Retenir"></td><td class="nowrap">${esc(fd(x.date))}</td><td>${esc(x.label)}${x.doublon ? `<div><span class="flag w">${x.doublon === 'exact' ? 'déjà importée' : 'doublon probable'}</span></div>` : ''}${x.frais ? `<div><span class="flag">frais bancaire</span></div>` : ''}</td>
      <td class="r nowrap num">${amt(x.amount, x.amount > 0 ? 'pos' : '', true)} ${x.sure ? '' : `<button class="btn sm ghost" data-flip title="Inverser débit / crédit">⇄</button>`}</td>
      <td><select class="in sm" data-cat style="min-width:170px">${catOptions(x.categorie, x.amount > 0)}</select></td></tr>`).join('')}</tbody></table></div>
    <div class="row wrap" style="margin-top:14px">${r.token ? `<label class="check grow"><input type="checkbox" id="ikeep" checked><span>Conserver le fichier d’origine, chiffré, dans le coffre-fort (5 ans conseillés)</span></label>` : '<span class="grow"></span>'}
      <button class="btn" id="icancel">Annuler</button><button class="btn primary" id="isave">${ic('check', 'sm')}Enregistrer ${pl(inc.length, 'opération')}</button></div></section>`;
  $$('tr[data-i]', box).forEach(tr => {
    const x = r.rows[+tr.dataset.i];
    $('[data-inc]', tr).addEventListener('change', e => { x.include = e.target.checked; renderPreview(); });
    const f = $('[data-flip]', tr); if (f) f.addEventListener('click', () => { x.amount = -x.amount; x.sure = true; renderPreview(); });
    $('[data-cat]', tr).addEventListener('change', e => { x.categorie = e.target.value; });
  });
  $('#iall', box).addEventListener('change', e => { r.rows.forEach(x => { x.include = e.target.checked; }); renderPreview(); });
  $('#icancel', box).addEventListener('click', () => { S.imp = null; box.innerHTML = ''; });
  $('#isave', box).addEventListener('click', async e => {
    const btn = e.currentTarget;
    btn.disabled = true;
    try {
      const res = await api('import.commit', { method: 'POST', body: { rows: r.rows.map(x => ({ date: x.date, label: x.label, amount: x.amount, include: x.include, categorie: x.categorie !== x.orig ? x.categorie : null })),
        meta: r.meta, check: r.check, token: r.token, garder_fichier: !!($('#ikeep') && $('#ikeep').checked), format: r.format, fichier: r.fichier } });
      S.imp = null;
      S.importDone = res;
      refreshBadge();
      toast('Import terminé.');
      return route();
    } catch (err) { toast(err.message, false); btn.disabled = false; }
  });
}

/* ============================================================== Opérations */
async function viewTx(v, q, tok) {
  if (!S.months.length) { try { const d = await api('dashboard'); S.months = d.mois_disponibles; } catch (e) { /* sans mois */ } }
  if (!live(tok)) return;
  const lecteurs = autresActifs().filter(m => m.partage_details);
  const moisSel = q.mois || S.months[S.months.length - 1] || 'tous';
  const mois = moisSel === 'tous' ? '' : moisSel;
  const qui = q.qui || 'moi';
  const r = await api('tx', { query: { mois, nature: q.nature || '', cat: q.cat || '', q: q.q || '', qui } });
  if (!live(tok)) return;
  const ops = r.operations;
  const ent = ops.filter(o => o.montant > 0).reduce((a, o) => a + o.montant, 0), sor = ops.filter(o => o.montant < 0).reduce((a, o) => a - o.montant, 0);
  const byDay = {};
  for (const o of ops) (byDay[o.date] = byDay[o.date] || []).push(o);
  v.innerHTML = `<div class="page-head"><div class="grow"><h1>Opérations</h1><p>Touchez une opération pour la reclasser, la marquer commune ou la contester.</p></div></div><div class="filters">
    <select class="in sm" id="fmois" style="width:auto" aria-label="Mois"><option value="tous" ${moisSel === 'tous' ? 'selected' : ''}>Tous les mois</option>${S.months.slice().reverse().map(m => `<option value="${m}" ${m === mois ? 'selected' : ''}>${esc(fm(m))}</option>`).join('')}</select>
    <select class="in sm" id="fnat" style="width:auto" aria-label="Nature"><option value="">Toutes natures</option>${Object.entries(S.me.natures).map(([k, n]) => `<option value="${k}" ${k === q.nature ? 'selected' : ''}>${esc(n[0])}</option>`).join('')}</select>
    <select class="in sm" id="fcat" style="width:auto;max-width:220px" aria-label="Catégorie"><option value="">Toutes catégories</option>${S.catList.map(c => `<option value="${c.id}" ${c.id === q.cat ? 'selected' : ''}>${esc(c.icone + ' ' + c.label)}</option>`).join('')}</select>
    <input class="in sm" id="fq" placeholder="Rechercher…" value="${esc(q.q || '')}" style="width:180px">
    ${lecteurs.length ? `<select class="in sm" id="fqui" style="width:auto" aria-label="Personne"><option value="moi" ${qui === 'moi' ? 'selected' : ''}>Mes opérations</option>${lecteurs.map(m => `<option value="${m.id}" ${qui === String(m.id) ? 'selected' : ''}>${esc(m.nom)}</option>`).join('')}<option value="foyer" ${qui === 'foyer' ? 'selected' : ''}>Tout le foyer</option></select>` : ''}
  </div>
  <div class="row wrap" style="margin-bottom:10px"><span class="chip">${pl(ops.length, 'opération')}</span><span class="chip">Entrées ${amt(ent)}</span><span class="chip">Sorties ${amt(sor)}</span>${exportMenu(mois, 'right')}</div>
  <section class="card txl">${ops.length ? Object.entries(byDay).map(([day, list]) => `<div class="day">${esc(new Date(day + 'T12:00:00').toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))}</div>${list.map(o => `
    <div class="txr" data-id="${o.id}" tabindex="0" role="button">
      <span class="ico" aria-hidden="true">${esc(o.icone)}</span>
      <div style="min-width:0"><div class="m">${esc(o.contrepartie)}${o.a_moi ? '' : ` <span class="chip">${esc(nomMembre(o.user_id))}</span>`}</div><div class="s"><i class="dot n-${esc(o.nature)}"></i>${esc(o.categorie_label)}${o.frais ? '<span class="flag">frais</span>' : ''}${o.commune ? '<span class="flag i">commune</span>' : ''}${o.note ? '· ' + esc(o.note) : ''}<span class="hide-m">· ${esc(o.libelle)}</span></div></div>
      <div class="a">${amt(o.montant, o.montant > 0 ? 'pos' : '', true)}<small>${esc(o.type)}</small></div></div>`).join('')}`).join('') : '<div class="empty"><div class="big">🔎</div>Aucune opération ne correspond.</div>'}</section>`;
  const nav = patch => go('operations', Object.assign({ mois: moisSel, nature: q.nature || '', cat: q.cat || '', q: q.q || '', qui }, patch));
  $('#fmois', v).addEventListener('change', e => nav({ mois: e.target.value }));
  $('#fnat', v).addEventListener('change', e => nav({ nature: e.target.value }));
  $('#fcat', v).addEventListener('change', e => nav({ cat: e.target.value }));
  let t; $('#fq', v).addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => nav({ q: e.target.value }), 450); });
  const fqui = $('#fqui', v); if (fqui) fqui.addEventListener('change', e => nav({ qui: e.target.value }));
  $$('.txr', v).forEach(el => { const o = ops.find(x => x.id === +el.dataset.id); const open = () => editTx(o); el.addEventListener('click', open); el.addEventListener('keydown', e => { if (e.key === 'Enter') open(); }); });
  if (q.q) { const f = $('#fq', v); f.focus(); f.setSelectionRange(f.value.length, f.value.length); }
}
/** Menu « Exporter » : un mois ou tout, dans le format du logiciel qui va le lire. */
function exportMenu(mois, cls = '', titre = 'Exporter') {
  const q = (f) => 'api.php?a=export&format=' + f + (mois ? '&mois=' + encodeURIComponent(mois) : '');
  const items = [['xlsx', 'Excel (.xlsx)', 'tableur avec dates et montants'], ['csv', 'CSV', 'Excel, LibreOffice, Google Sheets'], ['ofx', 'OFX', 'logiciels de comptes : HomeBank, GnuCash, Money…'], ['qif', 'QIF', 'anciens logiciels de comptes'], ['json', 'JSON', 'toutes mes données, lisibles par un programme']];
  return `<details class="menu ${cls}"><summary class="btn sm ghost">${ic('download', 'sm')}${esc(titre)}${mois ? ' (' + esc(fm(mois)) + ')' : ''}</summary><div class="menu-list">${items.map(([f, l, h]) => `<a href="${q(f)}" download><b>${esc(l)}</b><small>${esc(h)}</small></a>`).join('')}</div></details>`;
}
function editTx(o) {
  const mine = o.a_moi;
  const ctx = JSON.stringify({ date: o.date, label: o.libelle, montant: Math.abs(o.montant), type: o.type, creancier: o.contrepartie });
  modal({ title: o.contrepartie, html: `
    <div class="row" style="margin-bottom:12px"><span style="font-size:24px;font-weight:700">${amt(o.montant, o.montant > 0 ? 'pos' : '', true)}</span><span class="grow"></span><span class="sec">${esc(fd(o.date))}</span></div>
    <p class="small sec" style="word-break:break-word">${esc(o.libelle)}</p>
    ${o.frais ? `<div class="note warn small" style="margin-bottom:10px">Frais bancaire : ${esc((S.me.types_frais[o.frais] || [o.frais])[0])}. Voir <a href="#/frais" data-close>l’analyse des frais</a>.</div>` : ''}
    ${mine ? `<div class="grid g2"><div class="field"><label class="f" for="ecat">Catégorie</label><select class="in" id="ecat">${catOptions(o.categorie, o.montant > 0)}</select></div>
      <div class="field"><label class="f" for="enat">Nature</label><select class="in" id="enat">${Object.entries(S.me.natures).map(([k, n]) => `<option value="${k}" ${k === o.nature ? 'selected' : ''}>${esc(n[0])}</option>`).join('')}</select></div></div>
      ${o.montant < 0 ? `<label class="check"><input type="checkbox" id="ecom" ${o.commune ? 'checked' : ''}><span>Dépense commune du foyer <span class="mut small">(comptée dans l’équité)</span></span></label>` : ''}
      <div class="field"><label class="f" for="enote">Note</label><input class="in" id="enote" value="${esc(o.note)}" maxlength="200"></div>
      <label class="check"><input type="checkbox" id="erule"><span>Toujours classer ainsi les opérations contenant <input class="in sm" id="emotif" value="${esc(o.contrepartie)}" style="display:inline-block;width:200px;margin-left:4px"></span></label>
      ${o.montant < 0 ? `<h3 style="margin:14px 0 6px">Je ne reconnais pas cette opération</h3><div class="row wrap">
        <button class="btn sm" data-letter="operation_non_autorisee" data-ctx="${esc(ctx)}" data-close>Contester (13 mois)</button>
        ${o.type === 'prelevement' ? `<button class="btn sm" data-letter="prlv_8_semaines" data-ctx="${esc(ctx)}" data-close>Remboursement sous 8 semaines</button><button class="btn sm" data-letter="opposition_creancier" data-ctx="${esc(ctx)}" data-close>Bloquer ce créancier</button>` : ''}</div>` : ''}` : `<p class="sec">Opération de ${esc(nomMembre(o.user_id))} : lecture seule.</p>`}`,
    actions: mine ? [{ label: 'Annuler' }, { label: 'Enregistrer', kind: 'primary', onClick: async m => {
      const body = { id: o.id, categorie: $('#ecat', m).value, nature: $('#enat', m).value, note: $('#enote', m).value };
      if ($('#ecom', m)) body.commune = $('#ecom', m).checked;
      if ($('#erule', m).checked) { body.regle = true; body.motif = $('#emotif', m).value; }
      const r = await api('tx.update', { method: 'POST', body });
      toast(r.appliquees ? 'Règle créée : ' + pl(r.appliquees, 'opération reclassée') + '.' : 'Opération mise à jour.');
      route(); refreshBadge();
    } }] : [{ label: 'Fermer' }],
    onOpen: m => { bindLetterButtons(m); const cat = $('#ecat', m); if (cat) cat.addEventListener('change', () => { const c = S.cats[cat.value]; if (c) $('#enat', m).value = o.montant > 0 && ['essentiel', 'plaisir', 'evitable', 'inconnu'].includes((S.me.profil.natures || {})[c.id] || c.nature) ? 'entree' : ((S.me.profil.natures || {})[c.id] || c.nature); }); } });
}

/* ============================================================== Alertes */
async function viewAlerts(v, q, tok) {
  const st = q.statut || 'ouverte';
  const r = await api('alerts', { query: { statut: st } });
  if (!live(tok)) return;
  const groups = { critique: [], attention: [], info: [], bravo: [] };
  for (const a of r.alertes) (groups[a.niveau] || groups.info).push(a);
  const titles = { critique: 'Critique — argent à récupérer ou risque immédiat', attention: 'À vérifier', info: 'Conseils et habitudes', bravo: 'Vos progrès' };
  v.innerHTML = `<div class="page-head"><div class="grow"><h1>Alertes</h1><p>Classées par urgence. Une alerte traitée ou ignorée ne revient pas.</p></div></div><div class="filters"><div class="seg">${[['ouverte', 'À traiter'], ['traitee', 'Traitées'], ['ignoree', 'Ignorées'], ['tout', 'Toutes']].map(([k, l]) => `<button data-st="${k}" class="${k === st ? 'on' : ''}">${l}</button>`).join('')}</div><button class="btn sm ghost right" id="arefresh">Recalculer</button></div>
    ${r.alertes.length ? Object.entries(groups).filter(([, l]) => l.length).map(([k, l]) => `<h2 style="margin:18px 0 10px">${esc(titles[k])} <span class="mut" style="font-weight:500">· ${l.length}</span></h2>${l.map(a => alertCard(a)).join('')}`).join('') : `<div class="card empty"><div class="big">🌤️</div>${st === 'ouverte' ? 'Aucune alerte à traiter. Importez vos relevés chaque mois pour garder la veille active.' : 'Rien ici.'}</div>`}`;
  $$('[data-st]', v).forEach(b => { if (b.closest('.seg')) b.addEventListener('click', () => go('alertes', { statut: b.dataset.st })); });
  $('#arefresh', v).addEventListener('click', async () => { try { await api('alerts.refresh', { method: 'POST' }); toast('Alertes recalculées.'); route(); refreshBadge(); } catch (e) { toast(e.message, false); } });
  bindLetterButtons(v);
  $$('.al', v).forEach(el => {
    const id = +el.dataset.id;
    $$('.acts [data-st]', el).forEach(b => b.addEventListener('click', async () => { try { await api('alert.set', { method: 'POST', body: { id, statut: b.dataset.st } }); el.remove(); refreshBadge(); toast(b.dataset.st === 'traitee' ? 'Bien joué : alerte traitée.' : b.dataset.st === 'ignoree' ? 'Alerte ignorée : elle ne reviendra pas.' : 'Alerte rouverte.'); } catch (e) { toast(e.message, false); } }));
    const tb = $('[data-txs]', el);
    if (tb) tb.addEventListener('click', async () => {
      const box = $('.txs', el);
      if (box.innerHTML) { box.innerHTML = ''; return; }
      try { const r2 = await api('tx', { query: { ids: tb.dataset.txs } }); box.innerHTML = `<div class="tbl-wrap" style="margin-top:8px"><table class="tbl"><tbody>${r2.operations.map(o => `<tr><td class="nowrap">${esc(fd(o.date))}</td><td>${esc(o.libelle)}</td><td class="r num">${amt(o.montant, o.montant > 0 ? 'pos' : '', true)}</td></tr>`).join('')}</tbody></table></div>`; } catch (e) { toast(e.message, false); }
    });
  });
}

/* ============================================================== Frais bancaires */
async function viewFees(v, q, tok) {
  const f = await api('fees');
  if (!live(tok)) return;
  const y = f.annee;
  if (!S.months.length) { try { S.months = (await api('dashboard')).mois_disponibles; } catch (e) { /* sans mois */ } }
  if (!live(tok)) return;
  const feeMonths = Object.keys(f.months).sort();
  const months = feeMonths.length ? Array.from(new Set([...S.months.slice(-12), ...feeMonths])).sort() : [];
  const zero = { incident: 0, agios: 0, saisie: 0, service: 0, evitable: 0, n_incidents: 0 };
  for (const m of months) if (!f.months[m]) f.months[m] = zero;
  const TYPE = { droit: ['Exigible', 'k-critique'], fragilite: ['Statut fragile', 'k-attention'], a_verifier: ['À vérifier', 'k-attention'], geste: ['Geste commercial', 'k-info'], service: ['Frais de service', 'k-info'] };
  const st = { non: 'aucun statut particulier', fragile: 'client(e) reconnu(e) en situation de fragilité financière', ocf: 'offre spécifique « clientèle fragile »', sbb: 'services bancaires de base (droit au compte)' };
  v.innerHTML = `<div class="page-head"><div class="grow"><h1>Frais bancaires</h1><p>Chaque frais est confronté aux plafonds du Code monétaire et financier (références vérifiées le ${esc(fd(f.verifie))}).</p></div></div>
  <div class="grid g4">
    <div class="card tile"><div class="lab">Récupérable de droit</div><div class="val" style="color:${f.a_reclamer > 0 ? 'var(--crit)' : 'inherit'}">${amt(f.a_reclamer)}</div><div class="dl">frais au-delà des plafonds légaux</div></div>
    <div class="card tile"><div class="lab">Coût de la banque sur 12 mois</div><div class="val">${amt(y.total)}</div><div class="dl">incidents ${esc(E0(y.incident + y.saisie))} · agios ${esc(E0(y.agios))} · service ${esc(E0(y.service + y.evitable))}</div></div>
    <div class="card tile"><div class="lab">Frais déjà remboursés</div><div class="val pos">${amt(y.rembourses)}</div><div class="dl">gestes et réclamations obtenus</div></div>
    <div class="card tile"><div class="lab">Vos plafonds</div><div class="val" style="font-size:17px;white-space:normal">${esc(E(f.plafonds.ci_op))} / commission</div><div class="dl">${esc(E0(f.plafonds.ci_mois))} par mois · ${esc(st[f.plafonds.statut] || '')}${f.plafonds.fragile ? ' · incidents ≤ ' + esc(E0(f.plafonds.fragile)) + '/mois' : ''} · <a href="#/reglages">changer</a></div></div>
  </div>
  <section class="card" style="margin-top:16px"><div class="card-head"><div class="grow"><h2>Ce que vous pouvez réclamer</h2><p>Le droit d’abord, le geste commercial ensuite : ne confondez pas les deux dans un courrier.</p></div></div>
    ${f.opportunites.length ? f.opportunites.map(o => `<div class="opp"><div class="row wrap" style="align-items:flex-start"><div class="grow" style="min-width:220px"><span class="chip ${TYPE[o.type][1]}">${esc(TYPE[o.type][0])}</span>${o.mois ? ` <span class="chip">${esc(fm(o.mois))}</span>` : ''}<h3 style="margin-top:8px">${esc(o.titre)}</h3><p class="sec small" style="margin-top:4px">${esc(o.detail)}</p></div><div style="text-align:right"><div class="amtbig">${amt(o.montant)}</div><button class="btn sm primary" style="margin-top:8px" data-letter="${esc(o.courrier)}" data-opp="${esc(o.cle)}">${ic('mail', 'sm')}Préparer le courrier</button></div></div>
      ${o.refs_detail.length ? `<div class="refs">${o.refs_detail.map(r => `<details><summary>${esc(r.titre)}</summary><p>${esc(r.texte)}</p></details>`).join('')}</div>` : ''}</div>`).join('') : '<p class="sec">Aucun frais d’incident détecté sur la période. 🎉</p>'}
  </section>
  <div class="grid g2" style="margin-top:16px">
    <section class="card"><h2 style="margin-bottom:10px">Frais par mois</h2><div id="cfees"></div>
      <details class="tablev"><summary>Voir en tableau</summary><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Mois</th><th class="r">Incidents</th><th class="r">Agios</th><th class="r">Service</th><th class="r">Nb incidents</th></tr></thead><tbody>${months.slice().reverse().map(m => { const x = f.months[m]; return `<tr><td>${esc(fm(m))}</td><td class="r num">${amt(x.incident + x.saisie)}</td><td class="r num">${amt(x.agios)}</td><td class="r num">${amt(x.service + x.evitable)}</td><td class="r num">${x.n_incidents}</td></tr>`; }).join('')}</tbody></table></div></details></section>
    <section class="card"><h2>Éviter les frais d’incident</h2><ul class="sec" style="padding-left:18px;margin:10px 0 0">
      <li><b>Alerte de solde bas</b> par SMS ou notification : la plupart des banques la proposent gratuitement.</li>
      <li><b>Prélèvements après la paie</b> : demandez aux créanciers une date 2 à 5 jours après (courrier « Changer la date d’un prélèvement »).</li>
      <li><b>Un matelas de 100 à 200 €</b> laissé sur le compte courant, alimenté par l’épargne.</li>
      <li><b>Une autorisation de découvert adaptée</b> coûte souvent moins cher que des commissions d’intervention à répétition.</li>
      <li><b>5 incidents dans un mois</b> : demandez le statut de client « fragile » (plafond de 25 €/mois) et l’offre spécifique (3 €/mois au plus).</li>
      <li><b>Comparez</b> : changer de banque est gratuit, la nouvelle banque transfère vos prélèvements.</li></ul>
      ${f.services.length ? `<h3 style="margin:16px 0 6px">Frais de service sur 12 mois</h3><table class="tbl"><tbody>${f.services.map(s => `<tr><td>${esc(s.label)}${s.famille === 'evitable' ? ' <span class="flag w">évitable</span>' : ''}</td><td class="r num">${amt(s.total)}</td></tr>`).join('')}</tbody></table>` : ''}</section>
  </div>`;
  bindLetterButtons(v);
  if (months.length) stackChart($('#cfees', v), { labels: months, series: [{ name: 'Incidents', color: 'var(--s1)', values: months.map(m => f.months[m].incident + f.months[m].saisie) }, { name: 'Agios', color: 'var(--s2)', values: months.map(m => f.months[m].agios) }, { name: 'Service', color: 'var(--s3)', values: months.map(m => f.months[m].service + f.months[m].evitable) }] });
  else $('#cfees', v).innerHTML = '<p class="sec small">Aucun frais bancaire importé.</p>';
}

/* ============================================================== Abonnements */
async function viewSubs(v, q, tok) {
  const r = await api('recurring');
  if (!live(tok)) return;
  const act = r.recurrents.filter(x => x.actif), old = r.recurrents.filter(x => !x.actif);
  const RESIL = ['abonnements', 'jeux_video', 'sport', 'telecom', 'assurances', 'energie', 'eau', 'bnpl', 'frais_bancaires', 'formation', 'autre', 'loisirs', 'dons', 'animaux'];
  const tot = act.filter(x => x.category !== 'epargne').reduce((a, x) => a + x.mensuel, 0);
  const ep = act.filter(x => x.category === 'epargne').reduce((a, x) => a + x.mensuel, 0);
  const subs = act.filter(x => ['abonnements', 'jeux_video', 'sport'].includes(x.category));
  const row = x => `<tr><td><b>${esc(x.icone)} ${esc(x.merchant)}</b><div class="small mut">${esc(x.categorie_label)} · ${esc(x.periode)} · le ${x.jour} environ</div>${x.hausse ? `<span class="flag">+${esc(String(x.hausse.pct).replace('.', ','))} % : ${esc(E(x.hausse.avant))} → ${esc(E(x.hausse.apres))}</span> ` : ''}${x.nouveau ? '<span class="flag i">nouveau</span>' : ''}</td>
    <td class="r num">${amt(x.montant)}</td><td class="r num">${amt(x.annuel)}</td><td class="nowrap">${x.actif ? esc(fd(x.prochain)) : '—'}</td>
    <td>${RESIL.includes(x.category) ? `<div class="seg" data-key="${esc(x.key)}">${[['utile', 'Utile'], ['inutile', 'Inutile'], ['resilie', 'Résilié']].map(([k, l]) => `<button data-etat="${k}" class="${x.etat === k ? 'on' : ''}">${l}</button>`).join('')}</div>` : `<span class="small mut">${x.category === 'epargne' ? 'épargne programmée 👍' : 'charge contractuelle'}</span>`}</td>
    <td>${RESIL.includes(x.category) ? `<button class="btn sm ghost" data-letter="resiliation" data-ctx="${esc(JSON.stringify({ creancier: x.merchant, hausse: x.hausse || undefined }))}">${ic('mail', 'sm')}Résilier</button>` : ''}</td></tr>`;
  v.innerHTML = `<div class="page-head"><div class="grow"><h1>Abonnements et charges fixes</h1><p>Détectés automatiquement à partir de 2 mois de relevés. Marquez ce qui ne vous sert plus : Boussole vous relancera tant que c’est prélevé.</p></div></div>
  <div class="grid g3"><div class="card tile"><div class="lab">Charges récurrentes</div><div class="val">${amt(tot)}</div><div class="dl">par mois · ${esc(E0(tot * 12))} par an${ep ? ' · hors ' + esc(E0(ep)) + ' d’épargne programmée' : ''}</div></div>
    <div class="card tile"><div class="lab">Dont abonnements & loisirs</div><div class="val">${amt(subs.reduce((a, x) => a + x.mensuel, 0))}</div><div class="dl">${pl(subs.length, 'abonnement')}</div></div>
    <div class="card tile"><div class="lab">Jugés inutiles</div><div class="val">${amt(act.filter(x => x.etat === 'inutile').reduce((a, x) => a + x.annuel, 0))}</div><div class="dl">par an, à résilier</div></div></div>
  <section class="card" style="margin-top:16px">${act.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Créancier</th><th class="r">Montant</th><th class="r">Par an</th><th>Prochain</th><th>Pour vous</th><th></th></tr></thead><tbody>${act.map(row).join('')}</tbody></table></div>` : '<p class="sec">Rien de récurrent détecté pour l’instant : il faut au moins deux mois de relevés.</p>'}
  ${old.length ? `<details style="margin-top:14px"><summary class="sec" style="cursor:pointer">${pl(old.length, 'ancien prélèvement')} (plus actif)</summary><div class="tbl-wrap"><table class="tbl"><tbody>${old.map(row).join('')}</tbody></table></div></details>` : ''}</section>
  <p class="small mut" style="margin-top:12px">Conseil : faites le tri une fois par trimestre et alternez les plateformes de streaming au lieu de les cumuler. Une hausse de prix d’un opérateur télécom permet de résilier sans frais pendant 4 mois.</p>`;
  bindLetterButtons(v);
  $$('[data-key]', v).forEach(sg => $$('[data-etat]', sg).forEach(b => b.addEventListener('click', async () => {
    const etat = b.classList.contains('on') ? '' : b.dataset.etat;
    try { await api('sub.set', { method: 'POST', body: { cle: sg.dataset.key, etat } }); $$('[data-etat]', sg).forEach(x => x.classList.toggle('on', x.dataset.etat === etat)); toast(etat === 'resilie' ? 'Noté : vous serez alerté(e) si un prélèvement arrive encore.' : 'Enregistré.'); refreshBadge(); } catch (e) { toast(e.message, false); }
  })));
}

/* ============================================================== Coffre-fort */
async function viewVault(v, q, tok) {
  const [r, ds] = await Promise.all([api('docs'), api('dossiers')]);
  if (!live(tok)) return;
  const types = S.me.types_docs;
  const by = {};
  for (const d of r.documents) (by[d.type] = by[d.type] || []).push(d);
  const typeOpt = types.map(t => `<option value="${t.id}">${esc(t.icone + ' ' + t.label)}</option>`).join('');
  v.innerHTML = `<div class="page-head"><div class="grow"><h1>Coffre-fort</h1><p>Bulletins de paie, justificatifs, factures : chiffrés sur le serveur, avec la durée de conservation officielle de chacun.</p></div></div>
  <div class="grid g2">
    <section class="card"><h2 style="margin-bottom:10px">Ajouter un document</h2>
      <form id="fdoc"><div class="field"><label class="f" for="dfile">Fichier(s)</label><input class="in" type="file" id="dfile" required multiple style="padding-top:7px"><div class="help">Tous les formats : PDF, photos, tableurs, textes, archives, vidéos… (sauf les programmes). Plusieurs fichiers à la fois si vous voulez ; les photos lourdes sont réduites avant l’envoi.</div></div>
      <div class="grid g2"><div class="field"><label class="f" for="dtype">Type</label><select class="in" id="dtype">${typeOpt}</select></div><div class="field"><label class="f" for="ddate">Date du document</label><input class="in" type="date" id="ddate" value="${today()}"></div></div>
      <div class="help" id="dkeep" style="margin:-6px 0 10px"></div>
      <div class="field"><label class="f" for="dtitle">Titre (facultatif)</label><input class="in" id="dtitle" maxlength="120"></div>
      <div id="dpaie" class="hide"><div class="grid g2"><div class="field"><label class="f" for="dper">Mois de paie</label><input class="in" type="month" id="dper"></div><div class="field"><label class="f" for="dnet">Net à payer</label><input class="in" id="dnet" inputmode="decimal" placeholder="ex. 1 850,00"></div></div>
        <div class="grid g2"><div class="field"><label class="f" for="dnetimp">Net imposable</label><input class="in" id="dnetimp" inputmode="decimal"></div><div class="field"><label class="f" for="dbrut">Brut</label><input class="in" id="dbrut" inputmode="decimal"></div></div>
        <div class="help" style="margin:-4px 0 10px">Le net à payer est comparé au virement de salaire reçu : en cas d’écart, vous êtes prévenu(e).</div></div>
      <div id="dexp" class="hide field"><label class="f" for="dexpi">Date d’expiration</label><input class="in" type="date" id="dexpi"><div class="help">Rappel deux mois avant.</div></div>
      <label class="check"><input type="checkbox" id="dshare"><span>Visible par tout le foyer (bail, facture d’énergie, assurance habitation…)</span></label>
      <button class="btn primary" style="margin-top:8px">${ic('lock', 'sm')}Ranger dans le coffre</button></form></section>
    <section class="card"><h2 style="margin-bottom:6px">Dossiers prêts à envoyer</h2><p class="sec small" style="margin-bottom:8px">Boussole rassemble les bonnes pièces et signale ce qui manque ou est périmé.</p>
      ${ds.dossiers.map(d => `<details class="kb"><summary>${d.complet ? '<span class="req"><span class="st st-ok">✓</span></span>' : ''}${esc(d.titre)}<span class="chip ${d.complet ? 'k-bravo' : 'k-attention'}" style="margin-left:6px">${d.complet ? 'complet' : 'incomplet'}</span></summary>
        <p class="small sec">${esc(d.explication)}</p>${d.exigences.map(x => `<div class="req"><span class="st st-${esc(x.statut)}">${x.statut === 'ok' ? '✓' : x.statut === 'manquant' ? '!' : x.statut === 'ancien' ? '↻' : '–'}</span><div><b>${esc(x.libelle)}</b>${x.note ? ` <span class="small mut">(${esc(x.note)})</span>` : ''}<div class="small mut">${x.docs.map(dd => esc(dd.titre)).join(' · ') || (x.statut === 'facultatif' ? 'facultatif' : 'à ajouter')}</div></div></div>`).join('')}
        ${d.remarques.map(t => `<div class="note small" style="margin-top:8px">${esc(t)}</div>`).join('')}
        ${ds.zip ? `<a class="btn sm" style="margin:10px 0 6px" href="api.php?a=dossier.zip&id=${esc(d.id)}">${ic('download', 'sm')}Télécharger le dossier (.zip)</a>` : ''}</details>`).join('')}</section>
  </div>
  <section class="card" style="margin-top:16px"><div class="row wrap" style="margin-bottom:6px"><h2 class="grow">Mes documents</h2>${r.documents.length ? `<a class="btn sm" href="api.php?a=docs.zip">${ic('download', 'sm')}Tout télécharger (.zip)</a>` : ''}</div>${r.documents.length ? Object.entries(by).map(([t, list]) => { const ty = types.find(x => x.id === t) || { label: t, icone: '📁', conservation: '' }; return `<h3 style="margin:16px 0 4px">${esc(ty.icone + ' ' + ty.label)}</h3><p class="small mut">À conserver : ${esc(ty.conservation)}</p>${list.map(d => `<div class="doc"><span class="ico">${esc(d.icone)}</span><div style="min-width:0"><b>${esc(d.titre)}</b>${d.ext ? ' <span class="chip">' + esc(d.ext.toUpperCase()) + '</span>' : ''}${d.partage ? ' <span class="flag i">foyer</span>' : ''}${d.a_moi ? '' : ' <span class="chip">partagé par ' + esc(d.proprietaire || '') + '</span>'}${d.a_trier ? ' <span class="flag w">peut être jeté</span>' : ''}
      <div class="small mut">${esc(fd(d.date))}${d.conserver_jusqu ? ' · à garder jusqu’au ' + esc(fd(d.conserver_jusqu)) : ''}${d.expiration ? ' · expire le ' + esc(fd(d.expiration)) : ''} · ${Math.max(1, Math.round(d.taille / 1024))} Ko</div>
      ${d.rapprochement && d.rapprochement.attente ? `<div class="small mut">En attente du relevé où apparaîtra ce salaire.</div>` : ''}${d.rapprochement && !d.rapprochement.attente ? `<div class="small ${Math.abs(d.rapprochement.ecart) <= 100 ? 'pos' : ''}" style="${Math.abs(d.rapprochement.ecart) <= 100 ? '' : 'color:var(--warn)'}">${Math.abs(d.rapprochement.ecart) <= 100 ? '✓ Virement de salaire retrouvé : ' + esc(E(d.rapprochement.recu)) : (d.rapprochement.recu ? '⚠ Reçu ' + esc(E(d.rapprochement.recu)) + ' pour un net à payer de ' + esc(E(d.meta.net)) : '⚠ Aucun virement de salaire retrouvé pour ce mois')}</div>` : ''}</div>
      <div class="row">${d.apercu ? `<a class="btn sm ghost" href="api.php?a=doc.get&id=${d.id}" target="_blank" rel="noopener" title="Ouvrir">${ic('eye', 'sm')}</a>` : ''}<a class="btn sm ghost" href="api.php?a=doc.get&id=${d.id}&dl=1" title="Télécharger">${ic('download', 'sm')}</a>${d.a_moi ? `<button class="btn sm ghost danger" data-ddel="${d.id}" title="Supprimer">${ic('trash', 'sm')}</button>` : ''}</div></div>`).join('')}`; }).join('') : '<p class="sec">Le coffre est vide. Commencez par vos 3 derniers bulletins de paie et un justificatif de domicile récent.</p>'}</section>`;
  const tsel = $('#dtype', v);
  const upd = () => { const t = types.find(x => x.id === tsel.value); $('#dkeep', v).textContent = t ? 'Conservation : ' + t.conservation : ''; $('#dpaie', v).classList.toggle('hide', tsel.value !== 'paie'); $('#dexp', v).classList.toggle('hide', !['identite', 'vehicule', 'assurance'].includes(tsel.value)); };
  tsel.addEventListener('change', upd); upd();
  $('#fdoc', v).addEventListener('submit', async e => {
    e.preventDefault();
    const btn = e.submitter || $('button.primary', e.target);
    const files = [...$('#dfile').files];
    if (!files.length) return;
    btn.disabled = true;
    const titre = $('#dtitle').value.trim();
    let ok = 0;
    const refus = [];
    for (const [i, original] of files.entries()) {
      if (files.length > 1) btn.textContent = 'Envoi ' + (i + 1) + ' / ' + files.length + '…';
      try {
        const file = await shrinkImage(original);
        if (file.size > S.status.max_upload) throw new Error('trop lourd (' + Math.round(S.status.max_upload / 1048576) + ' Mo au plus)');
        const fdata = new FormData();
        fdata.append('file', file, file.name);
        fdata.append('type', tsel.value); fdata.append('date', $('#ddate').value);
        fdata.append('titre', titre && files.length > 1 ? titre + ' (' + (i + 1) + ')' : titre);
        fdata.append('partage', $('#dshare').checked ? '1' : '0');
        if (tsel.value === 'paie') { fdata.append('periode', $('#dper').value); fdata.append('net', $('#dnet').value); fdata.append('net_imposable', $('#dnetimp').value); fdata.append('brut', $('#dbrut').value); }
        if (!$('#dexp').classList.contains('hide')) fdata.append('expiration', $('#dexpi').value);
        await api('doc.upload', { method: 'POST', form: fdata });
        ok++;
      } catch (err) { refus.push(original.name + ' : ' + err.message); }
    }
    if (ok) toast(ok > 1 ? ok + ' documents rangés et chiffrés.' : 'Document rangé et chiffré.');
    if (refus.length) toast('Refusé — ' + refus.join(' · '), false);
    if (ok) { route(); refreshBadge(); } else { btn.disabled = false; btn.innerHTML = ic('lock', 'sm') + 'Ranger dans le coffre'; }
  });
  $$('[data-ddel]', v).forEach(b => b.addEventListener('click', async () => {
    if (!await confirmBox('Supprimer ce document ?', 'Le fichier chiffré sera effacé définitivement du serveur.', 'Supprimer', true)) return;
    try { await api('doc.delete', { method: 'POST', body: { id: +b.dataset.ddel } }); toast('Document supprimé.'); route(); } catch (e) { toast(e.message, false); }
  }));
}
/** Réduit une photo lourde (téléphone) avant l'envoi : 2 400 px au plus, JPEG qualité 0,85. */
async function shrinkImage(file) {
  if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 1.5 * 1048576) return file;
  try {
    const bmp = await createImageBitmap(file);
    const k = Math.min(1, 2400 / Math.max(bmp.width, bmp.height));
    const c = document.createElement('canvas');
    c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
    c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
    const blob = await new Promise(res => c.toBlob(res, 'image/jpeg', 0.85));
    if (!blob || blob.size >= file.size) return file;
    return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
  } catch (e) { return file; }
}

/* ============================================================== Foyer */
async function viewHousehold(v, q, tok) {
  S.me = await api('me');
  if (!live(tok)) return;
  const months = S.months.length ? S.months : (await api('dashboard')).mois_disponibles;
  if (!live(tok)) return;
  const mois = q.mois || months[months.length - 1] || '';
  let d = null;
  if (autresActifs().length && months.length) { try { d = await api('dashboard', { query: { vue: 'foyer', mois } }); } catch (e) { d = null; } }
  if (!live(tok)) return;
  const foyer = S.me.foyer, admin = S.me.moi.admin, max = S.me.max_membres || 8;
  const plaisirOrEss = S.catList.filter(c => ['essentiel', 'plaisir', 'inconnu'].includes(c.nature));
  const ETAT = { actif: ['actif', 'k-bravo'], invite: ['invitation en attente', 'k-attention'], desactive: ['désactivé', ''] };
  const ligne = m => `<div class="doc"><span class="avatar">${esc(m.nom.slice(0, 1).toUpperCase())}</span><div class="grow" style="min-width:0"><b>${esc(m.nom)}</b>${m.moi ? ' <span class="mut small">(vous)</span>' : ''} <span class="chip ${ETAT[m.etat][1]}">${ETAT[m.etat][0]}</span>${m.role === 'admin' ? ' <span class="chip">administrateur</span>' : ''}
      <div class="small mut">${m.etat === 'actif' ? 'Totaux ' + (m.partage_totaux ? 'partagés' : 'privés') + ' · détail ' + (m.partage_details ? 'partagé' : 'privé') + (m.equite ? '' : ' · hors partage des dépenses') : m.etat === 'invite' ? 'N’a pas encore choisi son mot de passe' : 'Ne peut plus se connecter ; ses données sont conservées'}${admin && m.email ? ' · ' + esc(m.email) : ''}${admin && m.etat === 'invite' && m.invitation_expire ? ' · code valable jusqu’au ' + esc(new Date(m.invitation_expire * 1000).toLocaleDateString('fr-FR')) : ''}</div></div>
      ${admin && !m.moi ? `<div class="row">${m.etat === 'invite' ? `<button class="btn sm" data-reinv="${m.id}">Nouveau code</button>` : ''}<button class="btn sm ghost" data-gerer="${m.id}" title="Gérer ${esc(m.nom)}" aria-label="Gérer ${esc(m.nom)}">${ic('settings', 'sm')}</button></div>` : ''}</div>`;
  v.innerHTML = `<div class="page-head"><div class="grow"><h1>${esc(foyer.nom)}</h1><p>Chacun garde ses comptes ; le foyer voit les totaux que chacun choisit de partager.</p></div></div>
  <section class="card"><div class="card-head"><div class="grow"><h2>Les personnes du foyer</h2><p>${pl(membres().length, 'personne')} sur ${max} au plus · chacune a son propre accès et personne ne voit le détail des autres sans leur accord.</p></div></div>
    ${membres().map(ligne).join('')}
    ${admin && membres().length < max ? `<h3 style="margin:16px 0 6px">Ajouter une personne</h3>
      <form id="finv" class="grid g4" style="align-items:end"><div class="field"><label class="f" for="inom">Prénom</label><input class="in" id="inom" required maxlength="40" autocomplete="off"></div>
        <div class="field"><label class="f" for="iemail">Adresse e-mail (facultatif)</label><input class="in" id="iemail" type="email" inputmode="email" autocomplete="off" placeholder="pour lui envoyer l’invitation"></div>
        <div class="field"><label class="f" for="irole">Rôle</label><select class="in" id="irole"><option value="membre">Membre</option><option value="admin">Administrateur</option></select></div>
        <div class="field"><button class="btn primary" style="width:100%">${ic('users', 'sm')}Inviter</button></div></form>
      <p class="small mut">Membre : gère ses propres relevés, alertes et documents. Administrateur : peut aussi inviter, régler le foyer et l’envoi des e-mails.${S.me.mail_pret ? ' Avec une adresse e-mail, l’invitation part toute seule.' : ' L’envoi des e-mails n’est pas réglé : vous transmettrez le code vous-même.'}</p>` : ''}
    ${admin ? '' : '<p class="small mut" style="margin-top:8px">Seul un administrateur du foyer peut ajouter ou gérer des personnes.</p>'}</section>
  ${d && d.equite ? `<div class="filters" style="margin-top:16px"><select class="in sm" id="emois" style="width:auto" aria-label="Mois">${months.slice().reverse().map(m => `<option value="${m}" ${m === mois ? 'selected' : ''}>${esc(fm(m))}</option>`).join('')}</select></div>${equityCard(d.equite)}`
    : autresActifs().length ? '<div class="note" style="margin-top:16px">L’équité s’affiche dès que deux personnes au moins partagent leurs totaux (Réglages › Confidentialité) et ont importé leurs relevés du mois.</div>' : ''}
  <div class="grid g2" style="margin-top:16px">
    <section class="card"><h2>Règle de partage</h2><p class="sec small" style="margin:4px 0 10px">Comment répartir les dépenses communes ?</p>
      <div class="seg" id="mode"><button data-mode="prorata" class="${foyer.mode === 'prorata' ? 'on' : ''}" ${admin ? '' : 'disabled'}>Au prorata des revenus</button><button data-mode="egal" class="${foyer.mode === 'egal' ? 'on' : ''}" ${admin ? '' : 'disabled'}>À parts égales</button></div>
      <p class="small mut" style="margin-top:10px">Prorata : qui gagne 60 % des revenus du foyer paie 60 % des dépenses communes, et chacun garde le même « reste pour soi » en proportion. À parts égales : plus simple, mais plus lourd pour le revenu le plus bas. Une personne peut se retirer du partage (enfant, colocataire) dans ses Réglages.</p></section>
    <section class="card"><h2>Dépenses communes</h2><p class="sec small" style="margin:4px 0 10px">Catégories comptées automatiquement comme communes (chaque opération reste modifiable).</p>
      <div style="columns:2;column-gap:16px">${plaisirOrEss.map(c => `<label class="check" style="break-inside:avoid"><input type="checkbox" data-com="${c.id}" ${foyer.communes.includes(c.id) ? 'checked' : ''} ${admin ? '' : 'disabled'}><span>${esc(c.icone + ' ' + c.label)}</span></label>`).join('')}</div>
      ${admin ? '<button class="btn" id="bcom" style="margin-top:8px">Enregistrer</button>' : '<p class="small mut">Réglé par un administrateur du foyer.</p>'}</section>
  </div>`;
  const em = $('#emois', v); if (em) em.addEventListener('change', e => go('foyer', { mois: e.target.value }));
  const fi = $('#finv', v);
  if (fi) fi.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = e.submitter || $('button', fi); btn.disabled = true;
    try { const r = await api('member.invite', { method: 'POST', body: { nom: $('#inom').value, email: $('#iemail').value, role: $('#irole').value } }); showInvite(r); route(); }
    catch (err) { toast(err.message, false); btn.disabled = false; }
  });
  $$('[data-reinv]', v).forEach(b => b.addEventListener('click', async () => { try { showInvite(await api('member.reinvite', { method: 'POST', body: { id: +b.dataset.reinv } })); route(); } catch (err) { toast(err.message, false); } }));
  $$('[data-gerer]', v).forEach(b => b.addEventListener('click', () => { const m = membres().find(x => x.id === +b.dataset.gerer); if (m) manageMember(m); }));
  if (admin) {
    $$('[data-mode]', v).forEach(b => b.addEventListener('click', async () => { try { await api('foyer.save', { method: 'POST', body: { mode: b.dataset.mode } }); S.me.foyer.mode = b.dataset.mode; route(); } catch (e) { toast(e.message, false); } }));
    $('#bcom', v).addEventListener('click', async () => { const list = $$('[data-com]', v).filter(x => x.checked).map(x => x.dataset.com); try { await api('foyer.save', { method: 'POST', body: { communes: list } }); S.me.foyer.communes = list; toast('Dépenses communes mises à jour (opérations reclassées).'); route(); } catch (e) { toast(e.message, false); } });
  }
}
function showInvite(r) {
  const lien = location.origin + location.pathname + '#/activer?code=' + encodeURIComponent(r.invitation);
  modal({ title: 'Invitation pour ' + r.nom, html: `${r.email_envoye ? `<div class="note ok small" style="margin-bottom:10px">${ic('check', 'sm')} Invitation envoyée à ${esc(r.email)}.</div>` : ''}
    <p>${r.email_envoye ? 'Si l’e-mail n’arrive pas, transmettez' : 'Transmettez'} ce code à ${esc(r.nom)} (valable 30 jours, à usage unique). Sur l’écran de connexion : « J’ai un code d’invitation », puis choix de son propre mot de passe.</p><div class="code">${esc(r.invitation)}</div>
    <p class="small sec" style="margin-top:10px">Ou ce lien, à ouvrir depuis la maison : <span style="word-break:break-all">${esc(lien)}</span></p>`,
    actions: [{ label: 'Copier le lien', onClick: () => { copyText(lien); return false; } }, { label: 'Copier le code', onClick: () => { copyText(r.invitation); return false; } }, { label: 'Fermer', kind: 'primary' }] });
}
function manageMember(m) {
  modal({ title: m.nom, html: `
    <div class="grid g2"><div class="field"><label class="f" for="mnom">Prénom</label><input class="in" id="mnom" value="${esc(m.nom)}" maxlength="40"></div>
      <div class="field"><label class="f" for="mrole">Rôle</label><select class="in" id="mrole"><option value="membre" ${m.role === 'membre' ? 'selected' : ''}>Membre</option><option value="admin" ${m.role === 'admin' ? 'selected' : ''}>Administrateur</option></select></div></div>
    <label class="check"><input type="checkbox" id="moff" ${m.etat === 'desactive' ? 'checked' : ''}><span>Compte désactivé <span class="small mut">— plus aucune connexion possible ; ses données restent en place</span></span></label>
    <h3 style="margin:18px 0 6px">Retirer ${esc(m.nom)} du foyer</h3>
    <p class="small mut">Efface son compte, ses relevés, ses opérations, ses alertes et ses documents. Irréversible.</p>
    <label class="check"><input type="checkbox" id="mkeep" checked><span>Garder ses documents partagés avec le foyer (bail, factures…) : ils passent à votre nom</span></label>
    <div class="row wrap"><input class="in" type="password" id="mpw" placeholder="Votre mot de passe" style="max-width:220px" autocomplete="current-password"><button class="btn danger" id="mdel">${ic('trash', 'sm')}Retirer du foyer</button></div>`,
    actions: [{ label: 'Annuler' }, { label: 'Enregistrer', kind: 'primary', onClick: async md => {
      await api('member.update', { method: 'POST', body: { id: m.id, nom: $('#mnom', md).value, role: $('#mrole', md).value, desactive: $('#moff', md).checked } });
      toast('Modifications enregistrées.');
      route();
    } }],
    onOpen: (md, close) => {
      $('#mdel', md).addEventListener('click', async () => {
        const pw = $('#mpw', md).value;
        if (!pw) return toast('Saisissez votre mot de passe pour confirmer.', false);
        if (!await confirmBox('Retirer ' + m.nom + ' ?', 'Son compte et toutes ses données seront effacés définitivement.', 'Retirer', true)) return;
        try {
          const r = await api('member.delete', { method: 'POST', body: { id: m.id, mot_de_passe: pw, garder_partages: $('#mkeep', md).checked } });
          close();
          toast(m.nom + ' a été retiré(e) du foyer.' + (r.documents_repris ? ' ' + pl(r.documents_repris, 'document partagé repris', 'documents partagés repris') + '.' : ''));
          route();
        } catch (e) { toast(e.message, false); }
      });
    } });
}

/* ============================================================== Courriers */
async function viewLetters(v) {
  const cat = S.me.courriers;
  const groups = { Banque: [], Créancier: [], Médiateur: [] };
  for (const [id, l] of Object.entries(cat)) (groups[l[1]] = groups[l[1]] || []).push([id, l]);
  v.innerHTML = `<div class="page-head"><div class="grow"><h1>Courriers prêts à envoyer</h1><p>Pré-remplis avec votre nom, votre adresse et les textes de loi. Les alertes et l’analyse des frais en préparent avec les montants exacts.</p></div></div>
  <div class="grid g2"><section class="card">${Object.entries(groups).filter(([, l]) => l.length).map(([g, l]) => `<h3 style="margin:6px 0 8px">${g === 'Banque' ? 'À votre banque' : g === 'Créancier' ? 'À un créancier (EDF, box, assurance…)' : 'Au médiateur'}</h3>${l.map(([id, x]) => `<button class="btn" style="width:100%;justify-content:flex-start;height:auto;padding:10px 14px;margin-bottom:6px;text-align:left;white-space:normal" data-pick="${id}"><span>${ic('mail', 'sm')}</span><span><b>${esc(x[0])}</b><br><span class="small mut" style="font-weight:500">${esc(x[2])}</span></span></button>`).join('')}`).join('')}</section>
  <section class="card" id="lform"><p class="sec">Choisissez un courrier à gauche.</p></section></div>`;
  $$('[data-pick]', v).forEach(b => b.addEventListener('click', () => {
    const id = b.dataset.pick;
    const needOp = ['operation_non_autorisee', 'prlv_8_semaines', 'frais_plafond', 'frais_geste', 'represente', 'fragilite', 'virement_instantane', 'mediateur', 'sbi', 'assurance_moyens'].includes(id);
    const needCr = ['opposition_creancier', 'changement_date', 'resiliation'].includes(id);
    $('#lform', v).innerHTML = `<h2 style="margin-bottom:10px">${esc(cat[id][0])}</h2><p class="sec small">${esc(cat[id][2])}</p>
      ${needOp ? `<div class="grid g2" style="margin-top:10px"><div class="field"><label class="f" for="ld">Date de l’opération</label><input class="in" type="date" id="ld"></div><div class="field"><label class="f" for="lm">Montant (€)</label><input class="in" id="lm" inputmode="decimal"></div></div><div class="field"><label class="f" for="ll">Libellé tel qu’il figure sur le relevé</label><input class="in" id="ll"></div>` : ''}
      ${needCr ? `<div class="field" style="margin-top:10px"><label class="f" for="lc">Créancier / service</label><input class="in" id="lc" placeholder="ex. EDF, Free, assurance…"></div>` : ''}
      ${id === 'changement_date' ? `<div class="field"><label class="f" for="lj">Jour de prélèvement souhaité</label><input class="in" type="number" min="1" max="28" id="lj" value="5"></div>` : ''}
      ${id === 'recap_frais' ? `<div class="field" style="margin-top:10px"><label class="f" for="la">Année</label><input class="in" type="number" id="la" value="${new Date().getFullYear() - 1}"></div>` : ''}
      <button class="btn primary" id="lgo" style="margin-top:6px">${ic('mail', 'sm')}Préparer le courrier</button>
      <p class="small mut" style="margin-top:10px">Pour des frais précis, le plus simple est de partir de l’onglet <a href="#/frais">Frais bancaires</a> ou d’une <a href="#/alertes">alerte</a> : les montants et les dates sont repris automatiquement.</p>`;
    $('#lgo', v).addEventListener('click', () => {
      const ctx = {};
      const g = s => { const el = $(s, v); return el ? el.value : ''; };
      if (needOp) { ctx.date = g('#ld'); ctx.label = g('#ll'); const c = toCents(g('#lm')); if (c && !Number.isNaN(c)) { ctx.montant = Math.abs(c); ctx.total = Math.abs(c); } }
      if (needCr) ctx.creancier = g('#lc');
      if (id === 'changement_date') ctx.jour = +g('#lj') || 5;
      if (id === 'recap_frais') ctx.annee = +g('#la');
      if (id === 'operation_non_autorisee' || id === 'prlv_8_semaines') ctx.type = 'prelevement';
      openLetter(id, { ctx });
    });
  }));
}

/* ============================================================== Vos droits */
async function viewRights(v, q, tok) {
  const r = await api('legal');
  if (!live(tok)) return;
  v.innerHTML = `<div class="page-head"><div class="grow"><h1>Vos droits face à la banque</h1><p>Textes vérifiés le ${esc(fd(r.verifie))} (Code monétaire et financier, INC, Banque de France, service-public.gouv.fr). Une aide pour agir, pas un conseil juridique personnalisé.</p></div></div>
  <div class="grid g-hero"><section class="card kb">${r.fiches.map((f, i) => `<details ${i === 0 ? 'open' : ''}><summary>${esc(f.titre)}</summary><p class="sec small">${esc(f.resume)}</p><ul>${f.points.map(p => `<li>${esc(p)}</li>`).join('')}</ul>
      ${f.refs.length ? `<div class="refs" style="margin-bottom:10px">${f.refs.map(c => r.references[c] ? `<details><summary>${esc(r.references[c][0])}</summary><p>${esc(r.references[c][1])}</p></details>` : '').join('')}</div>` : ''}
      ${f.liens.length ? `<p class="small">${f.liens.map(l => `<a href="${esc(l[1])}" target="_blank" rel="noopener noreferrer">${esc(l[0])} ↗</a>`).join(' · ')}</p>` : ''}</details>`).join('')}</section>
    <div class="stack"><section class="card"><h2>Les plafonds en un coup d’œil</h2><table class="tbl" style="margin-top:8px"><tbody>
      <tr><td>Commission d’intervention</td><td class="r num">${esc(E(r.plafonds.ci_op))} / opération<br><span class="mut">${esc(E0(r.plafonds.ci_mois))} / mois</span></td></tr>
      <tr><td>Rejet de prélèvement ou de virement (tout compris)</td><td class="r num">${esc(E(r.plafonds.rejet))}<br><span class="mut">et pas plus que le montant rejeté</span></td></tr>
      <tr><td>Chèque rejeté (tout compris)</td><td class="r num">${esc(E0(r.plafonds.cheque_bas))} / ${esc(E0(r.plafonds.cheque_haut))}<br><span class="mut">≤ 50 € / au-delà</span></td></tr>
      <tr><td>Client fragile : total des frais d’incidents</td><td class="r num">${esc(E0(r.plafonds.fragile))} / mois</td></tr>
      <tr><td>Solde laissé en cas de saisie</td><td class="r num">${esc(E(r.plafonds.sbi))}</td></tr></tbody></table></section>
    <section class="card"><h2>Combien coûte un découvert ?</h2><p class="sec small" style="margin:4px 0 10px">Les agios se calculent au jour le jour : montant × taux × jours / 365.</p>
      <div class="grid g3"><div class="field"><label class="f" for="sm">Découvert (€)</label><input class="in" id="sm" value="300" inputmode="decimal"></div><div class="field"><label class="f" for="sj">Jours</label><input class="in" id="sj" value="10" inputmode="numeric"></div><div class="field"><label class="f" for="st">Taux annuel (%)</label><input class="in" id="st" value="16" inputmode="decimal"></div></div>
      <p id="sres" style="font-size:18px;font-weight:700"></p><p class="small mut">Comparez avec une commission d’intervention (jusqu’à 8 € par opération) : rester dans le découvert autorisé coûte bien moins cher qu’un dépassement.</p></section></div></div>`;
  const calc = () => { const m = toCents($('#sm').value) || 0, j = +$('#sj').value || 0, t = parseFloat(String($('#st').value).replace(',', '.')) || 0; $('#sres').textContent = '≈ ' + E(Math.round(Math.abs(m) * t / 100 * j / 365)) + ' d’agios'; };
  ['#sm', '#sj', '#st'].forEach(s => $(s, v).addEventListener('input', calc)); calc();
}

/* ============================================================== Réglages */
async function viewSettings(v, q, tok) {
  S.me = await api('me');
  if (!live(tok)) return;
  const p = S.me.profil, admin = S.me.moi.admin;
  const budgets = S.me.budgets || {};
  const budCats = S.catList.filter(c => ['plaisir', 'essentiel'].includes(c.nature) && !['loyer', 'credit_immo', 'impots', 'frais_bancaires', 'administratif', 'credit_conso'].includes(c.id));
  const theme = store.get('theme', 'auto');
  v.innerHTML = `<div class="page-head"><div class="grow"><h1>Réglages</h1><p>Vos informations restent sur votre serveur.</p></div></div><div class="grid g2">
  <section class="card"><h2>Pour vos courriers</h2><p class="sec small" style="margin:4px 0 12px">Repris automatiquement dans les modèles. Restent sur votre serveur.</p>
    <div class="field"><label class="f" for="pn">Prénom et nom</label><input class="in" id="pn" value="${esc(p.nom_complet)}" autocomplete="name"></div>
    <div class="field"><label class="f" for="pa">Adresse postale</label><textarea class="in" id="pa" style="min-height:70px" autocomplete="street-address">${esc(p.adresse)}</textarea></div>
    <div class="grid g2"><div class="field"><label class="f" for="pv">Ville (pour « Fait à »)</label><input class="in" id="pv" value="${esc(p.ville)}"></div><div class="field"><label class="f" for="pb">Banque</label><input class="in" id="pb" value="${esc(p.banque)}"></div></div>
    <div class="field"><label class="f" for="pc">Référence du compte</label><input class="in" id="pc" value="${esc(p.compte)}" placeholder="ex. n° …5678"><div class="help">Les 4 derniers chiffres suffisent : inutile de stocker l’IBAN complet.</div></div>
    <button class="btn primary" data-save="courrier">Enregistrer</button></section>
  <section class="card"><h2>Reconnaissance automatique</h2><p class="sec small" style="margin:4px 0 12px">Comment ces noms apparaissent sur vos relevés (séparés par des virgules).</p>
    <div class="field"><label class="f" for="am">Mes noms (virements entre mes comptes)</label><input class="in" id="am" value="${esc(p.alias_moi.join(', '))}"></div>
    <div class="field"><label class="f" for="ap">Autres personnes du foyer (virements entre vous)</label><input class="in" id="ap" value="${esc(p.alias_partenaire.join(', '))}"><div class="help">Les prénoms et les noms que chacun déclare sont déjà reconnus${autres().length ? ' (' + esc(listeNoms(autres().map(m => m.nom))) + ')' : ''} ; ajoutez ici une autre écriture qui apparaît sur vos relevés (« M OU MME … »). Ces virements sont neutres pour le foyer.</div></div>
    <div class="field"><label class="f" for="ae">Mon employeur</label><input class="in" id="ae" value="${esc(p.alias_employeur.join(', '))}" placeholder="tel qu’il apparaît sur le virement de salaire"></div>
    <label class="check"><input type="checkbox" id="arec" checked><span>Reclasser mes opérations existantes</span></label>
    <button class="btn primary" data-save="alias">Enregistrer</button></section>
  <section class="card"><h2>Paie, solde, épargne</h2>
    <div class="grid g2" style="margin-top:10px"><div class="field"><label class="f" for="pj">Jour habituel de la paie</label><input class="in" type="number" min="0" max="31" id="pj" value="${p.jour_paie || ''}" placeholder="détecté automatiquement"></div>
      <div class="field"><label class="f" for="pe">Épargne disponible (€)</label><input class="in" id="pe" inputmode="decimal" value="${esc(centsIn(p.epargne_dispo))}" placeholder="livrets…"><div class="help">Sert à mesurer votre coussin de sécurité.</div></div></div>
    <div class="grid g2"><div class="field"><label class="f" for="ps">Solde du compte courant (€)</label><input class="in" id="ps" inputmode="decimal" value="${esc(centsIn(p.solde))}"></div><div class="field"><label class="f" for="psd">À la date du</label><input class="in" type="date" id="psd" value="${esc(p.solde_date || today())}"></div></div>
    <button class="btn primary" data-save="paie">Enregistrer</button></section>
  <section class="card"><h2>Statut bancaire</h2><p class="sec small" style="margin:4px 0 12px">Il change les plafonds de frais que Boussole vérifie.</p>
    ${[['non', 'Aucun statut particulier', 'Commission d’intervention : 8 €/opération, 80 €/mois.'], ['fragile', 'Reconnu(e) en situation de fragilité financière', 'Frais d’incidents plafonnés à 25 € par mois.'], ['ocf', 'Offre spécifique « clientèle fragile » souscrite', '20 €/mois et 200 €/an ; commissions à 4 €.'], ['sbb', 'Services bancaires de base (droit au compte)', 'Commissions à 4 €/opération, 20 €/mois.']].map(([k, l, h]) => `<label class="check"><input type="radio" name="frag" value="${k}" ${p.fragile === k ? 'checked' : ''}><span><b>${l}</b><br><span class="small mut">${h}</span></span></label>`).join('')}
    <button class="btn primary" data-save="fragile">Enregistrer</button></section>
  <section class="card span2"><h2>Enveloppes mensuelles</h2><p class="sec small" style="margin:4px 0 12px">Un budget « plaisir » assumé tient mieux qu’une interdiction. Alerte à 80 % puis à 100 %. Laissez vide pour ne pas suivre.</p>
    <div class="grid g4">${budCats.map(c => `<div class="field"><label class="f" for="b-${c.id}">${esc(c.icone + ' ' + c.label)}</label><input class="in" id="b-${c.id}" data-bud="${c.id}" inputmode="decimal" value="${esc(centsIn(budgets[c.id] || null))}" placeholder="€ / mois"></div>`).join('')}</div>
    <button class="btn primary" id="sbud">Enregistrer les enveloppes</button></section>
  <section class="card"><h2>Ce qui est « utile » pour vous</h2><p class="sec small" style="margin:4px 0 12px">Changez la nature par défaut d’une catégorie (ex. la salle de sport en « Essentiel »).</p>
    <div class="tbl-wrap" style="max-height:340px;overflow-y:auto"><table class="tbl"><tbody>${S.catList.filter(c => ['essentiel', 'plaisir', 'evitable', 'inconnu'].includes(c.nature)).map(c => `<tr><td>${esc(c.icone + ' ' + c.label)}</td><td><select class="in sm" data-nat="${c.id}">${['essentiel', 'plaisir', 'evitable', 'inconnu'].map(n => `<option value="${n}" ${((p.natures || {})[c.id] || c.nature) === n ? 'selected' : ''}>${esc(natLabel(n))}</option>`).join('')}</select></td></tr>`).join('')}</tbody></table></div>
    <button class="btn primary" style="margin-top:10px" data-save="natures">Enregistrer et reclasser</button></section>
  <section class="card"><h2>Confidentialité dans le foyer</h2>
    <label class="check"><input type="checkbox" id="pt" ${p.partage_totaux ? 'checked' : ''}><span><b>Partager mes totaux avec le foyer</b><br><span class="small mut">Revenus, dépenses par nature, dépenses communes : utiles pour la vue Foyer et l’équité.</span></span></label>
    <label class="check"><input type="checkbox" id="pd" ${p.partage_details ? 'checked' : ''}><span><b>Partager le détail de mes opérations</b><br><span class="small mut">Les autres personnes du foyer pourront les consulter (sans les modifier). Permet aussi de repérer les abonnements payés en double.</span></span></label>
    <label class="check"><input type="checkbox" id="peq" ${p.equite !== false ? 'checked' : ''}><span><b>Participer au partage des dépenses communes</b><br><span class="small mut">Décochez pour un enfant ou une personne hébergée qui ne contribue pas aux charges.</span></span></label>
    <button class="btn primary" data-save="partage">Enregistrer</button>
    <h3 style="margin:16px 0 6px">Affichage</h3><div class="seg" id="sth">${[['auto', 'Automatique'], ['light', 'Clair'], ['dark', 'Sombre']].map(([k, l]) => `<button data-th="${k}" class="${theme === k ? 'on' : ''}">${l}</button>`).join('')}</div></section>
  <section class="card"><h2>Alertes par e-mail</h2>
    <p class="sec small" style="margin:4px 0 10px">Identifiant de connexion et adresse de réception : <b>${esc(S.me.moi.email || 'non renseignée')}</b></p>
    ${S.me.mail_pret ? '' : '<div class="note warn small" style="margin-bottom:10px">L’envoi des e-mails n’est pas encore configuré : voir la carte « Envoi des e-mails ».</div>'}
    <div class="field"><label class="f" for="mlvl">Recevoir</label><select class="in" id="mlvl">${[['importantes', 'Les alertes critiques et à vérifier (conseillé)'], ['critiques', 'Seulement les alertes critiques'], ['toutes', 'Toutes, y compris conseils et bravos'], ['aucune', 'Aucun e-mail d’alerte']].map(([k, l]) => `<option value="${k}" ${p.alertes_email === k ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
    <div class="field"><label class="f" for="mcont">Contenu des e-mails</label><select class="in" id="mcont"><option value="titres" ${p.email_contenu !== 'discret' ? 'selected' : ''}>Le titre de chaque alerte</option><option value="discret" ${p.email_contenu === 'discret' ? 'selected' : ''}>Discret : seulement le nombre d’alertes, aucun montant</option></select><div class="help">Un e-mail n’est pas un canal confidentiel : le mode discret n’y fait figurer aucune information financière.</div></div>
    <div class="row wrap"><button class="btn primary" id="bmpref">Enregistrer</button><button class="btn" id="bmtest">Envoyer un e-mail de test</button></div>
    <h3 style="margin:16px 0 6px">Changer d’adresse</h3>
    <div class="field"><input class="in" id="nemail" type="email" inputmode="email" placeholder="nouvelle adresse" autocomplete="email"></div>
    <div class="row wrap"><input class="in" type="password" id="nemailpw" placeholder="mot de passe actuel" autocomplete="current-password" style="max-width:220px"><button class="btn" id="bnemail">Changer l’adresse</button></div></section>
  <section class="card"><h2>Envoi des e-mails</h2><p class="sec small" style="margin:4px 0 10px">Réglage commun à tout le foyer : par où partent les e-mails de Boussole.</p><div id="mailform" class="sec small">${admin ? 'Chargement…' : 'Réglé par un administrateur du foyer.'}</div></section>
  <section class="card"><h2>Sécurité</h2>
    <div class="field" style="margin-top:10px"><label class="f" for="op">Mot de passe actuel</label><input class="in" type="password" id="op" autocomplete="current-password"></div>
    <div class="field"><label class="f" for="np">Nouveau mot de passe</label><input class="in" type="password" id="np" autocomplete="new-password"></div>
    <div class="row wrap"><button class="btn primary" id="bpw">Changer le mot de passe</button><button class="btn" id="brec">Nouveau code de secours</button></div>
    <div id="sauvegarde"></div>
    <h3 style="margin:16px 0 6px">Journal de mon compte</h3><div id="journal" class="small sec">…</div></section>
  <section class="card"><h2>Mes données</h2><p class="sec small" style="margin:4px 0 12px">Vos données vous appartiennent.</p>
    <div class="row wrap">${exportMenu('', '', 'Exporter mes opérations')}<a class="btn" href="api.php?a=export&format=json" download>${ic('download', 'sm')}Toutes mes données (JSON)</a><a class="btn" href="api.php?a=docs.zip">${ic('download', 'sm')}Mes documents (.zip)</a></div>
    <h3 style="margin:16px 0 6px">Effacer mes données</h3><p class="small mut">Supprime vos opérations, relevés, documents, alertes et règles. Votre accès est conservé. Irréversible.</p>
    <div class="row wrap"><input class="in" type="password" id="purgepw" placeholder="Mot de passe" style="max-width:220px" autocomplete="current-password"><button class="btn danger" id="bpurge">${ic('trash', 'sm')}Tout effacer</button></div></section>
  </div>`;
  const val = s => $(s, v).value;
  const save = async (body, msg = 'Réglages enregistrés.') => { try { const r = await api('me.save', { method: 'POST', body }); S.me.profil = r.profil; toast(msg); refreshBadge(); } catch (e) { toast(e.message, false); } };
  const list = s => val(s).split(',').map(x => x.trim()).filter(Boolean);
  $$('[data-save]', v).forEach(b => b.addEventListener('click', () => {
    const k = b.dataset.save;
    if (k === 'courrier') save({ nom_complet: val('#pn'), adresse: val('#pa'), ville: val('#pv'), banque: val('#pb'), compte: val('#pc') });
    if (k === 'alias') save({ alias_moi: list('#am'), alias_partenaire: list('#ap'), alias_employeur: list('#ae'), reclasser: $('#arec').checked }, 'Enregistré : opérations reclassées.');
    if (k === 'paie') {
      const e = val('#pe').trim() === '' ? null : toCents(val('#pe')), sd = val('#ps').trim() === '' ? null : toCents(val('#ps'));
      if (Number.isNaN(e) || Number.isNaN(sd)) return toast('Montant invalide.', false);
      save({ jour_paie: +val('#pj') || 0, epargne_dispo: e, solde: sd, solde_date: val('#psd') });
    }
    if (k === 'fragile') { const r = $('input[name=frag]:checked', v); if (r) save({ fragile: r.value }, 'Statut enregistré : plafonds recalculés.'); }
    if (k === 'natures') { const nat = {}; $$('[data-nat]', v).forEach(s => { nat[s.dataset.nat] = s.value; }); save({ natures: nat, reclasser: true }, 'Natures enregistrées : opérations reclassées.'); }
    if (k === 'partage') save({ partage_totaux: $('#pt').checked, partage_details: $('#pd').checked, equite: $('#peq').checked });
  }));
  $('#sbud', v).addEventListener('click', async () => {
    try {
      for (const inp of $$('[data-bud]', v)) {
        const c = inp.value.trim() === '' ? 0 : toCents(inp.value);
        if (Number.isNaN(c)) throw new Error('Montant invalide : ' + inp.value);
        if ((budgets[inp.dataset.bud] || 0) !== c) await api('budget.set', { method: 'POST', body: { categorie: inp.dataset.bud, montant: c } });
      }
      toast('Enveloppes enregistrées.'); refreshBadge(); route();
    } catch (e) { toast(e.message, false); }
  });
  $$('[data-th]', v).forEach(b => b.addEventListener('click', () => { store.set('theme', b.dataset.th); applyTheme(); renderShell(); route(); }));
  $('#bmpref', v).addEventListener('click', () => save({ alertes_email: val('#mlvl'), email_contenu: val('#mcont') }, 'Préférences d’alerte enregistrées.'));
  $('#bmtest', v).addEventListener('click', async e => { const b = e.currentTarget; b.disabled = true; try { const r = await api('mail.test', { method: 'POST' }); toast(r.message); } catch (err) { toast(err.message, false); } b.disabled = false; });
  $('#bnemail', v).addEventListener('click', async () => {
    try { const r = await api('email.change', { method: 'POST', body: { email: val('#nemail'), mot_de_passe: val('#nemailpw') } }); S.me.moi.email = r.email; toast('Adresse changée : ' + r.email + '. C’est aussi votre nouvel identifiant.'); route(); }
    catch (err) { toast(err.message, false); }
  });
  if (admin) mailSettings($('#mailform', v));
  $('#bpw', v).addEventListener('click', async () => { try { await api('password', { method: 'POST', body: { ancien: val('#op'), nouveau: val('#np') } }); toast('Mot de passe changé.'); $('#op').value = ''; $('#np').value = ''; } catch (e) { toast(e.message, false); } });
  $('#brec', v).addEventListener('click', async () => {
    const pw = val('#op');
    if (!pw) return toast('Saisissez votre mot de passe actuel dans le premier champ.', false);
    try { const r = await api('recovery.new', { method: 'POST', body: { mot_de_passe: pw } }); showRecovery(r.code_secours, 'Nouveau code de secours'); $('#op').value = ''; } catch (e) { toast(e.message, false); }
  });
  $('#bpurge', v).addEventListener('click', async () => {
    if (!await confirmBox('Tout effacer ?', 'Opérations, relevés, documents et alertes seront supprimés définitivement.', 'Tout effacer', true)) return;
    try { await api('me.purge', { method: 'POST', body: { mot_de_passe: val('#purgepw') } }); toast('Vos données ont été effacées.'); S.months = []; go('accueil'); } catch (e) { toast(e.message, false); }
  });
  try {
    const j = await api('security');
    const sv = j.sauvegarde, se = j.sauvegarde_echec;
    if (sv || se) {
      const quand = t => new Date(t * 1000).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
      const vieux = sv && Date.now() / 1000 - sv.t > 8 * 86400;
      $('#sauvegarde', v).innerHTML = `<h3 style="margin:16px 0 6px">Sauvegardes</h3>${sv ? `<p class="small ${vieux ? '' : 'pos'}" style="${vieux ? 'color:var(--warn)' : ''}">${ic(vieux ? 'alert' : 'check', 'sm')} Dernière sauvegarde réussie : ${esc(quand(sv.t))} · ${pl(sv.docs, 'document')}${sv.nb ? ' · ' + pl(sv.nb, 'sauvegarde conservée', 'sauvegardes conservées') : ''}${sv.cle ? '' : ' · sans la clé de chiffrement'}</p>` : ''}${se && (!sv || se.t > sv.t) ? `<p class="small" style="color:var(--crit-mark)">${ic('alert', 'sm')} Échec le ${esc(quand(se.t))} : ${esc(se.erreur)}</p>` : ''}`;
    }
    const EV = { connexion: 'Connexion', echec_connexion: 'Mot de passe erroné', deconnexion: 'Déconnexion', import: 'Import', document_ouvert: 'Document ouvert', document_ajoute: 'Document ajouté', document_supprime: 'Document supprimé', reglages: 'Réglages', mot_de_passe_change: 'Mot de passe changé', activation: 'Activation', installation: 'Installation', export_csv: 'Export', dossier_telecharge: 'Dossier téléchargé', echec_code_secours: 'Code de secours erroné', mot_de_passe_reinitialise: 'Mot de passe réinitialisé', nouveau_code_secours: 'Nouveau code de secours', invitation: 'Invitation', suppression_releve: 'Relevé supprimé', donnees_effacees: 'Données effacées', reglages_foyer: 'Réglages du foyer', email_echec: 'Échec d’envoi d’e-mail', email_alertes: 'Alertes envoyées par e-mail', compte_bloque: 'Compte bloqué', reinit_demande: 'Lien « mot de passe oublié » demandé', email_change: 'Adresse e-mail changée', reglages_email: 'Réglages d’envoi des e-mails', email_test: 'E-mail de test' };
    $('#journal', v).innerHTML = j.journal.length ? `<div class="tbl-wrap" style="max-height:220px;overflow-y:auto"><table class="tbl"><tbody>${j.journal.map(e => `<tr><td class="nowrap">${esc(new Date(e.t * 1000).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }))}</td><td>${esc(EV[e.ev] || e.ev)}${e.ev.startsWith('echec') ? ' <span class="flag">!</span>' : ''}<div class="mut" style="font-size:11.5px">${esc(e.info || '')} ${esc(e.ip)}</div></td></tr>`).join('')}</tbody></table></div><p class="small mut" style="margin-top:6px">Une connexion que vous ne reconnaissez pas ? Changez immédiatement votre mot de passe.</p>` : 'Aucun évènement.';
  } catch (e) { /* journal non essentiel */ }
}

/* ============================================================== Envoi des e-mails (Réglages) */
async function mailSettings(box) {
  let c;
  try { c = await api('mail.get'); } catch (e) { box.textContent = e.message; return; }
  box.classList.remove('sec', 'small');
  box.innerHTML = `<div class="note small" style="margin-bottom:12px">Une adresse <b>Outlook, Hotmail ou Live</b> peut <b>recevoir</b> les alertes, mais plus les <b>envoyer</b> : depuis septembre 2024, Microsoft exige une connexion OAuth que ce petit serveur ne gère pas. Envoyez depuis l’adresse de votre hébergeur, Gmail (mot de passe d’application) ou Brevo.</div>
    <div class="grid g2"><div class="field"><label class="f" for="mpre">Préréglage</label><select class="in" id="mpre"><option value="">— choisir —</option>${c.prereglages.map(p => `<option value="${esc(p.id)}">${esc(p.label)}</option>`).join('')}</select></div>
      <div class="field"><label class="f" for="mmode">Mode</label><select class="in" id="mmode"><option value="off">Désactivé</option><option value="php" ${c.mail_php ? '' : 'disabled'}>Fonction mail() de l’hébergeur</option><option value="smtp">Serveur SMTP</option></select></div></div>
    <div class="grid g2"><div class="field"><label class="f" for="mfrom">Adresse d’expédition</label><input class="in" id="mfrom" type="email" value="${esc(c.from)}" placeholder="alertes@mon-domaine.fr"></div><div class="field"><label class="f" for="mname">Nom affiché</label><input class="in" id="mname" value="${esc(c.from_name)}"></div></div>
    <div id="msmtp"><div class="grid g3"><div class="field"><label class="f" for="mhost">Serveur SMTP</label><input class="in" id="mhost" value="${esc(c.host)}" placeholder="ssl0.ovh.net"></div><div class="field"><label class="f" for="mport">Port</label><input class="in" id="mport" type="number" value="${esc(c.port)}"></div>
      <div class="field"><label class="f" for="msec">Chiffrement</label><select class="in" id="msec"><option value="tls">STARTTLS (587)</option><option value="ssl">SSL/TLS (465)</option><option value="none">Aucun (réseau local)</option></select></div></div>
      <div class="grid g2"><div class="field"><label class="f" for="muser">Identifiant</label><input class="in" id="muser" value="${esc(c.user)}" autocomplete="off"></div><div class="field"><label class="f" for="mpass">Mot de passe</label><input class="in" id="mpass" type="password" autocomplete="new-password" placeholder="${c.mot_de_passe_enregistre ? 'enregistré (vide = inchangé)' : ''}"><div class="help">Conservé chiffré sur le serveur.</div></div></div></div>
    <div class="field"><label class="f" for="murl">Adresse de l’application (liens des e-mails)</label><input class="in" id="murl" value="${esc(c.app_url)}" placeholder="détectée : ${esc(c.url_detectee)}"></div>
    <div class="row wrap"><button class="btn primary" id="bmsave">Enregistrer</button><button class="btn" id="bmsavetest">Enregistrer et m’envoyer un test</button><span class="chip ${c.pret ? 'k-bravo' : ''}" id="mstate">${c.pret ? 'prêt' : 'non configuré'}</span></div>
    <details style="margin-top:12px"><summary class="small sec" style="cursor:pointer">Veille automatique (avancé)</summary><p class="small sec" style="margin:6px 0">Boussole recalcule les alertes au plus toutes les 6 heures, dès que quelqu’un ouvre l’application. Pour être prévenu même sans l’ouvrir (découvert prévu, papier qui expire), programmez chez votre hébergeur une tâche quotidienne qui appelle cette adresse (gardez-la secrète) :</p><div class="code" style="font-size:12px;word-break:break-all;text-align:left">${esc(c.veille)}</div></details>`;
  const mode = $('#mmode', box), secure = $('#msec', box);
  mode.value = c.mode; secure.value = c.secure;
  const sync = () => { $('#msmtp', box).classList.toggle('hide', mode.value !== 'smtp'); };
  mode.addEventListener('change', sync); sync();
  $('#mpre', box).addEventListener('change', e => {
    const p = c.prereglages.find(x => x.id === e.target.value); if (!p) return;
    const v = p.valeurs;
    if (v.mode) mode.value = v.mode;
    if (v.host !== undefined) $('#mhost', box).value = v.host;
    if (v.port) $('#mport', box).value = v.port;
    if (v.secure) secure.value = v.secure;
    sync();
    if (p.id === 'gmail') toast('Gmail : identifiant = votre adresse Gmail ; mot de passe = un « mot de passe d’application » (compte Google › Sécurité).');
    if (p.id === 'brevo') toast('Brevo : identifiant et clé SMTP dans votre compte Brevo (rubrique SMTP & API).');
  });
  const doSave = async () => {
    const r = await api('mail.save', { method: 'POST', body: { mode: mode.value, from: $('#mfrom', box).value, from_name: $('#mname', box).value, host: $('#mhost', box).value, port: +$('#mport', box).value || 587, secure: secure.value, user: $('#muser', box).value, mot_de_passe: $('#mpass', box).value, app_url: $('#murl', box).value } });
    S.me.mail_pret = r.pret; S.status.mail = r.pret;
    const st = $('#mstate', box); st.textContent = r.pret ? 'prêt' : 'non configuré'; st.classList.toggle('k-bravo', r.pret);
    $('#mpass', box).value = '';
    return r;
  };
  $('#bmsave', box).addEventListener('click', async e => { const b = e.currentTarget; b.disabled = true; try { await doSave(); toast('Réglages d’envoi enregistrés.'); } catch (err) { toast(err.message, false); } b.disabled = false; });
  $('#bmsavetest', box).addEventListener('click', async e => {
    const b = e.currentTarget; b.disabled = true;
    try { await doSave(); const r = await api('mail.test', { method: 'POST' }); toast(r.message); } catch (err) { toast(err.message, false); }
    b.disabled = false;
  });
}
