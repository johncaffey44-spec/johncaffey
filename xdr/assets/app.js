/* Sentinelle XDR — interface web. Aucune dépendance externe, aucune donnée injectée en HTML brut :
   tout texte venant du réseau (noms d'appareils, flux RSS…) passe par textContent. */
'use strict';
(() => {
  /* =====================================================================
   * Outils DOM
   * ===================================================================== */
  const SVGNS = 'http://www.w3.org/2000/svg';
  function add(el, kids) {
    for (const k of kids.flat(Infinity)) {
      if (k === null || k === undefined || k === false || k === '') continue;
      el.append(k instanceof Node ? k : document.createTextNode(String(k)));
    }
    return el;
  }
  function setAttrs(el, attrs) {
    if (!attrs) return;
    for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.setAttribute('class', v);
      else if (k === 'style') for (const [p, val] of Object.entries(v)) el.style.setProperty(p.startsWith('--') ? p : p.replace(/[A-Z]/g, m => '-' + m.toLowerCase()), val);
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else if (k === 'value' && 'value' in el) el.value = v;
      else if (k === 'checked' || k === 'selected' || k === 'disabled') el[k] = !!v;
      else el.setAttribute(k, v === true ? '' : String(v));
    }
  }
  function h(tag, attrs, ...kids) {
    const el = document.createElement(tag);
    setAttrs(el, attrs);
    return add(el, kids);
  }
  function s(tag, attrs, ...kids) {
    const el = document.createElementNS(SVGNS, tag);
    setAttrs(el, attrs);
    return add(el, kids);
  }
  const put = (el, ...kids) => { el.replaceChildren(); return add(el, kids); };
  let uid = 0;
  const nextId = p => `${p}-${++uid}`;

  /* Icônes maison (tracés constants, jamais de données) */
  const ICONS = {
    dashboard: '<rect x="3" y="3" width="7.5" height="9" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="5" rx="1.6"/><rect x="13.5" y="11" width="7.5" height="10" rx="1.6"/><rect x="3" y="15" width="7.5" height="6" rx="1.6"/>',
    devices: '<rect x="2.5" y="5" width="13" height="9" rx="1.6"/><path d="M1.5 17.5h15"/><rect x="17.5" y="8" width="5" height="11" rx="1.2"/>',
    traffic: '<path d="M3 12h4l2.5-6 5 12 2.5-6H21"/>',
    shield: '<path d="M12 2.8l7.5 3v5.6c0 4.6-3.1 8.4-7.5 9.8-4.4-1.4-7.5-5.2-7.5-9.8V5.8z"/>',
    shieldok: '<path d="M12 2.8l7.5 3v5.6c0 4.6-3.1 8.4-7.5 9.8-4.4-1.4-7.5-5.2-7.5-9.8V5.8z"/><path d="M8.8 12.2l2.2 2.2 4.3-4.5"/>',
    isolate: '<path d="M12 2.8l7.5 3v5.6c0 4.6-3.1 8.4-7.5 9.8-4.4-1.4-7.5-5.2-7.5-9.8V5.8z"/><path d="M9.3 9.3l5.4 5.4M14.7 9.3l-5.4 5.4"/>',
    alert: '<path d="M12 3.5l9.5 16.5h-19z"/><path d="M12 10v4M12 17h.01"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5h.01"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    wifi: '<path d="M2.5 9a14 14 0 0 1 19 0M5.5 12.5a9.5 9.5 0 0 1 13 0M8.7 16a5 5 0 0 1 6.6 0"/><circle cx="12" cy="19" r="1"/>',
    router: '<rect x="3" y="12" width="18" height="7" rx="2"/><path d="M7 15.5h.01M10.5 15.5h.01M8 12V7M16 12V7"/><path d="M5.5 6.2a4 4 0 0 1 5 0M13.5 6.2a4 4 0 0 1 5 0"/>',
    tv: '<rect x="2.5" y="5" width="19" height="12.5" rx="2"/><path d="M8 21h8M9 2.5l3 2.5 3-2.5"/>',
    news: '<rect x="3" y="4" width="15" height="16" rx="2"/><path d="M18 8h3v10a2 2 0 0 1-2 2M7 8h7M7 12h7M7 16h4"/>',
    journal: '<path d="M8.5 6h12.5M8.5 12h12.5M8.5 18h12.5"/><circle cx="4" cy="6" r="1.2"/><circle cx="4" cy="12" r="1.2"/><circle cx="4" cy="18" r="1.2"/>',
    settings: '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
    logout: '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 8l-4 4 4 4M6 12h10"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
    moon: '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
    x: '<path d="M6 6l12 12M18 6L6 18"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    lock: '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
    unlock: '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 7.7-1.5"/>',
    ban: '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
    power: '<path d="M12 3v8M7 5.5a8 8 0 1 0 10 0"/>',
    refresh: '<path d="M20 11a8 8 0 0 0-14.5-4.5L3.5 9M4 13a8 8 0 0 0 14.5 4.5l2-2.5"/><path d="M3.5 4v5h5M20.5 20v-5h-5"/>',
    zap: '<path d="M13 2.5L4.5 13.5H12l-1 8 8.5-11H12z"/>',
    edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
    eye: '<path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    eyeoff: '<path d="M3 3l18 18M10.6 5.6A10 10 0 0 1 12 5.5c6.5 0 10 6.5 10 6.5a17 17 0 0 1-3.2 3.9M6.2 6.9A17 17 0 0 0 2 12s3.5 6.5 10 6.5a9.6 9.6 0 0 0 4.3-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
    menu: '<path d="M4 6.5h16M4 12h16M4 17.5h16"/>',
    down: '<path d="M12 4v15M6 13l6 6 6-6"/>',
    up: '<path d="M12 20V5M6 11l6-6 6 6"/>',
    external: '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    chevron: '<path d="M9 5l7 7-7 7"/>',
    trash: '<path d="M4 7h16M9 7V4.5h6V7M6.5 7l1 13h9l1-13"/>',
    calendar: '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    thermo: '<path d="M10 14.5V5a2 2 0 0 1 4 0v9.5a4 4 0 1 1-4 0z"/><path d="M12 9v7"/>',
    play: '<path d="M7 4.5v15l12-7.5z"/>',
    volume: '<path d="M4 9.5h3.5L12 5v14l-4.5-4.5H4z"/><path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11"/>',
    ethernet: '<rect x="4" y="6" width="16" height="12" rx="2"/><path d="M8 18v-4h8v4M8 10h.01M11 10h.01M14 10h.01M17 10h.01"/>',
    key: '<circle cx="7.5" cy="15.5" r="4"/><path d="M10.5 12.5L20 3M16 7l3 3M14 9l2 2"/>',
    bell: '<path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
    dns: '<circle cx="12" cy="12" r="9"/><path d="M8 9.5h8M8 14.5h8"/>',
    fire: '<path d="M12 21a7 7 0 0 0 7-7c0-4-3-6-4-9-2 2-2 4-3 5-1-1-2-2-2-4-3 2-5 5-5 8a7 7 0 0 0 7 7z"/>',
    phone: '<rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
    tablet: '<rect x="4" y="3" width="16" height="18" rx="2.5"/><path d="M11 18h2"/>',
    laptop: '<rect x="4.5" y="5" width="15" height="10" rx="1.5"/><path d="M2.5 19h19l-2-4h-15z"/>',
    desktop: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M9 20h6M12 16v4"/>',
    printer: '<path d="M7 9V3.5h10V9"/><rect x="3.5" y="9" width="17" height="8" rx="2"/><path d="M7 14h10v6.5H7z"/>',
    gamepad: '<path d="M7.5 8h9a4.5 4.5 0 0 1 4.4 5.4l-.8 4a2.5 2.5 0 0 1-4.3 1.1L14 16.5h-4l-1.8 2a2.5 2.5 0 0 1-4.3-1.1l-.8-4A4.5 4.5 0 0 1 7.5 8z"/><path d="M8 11v3M6.5 12.5h3M15.5 11.5h.01M17 13.5h.01"/>',
    nas: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h3M15.5 17h.01"/>',
    camera: '<rect x="3" y="6" width="13" height="8" rx="4"/><circle cx="9" cy="10" r="2"/><path d="M16 9l4-2v6l-4-2M9 14v3M5.5 20h7"/>',
    ipphone: '<path d="M6.5 3.5h3l1.5 4-2 1.5a11 11 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2 2A16 16 0 0 1 4.5 5.5a2 2 0 0 1 2-2z"/>',
    stb: '<rect x="2.5" y="9" width="19" height="7" rx="2"/><path d="M6 12.5h3M15 12.5h.01M18 12.5h.01M7 16v2M17 16v2"/>',
    speaker: '<rect x="6" y="2.5" width="12" height="19" rx="3"/><circle cx="12" cy="14" r="3.5"/><path d="M12 7h.01"/>',
    chip: '<rect x="7" y="7" width="10" height="10" rx="1.5"/><path d="M10 3v4M14 3v4M10 17v4M14 17v4M3 10h4M3 14h4M17 10h4M17 14h4"/>',
    car: '<path d="M5 16.5V12l2-4.5h10L19 12v4.5"/><path d="M3.5 16.5h17v2h-17z"/><path d="M7.5 13.5h.01M16.5 13.5h.01"/>',
    server: '<rect x="4" y="3.5" width="16" height="7" rx="1.5"/><rect x="4" y="13.5" width="16" height="7" rx="1.5"/><path d="M8 7h.01M8 17h.01M12 7h4M12 17h4"/>',
    unknown: '<rect x="4" y="4" width="16" height="16" rx="4"/><path d="M9.8 9.5a2.3 2.3 0 1 1 3.3 2.1c-.7.3-1.1.9-1.1 1.6v.3M12 16.5h.01"/>',
  };
  const LOGO = '<defs><linearGradient id="LGID" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2f7cff"/><stop offset=".52" stop-color="#8a4dff"/>'
    + '<stop offset="1" stop-color="#ff3b5c"/></linearGradient></defs><path d="M24 3l17 6.5v12.2c0 10.6-7.1 19.4-17 22.3C14.1 41.1 7 32.3 7 21.7V9.5z" fill="url(#LGID)"/>'
    + '<path d="M24 12.5a11 11 0 1 1-11 11" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/><circle cx="24" cy="23.5" r="3.2" fill="#fff"/>'
    + '<path d="M24 23.5l7.5-7.5" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>';
  function icon(name, cls = '') {
    const el = document.createElementNS(SVGNS, 'svg');
    el.setAttribute('viewBox', '0 0 24 24');
    el.setAttribute('class', 'ic' + (cls ? ' ' + cls : ''));
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML = ICONS[name] || ICONS.unknown;
    return el;
  }
  function logo() {
    const el = document.createElementNS(SVGNS, 'svg');
    el.setAttribute('viewBox', '0 0 48 48');
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML = LOGO.replaceAll('LGID', nextId('lg'));
    return el;
  }
  const TYPE_ICON = {
    smartphone: 'phone', tablet: 'tablet', laptop: 'laptop', workstation: 'desktop', printer: 'printer', vg_console: 'gamepad',
    television: 'tv', nas: 'nas', ip_camera: 'camera', ip_phone: 'ipphone', freebox_player: 'stb', freebox_hd: 'stb', freebox_crystal: 'stb',
    freebox_mini: 'stb', freebox_delta: 'router', freebox_one: 'router', freebox_wifi: 'wifi', freebox_pop: 'router', networking_device: 'router',
    multimedia_device: 'speaker', car: 'car', smart_home: 'chip', speaker: 'speaker', server: 'server', other: 'unknown',
  };
  function avatar(d, lg = false) {
    return h('span', { class: `avatar t-${d.type}${d.online ? '' : ' off'}${lg ? ' lg' : ''}`, title: d.type_label },
      icon(TYPE_ICON[d.type] || 'unknown'), h('span', { class: 'state', 'aria-hidden': 'true' }));
  }

  /* =====================================================================
   * Formats (français)
   * ===================================================================== */
  const nf0 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
  const nf1 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });
  const fmt = {
    rate(bps) {
      const b = (bps || 0) * 8;
      if (b >= 1e9) return nf1.format(b / 1e9) + ' Gb/s';
      if (b >= 1e6) return (b >= 1e8 ? nf0 : nf1).format(b / 1e6) + ' Mb/s';
      if (b >= 1e3) return nf0.format(b / 1e3) + ' kb/s';
      return nf0.format(b) + ' b/s';
    },
    bits(b) {
      if (b >= 1e9) return nf1.format(b / 1e9) + ' Gb/s';
      if (b >= 1e6) return nf0.format(b / 1e6) + ' Mb/s';
      return nf0.format(b / 1e3) + ' kb/s';
    },
    bytes(n) {
      const u = ['o', 'Ko', 'Mo', 'Go', 'To', 'Po'];
      let i = 0;
      n = n || 0;
      while (n >= 1000 && i < u.length - 1) { n /= 1000; i++; }
      return (i ? nf1 : nf0).format(n) + ' ' + u[i];
    },
    ago(ts) {
      if (!ts) return '—';
      const d = Date.now() / 1000 - ts;
      if (d < 60) return 'à l\'instant';
      if (d < 3600) return `il y a ${Math.floor(d / 60)} min`;
      if (d < 86400) return `il y a ${Math.floor(d / 3600)} h`;
      if (d < 172800) return 'hier';
      return 'le ' + new Date(ts * 1000).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
    },
    time: ts => new Date(ts * 1000).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }),
    datetime: ts => new Date(ts * 1000).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }),
    date: ts => new Date(ts * 1000).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' }),
    dur(sec) {
      sec = Math.max(0, Math.floor(sec || 0));
      const d = Math.floor(sec / 86400), hh = Math.floor(sec % 86400 / 3600), m = Math.floor(sec % 3600 / 60);
      return d ? `${d} j ${hh} h` : hh ? `${hh} h ${m} min` : `${m} min`;
    },
    signal(dbm) {
      if (dbm === null || dbm === undefined) return null;
      const q = dbm >= -55 ? 'excellent' : dbm >= -65 ? 'bon' : dbm >= -72 ? 'moyen' : 'faible';
      return `${dbm} dBm (${q})`;
    },
  };
  const SEV = {
    critique: ['Critique', 'crit', 'alert'], elevee: ['Élevée', 'serious', 'alert'], moyenne: ['Moyenne', 'warn', 'info'],
    faible: ['Faible', 'low', 'info'], info: ['Info', '', 'info'],
  };
  const sevChip = sev => { const [l, c, i] = SEV[sev] || SEV.info; return h('span', { class: 'chip ' + c }, icon(i), l); };
  const sevIcon = sev => h('span', { class: 'sev-ic ' + (SEV[sev] ? sev : 'info') }, icon((SEV[sev] || SEV.info)[2]));
  const MODE = { allowed: ['Internet autorisé', 'ok', 'unlock'], denied: ['Internet coupé', 'crit', 'lock'], webonly: ['Web seulement', 'warn', 'globe'] };
  const modeChip = m => { const [l, c, i] = MODE[m] || MODE.allowed; return h('span', { class: 'chip ' + c }, icon(i), l); };

  /* =====================================================================
   * API
   * ===================================================================== */
  class ApiError extends Error {
    constructor(msg, status, hint, code) { super(msg); this.status = status; this.hint = hint || ''; this.code = code || ''; }
  }
  const state = { session: null, csrf: null, view: '', timer: null, live: [], ov: null, devFilter: 'all', devSearch: '', newsTag: 'all', alertsTab: 'active', journalTab: 'events' };
  const api = {
    async req(method, action, body, params) {
      const q = new URLSearchParams({ action, ...(params || {}) });
      const opt = { method, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
      if (method === 'POST') {
        opt.headers['Content-Type'] = 'application/json';
        if (state.csrf) opt.headers['X-CSRF-Token'] = state.csrf;
        opt.body = JSON.stringify(body || {});
      }
      let res;
      try { res = await fetch('api.php?' + q.toString(), opt); } catch {
        throw new ApiError('Serveur injoignable : le PC serveur est-il allumé et sur le même réseau ?', 0);
      }
      let j = null;
      try { j = await res.json(); } catch { /* réponse non JSON */ }
      if (!j) throw new ApiError(`Réponse illisible du serveur (HTTP ${res.status}). PHP est-il bien activé dans IIS ?`, res.status);
      if (!j.ok) {
        if (res.status === 401 && j.code === 'auth' && action !== 'login') setTimeout(() => location.reload(), 1200);
        throw new ApiError(j.error || 'Erreur inconnue', res.status, j.hint, j.code);
      }
      return j.data;
    },
    get(action, params) { return this.req('GET', action, null, params); },
    post(action, body) { return this.req('POST', action, body); },
  };

  /* =====================================================================
   * Retours visuels : notifications, fenêtres, confirmations
   * ===================================================================== */
  let toastBox = null;
  function toast(title, kind = 'ok', sub = '') {
    if (!toastBox) { toastBox = h('div', { class: 'toasts', role: 'status', 'aria-live': 'polite' }); document.body.append(toastBox); }
    const el = h('div', { class: 'toast ' + kind }, icon(kind === 'ok' ? 'check' : kind === 'err' ? 'alert' : 'info'), h('div', null, h('b', null, title), sub ? h('p', null, sub) : null));
    toastBox.append(el);
    setTimeout(() => el.remove(), kind === 'err' ? 9000 : 5000);
  }
  const fail = e => toast(e.message || 'Erreur', 'err', e.hint || '');
  function errorBox(e) {
    return h('div', { class: 'err-box', role: 'alert' }, icon('alert'), h('div', null, h('b', null, e.message || String(e)), e.hint ? h('p', null, e.hint) : null));
  }
  function emptyBox(text, ic = 'info') { return h('div', { class: 'empty' }, icon(ic), h('div', null, text)); }
  function noteBox(...kids) { return h('div', { class: 'note-box' }, icon('info'), h('div', null, ...kids)); }

  function modal(content, { drawer = false, label = 'Fenêtre' } = {}) {
    const back = h('div', { class: 'backdrop' + (drawer ? ' drawer-back' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-label': label });
    const box = h('div', { class: drawer ? 'modal drawer' : 'modal' });
    add(box, [content]);
    back.append(box);
    const prev = document.activeElement;
    const close = () => { back.remove(); document.removeEventListener('keydown', onKey); if (prev && prev.focus) prev.focus(); m.onclose && m.onclose(); };
    const onKey = e => { if (e.key === 'Escape') close(); };
    back.addEventListener('mousedown', e => { if (e.target === back) close(); });
    document.addEventListener('keydown', onKey);
    document.body.append(back);
    const f = box.querySelector('input, select, textarea, button.primary, button');
    if (f) setTimeout(() => f.focus(), 30);
    const m = { close, box, back, onclose: null };
    return m;
  }
  function pwField(attrs = {}) {
    const input = h('input', { class: 'input', type: 'password', autocomplete: 'current-password', ...attrs });
    const btn = h('button', { type: 'button', class: 'btn ghost sm icon', 'aria-label': 'Afficher le mot de passe' }, icon('eye'));
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      put(btn, icon(show ? 'eyeoff' : 'eye'));
      btn.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
    });
    return { wrap: h('div', { class: 'pw' }, input, btn), input };
  }
  /** Confirmation ; renvoie null si annulé, sinon { password? }. */
  function confirmBox({ title, lead = '', body = null, ok = 'Confirmer', danger = false, password = false }) {
    return new Promise(resolve => {
      const pw = password ? pwField({ placeholder: 'Mot de passe Sentinelle' }) : null;
      let done = false;
      const okBtn = h('button', { class: 'btn ' + (danger ? 'danger' : 'primary'), type: 'submit' }, ok);
      const form = h('form', null, h('h2', null, title), lead ? h('p', { class: 'lead' }, lead) : null, body,
        pw ? h('label', { class: 'field' }, 'Confirmez avec votre mot de passe', pw.wrap) : null,
        h('div', { class: 'actions' }, h('button', { class: 'btn ghost', type: 'button', onclick: () => m.close() }, 'Annuler'), okBtn));
      form.addEventListener('submit', e => { e.preventDefault(); done = true; m.close(); resolve(pw ? { password: pw.input.value } : {}); });
      const m = modal(form, { label: title });
      m.onclose = () => { if (!done) resolve(null); };
    });
  }
  async function busy(btn, fn) {
    if (btn) btn.disabled = true;
    try { return await fn(); } finally { if (btn) btn.disabled = false; }
  }

  /* =====================================================================
   * Graphique en aires (une série par graphique ; petits multiples synchronisés)
   * ===================================================================== */
  function niceMax(v) {
    if (!(v > 0)) return 1;
    const e = Math.pow(10, Math.floor(Math.log10(v)));
    const f = v / e;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * e;
  }
  class AreaChart {
    constructor({ title, sub = '', color, height = 150, keyCls = 'down', valueFmt = v => nf1.format(v) + ' Mb/s', group = null }) {
      Object.assign(this, { title, color, height, valueFmt, group, data: [], hoverT: null });
      this.gid = nextId('g');
      this.el = h('div', { class: 'chart' });
      this.head = h('div', { class: 'title' }, h('span', { class: 'key ' + keyCls }), h('b', null, title), sub ? h('span', null, sub) : null);
      this.svg = s('svg', { role: 'img', 'aria-label': title });
      this.tip = h('div', { class: 'tooltip', 'aria-hidden': 'true' });
      this.el.append(this.head, this.svg, this.tip);
      this.ro = new ResizeObserver(() => this.render());
      this.ro.observe(this.el);
      this.svg.addEventListener('pointermove', e => this.onMove(e));
      this.svg.addEventListener('pointerleave', () => (this.group ? this.group.hover(null) : this.showAt(null)));
      if (group) group.add(this);
    }
    set(data) { this.data = data; this.render(); }
    geom() {
      const W = Math.max(260, this.el.clientWidth || 600), H = this.height, pad = { l: 44, r: 78, t: 12, b: 24 };
      const t0 = this.data.length ? this.data[0].t : Date.now() / 1000 - 600;
      const t1 = this.data.length ? Math.max(this.data[this.data.length - 1].t, t0 + 1) : Date.now() / 1000;
      const max = niceMax(Math.max(...this.data.map(d => d.v), 0.001) * 1.1);
      return { W, H, pad, t0, t1, max, x: t => pad.l + (t - t0) / (t1 - t0) * (W - pad.l - pad.r), y: v => pad.t + (1 - v / max) * (H - pad.t - pad.b) };
    }
    render() {
      const g = this.geom();
      this.g = g;
      const { W, H, pad } = g;
      this.svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
      this.svg.setAttribute('height', H);
      const kids = [];
      const defs = s('defs', null, s('linearGradient', { id: this.gid, x1: 0, y1: 0, x2: 0, y2: 1 },
        s('stop', { offset: '0', 'stop-color': this.color, 'stop-opacity': '.28' }), s('stop', { offset: '1', 'stop-color': this.color, 'stop-opacity': '.02' })));
      kids.push(defs);
      for (let i = 0; i <= 2; i++) {
        const v = g.max * i / 2, y = g.y(v);
        kids.push(s('line', { class: i ? 'grid-line' : 'axis-line', x1: pad.l, x2: W - pad.r, y1: y, y2: y }));
        kids.push(s('text', { class: 'tick', x: pad.l - 8, y: y + 4, 'text-anchor': 'end' }, nf1.format(v)));
      }
      const span = g.t1 - g.t0;
      const step = span > 4 * 86400 ? 86400 : span > 30000 ? 4 * 3600 : span > 7200 ? 1800 : span > 1500 ? 600 : 120;
      for (let t = Math.ceil(g.t0 / step) * step; t <= g.t1; t += step) {
        const label = step >= 86400 ? new Date(t * 1000).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' }) : fmt.time(t);
        kids.push(s('text', { class: 'tick', x: g.x(t), y: H - 6, 'text-anchor': 'middle' }, label));
      }
      if (this.data.length > 1) {
        const pts = this.data.map(d => [g.x(d.t), g.y(d.v)]);
        const line = 'M' + pts.map(p => p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join('L');
        const base = g.y(0).toFixed(1);
        kids.push(s('path', { d: `${line}L${pts[pts.length - 1][0].toFixed(1)} ${base}L${pts[0][0].toFixed(1)} ${base}Z`, fill: `url(#${this.gid})` }));
        kids.push(s('path', { d: line, fill: 'none', stroke: this.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
        const last = this.data[this.data.length - 1], lp = pts[pts.length - 1];
        kids.push(s('circle', { cx: lp[0], cy: lp[1], r: 4.5, fill: this.color, stroke: 'var(--panel-solid)', 'stroke-width': 2 }));
        kids.push(s('text', { class: 'end-label', x: lp[0] + 10, y: Math.min(Math.max(lp[1] + 4, 14), H - pad.b) }, this.valueFmt(last.v)));
      }
      this.cross = s('g', { visibility: 'hidden' }, s('line', { class: 'cross', y1: pad.t, y2: H - pad.b }),
        s('circle', { r: 5, fill: this.color, stroke: 'var(--panel-solid)', 'stroke-width': 2 }));
      kids.push(this.cross);
      kids.push(s('rect', { x: pad.l, y: 0, width: Math.max(0, W - pad.l - pad.r), height: H, fill: 'transparent' }));
      put(this.svg, kids);
      if (this.hoverT !== null) this.showAt(this.hoverT);
    }
    onMove(e) {
      if (!this.g || !this.data.length) return;
      const r = this.svg.getBoundingClientRect();
      const px = (e.clientX - r.left) * (this.g.W / r.width);
      const t = this.g.t0 + (px - this.g.pad.l) / (this.g.W - this.g.pad.l - this.g.pad.r) * (this.g.t1 - this.g.t0);
      this.group ? this.group.hover(t) : this.showAt(t);
    }
    showAt(t) {
      this.hoverT = t;
      if (t === null || !this.data.length || !this.g) { if (this.cross) this.cross.setAttribute('visibility', 'hidden'); this.tip.classList.remove('on'); return; }
      let best = this.data[0];
      for (const d of this.data) if (Math.abs(d.t - t) < Math.abs(best.t - t)) best = d;
      const x = this.g.x(best.t), y = this.g.y(best.v);
      const [line, dot] = this.cross.children;
      line.setAttribute('x1', x); line.setAttribute('x2', x);
      dot.setAttribute('cx', x); dot.setAttribute('cy', y);
      this.cross.setAttribute('visibility', 'visible');
      put(this.tip, h('div', { class: 't' }, best.t > Date.now() / 1000 - 86400 ? fmt.time(best.t) : fmt.datetime(best.t)),
        h('div', { class: 'row' }, h('span', { class: 'key', style: { background: this.color } }), h('b', null, this.valueFmt(best.v)), h('span', null, this.title)));
      const r = this.svg.getBoundingClientRect();
      const left = x * (r.width / this.g.W);
      this.tip.style.left = Math.min(Math.max(left + 14, 0), r.width - 170) + 'px';
      this.tip.style.top = '28px';
      this.tip.classList.add('on');
    }
  }
  class ChartGroup {
    constructor() { this.charts = []; }
    add(c) { this.charts.push(c); }
    hover(t) { for (const c of this.charts) c.showAt(t); }
  }
  const toMbps = bps => (bps || 0) * 8 / 1e6;

  function scoreRing(score) {
    const id = nextId('ring');
    const r = 62, c = 2 * Math.PI * r;
    const svg = s('svg', { viewBox: '0 0 148 148', 'aria-hidden': 'true' },
      s('defs', null, s('linearGradient', { id, x1: 0, y1: 0, x2: 1, y2: 1 }, s('stop', { offset: '0', 'stop-color': '#2f7cff' }),
        s('stop', { offset: '.55', 'stop-color': '#8a4dff' }), s('stop', { offset: '1', 'stop-color': '#ff3b5c' }))),
      s('circle', { cx: 74, cy: 74, r, fill: 'none', stroke: 'var(--line)', 'stroke-width': 12 }),
      s('circle', { cx: 74, cy: 74, r, fill: 'none', stroke: `url(#${id})`, 'stroke-width': 12, 'stroke-linecap': 'round',
        'stroke-dasharray': `${(c * Math.max(0.02, score.value / 100)).toFixed(1)} ${c.toFixed(1)}` }));
    return h('div', { class: 'ring', role: 'img', 'aria-label': `Score de sécurité ${score.value} sur 100` }, svg,
      h('div', { class: 'num' }, h('b', null, String(score.value)), h('span', null, 'sur 100')));
  }
  function scoreCard(score, extra = null) {
    return h('div', { class: 'card glow score' }, scoreRing(score),
      h('div', null, h('div', { class: 'faint', style: { fontSize: '12px', letterSpacing: '1.2px', textTransform: 'uppercase' } }, 'Score de sécurité'),
        h('h3', null, h('span', { class: 'grade', 'aria-label': 'Note' }, score.grade), ' ', score.text),
        h('div', { class: 'sevs' }, ['critique', 'elevee', 'moyenne', 'faible'].filter(k => score.counts[k]).map(k => {
          const [l, c, i] = SEV[k];
          return h('span', { class: 'chip ' + c }, icon(i), `${score.counts[k]} ${l.toLowerCase()}${score.counts[k] > 1 ? 's' : ''}`);
        }), Object.values(score.counts).every(v => !v) ? h('span', { class: 'chip ok' }, icon('check'), 'Aucune alerte ouverte') : null),
        extra));
  }

  /* =====================================================================
   * Démarrage, connexion, coquille
   * ===================================================================== */
  const NAV = [
    ['dashboard', 'Tableau de bord', 'dashboard', 'Vue d\'ensemble en direct de votre réseau'],
    ['devices', 'Appareils', 'devices', 'Tout ce qui est connecté à votre Freebox'],
    ['traffic', 'Trafic', 'traffic', 'Débits en direct et historique'],
    ['threats', 'Menaces', 'shield', 'Alertes, explications et corrections'],
    ['access', 'Contrôle d\'accès', 'clock', 'Couper Internet par profil et horaires'],
    ['exposure', 'Exposition', 'globe', 'Ce qui est ouvert sur Internet'],
    ['wifi', 'Wi-Fi', 'wifi', 'Réseaux, chiffrement et filtrage'],
    ['box', 'Ma Freebox', 'router', 'Configuration, connexion et mises à jour'],
    ['player', 'Player TV', 'tv', 'Boîtier TV : état et mises à jour'],
    ['news', 'Actualités Free', 'news', 'Les dernières nouvelles Free et Freebox'],
    ['journal', 'Journal', 'journal', 'Historique du réseau et des actions'],
    ['settings', 'Paramètres', 'settings', 'Freebox, sécurité, détection, notifications'],
  ];
  const ui = {};
  const theme = {
    get() { try { return localStorage.getItem('sentinelle-theme'); } catch { return null; } },
    set(t) { try { localStorage.setItem('sentinelle-theme', t); } catch { /* stockage indisponible */ } },
  };
  function applyTheme(t) {
    const v = t === 'light' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', v);
    return v;
  }

  async function boot() {
    applyTheme(theme.get());
    const root = document.getElementById('app');
    try {
      state.session = await api.get('session');
    } catch (e) {
      put(root, h('div', { class: 'auth' }, h('div', { class: 'card' }, errorBox(e))));
      return;
    }
    state.csrf = state.session.csrf;
    if (!state.session.authenticated) return renderAuth(root);
    renderShell(root);
    window.addEventListener('hashchange', route);
    route();
  }

  function renderAuth(root) {
    const ss = state.session;
    const setup = ss.needs_setup;
    const pw = pwField({ required: true, autocomplete: setup ? 'new-password' : 'current-password', minlength: setup ? 10 : null, placeholder: setup ? '10 caractères minimum' : '' });
    const pw2 = setup ? pwField({ required: true, autocomplete: 'new-password', placeholder: 'Retapez-le' }) : null;
    const code = h('input', { class: 'input', inputmode: 'numeric', autocomplete: 'one-time-code', placeholder: '123 456 ou code de secours', maxlength: 11 });
    const msg = h('div');
    const btn = h('button', { class: 'btn primary', type: 'submit' }, setup ? 'Créer le mot de passe et entrer' : 'Se connecter');
    const form = h('form', null,
      h('label', { class: 'field' }, setup ? 'Choisissez le mot de passe administrateur' : 'Mot de passe', pw.wrap),
      pw2 ? h('label', { class: 'field' }, 'Confirmation', pw2.wrap) : null,
      !setup && ss.totp ? h('label', { class: 'field' }, 'Code de l\'application d\'authentification', code) : null,
      msg, btn);
    form.addEventListener('submit', async e => {
      e.preventDefault();
      put(msg);
      if (setup && pw.input.value !== pw2.input.value) { put(msg, errorBox({ message: 'Les deux saisies ne correspondent pas.' })); return; }
      await busy(btn, async () => {
        try {
          const r = await api.post(setup ? 'setup' : 'login', { password: pw.input.value, code: code.value });
          state.csrf = r.csrf;
          location.reload();
        } catch (err) { put(msg, errorBox(err)); }
      });
    });
    const blocked = setup && !ss.setup_allowed;
    put(root, h('div', { class: 'auth' }, h('div', { class: 'card glow' },
      h('div', { class: 'brand' }, logo()),
      h('h1', null, h('span', { class: 'grad-text' }, 'Sentinelle'), ' XDR'),
      h('p', { class: 'lead' }, setup ? 'Mise en service : protégez la console de votre réseau.' : 'Console de sécurité de la maison'),
      blocked ? errorBox({ message: 'Mise en service à faire depuis le serveur lui-même.', hint: 'Ouvrez http://localhost/xdr/ directement sur le PC serveur : personne d\'autre sur le réseau ne peut ainsi choisir le mot de passe à votre place.' }) : form,
      h('div', { class: 'foot' }, ss.mode === 'demo' ? 'Mode démonstration : données simulées jusqu\'à l\'association de votre Freebox.' : 'Freebox associée', ' · v', ss.version))));
    setTimeout(() => pw.input.focus(), 50);
  }

  function renderShell(root) {
    ui.nav = h('nav', { class: 'nav', 'aria-label': 'Navigation principale' });
    for (const [id, label, ic] of NAV) {
      const b = h('button', { type: 'button', 'data-view': id, onclick: () => { location.hash = '#/' + id; ui.side.classList.remove('open'); } }, icon(ic), h('span', null, label));
      if (id === 'threats') { ui.threatCount = h('span', { class: 'count', hidden: true }); b.append(ui.threatCount); }
      ui.nav.append(b);
    }
    ui.modeInfo = h('div');
    ui.side = h('aside', { class: 'side' },
      h('div', { class: 'brand' }, logo(), h('div', null, h('b', null, h('span', { class: 'grad-text' }, 'Sentinelle')), h('span', null, 'XDR maison'))),
      ui.nav,
      h('div', { class: 'side-foot' }, ui.modeInfo, h('div', null, 'Version ', state.session.version)));
    ui.title = h('h1');
    ui.sub = h('div', { class: 'sub' });
    ui.live = h('span', { class: 'live off' }, h('i'), h('span', null, 'Connexion…'));
    const themeBtn = h('button', { class: 'btn icon', type: 'button', 'aria-label': 'Changer de thème' });
    const paintTheme = () => put(themeBtn, icon(document.documentElement.getAttribute('data-theme') === 'light' ? 'moon' : 'sun'));
    themeBtn.addEventListener('click', () => {
      const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      theme.set(next); applyTheme(next); paintTheme();
      if (state.charts) for (const c of state.charts) c.render();
    });
    paintTheme();
    const logoutBtn = h('button', { class: 'btn icon', type: 'button', 'aria-label': 'Se déconnecter', title: 'Se déconnecter' }, icon('logout'));
    logoutBtn.addEventListener('click', async () => { try { await api.post('logout'); } catch { /* déjà déconnecté */ } location.reload(); });
    ui.banner = h('div');
    ui.view = h('section', { id: 'view', 'aria-live': 'polite' });
    put(root, h('div', { class: 'shell' }, ui.side,
      h('main', { class: 'main' },
        h('header', { class: 'top' },
          h('button', { class: 'btn icon menu-btn', type: 'button', 'aria-label': 'Menu', onclick: () => ui.side.classList.toggle('open') }, icon('menu')),
          h('div', { class: 'grow' }, ui.title, ui.sub),
          h('div', { class: 'tools' }, ui.live, themeBtn, logoutBtn)),
        ui.banner, ui.view)));
    paintMode();
  }
  function paintMode() {
    const demo = state.session.mode === 'demo';
    put(ui.modeInfo, h('span', { class: 'chip ' + (demo ? 'warn' : 'ok') }, icon(demo ? 'info' : 'check'), demo ? 'Mode démonstration' : 'Freebox associée'));
    put(ui.banner, demo ? h('div', { class: 'banner' }, icon('info'),
      h('p', null, h('b', null, 'Mode démonstration. '), 'Les appareils et le trafic sont simulés (dont un incident fictif à traiter). Associez votre Freebox pour voir votre vrai réseau.'),
      h('a', { class: 'btn primary sm', href: '#/settings' }, icon('router'), 'Associer ma Freebox')) : null);
  }
  function setLive(kind, text) {
    if (!ui.live) return;
    ui.live.className = 'live' + (kind === 'on' ? '' : kind === 'err' ? ' err' : ' off');
    put(ui.live, h('i'), h('span', null, text || (kind === 'on' ? 'En direct' : 'En pause')));
  }
  function setThreatCount(n) {
    if (!ui.threatCount) return;
    ui.threatCount.hidden = !n;
    ui.threatCount.textContent = n > 99 ? '99+' : String(n);
  }

  function route() {
    const id = (location.hash.match(/^#\/([a-z]+)/) || [])[1] || 'dashboard';
    const nav = NAV.find(n => n[0] === id) || NAV[0];
    clearInterval(state.timer);
    state.timer = null;
    state.charts = [];
    state.view = nav[0];
    for (const b of ui.nav.children) { if (b.dataset.view === nav[0]) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current'); }
    put(ui.title, nav[1]);
    put(ui.sub, nav[3]);
    document.title = nav[1] + ' · Sentinelle XDR';
    put(ui.view, h('div', { class: 'grid g-2' }, h('div', { class: 'skeleton' }), h('div', { class: 'skeleton' })));
    setLive('off');
    const fn = VIEWS[nav[0]];
    Promise.resolve(fn(ui.view)).catch(e => put(ui.view, errorBox(e)));
  }
  function poll(fn, seconds) {
    clearInterval(state.timer);
    const view = state.view;
    state.timer = setInterval(() => { if (!document.hidden && state.view === view) fn(); }, Math.max(3, seconds || state.session.poll || 5) * 1000);
  }

  /* =====================================================================
   * Actions sur les appareils (communes à plusieurs écrans)
   * ===================================================================== */
  async function blockFlow(dev, after) {
    const opts = [[30, '30 minutes'], [60, '1 heure'], [120, '2 heures'], [480, '8 heures'], [0, 'Jusqu\'à nouvel ordre']];
    const choice = await new Promise(resolve => {
      let done = false;
      const m = modal(h('div', null, h('h2', null, 'Couper Internet'),
        h('p', { class: 'lead' }, `« ${dev.name} » ne pourra plus aller sur Internet (il reste visible sur le réseau local). La coupure est appliquée par la Freebox elle-même.`),
        h('div', { class: 'btns' }, opts.map(([min, label]) => h('button', { class: 'btn' + (min === 0 ? ' danger' : ''), type: 'button', onclick: () => { done = true; m.close(); resolve(min); } }, icon(min ? 'clock' : 'lock'), label))),
        h('div', { class: 'actions' }, h('button', { class: 'btn ghost', type: 'button', onclick: () => m.close() }, 'Annuler'))), { label: 'Couper Internet' });
      m.onclose = () => { if (!done) resolve(null); };
    });
    if (choice === null) return;
    try {
      let r = await api.post('device_block', { mac: dev.mac, minutes: choice });
      if (r.needs_choice) {
        const scope = await new Promise(resolve => {
          let done = false;
          const m = modal(h('div', null, h('h2', null, 'Profil partagé'),
            h('p', { class: 'lead' }, `« ${dev.name} » fait partie du profil Freebox « ${r.profile} » (${r.count} appareils). La Freebox coupe Internet par profil.`),
            h('div', { class: 'stack' },
              h('button', { class: 'btn', type: 'button', onclick: () => { done = true; m.close(); resolve('dedicated'); } }, icon('devices'), 'Seulement cet appareil (profil dédié créé sur la Freebox)'),
              h('button', { class: 'btn danger', type: 'button', onclick: () => { done = true; m.close(); resolve('profile'); } }, icon('lock'), `Tout le profil « ${r.profile} »`)),
            h('div', { class: 'actions' }, h('button', { class: 'btn ghost', type: 'button', onclick: () => m.close() }, 'Annuler'))), { label: 'Profil partagé' });
          m.onclose = () => { if (!done) resolve(null); };
        });
        if (!scope) return;
        r = await api.post('device_block', { mac: dev.mac, minutes: choice, scope });
      }
      toast('Internet coupé', 'ok', `${dev.name}${r.until ? ' jusqu\'à ' + fmt.time(r.until) : ''} — profil « ${r.profile} »`);
      after && after();
    } catch (e) { fail(e); }
  }
  async function allowFlow(dev, after) {
    try {
      const r = await api.post('device_allow', { mac: dev.mac });
      if (r.still_denied) {
        const ok = await confirmBox({ title: 'Toujours coupé par le planning', ok: 'Autoriser exceptionnellement',
          lead: `Le planning de la Freebox coupe encore le profil « ${r.profile} »${r.next_change ? ' jusqu\'à ' + fmt.time(r.next_change) : ''}. Autoriser Internet malgré tout jusque-là ?` });
        if (ok) { await api.post('device_allow', { mac: dev.mac, force: true }); toast('Accès autorisé exceptionnellement', 'ok', dev.name); }
      } else toast(r.note ? 'Rien à rétablir' : 'Internet rétabli', 'ok', r.note || dev.name);
      after && after();
    } catch (e) { fail(e); }
  }
  async function isolateFlow(dev, after) {
    const ok = await confirmBox({ title: `Isoler « ${dev.name} » ?`, ok: 'Isoler maintenant', danger: true,
      lead: 'À utiliser si vous pensez que cet appareil est piraté ou inconnu. Sentinelle va :',
      body: h('ul', { class: 'muted', style: { margin: '0', paddingLeft: '20px', fontSize: '14px', lineHeight: '1.7' } },
        h('li', null, 'lui couper Internet (profil dédié sur la Freebox) ;'),
        h('li', null, 'le refuser sur le Wi-Fi (liste noire de la box) ;'),
        h('li', null, 'lui refuser le DNS si AdGuard Home est configuré.'),
        h('li', null, 'Limite : un appareil branché en Ethernet reste joignable par la maison. Débranchez son câble.')) });
    if (!ok) return;
    try {
      const r = await api.post('device_isolate', { mac: dev.mac });
      const rows = [['Internet', r.report.internet], ['Wi-Fi', r.report.wifi], ['DNS', r.report.dns], ['Réseau local', r.report.lan]];
      const m = modal(h('div', null, h('h2', null, 'Isolement appliqué'), h('p', { class: 'lead' }, dev.name),
        h('div', { class: 'stack' }, rows.map(([label, x]) => x ? h('div', { class: 'note-box' }, icon(x.ok === true ? 'check' : x.ok === false ? 'alert' : 'info'),
          h('div', null, h('b', null, label + ' : '), x.detail)) : null)),
        h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'button', onclick: () => m.close() }, 'Compris'))), { label: 'Isolement' });
      after && after();
    } catch (e) { fail(e); }
  }
  async function releaseFlow(dev, after) {
    try { const r = await api.post('device_release', { mac: dev.mac }); toast('Fin d\'isolement', 'ok', r.done.join(' · ')); after && after(); } catch (e) { fail(e); }
  }
  async function approveFlow(dev, approved, after) {
    try { await api.post('device_approve', { mac: dev.mac, approved }); toast(approved ? 'Appareil reconnu' : 'Marqué comme inconnu', 'ok', dev.name); after && after(); } catch (e) { fail(e); }
  }
  async function runAction(act, after) {
    if (act.type === 'fix') {
      const ok = await confirmBox({ title: act.label, lead: 'Sentinelle va appliquer ce réglage sur votre Freebox, puis vérifier qu\'il a bien été pris en compte.', ok: 'Appliquer' });
      if (!ok) return;
      try { await api.post('fix', { fix: act.fix, id: act.id ?? '' }); toast('Correction appliquée', 'ok', act.label); after && after(); } catch (e) { fail(e); }
      return;
    }
    const dev = { mac: act.mac, name: act.name || act.mac };
    if (act.act === 'approve') return approveFlow(dev, true, after);
    if (act.act === 'isolate') return isolateFlow(dev, after);
    if (act.act === 'block') return blockFlow(dev, after);
    if (act.act === 'release') return releaseFlow(dev, after);
  }

  /* ---------- carte d'appareil ---------- */
  function connText(d) {
    const c = d.conn || {};
    if (c.kind === 'wifi') return h('span', null, icon('wifi'), `Wi-Fi ${c.band || ''}`, c.signal != null ? ` · ${c.signal} dBm` : '');
    if (c.kind === 'ethernet') return h('span', null, icon('ethernet'), `Ethernet ${c.port_name || ''}`, c.shared ? ' (partagé)' : '');
    return h('span', null, icon('info'), d.online ? 'Connexion inconnue' : 'Hors ligne');
  }
  function devFlags(d) {
    const f = d.flags;
    return [
      f.isolated ? h('span', { class: 'chip crit' }, icon('isolate'), 'Isolé') : null,
      !f.isolated && f.blocked ? h('span', { class: 'chip crit' }, icon('lock'), 'Coupé') : null,
      f.new ? h('span', { class: 'chip warn' }, icon('alert'), 'Nouveau') : null,
      f.guest ? h('span', { class: 'chip low' }, icon('wifi'), 'Invité') : null,
      d.schedules && d.schedules.length ? h('span', { class: 'chip low' }, icon('clock'), 'Horaires') : null,
    ];
  }
  function deviceCard(d, maxRate, refresh) {
    const pct = v => Math.min(100, maxRate ? (v / maxRate) * 100 : 0).toFixed(1) + '%';
    const alarm = d.flags.isolated || (state.ov && state.ov.alerts.some(a => a.mac === d.mac && (a.severity === 'critique' || a.severity === 'elevee')));
    const stop = fn => e => { e.stopPropagation(); fn(); };
    const card = h('article', { class: 'dev' + (d.online ? '' : ' off') + (alarm ? ' alarm' : ''), tabindex: 0, role: 'button', 'aria-label': `Détails de ${d.name}` },
      h('div', { class: 'dev-head' }, avatar(d),
        h('div', { class: 'dev-name' }, h('b', null, d.name), h('span', null, d.type_label + (d.vendor ? ' · ' + d.vendor : ''))),
        d.online ? h('span', { class: 'chip ok' }, h('span', { class: 'dot' }), 'En ligne') : h('span', { class: 'chip' }, h('span', { class: 'dot' }), fmt.ago(d.last_activity))),
      h('div', { class: 'dev-meta' }, d.ipv4 ? h('span', { class: 'mono' }, d.ipv4) : null, connText(d)),
      d.online ? h('div', { class: 'rates' },
        h('div', { class: 'rate down', title: 'Réception (vers l\'appareil)' }, icon('down'), h('div', { class: 'bar' }, h('i', { style: { width: pct(d.rate.down) } })), h('b', null, fmt.rate(d.rate.down))),
        h('div', { class: 'rate up', title: 'Envoi (depuis l\'appareil)' }, icon('up'), h('div', { class: 'bar' }, h('i', { style: { width: pct(d.rate.up) } })), h('b', null, fmt.rate(d.rate.up)))) : null,
      d.activity && (d.activity.services.length || d.activity.domains.length)
        ? h('div', { class: 'activity' }, icon('eye'), h('span', null, 'En ce moment : ', d.activity.services.length ? d.activity.services.join(', ') : d.activity.domains.slice(0, 2).join(', ')))
        : null,
      h('div', { class: 'chips' }, devFlags(d)),
      h('div', { class: 'dev-actions' },
        d.flags.isolated ? h('button', { class: 'btn sm', type: 'button', onclick: stop(() => releaseFlow(d, refresh)) }, icon('unlock'), 'Rétablir')
          : d.flags.blocked ? h('button', { class: 'btn sm', type: 'button', onclick: stop(() => allowFlow(d, refresh)) }, icon('unlock'), 'Rétablir')
            : h('button', { class: 'btn sm', type: 'button', onclick: stop(() => blockFlow(d, refresh)) }, icon('lock'), 'Couper'),
        !d.flags.isolated ? h('button', { class: 'btn sm danger', type: 'button', onclick: stop(() => isolateFlow(d, refresh)) }, icon('isolate'), 'Isoler') : null,
        d.flags.new ? h('button', { class: 'btn sm', type: 'button', onclick: stop(() => approveFlow(d, true, refresh)) }, icon('check'), 'C\'est à moi') : null));
    card.addEventListener('click', () => openDevice(d.mac, refresh));
    card.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && e.target === card) { e.preventDefault(); openDevice(d.mac, refresh); } });
    return card;
  }
  function filterDevices(list) {
    const q = state.devSearch.trim().toLowerCase();
    return list.filter(d => {
      const f = state.devFilter;
      if (f === 'online' && !d.online) return false;
      if (f === 'wifi' && (d.conn.kind !== 'wifi' || !d.online)) return false;
      if (f === 'ethernet' && (d.conn.kind !== 'ethernet' || !d.online)) return false;
      if (f === 'new' && !d.flags.new) return false;
      if (f === 'blocked' && !(d.flags.blocked || d.flags.isolated)) return false;
      if (q && ![d.name, d.vendor, d.ipv4, d.mac, d.type_label].join(' ').toLowerCase().includes(q)) return false;
      return true;
    });
  }
  function deviceFilters(onChange, counts) {
    const opts = [['all', 'Tous'], ['online', 'En ligne'], ['wifi', 'Wi-Fi'], ['ethernet', 'Ethernet'], ['new', 'Nouveaux'], ['blocked', 'Coupés / isolés']];
    const seg = h('div', { class: 'seg', role: 'group', 'aria-label': 'Filtrer les appareils' });
    for (const [k, label] of opts) {
      const b = h('button', { type: 'button', 'aria-pressed': String(state.devFilter === k) }, label, counts && counts[k] !== undefined ? ` (${counts[k]})` : '');
      b.addEventListener('click', () => { state.devFilter = k; for (const x of seg.children) x.setAttribute('aria-pressed', String(x === b)); onChange(); });
      seg.append(b);
    }
    const input = h('input', { class: 'input', type: 'search', placeholder: 'Rechercher (nom, IP, MAC, marque)…', value: state.devSearch, 'aria-label': 'Rechercher un appareil' });
    input.addEventListener('input', () => { state.devSearch = input.value; onChange(); });
    return h('div', { class: 'filters' }, seg, h('div', { class: 'search' }, icon('search'), input));
  }
  function devCounts(list) {
    return {
      all: list.length, online: list.filter(d => d.online).length, wifi: list.filter(d => d.online && d.conn.kind === 'wifi').length,
      ethernet: list.filter(d => d.online && d.conn.kind === 'ethernet').length, new: list.filter(d => d.flags.new).length,
      blocked: list.filter(d => d.flags.blocked || d.flags.isolated).length,
    };
  }

  /* ---------- fiche détaillée d'un appareil ---------- */
  async function openDevice(mac, refreshParent) {
    const body = h('div', null, h('div', { class: 'skeleton' }));
    const m = modal(body, { drawer: true, label: 'Fiche appareil' });
    let tab = 'conn';
    const load = async () => {
      let d;
      try { d = await api.get('device', { mac }); } catch (e) { put(body, errorBox(e), h('div', { class: 'actions' }, h('button', { class: 'btn', onclick: () => m.close() }, 'Fermer'))); return; }
      const reload = () => { load(); refreshParent && refreshParent(); };
      const c = d.conn || {};
      const tabs = [['conn', 'Connexion'], ['dns', 'Activité'], ['hist', 'Historique'], ['alerts', `Alertes${d.alerts.length ? ' (' + d.alerts.length + ')' : ''}`]];
      const tabBar = h('div', { class: 'tabs', role: 'tablist' });
      const panel = h('div', { role: 'tabpanel' });
      const show = id => {
        tab = id;
        for (const b of tabBar.children) b.setAttribute('aria-selected', String(b.dataset.id === id));
        if (id === 'conn') put(panel, h('dl', { class: 'kv' },
          h('dt', null, 'Adresse IPv4'), h('dd', { class: 'mono' }, d.ipv4_all.join(', ') || '—'),
          h('dt', null, 'Adresses IPv6'), h('dd', { class: 'mono' }, d.ipv6.join(', ') || '—'),
          h('dt', null, 'Adresse MAC'), h('dd', null, h('span', { class: 'mono' }, d.mac), d.flags.random_mac ? h('span', { class: 'faint' }, ' · adresse privée (aléatoire)') : null),
          h('dt', null, 'Fabricant'), h('dd', null, d.vendor || 'Inconnu'),
          h('dt', null, 'Type'), h('dd', null, d.type_label),
          h('dt', null, 'Connexion'), h('dd', null, c.kind === 'wifi' ? `Wi-Fi ${c.band || ''} (${c.ap || 'point d\'accès'})` : c.kind === 'ethernet' ? `Ethernet, ${c.port_name || 'port ' + c.port}${c.speed ? ' à ' + c.speed + ' Mb/s' : ''}${c.shared ? ' — port partagé avec ' + c.neighbours + ' autre(s) appareil(s) : débits cumulés' : ''}` : (d.online ? 'Inconnue' : 'Hors ligne')),
          c.kind === 'wifi' ? [h('dt', null, 'Signal'), h('dd', null, fmt.signal(c.signal) || '—'),
            h('dt', null, 'Débit radio'), h('dd', null, c.phy_down ? `↓ ${c.phy_down} Mb/s · ↑ ${c.phy_up} Mb/s` : '—'),
            h('dt', null, 'Connecté depuis'), h('dd', null, c.since ? fmt.dur(c.since) : '—')] : null,
          h('dt', null, 'Débit actuel'), h('dd', null, d.online ? `↓ ${fmt.rate(d.rate.down)} · ↑ ${fmt.rate(d.rate.up)}` : '—'),
          h('dt', null, 'Profil Freebox'), h('dd', null, d.profile ? h('span', null, `« ${d.profile.name} » `, modeChip(d.profile.mode),
            d.profile.override && d.profile.until ? h('span', { class: 'faint' }, ' jusqu\'à ' + fmt.time(d.profile.until)) : null) : 'Aucun (accès libre)'),
          h('dt', null, 'Noms annoncés'), h('dd', null, d.names.map(n => `${n.name} (${n.source})`).join(', ') || '—'),
          h('dt', null, 'Vu pour la 1re fois'), h('dd', null, d.first_seen ? fmt.datetime(d.first_seen) : '—'),
          h('dt', null, 'Dernière activité'), h('dd', null, d.online ? 'maintenant' : fmt.ago(d.last_activity))));
        if (id === 'dns') {
          if (!d.dns_enabled) put(panel, noteBox(h('b', null, 'Activité de navigation indisponible. '), 'La Freebox ne fournit pas l\'historique des sites. Installez AdGuard Home (gratuit, sous Windows) puis renseignez-le dans Paramètres › DNS : vous verrez les noms de domaine demandés par chaque appareil.'));
          else if (d.dns_error) put(panel, errorBox({ message: d.dns_error }));
          else {
            const svc = {};
            for (const q of d.dns) if (q.service) svc[q.service] = (svc[q.service] || 0) + 1;
            const top = Object.entries(svc).sort((a, b) => b[1] - a[1]).slice(0, 8);
            put(panel, h('div', { class: 'stack' },
              noteBox('Noms de domaine demandés par cet appareil (pas le contenu des pages, chiffré en HTTPS). Informez les personnes concernées : surveiller à son insu la navigation d\'un adulte, conjoint compris, peut constituer un délit (atteinte à la vie privée, secret des correspondances : art. 226-1 et 226-15 du Code pénal).'),
              top.length ? h('div', { class: 'chips' }, top.map(([n, c]) => h('span', { class: 'chip grad' }, `${n} · ${c}`))) : null,
              d.dns.length ? h('div', { class: 'dns-list' }, d.dns.map(q => h('div', { class: 'dns-row' + (q.blocked ? ' blocked' : '') },
                h('span', { class: 'faint tnum' }, fmt.time(q.t)), h('span', { class: 'd mono' }, q.domain),
                q.blocked ? h('span', { class: 'chip serious' }, icon('ban'), q.reason === 'FilteredSafeBrowsing' ? 'Malveillant' : 'Bloqué')
                  : q.status === 'NXDOMAIN' ? h('span', { class: 'chip warn' }, 'Inexistant') : q.service ? h('span', { class: 'chip' }, q.service) : h('span')))) : emptyBox('Aucune requête récente.')));
          }
        }
        if (id === 'hist') {
          const group = new ChartGroup();
          const cd = new AreaChart({ title: 'Réception', color: 'var(--series-down)', keyCls: 'down', height: 120, group, sub: 'Mb/s, 6 dernières heures' });
          const cu = new AreaChart({ title: 'Envoi', color: 'var(--series-up)', keyCls: 'up', height: 120, group, sub: 'Mb/s' });
          state.charts.push(cd, cu);
          put(panel, h('div', { class: 'stack' },
            d.history.length > 1 ? h('div', { class: 'stack' }, cd.el, cu.el) : noteBox('L\'historique se construit avec la collecte (une mesure par minute quand l\'appareil est actif).'),
            h('h3', null, icon('journal'), 'Événements'),
            d.events.length ? h('div', { class: 'table-wrap' }, h('table', null, h('tbody', null, d.events.map(ev => h('tr', null, h('td', { class: 'faint nowrap' }, fmt.datetime(ev.ts)), h('td', null, ev.text)))))) : emptyBox('Aucun événement.')));
          if (d.history.length > 1) {
            cd.set(d.history.map(p => ({ t: p[0], v: toMbps(p[1]) })));
            cu.set(d.history.map(p => ({ t: p[0], v: toMbps(p[2]) })));
          }
        }
        if (id === 'alerts') put(panel, d.alerts.length ? h('div', { class: 'alerts' }, d.alerts.map(a => h('div', { class: 'alert-mini', onclick: () => { m.close(); location.hash = '#/threats'; } },
          sevIcon(a.severity), h('div', null, h('b', null, a.title), h('span', null, fmt.ago(a.last_ts)))))) : emptyBox('Aucune alerte ouverte pour cet appareil.', 'shieldok'));
      };
      for (const [id, label] of tabs) tabBar.append(h('button', { type: 'button', role: 'tab', 'data-id': id, onclick: () => show(id) }, label));
      const renameBtn = h('button', { class: 'btn sm', type: 'button', onclick: () => renameFlow(d, reload) }, icon('edit'), 'Renommer');
      put(body,
        h('div', { class: 'drawer-head' }, avatar(d, true),
          h('div', { class: 'grow' }, h('h2', null, d.name), h('div', { class: 'muted' }, d.type_label, d.vendor ? ' · ' + d.vendor : '')),
          h('button', { class: 'btn icon ghost', type: 'button', 'aria-label': 'Fermer', onclick: () => m.close() }, icon('x'))),
        h('div', { class: 'chips' }, d.online ? h('span', { class: 'chip ok' }, h('span', { class: 'dot' }), 'En ligne') : h('span', { class: 'chip' }, 'Hors ligne'), devFlags(d),
          d.flags.random_mac ? h('span', { class: 'chip' }, icon('key'), 'MAC privée') : null),
        h('div', { class: 'btns', style: { marginTop: '14px' } },
          d.flags.isolated ? h('button', { class: 'btn primary sm', type: 'button', onclick: () => releaseFlow(d, reload) }, icon('unlock'), 'Fin d\'isolement')
            : h('button', { class: 'btn danger sm', type: 'button', onclick: () => isolateFlow(d, reload) }, icon('isolate'), 'Isoler'),
          d.flags.blocked && !d.flags.isolated ? h('button', { class: 'btn sm', type: 'button', onclick: () => allowFlow(d, reload) }, icon('unlock'), 'Rétablir Internet')
            : !d.flags.isolated ? h('button', { class: 'btn sm', type: 'button', onclick: () => blockFlow(d, reload) }, icon('lock'), 'Couper Internet') : null,
          h('button', { class: 'btn sm', type: 'button', onclick: async () => { try { await api.post('device_wake', { mac: d.mac }); toast('Réveil envoyé', 'ok', d.name); } catch (e) { fail(e); } } }, icon('zap'), 'Réveiller'),
          d.flags.approved ? h('button', { class: 'btn sm', type: 'button', onclick: () => approveFlow(d, false, reload) }, icon('alert'), 'Je ne le connais pas')
            : h('button', { class: 'btn sm', type: 'button', onclick: () => approveFlow(d, true, reload) }, icon('check'), 'C\'est à moi'),
          renameBtn,
          d.profile ? h('a', { class: 'btn sm ghost', href: '#/access', onclick: () => m.close() }, icon('clock'), 'Horaires') : null),
        tabBar, panel);
      show(tab);
    };
    load();
  }
  async function renameFlow(d, after) {
    const name = h('input', { class: 'input', value: d.name, maxlength: 60, required: true });
    const type = h('select', { class: 'input' }, Object.entries(d.types).map(([k, v]) => h('option', { value: k, selected: k === d.type }, v)));
    const form = h('form', null, h('h2', null, 'Renommer l\'appareil'), h('p', { class: 'lead' }, 'Le nom est aussi enregistré dans la Freebox.'),
      h('div', { class: 'stack' }, h('label', { class: 'field' }, 'Nom', name), h('label', { class: 'field' }, 'Type (icône)', type)),
      h('div', { class: 'actions' }, h('button', { class: 'btn ghost', type: 'button', onclick: () => m.close() }, 'Annuler'), h('button', { class: 'btn primary', type: 'submit' }, 'Enregistrer')));
    const m = modal(form, { label: 'Renommer' });
    form.addEventListener('submit', async e => {
      e.preventDefault();
      try { await api.post('device_rename', { mac: d.mac, name: name.value, type: type.value }); m.close(); toast('Appareil renommé', 'ok', name.value); after && after(); } catch (err) { fail(err); }
    });
  }

  /* =====================================================================
   * Écrans
   * ===================================================================== */
  const VIEWS = {};

  /* ---------- Tableau de bord ---------- */
  VIEWS.dashboard = async root => {
    const group = new ChartGroup();
    const cDown = new AreaChart({ title: 'Réception', sub: 'Mb/s · 10 dernières minutes', color: 'var(--series-down)', keyCls: 'down', group, height: 132 });
    const cUp = new AreaChart({ title: 'Envoi', sub: 'Mb/s', color: 'var(--series-up)', keyCls: 'up', group, height: 112 });
    state.charts = [cDown, cUp];
    const slots = { score: h('div'), tiles: h('div', { class: 'grid g-tiles' }), alerts: h('div', { class: 'alerts' }), devices: h('div', { class: 'devs' }), filters: h('div'), box: h('div') };
    let first = true;
    if (state.live.length < 3) {
      try {
        const tr = await api.get('traffic', { range: 'hour' });
        const cut = Date.now() / 1000 - 600;
        state.live = tr.points.filter(p => p[0] >= cut).map(p => ({ t: p[0], down: p[1], up: p[2] }));
      } catch { /* l'historique arrivera avec le direct */ }
    }
    const drawDevices = () => {
      const ov = state.ov;
      if (!ov) return;
      const list = filterDevices(ov.devices);
      const max = Math.max(...ov.devices.map(d => Math.max(d.rate.down, d.rate.up)), 1);
      put(slots.devices, list.length ? list.map(d => deviceCard(d, max, refresh)) : emptyBox('Aucun appareil ne correspond au filtre.'));
    };
    const refresh = async () => {
      let ov;
      try { ov = await api.get('overview'); } catch (e) { setLive('err', 'Hors connexion'); if (first) put(root, errorBox(e)); return; }
      state.ov = ov;
      setLive('on');
      setThreatCount(ov.alerts.length ? Object.values(ov.score.counts).reduce((a, b) => a + b, 0) : 0);
      state.live.push({ t: ov.ts, down: ov.link.down, up: ov.link.up });
      const cut = ov.ts - 600;
      state.live = state.live.filter(p => p.t >= cut);
      if (first) {
        first = false;
        put(root,
          h('div', { class: 'grid g-hero' }, slots.score, slots.tiles),
          h('div', { class: 'grid g-2-1', style: { marginTop: '18px' } },
            h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('traffic'), 'Trafic Internet en direct'), h('div', { class: 'grow' }),
              h('a', { class: 'btn sm ghost', href: '#/traffic' }, 'Historique', icon('chevron', 'sm'))), h('div', { class: 'stack' }, cDown.el, cUp.el)),
            h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('shield'), 'Alertes récentes'), h('div', { class: 'grow' }),
              h('a', { class: 'btn sm ghost', href: '#/threats' }, 'Tout voir', icon('chevron', 'sm'))), slots.alerts, slots.box)),
          h('div', { class: 'section-title' }, 'Appareils connectés'),
          slots.filters, slots.devices);
        put(slots.filters, deviceFilters(drawDevices, null));
      }
      put(slots.score, scoreCard(ov.score, h('div', { class: 'btns', style: { marginTop: '12px' } }, h('a', { class: 'btn sm primary', href: '#/threats' }, icon('shield'), 'Voir les menaces'))));
      const bwd = ov.link.bw_down / 8, bwu = ov.link.bw_up / 8;
      put(slots.tiles,
        h('div', { class: 'tile' }, h('div', { class: 'lab' }, h('span', { class: 'key down' }), 'Réception'), h('div', { class: 'val' }, fmt.rate(ov.link.down)),
          h('div', { class: 'note' }, bwd ? `sur ${fmt.bits(ov.link.bw_down)} disponibles` : ''), bwd ? h('div', { class: 'meter' }, h('i', { style: { width: Math.min(100, ov.link.down / bwd * 100).toFixed(2) + '%' } })) : null),
        h('div', { class: 'tile' }, h('div', { class: 'lab' }, h('span', { class: 'key up' }), 'Envoi'), h('div', { class: 'val' }, fmt.rate(ov.link.up)),
          h('div', { class: 'note' }, bwu ? `sur ${fmt.bits(ov.link.bw_up)} disponibles` : ''), bwu ? h('div', { class: 'meter' }, h('i', { style: { width: Math.min(100, ov.link.up / bwu * 100).toFixed(2) + '%' } })) : null),
        h('div', { class: 'tile' }, h('div', { class: 'lab' }, icon('devices', 'sm'), 'Appareils en ligne'), h('div', { class: 'val' }, String(ov.counts.online), h('small', null, `/ ${ov.counts.total}`)),
          h('div', { class: 'note' }, `${ov.counts.wifi} en Wi-Fi · ${ov.counts.ethernet} en Ethernet`, ov.counts.new ? ` · ${ov.counts.new} nouveau(x)` : '')),
        h('div', { class: 'tile' }, h('div', { class: 'lab' }, icon('router', 'sm'), ov.box.model || 'Freebox'), h('div', { class: 'val', style: { fontSize: '20px' } }, ov.link.state === 'up' ? 'Connectée' : 'Hors ligne'),
          h('div', { class: 'note' }, `${(ov.link.media || '').toUpperCase()} · version ${ov.box.firmware || '?'} · allumée depuis ${fmt.dur(ov.box.uptime)}${ov.box.temp ? ` · ${nf0.format(ov.box.temp)} °C` : ''}`)));
      put(slots.alerts, ov.alerts.length ? ov.alerts.map(a => h('button', { class: 'alert-mini', type: 'button', onclick: () => { location.hash = '#/threats'; } },
        sevIcon(a.severity), h('div', null, h('b', null, a.title), h('span', null, `${(SEV[a.severity] || SEV.info)[0]} · ${fmt.ago(a.last)}`)))) : emptyBox('Aucune alerte ouverte. Tout va bien !', 'shieldok'));
      put(slots.box, ov.cron < Date.now() / 1000 - 900 ? h('div', { style: { marginTop: '12px' } }, noteBox(h('b', null, 'Surveillance continue inactive. '), 'L\'analyse ne tourne que lorsque cette page est ouverte. Installez la tâche planifiée (Paramètres › Surveillance).')) : null);
      cDown.set(state.live.map(p => ({ t: p.t, v: toMbps(p.down) })));
      cUp.set(state.live.map(p => ({ t: p.t, v: toMbps(p.up) })));
      drawDevices();
    };
    await refresh();
    poll(refresh);
  };

  /* ---------- Appareils ---------- */
  VIEWS.devices = async root => {
    let data = null;
    const list = h('div', { class: 'devs' });
    const filt = h('div');
    const draw = () => {
      if (!data) return;
      const max = Math.max(...data.devices.map(d => Math.max(d.rate.down, d.rate.up)), 1);
      const l = filterDevices(data.devices);
      put(list, l.length ? l.map(d => deviceCard(d, max, refresh)) : emptyBox('Aucun appareil ne correspond.'));
    };
    const summary = h('p', { class: 'faint', style: { margin: '-6px 0 14px', fontSize: '13px' } });
    const refresh = async () => {
      try { data = await api.get('devices'); setLive('on'); } catch (e) { setLive('err', 'Erreur'); if (!data) put(root, errorBox(e)); return; }
      state.ov = state.ov || { alerts: [] };
      if (!filt.firstChild) put(filt, deviceFilters(draw, null));
      const c = devCounts(data.devices);
      put(summary, `${c.all} appareils connus · ${c.online} en ligne (${c.wifi} en Wi-Fi, ${c.ethernet} en Ethernet) · ${c.new} nouveau(x) · ${c.blocked} coupé(s) ou isolé(s)`);
      draw();
    };
    put(root, filt, summary, list);
    await refresh();
    poll(refresh, 10);
  };

  /* ---------- Trafic ---------- */
  VIEWS.traffic = async root => {
    let range = 'hour';
    const group = new ChartGroup();
    const cd = new AreaChart({ title: 'Réception', sub: 'Mb/s', color: 'var(--series-down)', keyCls: 'down', height: 200, group });
    const cu = new AreaChart({ title: 'Envoi', sub: 'Mb/s', color: 'var(--series-up)', keyCls: 'up', height: 170, group });
    state.charts = [cd, cu];
    const info = h('div');
    const top = h('div');
    const table = h('div');
    const seg = h('div', { class: 'seg', role: 'group', 'aria-label': 'Période' });
    for (const [k, l] of [['hour', 'Dernière heure'], ['day', '24 heures'], ['week', '7 jours']]) {
      const b = h('button', { type: 'button', 'aria-pressed': String(k === range) }, l);
      b.addEventListener('click', () => { range = k; for (const x of seg.children) x.setAttribute('aria-pressed', String(x === b)); load(); });
      seg.append(b);
    }
    const showTable = h('button', { class: 'btn sm ghost', type: 'button' }, icon('journal', 'sm'), 'Voir en tableau');
    let last = null;
    showTable.addEventListener('click', () => {
      if (!last) return;
      put(table, h('div', { class: 'table-wrap', style: { maxHeight: '320px', overflowY: 'auto' } }, h('table', null,
        h('thead', null, h('tr', null, h('th', null, 'Heure'), h('th', null, 'Réception'), h('th', null, 'Envoi'))),
        h('tbody', null, last.points.slice().reverse().map(p => h('tr', null, h('td', null, fmt.datetime(p[0])), h('td', { class: 'num' }, fmt.rate(p[1])), h('td', { class: 'num' }, fmt.rate(p[2]))))))));
    });
    put(root,
      h('div', { class: 'filters' }, seg, showTable),
      h('div', { class: 'grid g-2-1' },
        h('div', { class: 'card' }, h('div', { class: 'stack' }, cd.el, cu.el), info, table),
        h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('devices'), 'Appareils les plus actifs'), h('p', { class: 'grow' }, 'Moyenne sur la période')), top)));
    const load = async () => {
      root.classList.add('refetch');
      try {
        const r = await api.get('traffic', { range });
        last = r;
        setLive('on');
        cd.set(r.points.map(p => ({ t: p[0], v: toMbps(p[1]) })));
        cu.set(r.points.map(p => ({ t: p[0], v: toMbps(p[2]) })));
        put(info, h('p', { class: 'faint', style: { fontSize: '12.5px', margin: '10px 0 0' } },
          r.source === 'freebox' ? 'Source : statistiques de la Freebox.' : 'Source : mesures de Sentinelle (une par minute).',
          ` En ce moment : ↓ ${fmt.rate(r.link.down)} · ↑ ${fmt.rate(r.link.up)}.`));
        const max = Math.max(...r.top.map(t => Math.max(t.down, t.up)), 1);
        put(top, r.top.length ? h('div', { class: 'stack' },
          h('div', { class: 'legend' }, h('span', null, h('span', { class: 'key down' }), 'Réception'), h('span', null, h('span', { class: 'key up' }), 'Envoi')),
          h('div', { class: 'hbars' }, r.top.map(t => h('div', { class: 'hbar' }, h('span', { class: 'name', title: t.name }, t.name),
            h('div', { class: 'track' }, h('i', { class: 'd', style: { width: Math.max(1, t.down / max * 100) + '%' }, title: 'Réception ' + fmt.rate(t.down) }),
              h('i', { class: 'u', style: { width: Math.max(1, t.up / max * 100) + '%' }, title: 'Envoi ' + fmt.rate(t.up) })),
            h('b', null, fmt.rate(t.down + t.up))))))
          : emptyBox('Les moyennes par appareil apparaissent après quelques minutes de collecte.'));
      } catch (e) { put(info, errorBox(e)); } finally { root.classList.remove('refetch'); }
    };
    await load();
    poll(load, 30);
  };

  /* ---------- Menaces ---------- */
  VIEWS.threats = async root => {
    const head = h('div');
    const listEl = h('div', { class: 'alerts' });
    const seg = h('div', { class: 'seg', role: 'group', 'aria-label': 'Statut des alertes' });
    for (const [k, l] of [['active', 'À traiter'], ['ack', 'Risque accepté'], ['resolved', 'Résolues'], ['all', 'Toutes']]) {
      const b = h('button', { type: 'button', 'aria-pressed': String(state.alertsTab === k) }, l);
      b.addEventListener('click', () => { state.alertsTab = k; for (const x of seg.children) x.setAttribute('aria-pressed', String(x === b)); load(); });
      seg.append(b);
    }
    const rerun = h('button', { class: 'btn sm', type: 'button' }, icon('refresh', 'sm'), 'Relancer l\'analyse');
    rerun.addEventListener('click', () => busy(rerun, async () => {
      try { const r = await api.post('detect_run'); toast('Analyse terminée', 'ok', r.new ? `${r.new} nouvelle(s) alerte(s)` : 'Aucune nouvelle alerte'); load(); } catch (e) { fail(e); }
    }));
    const mitre = str => (str || '').split('·').map(x => x.trim()).filter(Boolean).map(id => h('a', {
      class: 'chip link', target: '_blank', rel: 'noopener noreferrer',
      href: id.startsWith('TA') ? `https://attack.mitre.org/tactics/${id}/` : `https://attack.mitre.org/techniques/${id.replace('.', '/')}/`,
      title: 'Fiche MITRE ATT&CK de cette technique',
    }, icon('external'), 'MITRE ' + id));
    const load = async () => {
      let r;
      try { r = await api.get('alerts', { status: state.alertsTab }); } catch (e) { put(listEl, errorBox(e)); return; }
      put(head, scoreCard(r.score, h('p', { class: 'muted', style: { margin: '10px 0 0', fontSize: '13px' } },
        'Chaque alerte ouverte retire des points. Corrigez, acceptez un risque connu, ou isolez l\'appareil en cause.')));
      setThreatCount(Object.values(r.score.counts).reduce((a, b) => a + b, 0));
      put(listEl, r.alerts.length ? r.alerts.map(a => h('div', { class: `alert ${a.severity} st-${a.status}` },
        h('div', null,
          h('div', { class: 'alert-top' }, sevChip(a.severity), h('b', null, a.title),
            a.status === 'ack' ? h('span', { class: 'chip' }, 'Risque accepté') : a.status === 'resolved' ? h('span', { class: 'chip ok' }, icon('check'), 'Résolue') : null,
            h('span', { class: 'faint', style: { fontSize: '12px' } }, `${a.kind === 'event' ? 'Événement' : 'Configuration'} · ${fmt.ago(a.last)}${a.count > 1 ? ` · ${a.count} fois` : ''}`)),
          h('p', null, a.detail),
          a.fix ? h('div', { class: 'fix' }, icon('shieldok', 'sm'), h('span', null, a.fix)) : null,
          a.mitre ? h('div', { class: 'chips', style: { marginTop: '8px' } }, mitre(a.mitre)) : null,
          h('div', { class: 'btns' },
            a.status !== 'resolved' ? a.actions.map(act => h('button', { class: 'btn sm ' + (act.act === 'isolate' ? 'danger' : act.type === 'fix' ? 'primary' : ''), type: 'button',
              onclick: () => runAction({ ...act, name: a.title.split(' : ').pop() }, load) }, icon(act.act === 'isolate' ? 'isolate' : act.act === 'approve' ? 'check' : act.act === 'block' ? 'lock' : act.act === 'release' ? 'unlock' : 'zap'), act.label)) : null,
            a.status === 'open' ? h('button', { class: 'btn sm ghost', type: 'button', onclick: () => setStatus(a, 'ack') }, icon('check', 'sm'), 'Accepter le risque') : null,
            a.status === 'open' && a.kind === 'event' ? h('button', { class: 'btn sm ghost', type: 'button', onclick: () => setStatus(a, 'resolved') }, 'Marquer traitée') : null,
            a.status !== 'open' ? h('button', { class: 'btn sm ghost', type: 'button', onclick: () => setStatus(a, 'open') }, 'Rouvrir') : null))))
        : emptyBox(state.alertsTab === 'active' ? 'Aucune alerte à traiter. Votre réseau est en ordre.' : 'Rien dans cette catégorie.', 'shieldok'));
      setLive('on');
    };
    const setStatus = async (a, st) => { try { await api.post('alert_status', { id: a.id, status: st }); load(); } catch (e) { fail(e); } };
    put(root, head, h('div', { class: 'filters', style: { marginTop: '18px' } }, seg, rerun), listEl);
    await load();
    poll(load, 20);
  };

  /* ---------- Contrôle d'accès ---------- */
  const DAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
  function weekStrip(schedules) {
    const rows = DAYS.map(() => []);
    for (const sc of schedules) {
      if (!sc.enabled) continue;
      const [sh, sm] = sc.start.split(':').map(Number), [eh, em] = sc.stop.split(':').map(Number);
      const a = sh * 60 + sm, b = eh * 60 + em;
      for (let d = 0; d < 7; d++) {
        if (!((sc.days >> d) & 1)) continue;
        if (b > a) rows[d].push([a, b]);
        else { rows[d].push([a, 1440]); rows[(d + 1) % 7].push([0, b]); }
      }
    }
    return h('div', { class: 'week', 'aria-label': 'Semaine type : plages de coupure' },
      DAYS.map((d, i) => [h('span', null, d), h('div', { class: 'strip' }, rows[i].map(([a, b]) => h('i', { style: { left: (a / 14.4) + '%', width: ((b - a) / 14.4) + '%' }, title: `${d} : coupé de ${String(Math.floor(a / 60)).padStart(2, '0')}:${String(a % 60).padStart(2, '0')} à ${b === 1440 ? '24:00' : String(Math.floor(b / 60)).padStart(2, '0') + ':' + String(b % 60).padStart(2, '0')}` })))]),
      h('div', { class: 'scale' }, h('span', null, '0 h'), h('span', null, '6 h'), h('span', null, '12 h'), h('span', null, '18 h'), h('span', null, '24 h')));
  }
  function scheduleForm(profiles, sc, after) {
    const name = h('input', { class: 'input', value: sc ? sc.name : 'Coupure du soir', maxlength: 40 });
    const prof = h('select', { class: 'input' }, profiles.map(p => h('option', { value: p.id, selected: sc ? sc.profile_id === p.id : false }, p.name)));
    const start = h('input', { class: 'input', type: 'time', value: sc ? sc.start : '21:30', required: true });
    const stop = h('input', { class: 'input', type: 'time', value: sc ? sc.stop : '07:00', required: true });
    const mask = sc ? sc.days : 0b1001111;
    const days = h('div', { class: 'days' }, DAYS.map((d, i) => h('label', null, h('input', { type: 'checkbox', value: i, checked: !!((mask >> i) & 1), 'aria-label': d }), h('span', null, d))));
    const en = h('input', { type: 'checkbox', checked: sc ? sc.enabled : true });
    const form = h('form', null, h('h2', null, sc ? 'Modifier l\'horaire' : 'Nouvel horaire de coupure'),
      h('p', { class: 'lead' }, 'Pendant la plage, Internet est coupé pour tout le profil. La Freebox rétablit l\'accès seule à l\'heure de fin.'),
      h('div', { class: 'stack' },
        h('div', { class: 'form-grid' }, h('label', { class: 'field' }, 'Nom', name), h('label', { class: 'field' }, 'Profil Freebox', prof)),
        h('div', { class: 'form-grid' }, h('label', { class: 'field' }, 'Coupure à', start), h('label', { class: 'field' }, 'Rétablissement à', stop)),
        h('div', { class: 'field' }, 'Jours (une plage qui passe minuit compte pour le jour où elle commence)', days),
        h('label', { class: 'check' }, h('span', { class: 'switch' }, en, h('span')), 'Horaire actif')),
      h('div', { class: 'actions' },
        sc ? h('button', { class: 'btn danger', type: 'button', onclick: async () => { try { await api.post('schedule_delete', { id: sc.id }); m.close(); toast('Horaire supprimé'); after(); } catch (e) { fail(e); } } }, icon('trash'), 'Supprimer') : null,
        h('button', { class: 'btn ghost', type: 'button', onclick: () => m.close() }, 'Annuler'), h('button', { class: 'btn primary', type: 'submit' }, 'Enregistrer')));
    const m = modal(form, { label: 'Horaire' });
    form.addEventListener('submit', async e => {
      e.preventDefault();
      let bits = 0;
      days.querySelectorAll('input').forEach(i => { if (i.checked) bits |= 1 << Number(i.value); });
      try {
        await api.post('schedule_save', { id: sc ? sc.id : 0, name: name.value, profile_id: Number(prof.value), start: start.value, stop: stop.value, days: bits, enabled: en.checked });
        m.close(); toast('Horaire enregistré', 'ok', `${start.value} → ${stop.value}`); after();
      } catch (err) { fail(err); }
    });
  }
  VIEWS.access = async root => {
    const load = async () => {
      let r;
      try { r = await api.get('access'); setLive('on'); } catch (e) { put(root, errorBox(e)); return; }
      const cut = async (p, mode, minutes = 0) => {
        try { await api.post('profile_mode', { id: p.id, mode, minutes }); toast(mode === 'denied' ? 'Internet coupé' : 'Accès mis à jour', 'ok', `Profil « ${p.name} »`); load(); } catch (e) { fail(e); }
      };
      put(root,
        r.error ? h('div', { style: { marginBottom: '16px' } }, errorBox({ message: 'Profils Freebox inaccessibles.', hint: r.error })) : null,
        noteBox(h('b', null, 'Comment ça marche. '), 'La Freebox coupe Internet par « profil » (un profil regroupe les appareils d\'une personne). Créez vos profils dans Freebox OS (Contrôle parental) ; Sentinelle les pilote : coupure immédiate, minutée ou programmée. Un appareil coupé reste visible sur le réseau local.'),
        h('div', { class: 'card-head', style: { marginTop: '18px' } }, h('div', { class: 'grow' }),
          r.profiles.length ? h('button', { class: 'btn primary', type: 'button', onclick: () => scheduleForm(r.profiles, null, load) }, icon('plus'), 'Ajouter un horaire') : null),
        r.profiles.length ? h('div', { class: 'profiles' }, r.profiles.map(p => {
          const scheds = r.schedules.filter(sc => sc.profile_id === p.id);
          return h('div', { class: 'card' },
            h('div', { class: 'card-head' }, h('h2', null, icon('devices'), p.name), h('div', { class: 'grow' }), modeChip(p.mode)),
            p.override ? h('p', { class: 'muted', style: { margin: '0 0 10px', fontSize: '13px' } }, `Forçage manuel : ${MODE[p.override_mode] ? MODE[p.override_mode][0].toLowerCase() : p.override_mode}${p.until ? ' jusqu\'à ' + fmt.time(p.until) : ''}.`) : null,
            !p.override && p.next_change ? h('p', { class: 'muted', style: { margin: '0 0 10px', fontSize: '13px' } }, `Planning Freebox : prochain changement à ${fmt.time(p.next_change)}.`) : null,
            h('div', { class: 'chips', style: { marginBottom: '12px' } }, p.devices.length ? p.devices.map(d => h('span', { class: 'chip' + (d.online ? ' ok' : '') }, icon(TYPE_ICON[d.type] || 'unknown'), d.name)) : h('span', { class: 'faint' }, 'Aucun appareil dans ce profil')),
            h('div', { class: 'btns' },
              p.mode === 'denied'
                ? h('button', { class: 'btn sm primary', type: 'button', onclick: () => cut(p, p.override ? 'clear' : 'allowed', p.override ? 0 : 60) }, icon('unlock'), p.override ? 'Rétablir' : 'Autoriser 1 h')
                : [h('button', { class: 'btn sm danger', type: 'button', onclick: () => cut(p, 'denied') }, icon('lock'), 'Couper'),
                  h('button', { class: 'btn sm', type: 'button', onclick: () => cut(p, 'denied', 30) }, icon('clock'), '30 min'),
                  h('button', { class: 'btn sm', type: 'button', onclick: () => cut(p, 'denied', 60) }, icon('clock'), '1 h')]),
            h('div', { class: 'section-title', style: { margin: '18px 0 10px' } }, 'Horaires Sentinelle'),
            scheds.length ? h('div', { class: 'stack', style: { gap: '8px' } }, scheds.map(sc => h('button', { class: 'alert-mini', type: 'button', onclick: () => scheduleForm(r.profiles, sc, load) },
              h('span', { class: 'sev-ic ' + (sc.active ? 'elevee' : 'faible') }, icon('clock')),
              h('div', null, h('b', null, `${sc.name} · ${sc.start} → ${sc.stop}`), h('span', null, DAYS.filter((_, i) => (sc.days >> i) & 1).join(' '), sc.enabled ? (sc.active ? ' · en cours' : '') : ' · désactivé'))))) : h('p', { class: 'faint', style: { fontSize: '13px', margin: 0 } }, 'Aucun horaire.'),
            scheds.length ? h('div', { style: { marginTop: '14px' } }, weekStrip(scheds)) : null);
        })) : emptyBox('Aucun profil sur la Freebox. Créez-en un dans Freebox OS › Contrôle parental, ou utilisez « Couper » sur un appareil : Sentinelle créera un profil dédié.'),
        r.unassigned.length ? [h('div', { class: 'section-title' }, 'Appareils sans profil (accès libre)'),
          h('div', { class: 'chips' }, r.unassigned.map(d => h('button', { class: 'chip link', type: 'button', onclick: () => openDevice(d.mac, load) }, icon(TYPE_ICON[d.type] || 'unknown'), d.name)))] : null,
        h('div', { style: { marginTop: '18px' } }, noteBox(h('b', null, 'Bon à savoir. '), 'Les horaires Sentinelle sont posés par la tâche planifiée du serveur ; la box rétablit seule l\'accès à l\'heure de fin. Si le PC serveur est éteint à l\'heure de début, la coupure n\'a pas lieu : pour une garantie totale, ajoutez aussi la planification de Freebox OS.')));
    };
    await load();
  };

  /* ---------- Exposition sur Internet ---------- */
  VIEWS.exposure = async root => {
    const load = async () => {
      let r;
      try { r = await api.get('exposure'); setLive('on'); } catch (e) { put(root, errorBox(e)); return; }
      const fix = (label, fixId, id = '') => h('button', { class: 'btn sm primary', type: 'button', onclick: () => runAction({ type: 'fix', fix: fixId, id, label }, load) }, icon('zap'), label);
      const level = lv => sevChip(lv === 'ok' ? 'info' : lv);
      const status = (ok, okText, koText) => h('span', { class: 'chip ' + (ok ? 'ok' : 'crit') }, icon(ok ? 'check' : 'alert'), ok ? okText : koText);
      const activeRedirs = r.redirs.filter(x => x.enabled).length;
      put(root,
        h('div', { class: 'grid g-3' },
          h('div', { class: 'tile' }, h('div', { class: 'lab' }, icon('globe', 'sm'), 'Ports redirigés'), h('div', { class: 'val' }, String(activeRedirs)), h('div', { class: 'note' }, 'règles actives configurées à la main')),
          h('div', { class: 'tile' }, h('div', { class: 'lab' }, icon('zap', 'sm'), 'Ouvertures UPnP'), h('div', { class: 'val' }, r.upnp.enabled ? String(r.upnp.redirs.length) : '—'), h('div', { class: 'note' }, r.upnp.enabled ? 'ouvertes automatiquement par les appareils' : 'UPnP désactivé')),
          h('div', { class: 'tile' }, h('div', { class: 'lab' }, icon('shield', 'sm'), 'Pare-feu IPv6'), h('div', { class: 'val', style: { fontSize: '20px' } }, !r.ipv6.known ? 'Inconnu' : !r.ipv6.enabled ? 'IPv6 coupé' : r.ipv6.firewall ? 'Actif' : 'Désactivé'), h('div', { class: 'note' }, r.ipv6.prefixes.join(', ')))),
        r.errors.length ? h('div', { style: { marginTop: '16px' } }, errorBox({ message: 'Certaines informations n\'ont pas pu être lues.', hint: r.errors.join(' · ') })) : null,
        h('div', { class: 'grid g-2', style: { marginTop: '18px' } },
          h('div', { class: 'card' + (r.ipv6.enabled && !r.ipv6.firewall ? ' glow' : '') }, h('div', { class: 'card-head' }, h('h2', null, icon('shield'), 'Pare-feu IPv6'), h('div', { class: 'grow' }), status(!r.ipv6.enabled || r.ipv6.firewall, 'Protégé', 'Exposé')),
            h('p', { class: 'muted', style: { marginTop: 0, fontSize: '13.5px' } }, 'En IPv6, chaque appareil a une adresse publique. Sans ce pare-feu, un service ouvert sur un PC (partage Windows, IIS…) est joignable depuis Internet, même sans redirection de port.'),
            r.ipv6.enabled && !r.ipv6.firewall ? fix('Activer le pare-feu IPv6', 'ipv6_firewall_on') : null),
          h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('router'), 'Administration à distance'), h('div', { class: 'grow' }), status(!r.remote.remote_access, 'Fermée', 'Ouverte')),
            h('dl', { class: 'kv' }, h('dt', null, 'Freebox OS depuis Internet'), h('dd', null, r.remote.remote_access ? `Oui, port ${r.remote.port}${r.remote.secure_pass ? '' : ' — mot de passe jugé faible'}` : 'Non'),
              h('dt', null, 'Applications à distance'), h('dd', null, r.remote.api_remote_access ? 'Autorisées' : 'Non'),
              h('dt', null, 'Nouvelles associations'), h('dd', null, r.remote.allow_token_request ? 'Autorisées' : 'Refusées'),
              h('dt', null, 'Réponse au ping'), h('dd', null, r.remote.ping ? 'Oui' : 'Non'),
              h('dt', null, 'Adresse publique'), h('dd', { class: 'mono' }, r.wan.ipv4 || '—')),
            h('div', { class: 'btns', style: { marginTop: '12px' } }, r.remote.remote_access ? fix('Couper l\'accès distant', 'remote_access_off') : null,
              r.remote.allow_token_request ? fix('Refuser les nouvelles associations', 'token_request_off') : null))),
        h('div', { class: 'card', style: { marginTop: '18px' } },
          h('div', { class: 'card-head' }, h('h2', null, icon('globe'), 'Redirections de ports'), h('p', { class: 'grow' }, 'Configurées dans Freebox OS › Gestion des ports')),
          r.redirs.length ? h('div', { class: 'table-wrap' }, h('table', null,
            h('thead', null, h('tr', null, ['Port public', 'Vers', 'Risque', 'Source', 'État', ''].map(t => h('th', null, t)))),
            h('tbody', null, r.redirs.map(x => h('tr', null,
              h('td', { class: 'mono' }, `${x.wan}/${x.proto}`),
              h('td', null, h('b', null, x.host || x.lan_ip), h('div', { class: 'faint mono' }, `${x.lan_ip}:${x.lan_port}`), x.comment ? h('div', { class: 'faint' }, x.comment) : null),
              h('td', null, level(x.risk.level), h('div', { class: 'faint', style: { fontSize: '12px', maxWidth: '260px' } }, x.risk.why)),
              h('td', null, x.src === '0.0.0.0' || !x.src ? 'Tout Internet' : x.src),
              h('td', null, x.enabled ? h('span', { class: 'chip warn' }, h('span', { class: 'dot' }), 'Active') : h('span', { class: 'chip' }, 'Désactivée')),
              h('td', null, h('div', { class: 'btns' },
                x.enabled ? fix('Désactiver', 'redir_disable', x.id) : h('button', { class: 'btn sm', type: 'button', onclick: () => runAction({ type: 'fix', fix: 'redir_enable', id: x.id, label: 'Réactiver la redirection' }, load) }, 'Réactiver'),
                h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Supprimer', onclick: () => runAction({ type: 'fix', fix: 'redir_delete', id: x.id, label: 'Supprimer la redirection' }, load) }, icon('trash', 'sm')))))))))
            : emptyBox('Aucune redirection de port : rien n\'est ouvert manuellement.', 'shieldok')),
        h('div', { class: 'card', style: { marginTop: '18px' } },
          h('div', { class: 'card-head' }, h('h2', null, icon('zap'), 'UPnP (ouvertures automatiques)'), h('div', { class: 'grow' }), r.upnp.enabled ? h('span', { class: 'chip warn' }, icon('alert'), 'Actif') : h('span', { class: 'chip ok' }, icon('check'), 'Désactivé'),
            r.upnp.enabled ? fix('Désactiver l\'UPnP', 'upnp_off') : null),
          r.upnp.enabled ? (r.upnp.redirs.length ? h('div', { class: 'table-wrap' }, h('table', null,
            h('thead', null, h('tr', null, ['Port public', 'Appareil', 'Description annoncée', 'Risque', ''].map(t => h('th', null, t)))),
            h('tbody', null, r.upnp.redirs.map(x => h('tr', null, h('td', { class: 'mono' }, `${x.ext_port}/${x.proto}`),
              h('td', null, h('b', null, x.host || x.int_ip), h('div', { class: 'faint mono' }, `${x.int_ip}:${x.int_port}`)),
              h('td', null, x.desc || '—'), h('td', null, level(x.risk.level), h('div', { class: 'faint', style: { fontSize: '12px', maxWidth: '240px' } }, x.risk.why)),
              h('td', null, h('div', { class: 'btns' }, fix('Fermer', 'upnp_redir_delete', x.id),
                x.mac ? h('button', { class: 'btn sm danger', type: 'button', onclick: () => isolateFlow({ mac: x.mac, name: x.host }, load) }, icon('isolate', 'sm'), 'Isoler') : null)))))))
            : emptyBox('UPnP actif mais aucun port ouvert pour l\'instant.')) : emptyBox('Les appareils ne peuvent pas ouvrir de ports tout seuls. Parfait.', 'shieldok')),
        h('div', { class: 'grid g-2', style: { marginTop: '18px' } },
          h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('nas'), 'Serveur FTP de la box'), h('div', { class: 'grow' }), status(!(r.ftp.enabled && r.ftp.remote), r.ftp.enabled ? 'Local seulement' : 'Désactivé', 'Ouvert sur Internet')),
            h('p', { class: 'muted', style: { margin: 0, fontSize: '13.5px' } }, r.ftp.enabled ? `FTP actif${r.ftp.anonymous ? ', accès anonyme autorisé' : ''}.` : 'FTP désactivé.'),
            r.ftp.enabled && r.ftp.remote ? h('div', { style: { marginTop: '10px' } }, fix('Couper le FTP distant', 'ftp_remote_off')) : null),
          h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('nas'), 'Partage de fichiers Windows'), h('div', { class: 'grow' }), status(!r.samba.enabled || r.samba.logon, r.samba.enabled ? 'Protégé par mot de passe' : 'Désactivé', 'Sans mot de passe')),
            h('p', { class: 'muted', style: { margin: 0, fontSize: '13.5px' } }, 'Partage du disque de la box sur le réseau local (jamais exposé sur Internet par la box).'))));
    };
    await load();
  };

  /* ---------- Wi-Fi ---------- */
  VIEWS.wifi = async root => {
    const load = async () => {
      let r, devs = null;
      try { [r, devs] = await Promise.all([api.get('wifi'), api.get('devices').catch(() => null)]); setLive('on'); } catch (e) { put(root, errorBox(e)); return; }
      const modes = [['disabled', 'Désactivé'], ['blacklist', 'Liste noire (refuser certains appareils)'], ['whitelist', 'Liste blanche (n\'accepter que certains appareils)']];
      const modeSel = h('select', { class: 'input' }, modes.map(([k, l]) => h('option', { value: k, selected: r.mac_filter_state === k }, l)));
      modeSel.addEventListener('change', async () => {
        const mode = modeSel.value;
        if (mode === 'whitelist') {
          const ok = await confirmBox({ title: 'Activer la liste blanche ?', danger: true, ok: 'Activer',
            lead: 'Seuls les appareils de la liste blanche pourront se connecter au Wi-Fi. Tous les autres seront déconnectés immédiatement — y compris peut-être l\'appareil que vous tenez.' });
          if (!ok) { modeSel.value = r.mac_filter_state; return; }
        }
        try { await api.post('wifi_filter', { op: 'mode', mode }); toast('Filtrage Wi-Fi mis à jour'); load(); } catch (e) { fail(e); modeSel.value = r.mac_filter_state; }
      });
      const pick = h('select', { class: 'input' }, h('option', { value: '' }, 'Choisir un appareil…'),
        (devs ? devs.devices : []).map(d => h('option', { value: d.mac }, `${d.name} (${d.mac})`)));
      const macIn = h('input', { class: 'input mono', placeholder: 'AA:BB:CC:DD:EE:FF', maxlength: 17 });
      pick.addEventListener('change', () => { macIn.value = pick.value; });
      const typeSel = h('select', { class: 'input' }, h('option', { value: 'blacklist' }, 'Liste noire'), h('option', { value: 'whitelist' }, 'Liste blanche'));
      const addForm = h('form', { class: 'form-grid', style: { alignItems: 'end' } }, h('label', { class: 'field' }, 'Appareil', pick), h('label', { class: 'field' }, 'ou adresse MAC', macIn),
        h('label', { class: 'field' }, 'Liste', typeSel), h('button', { class: 'btn primary', type: 'submit' }, icon('plus'), 'Ajouter'));
      addForm.addEventListener('submit', async e => {
        e.preventDefault();
        try { await api.post('wifi_filter', { op: 'add', mac: macIn.value, type: typeSel.value, comment: 'Ajouté par Sentinelle' }); toast('Ajouté au filtrage'); load(); } catch (err) { fail(err); }
      });
      put(root,
        h('div', { class: 'grid g-3' }, r.aps.map(a => h('div', { class: 'tile' }, h('div', { class: 'lab' }, icon('wifi', 'sm'), `Wi-Fi ${a.band}`),
          h('div', { class: 'val' }, String(a.stations), h('small', null, 'appareil' + (a.stations > 1 ? 's' : ''))),
          h('div', { class: 'note' }, `Canal ${a.channel || '—'} · ${a.width ? a.width + ' MHz' : ''} · ${a.status === 'active' ? 'actif' : a.status}`)))),
        r.errors.length ? h('div', { style: { marginTop: '16px' } }, errorBox({ message: 'Lecture partielle du Wi-Fi.', hint: r.errors.join(' · ') })) : null,
        h('div', { class: 'card', style: { marginTop: '18px' } }, h('div', { class: 'card-head' }, h('h2', null, icon('lock'), 'Réseaux et chiffrement'), h('div', { class: 'grow' }), r.enabled ? h('span', { class: 'chip ok' }, icon('check'), 'Wi-Fi allumé') : h('span', { class: 'chip' }, 'Wi-Fi éteint')),
          r.bss.length ? h('div', { class: 'table-wrap' }, h('table', null, h('thead', null, h('tr', null, ['Nom (SSID)', 'Sécurité', 'Appareils', 'Options'].map(t => h('th', null, t)))),
            h('tbody', null, r.bss.map(b => h('tr', null, h('td', null, h('b', null, b.ssid || '—'), h('div', { class: 'faint' }, `Bande ${['2,4 GHz', '5 GHz', '6 GHz'][b.phy] || b.phy}`)),
              h('td', null, b.security.level === 'ok' ? h('span', { class: 'chip ok' }, icon('check'), b.security.label) : sevChip(b.security.level === 'inconnu' ? 'info' : b.security.level), h('div', { class: 'faint mono', style: { fontSize: '11.5px' } }, b.encryption)),
              h('td', { class: 'num' }, String(b.stations)), h('td', null, h('div', { class: 'chips' }, b.hidden ? h('span', { class: 'chip' }, 'Masqué') : null, b.wps ? h('span', { class: 'chip warn' }, 'WPS actif') : null))))))) : emptyBox('Aucun réseau lu.'),
          h('p', { class: 'faint', style: { fontSize: '12.5px', marginBottom: 0 } }, 'La clé Wi-Fi n\'est jamais transmise à cette page.')),
        h('div', { class: 'card', style: { marginTop: '18px' } }, h('div', { class: 'card-head' }, h('h2', null, icon('ban'), 'Filtrage par adresse MAC'), h('p', { class: 'grow' }, 'Refuser ou n\'accepter que certains appareils sur le Wi-Fi')),
          h('label', { class: 'field', style: { maxWidth: '440px' } }, 'Mode de filtrage', modeSel),
          h('div', { style: { margin: '14px 0' } }, noteBox('Limite : un appareil peut changer d\'adresse MAC (adresses privées des smartphones, ou usurpation). Le filtrage MAC complète un bon mot de passe WPA2/WPA3 sans le remplacer.')),
          addForm,
          r.filters.length ? h('div', { class: 'table-wrap', style: { marginTop: '14px' } }, h('table', null, h('thead', null, h('tr', null, ['Appareil', 'MAC', 'Liste', 'Note', ''].map(t => h('th', null, t)))),
            h('tbody', null, r.filters.map(f => h('tr', null, h('td', null, f.hostname || '—'), h('td', { class: 'mono' }, f.mac),
              h('td', null, f.type === 'blacklist' ? h('span', { class: 'chip crit' }, 'Refusé') : h('span', { class: 'chip ok' }, 'Autorisé')), h('td', { class: 'faint' }, f.comment),
              h('td', null, h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Retirer', onclick: async () => { try { await api.post('wifi_filter', { op: 'delete', id: f.id }); toast('Retiré du filtrage'); load(); } catch (e) { fail(e); } } }, icon('trash', 'sm')))))))) : h('p', { class: 'faint' }, 'Aucune adresse dans les listes.')));
    };
    await load();
  };

  /* ---------- Ma Freebox ---------- */
  function updatesCard(u, title = 'Mises à jour') {
    return h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('refresh'), title)),
      u.current ? h('dl', { class: 'kv' }, h('dt', null, 'Version installée'), h('dd', null, h('b', null, u.current)),
        h('dt', null, 'Dernière version annoncée'), h('dd', null, u.latest_seen || 'aucune repérée'),
        h('dt', null, 'État'), h('dd', null, u.behind ? h('span', { class: 'chip warn' }, icon('refresh'), 'Mise à jour en cours de déploiement') : u.latest_seen ? h('span', { class: 'chip ok' }, icon('check'), 'À jour') : '—')) : null,
      h('div', { style: { margin: '12px 0' } }, noteBox('Free ne publie pas de calendrier : la box se met à jour seule, en général la nuit. Sentinelle repère les versions annoncées dans l\'actualité et les compare à la vôtre.')),
      u.box && u.box.length ? h('div', { class: 'stack', style: { gap: '8px' } }, u.box.map(i => h('a', { class: 'alert-mini', href: i.link, target: '_blank', rel: 'noopener noreferrer' },
        h('span', { class: 'sev-ic faible' }, icon('news')), h('div', null, h('b', null, i.title), h('span', null, `${i.source} · ${fmt.ago(i.date)}${i.versions.length ? ' · v' + i.versions.join(', ') : ''}`)))))
        : emptyBox('Aucune annonce de mise à jour repérée dans l\'actualité récente.'));
  }
  VIEWS.box = async root => {
    let r;
    try { r = await api.get('box'); setLive('on'); } catch (e) { put(root, errorBox(e)); return; }
    const c = r.connection;
    const updSlot = h('div', { style: { marginTop: '18px' } }, h('div', { class: 'skeleton' }));
    const reboot = h('button', { class: 'btn danger', type: 'button' }, icon('power'), 'Redémarrer la Freebox');
    reboot.addEventListener('click', async () => {
      const ok = await confirmBox({ title: 'Redémarrer la Freebox ?', danger: true, password: true, ok: 'Redémarrer',
        lead: 'Internet, le Wi-Fi, la TV et le téléphone seront coupés 2 à 4 minutes. Sentinelle aussi perdra le contact.' });
      if (!ok) return;
      try { await api.post('box_reboot', { password: ok.password }); toast('Redémarrage demandé', 'ok', 'La box revient dans quelques minutes.'); } catch (e) { fail(e); }
    });
    put(root,
      h('div', { class: 'grid g-3' },
        h('div', { class: 'tile' }, h('div', { class: 'lab' }, icon('router', 'sm'), 'Modèle'), h('div', { class: 'val', style: { fontSize: '22px' } }, r.model || 'Freebox'), h('div', { class: 'note' }, `Freebox OS ${r.firmware || '?'}`)),
        h('div', { class: 'tile' }, h('div', { class: 'lab' }, icon('clock', 'sm'), 'Allumée depuis'), h('div', { class: 'val', style: { fontSize: '22px' } }, fmt.dur(r.uptime)), h('div', { class: 'note' }, 'sans redémarrage')),
        h('div', { class: 'tile' }, h('div', { class: 'lab' }, icon('globe', 'sm'), 'Connexion'), h('div', { class: 'val', style: { fontSize: '22px' } }, c.state === 'up' ? 'En ligne' : c.state || '—'),
          h('div', { class: 'note' }, `${(c.media || '').toUpperCase()} · ${c.bw_down ? '↓ ' + fmt.bits(c.bw_down) : ''}${c.bw_up ? ' · ↑ ' + fmt.bits(c.bw_up) : ''}`))),
      r.errors.length ? h('div', { style: { marginTop: '16px' } }, errorBox({ message: 'Lecture partielle de la configuration.', hint: r.errors.join(' · ') })) : null,
      h('div', { class: 'grid g-2', style: { marginTop: '18px' } },
        h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('info'), 'Configuration')),
          h('dl', { class: 'kv' },
            h('dt', null, 'Adresse IPv4 publique'), h('dd', { class: 'mono' }, c.ipv4 || '—'),
            h('dt', null, 'Adresse IPv6'), h('dd', { class: 'mono' }, c.ipv6 || '—'),
            h('dt', null, 'Données échangées'), h('dd', null, `↓ ${fmt.bytes(c.bytes_down)} · ↑ ${fmt.bytes(c.bytes_up)} (depuis la dernière connexion)`),
            r.ftth ? [h('dt', null, 'Fibre (module optique)'), h('dd', null, `${r.ftth.link ? 'Signal présent' : 'Pas de signal'}${r.ftth.rx_dbm !== null ? ` · réception ${nf1.format(r.ftth.rx_dbm)} dBm` : ''}${r.ftth.tx_dbm !== null ? ` · émission ${nf1.format(r.ftth.tx_dbm)} dBm` : ''}`)] : null,
            r.xdsl ? [h('dt', null, 'Ligne ADSL/VDSL'), h('dd', null, `${r.xdsl.modulation} · ${r.xdsl.status}`)] : null,
            h('dt', null, 'Réseau local'), h('dd', null, `${r.lan.ip} · mode ${r.lan.mode === 'router' ? 'routeur' : r.lan.mode === 'bridge' ? 'bridge' : r.lan.mode}`),
            h('dt', null, 'DHCP'), h('dd', null, r.dhcp.enabled ? `${r.dhcp.range} · DNS : ${r.dhcp.dns.join(', ') || 'Freebox'}` : 'Désactivé'),
            h('dt', null, 'Baux fixes'), h('dd', null, r.dhcp.static.length ? r.dhcp.static.map(l => `${l.name || l.mac} → ${l.ip}`).join(' · ') : 'Aucun'),
            h('dt', null, 'Numéro de série'), h('dd', { class: 'mono' }, r.serial || '—'),
            h('dt', null, 'Adresse MAC'), h('dd', { class: 'mono' }, r.mac || '—'),
            h('dt', null, 'Liaison Sentinelle'), h('dd', null, r.link.encrypted ? h('span', { class: 'chip ok' }, icon('lock'), 'Chiffrée (HTTPS)') : h('span', { class: 'chip warn' }, icon('unlock'), 'Non chiffrée'))),
          h('div', { class: 'btns', style: { marginTop: '16px' } }, reboot)),
        h('div', { class: 'stack' },
          h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('thermo'), 'Températures et ventilation')),
            r.sensors.length || r.fans.length ? h('div', { class: 'hbars' }, r.sensors.map(x => h('div', { class: 'hbar' }, h('span', { class: 'name' }, x.name),
              h('div', { class: 'track' }, h('i', { class: 'd', style: { width: Math.min(100, x.value) + '%' } })), h('b', null, nf0.format(x.value) + ' °C'))),
              r.fans.map(x => h('div', { class: 'hbar' }, h('span', { class: 'name' }, x.name), h('span'), h('b', null, nf0.format(x.value) + ' tr/min')))) : emptyBox('Capteurs non disponibles.')),
          h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('ethernet'), 'Prises Ethernet')),
            h('div', { class: 'stack', style: { gap: '8px' } }, r.ports.map(p => h('div', { class: 'alert-mini' }, h('span', { class: 'sev-ic ' + (p.link === 'up' ? 'faible' : 'info') }, icon('ethernet')),
              h('div', null, h('b', null, `${p.name || 'Port ' + p.id} · ${p.link === 'up' ? (p.speed ? p.speed + ' Mb/s' : 'connecté') : 'libre'}`), h('span', null, p.devices.join(', ') || '—')))))))),
      updSlot);
    api.get('updates').then(u => put(updSlot, updatesCard(u, 'Mises à jour de la Freebox'))).catch(e => put(updSlot, errorBox(e)));
  };

  /* ---------- Player TV ---------- */
  VIEWS.player = async root => {
    const newsSlot = h('div', null, h('div', { class: 'skeleton', style: { marginTop: '12px', height: '80px' } }));
    const load = async () => {
      let r;
      try { r = await api.get('player'); setLive('on'); } catch (e) { put(root, errorBox(e)); return; }
      put(root,
        r.errors.length && !r.players.length ? errorBox({ message: 'Player inaccessible.', hint: r.errors.join(' · ') }) : null,
        r.players.length ? h('div', { class: 'grid g-2' }, r.players.map(p => h('div', { class: 'card glow' },
          h('div', { class: 'drawer-head' }, h('span', { class: 'avatar lg t-freebox_player' + (p.reachable ? '' : ' off') }, icon('stb'), h('span', { class: 'state' })),
            h('div', { class: 'grow' }, h('h2', null, p.name), h('div', { class: 'muted' }, p.model || 'Player Freebox'))),
          h('div', { class: 'chips', style: { marginBottom: '14px' } },
            p.power === 'running' ? h('span', { class: 'chip ok' }, icon('play'), 'Allumé') : p.power === 'standby' ? h('span', { class: 'chip' }, icon('power'), 'En veille') : h('span', { class: 'chip' }, p.reachable ? 'État inconnu' : 'Injoignable'),
            p.playback ? h('span', { class: 'chip low' }, p.playback === 'PLAYING' ? 'Lecture' : p.playback === 'PAUSED' ? 'En pause' : p.playback) : null),
          p.power === 'running' && (p.channel || p.app) ? h('div', { class: 'tile', style: { marginBottom: '14px' } }, h('div', { class: 'lab' }, icon('eye', 'sm'), 'En ce moment'),
            h('div', { class: 'val', style: { fontSize: '22px' } }, p.channel ? `${p.channel.number ? p.channel.number + ' · ' : ''}${p.channel.name}` : p.app.name),
            h('div', { class: 'note' }, p.channel ? (p.channel.bouquet || 'Télévision') : `Application : ${p.app.package}`)) : null,
          h('dl', { class: 'kv' },
            h('dt', null, 'Volume'), h('dd', null, p.volume ? `${p.volume.level} %${p.volume.mute ? ' (muet)' : ''}` : '—'),
            h('dt', null, 'Adresse IP'), h('dd', { class: 'mono' }, p.host ? p.host.ip : '—'),
            h('dt', null, 'Connexion'), h('dd', null, p.host ? (p.host.conn.kind === 'ethernet' ? `Ethernet (${p.host.conn.port_name || 'port ' + p.host.conn.port})` : p.host.conn.kind === 'wifi' ? `Wi-Fi ${p.host.conn.band}` : '—') : '—'),
            h('dt', null, 'Débit'), h('dd', null, p.host ? `↓ ${fmt.rate(p.host.rate.down)} · ↑ ${fmt.rate(p.host.rate.up)}` : '—'),
            h('dt', null, 'Adresse MAC'), h('dd', { class: 'mono' }, p.mac || '—'),
            h('dt', null, 'API du Player'), h('dd', null, p.api_available ? `disponible (v${p.api_version})` : 'indisponible'),
            h('dt', null, 'Plateforme'), h('dd', null, p.stb_type || '—'))))) : emptyBox('Aucun Player détecté. Vérifiez qu\'il est allumé et que le droit « Contrôle du Freebox Player » est accordé à Sentinelle.', 'tv'),
        h('div', { class: 'card', style: { marginTop: '18px' } }, h('div', { class: 'card-head' }, h('h2', null, icon('news'), 'Actualités et mises à jour du Player')),
          noteBox('Le Player se met à jour automatiquement (Android TV et logiciel Free). Voici les dernières nouvelles le concernant.'), newsSlot));
      if (!newsSlot.dataset.done) {
        newsSlot.dataset.done = '1';
        api.get('updates').then(u => put(newsSlot, u.player.length ? h('div', { class: 'stack', style: { gap: '8px', marginTop: '12px' } }, u.player.map(i => h('a', { class: 'alert-mini', href: i.link, target: '_blank', rel: 'noopener noreferrer' },
          h('span', { class: 'sev-ic faible' }, icon('tv')), h('div', null, h('b', null, i.title), h('span', null, `${i.source} · ${fmt.ago(i.date)}`))))) : emptyBox('Aucune actualité récente sur le Player.')))
          .catch(e => put(newsSlot, errorBox(e)));
      }
    };
    await load();
    poll(load, 15);
  };

  /* ---------- Actualités ---------- */
  VIEWS.news = async root => {
    const TAGS = [['all', 'Tout'], ['maj', 'Mises à jour'], ['freebox', 'Freebox'], ['player', 'Player TV'], ['tv', 'TV & streaming'], ['mobile', 'Free Mobile'], ['securite', 'Sécurité']];
    let data = null;
    const grid = h('div', { class: 'news' });
    const srcInfo = h('div');
    const seg = h('div', { class: 'seg', role: 'group', 'aria-label': 'Thème' });
    const draw = () => {
      if (!data) return;
      const items = data.items.filter(i => state.newsTag === 'all' || i.tags.includes(state.newsTag));
      put(grid, items.length ? items.map(i => {
        const img = h('div', { class: 'img' }, icon('news'));
        if (i.image) {
          const im = h('img', { src: i.image, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', decoding: 'async' });
          im.addEventListener('error', () => im.remove());
          img.append(im);
        }
        return h('a', { class: 'article', href: i.link, target: '_blank', rel: 'noopener noreferrer' }, img,
          h('div', { class: 'body' },
            h('div', { class: 'meta' }, h('span', { class: 'chip grad' }, i.source), h('span', null, fmt.ago(i.date)), i.tags.includes('maj') ? h('span', { class: 'chip warn' }, icon('refresh'), 'Mise à jour') : null,
              i.tags.includes('securite') ? h('span', { class: 'chip crit' }, icon('shield'), 'Sécurité') : null),
            h('h3', null, i.title), i.excerpt ? h('p', null, i.excerpt) : null));
      }) : emptyBox('Aucun article dans cette rubrique.'));
    };
    for (const [k, l] of TAGS) {
      const b = h('button', { type: 'button', 'aria-pressed': String(state.newsTag === k) }, l);
      b.addEventListener('click', () => { state.newsTag = k; for (const x of seg.children) x.setAttribute('aria-pressed', String(x === b)); draw(); });
      seg.append(b);
    }
    const refreshBtn = h('button', { class: 'btn sm', type: 'button' }, icon('refresh', 'sm'), 'Actualiser');
    const load = async refresh => {
      grid.classList.add('refetch');
      try {
        data = await api.get('news', refresh ? { refresh: 1 } : {});
        setLive('on');
        put(srcInfo, h('p', { class: 'faint', style: { fontSize: '12.5px' } }, 'Sources : ', data.sources.map((x, i) => [i ? ' · ' : '', x.ok ? `${x.name} (${x.count})` : `${x.name} — indisponible : ${x.error}`]),
          ` · mis à jour ${fmt.ago(data.ts)}. Les liens s'ouvrent sur les sites d'origine.`));
        draw();
      } catch (e) { put(grid, errorBox(e)); } finally { grid.classList.remove('refetch'); }
    };
    refreshBtn.addEventListener('click', () => busy(refreshBtn, () => load(true)));
    put(root, h('div', { class: 'filters' }, seg, refreshBtn), srcInfo, grid);
    await load(false);
  };

  /* ---------- Journal ---------- */
  VIEWS.journal = async root => {
    let r;
    try { r = await api.get('journal'); setLive('on'); } catch (e) { put(root, errorBox(e)); return; }
    const KIND = { new: ['Nouvel appareil', 'warn'], online: ['Connexion', 'ok'], offline: ['Déconnexion', ''], block: ['Coupure', 'crit'], allow: ['Rétablissement', 'ok'], isolate: ['Isolement', 'crit'],
      release: ['Fin d\'isolement', 'ok'], schedule: ['Horaire', 'low'], approve: ['Inventaire', 'low'], wol: ['Réveil', 'low'], profile: ['Profil', 'low'], inventory: ['Inventaire', 'low'], notify: ['Notification', 'warn'] };
    const panel = h('div');
    const seg = h('div', { class: 'seg', role: 'group', 'aria-label': 'Journal' });
    const show = tab => {
      state.journalTab = tab;
      for (const x of seg.children) x.setAttribute('aria-pressed', String(x.dataset.k === tab));
      if (tab === 'events') put(panel, r.events.length ? h('div', { class: 'card' }, h('div', { class: 'table-wrap' }, h('table', null,
        h('thead', null, h('tr', null, h('th', null, 'Quand'), h('th', null, 'Type'), h('th', null, 'Événement'))),
        h('tbody', null, r.events.map(e => h('tr', null, h('td', { class: 'faint nowrap' }, fmt.datetime(e.ts)),
          h('td', null, h('span', { class: 'chip ' + ((KIND[e.kind] || [])[1] || '') }, (KIND[e.kind] || [e.kind])[0])), h('td', null, e.text))))))) : emptyBox('Aucun événement pour l\'instant.'));
      else put(panel, r.audit.length ? h('div', { class: 'card' }, h('div', { class: 'table-wrap' }, h('table', null,
        h('thead', null, h('tr', null, h('th', null, 'Quand'), h('th', null, 'Depuis'), h('th', null, 'Action'), h('th', null, 'Cible'), h('th', null, 'Résultat'))),
        h('tbody', null, r.audit.map(a => h('tr', null, h('td', { class: 'faint nowrap' }, fmt.datetime(a.ts)), h('td', { class: 'mono' }, a.ip), h('td', null, a.action), h('td', { class: 'mono' }, a.target),
          h('td', null, a.ok ? h('span', { class: 'chip ok' }, icon('check'), 'OK') : h('span', { class: 'chip crit' }, icon('x'), 'Échec'), a.detail ? h('div', { class: 'faint', style: { fontSize: '12px', maxWidth: '360px' } }, a.detail) : null))))))) : emptyBox('Aucune action enregistrée.'));
    };
    for (const [k, l] of [['events', 'Réseau'], ['audit', 'Actions et connexions (audit)']]) seg.append(h('button', { type: 'button', 'data-k': k, onclick: () => show(k) }, l));
    put(root, h('div', { class: 'filters' }, seg), panel);
    show(state.journalTab);
  };

  /* ---------- Paramètres ---------- */
  async function pairFlow(after) {
    const steps = h('div', { class: 'steps' });
    const status = h('div');
    const closeBtn = h('button', { class: 'btn ghost', type: 'button' }, 'Fermer');
    const m = modal(h('div', null, h('h2', null, 'Associer ma Freebox'),
      h('p', { class: 'lead' }, 'Sentinelle va demander un accès à votre Freebox. La demande doit être validée sur la box elle-même : personne ne peut le faire à distance.'),
      steps, status, h('div', { class: 'actions' }, closeBtn)), { label: 'Association Freebox' });
    let stop = false;
    closeBtn.addEventListener('click', () => m.close());
    m.onclose = () => { stop = true; after && after(); };
    const step = (title, text, done = false, extra = null) => h('div', { class: 'step' + (done ? ' done' : '') }, h('div', null, h('b', null, title), h('p', null, text), extra));
    put(steps, step('Demande envoyée…', 'Connexion à la Freebox.'));
    let r;
    try { r = await api.post('freebox_pair_start'); } catch (e) { put(status, errorBox(e)); return; }
    put(steps,
      step('Demande envoyée', `Application « Sentinelle XDR » sur « ${r.device_name} ».`, true),
      step('Validez sur la Freebox', 'Allez devant la Freebox (le boîtier serveur) : un message « Sentinelle XDR » s\'affiche sur son écran. Appuyez sur la flèche droite (›) pour accepter. Vous avez environ 2 minutes.', false, h('div', { class: 'btns', style: { marginTop: '8px' } }, h('span', { class: 'spinner' }), h('span', { class: 'muted' }, 'En attente de votre validation…'))));
    const t0 = Date.now();
    while (!stop && Date.now() - t0 < 150000) {
      await new Promise(res => setTimeout(res, 2000));
      let st;
      try { st = await api.get('freebox_pair_status'); } catch (e) { put(status, errorBox(e)); return; }
      if (st.status === 'pending') continue;
      if (st.status === 'granted') {
        put(steps, step('Demande envoyée', 'OK', true), step('Validé sur la Freebox', 'Sentinelle est associée.', true),
          step('Accordez les droits (obligatoire)', 'Ouvrez Freebox OS (http://mafreebox.freebox.fr) › Paramètres de la Freebox › Gestion des accès › onglet Applications › « Sentinelle XDR » › crayon. Cochez : « Modification des réglages de la Freebox », « Accès au contrôle parental », « Gestion des profils utilisateur » et « Contrôle du Freebox Player ». Validez.'),
          step('Fermez le robinet', 'Dans Gestion des accès › Paramètres, désactivez ensuite les « nouvelles demandes d\'association » : plus aucune application ne pourra demander l\'accès.'));
        put(status, h('div', { style: { marginTop: '14px' } }, noteBox(h('b', null, 'Terminé. '), 'Rouvrez Paramètres pour vérifier les droits : chaque droit accordé y apparaît coché.')));
        toast('Freebox associée', 'ok');
        return;
      }
      put(status, errorBox({ message: st.status === 'denied' ? 'Demande refusée sur la Freebox.' : st.status === 'timeout' ? 'Délai dépassé : relancez l\'association.' : 'Association impossible (' + st.status + ').' }));
      return;
    }
  }
  VIEWS.settings = async root => {
    let r;
    try { r = await api.get('settings'); setLive('on'); } catch (e) { put(root, errorBox(e)); return; }
    const st = r.settings;
    const save = async (patch, msg = 'Réglages enregistrés') => {
      try { const res = await api.post('settings_save', { settings: patch }); toast(msg); return res; } catch (e) { fail(e); return null; }
    };

    /* Freebox */
    const modeSel = h('select', { class: 'input' }, [['auto', 'Automatique (démo tant qu\'aucune box n\'est associée)'], ['freebox', 'Ma Freebox'], ['demo', 'Démonstration']].map(([k, l]) => h('option', { value: k, selected: st.mode === k }, l)));
    const urlIn = h('input', { class: 'input mono', value: st.freebox_url });
    const fallback = h('input', { type: 'checkbox', checked: st.freebox_http_fallback });
    const fb = r.freebox;
    const perms = r.permissions;
    const permList = h('div', { class: 'stack', style: { gap: '8px' } }, Object.entries(r.permission_labels).map(([k, label]) => {
      const ok = perms ? !!perms[k] : null;
      return h('div', { class: 'note-box' }, icon(ok ? 'check' : ok === false ? 'x' : 'info'), h('div', null, h('b', null, label), ok === false ? h('div', null, 'Non accordé : cochez-le dans Freebox OS › Gestion des accès › Applications.') : null));
    }));
    const freeboxCard = h('div', { class: 'card' },
      h('div', { class: 'card-head' }, h('h2', null, icon('router'), 'Freebox'), h('div', { class: 'grow' }),
        fb.paired ? h('span', { class: 'chip ok' }, icon('check'), 'Associée') : h('span', { class: 'chip warn' }, icon('info'), 'Non associée')),
      h('div', { class: 'form-grid' }, h('label', { class: 'field' }, 'Source des données', modeSel), h('label', { class: 'field' }, 'Adresse de la Freebox', urlIn)),
      h('label', { class: 'check', style: { marginTop: '12px' } }, h('span', { class: 'switch' }, fallback, h('span')), 'Si le HTTPS échoue, essayer en HTTP sur le réseau local'),
      h('div', { class: 'btns', style: { marginTop: '14px' } },
        h('button', { class: 'btn', type: 'button', onclick: async () => { const res = await save({ mode: modeSel.value, freebox_url: urlIn.value, freebox_http_fallback: fallback.checked }); if (res) location.reload(); } }, icon('check'), 'Enregistrer'),
        h('button', { class: 'btn primary', type: 'button', onclick: () => pairFlow(() => VIEWS.settings(root)) }, icon('key'), fb.paired ? 'Réassocier' : 'Associer ma Freebox'),
        fb.paired ? h('button', { class: 'btn danger', type: 'button', onclick: async () => {
          const ok = await confirmBox({ title: 'Dissocier la Freebox ?', danger: true, password: true, ok: 'Dissocier', lead: 'Sentinelle oubliera sa clé d\'accès. Pensez aussi à révoquer l\'application dans Freebox OS › Gestion des accès.' });
          if (!ok) return;
          try { await api.post('freebox_unpair', { password: ok.password }); location.reload(); } catch (e) { fail(e); }
        } }, icon('x'), 'Dissocier') : null),
      fb.paired ? h('dl', { class: 'kv', style: { marginTop: '16px' } },
        h('dt', null, 'Associée le'), h('dd', null, fb.paired_at ? fmt.datetime(fb.paired_at) : '—'),
        h('dt', null, 'Adresse utilisée'), h('dd', { class: 'mono' }, fb.base || '—'),
        h('dt', null, 'Chiffrement'), h('dd', null, fb.encrypted ? 'HTTPS (certificat Freebox vérifié)' : 'HTTP local'),
        h('dt', null, 'Version de l\'API'), h('dd', null, fb.api ? `${fb.api.api_version} — ${fb.api.box_model || fb.api.device_name}` : '—')) : null,
      h('div', { class: 'section-title' }, 'Droits accordés à Sentinelle'),
      r.permissions_error ? errorBox({ message: r.permissions_error }) : permList);

    /* Sécurité */
    const cur = pwField({ autocomplete: 'current-password' }), nxt = pwField({ autocomplete: 'new-password', minlength: 10 }), nxt2 = pwField({ autocomplete: 'new-password' });
    const pwForm = h('form', { class: 'stack' }, h('div', { class: 'form-grid' }, h('label', { class: 'field' }, 'Mot de passe actuel', cur.wrap), h('label', { class: 'field' }, 'Nouveau (10 caractères min.)', nxt.wrap), h('label', { class: 'field' }, 'Confirmation', nxt2.wrap)),
      h('div', null, h('button', { class: 'btn', type: 'submit' }, icon('key'), 'Changer le mot de passe')));
    pwForm.addEventListener('submit', async e => {
      e.preventDefault();
      if (nxt.input.value !== nxt2.input.value) { toast('Les deux saisies ne correspondent pas', 'err'); return; }
      try { await api.post('password_change', { current: cur.input.value, next: nxt.input.value }); toast('Mot de passe changé', 'ok', 'Les autres sessions sont déconnectées.'); pwForm.reset(); } catch (err) { fail(err); }
    });
    const totpBox = h('div');
    const drawTotp = enabled => put(totpBox, enabled
      ? h('div', { class: 'btns' }, h('span', { class: 'chip ok' }, icon('check'), 'Double authentification active'),
        h('button', { class: 'btn danger sm', type: 'button', onclick: async () => {
          const ok = await confirmBox({ title: 'Désactiver la double authentification ?', danger: true, password: true, ok: 'Désactiver' });
          if (!ok) return;
          try { await api.post('totp_disable', { password: ok.password }); toast('Double authentification désactivée'); drawTotp(false); } catch (e) { fail(e); }
        } }, 'Désactiver'))
      : h('button', { class: 'btn', type: 'button', onclick: async () => {
        let t;
        try { t = await api.post('totp_begin'); } catch (e) { fail(e); return; }
        const code = h('input', { class: 'input', inputmode: 'numeric', maxlength: 7, placeholder: '123456', required: true });
        const pw = pwField({ required: true });
        const form = h('form', null, h('h2', null, 'Double authentification'),
          h('p', { class: 'lead' }, 'Dans Google Authenticator, Microsoft Authenticator ou équivalent : « Ajouter un compte » › « Saisir une clé de configuration », puis entrez cette clé (type « basé sur le temps »).'),
          h('div', { class: 'tile', style: { textAlign: 'center' } }, h('div', { class: 'lab', style: { justifyContent: 'center' } }, 'Clé de configuration'), h('div', { class: 'val mono', style: { fontSize: '18px', overflowWrap: 'anywhere' } }, t.secret)),
          h('div', { class: 'stack', style: { marginTop: '14px' } }, h('label', { class: 'field' }, 'Code affiché par l\'application', code), h('label', { class: 'field' }, 'Mot de passe Sentinelle', pw.wrap)),
          h('div', { class: 'actions' }, h('button', { class: 'btn ghost', type: 'button', onclick: () => mm.close() }, 'Annuler'), h('button', { class: 'btn primary', type: 'submit' }, 'Activer')));
        const mm = modal(form, { label: 'Double authentification' });
        form.addEventListener('submit', async e => {
          e.preventDefault();
          try {
            const res = await api.post('totp_enable', { code: code.value, password: pw.input.value });
            put(form, h('h2', null, 'Codes de secours'), h('p', { class: 'lead' }, 'Notez-les et rangez-les en lieu sûr : chacun permet UNE connexion si vous perdez votre téléphone. Ils ne seront plus affichés.'),
              h('div', { class: 'tile mono', style: { fontSize: '16px', lineHeight: '1.9' } }, res.recovery.map(c => h('div', null, c))),
              h('div', { class: 'actions' }, h('button', { class: 'btn primary', type: 'button', onclick: () => { mm.close(); drawTotp(true); } }, 'C\'est noté')));
          } catch (err) { fail(err); }
        });
      } }, icon('lock'), 'Activer la double authentification'));
    drawTotp(r.totp);
    const securityCard = h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('lock'), 'Sécurité de la console')), pwForm,
      h('div', { class: 'section-title' }, 'Double authentification'), totpBox,
      h('div', { style: { marginTop: '16px' } }, noteBox('Sentinelle n\'accepte que les connexions du réseau local. N\'ouvrez jamais de redirection de port vers elle : depuis l\'extérieur, utilisez le VPN WireGuard de la Freebox.')));

    /* Détection */
    const spike = h('input', { class: 'input', type: 'number', min: 1, max: 1000, step: 1, value: st.upload_spike_mbps });
    const factor = h('input', { class: 'input', type: 'number', min: 2, max: 50, step: 1, value: st.upload_spike_factor });
    const pollIn = h('input', { class: 'input', type: 'number', min: 3, max: 60, value: st.poll_seconds });
    const retention = h('input', { class: 'input', type: 'number', min: 2, max: 365, value: st.retention_days });
    const detectCard = h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('shield'), 'Détection')),
      h('div', { class: 'form-grid' }, h('label', { class: 'field' }, 'Envoi suspect au-delà de (Mb/s)', spike), h('label', { class: 'field' }, '… et N fois l\'habitude de l\'appareil', factor),
        h('label', { class: 'field' }, 'Rafraîchissement du direct (s)', pollIn), h('label', { class: 'field' }, 'Conservation de l\'historique (jours)', retention)),
      h('div', { class: 'btns', style: { marginTop: '14px' } }, h('button', { class: 'btn', type: 'button', onclick: () => save({ upload_spike_mbps: Number(spike.value), upload_spike_factor: Number(factor.value), poll_seconds: Number(pollIn.value), retention_days: Number(retention.value) }) }, icon('check'), 'Enregistrer')));

    /* DNS */
    const ag = st.adguard;
    const agOn = h('input', { type: 'checkbox', checked: ag.enabled });
    const agUrl = h('input', { class: 'input mono', value: ag.url, placeholder: 'http://192.168.1.51:3000' });
    const agUser = h('input', { class: 'input', value: ag.user, autocomplete: 'off' });
    const agPass = pwField({ value: ag.pass, autocomplete: 'new-password' });
    const dnsVis = h('input', { type: 'checkbox', checked: st.dns_visibility });
    const dnsCard = h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('dns'), 'Navigation (DNS) avec AdGuard Home')),
      noteBox(h('b', null, 'Pourquoi ? '), 'La Freebox ne dit pas quels sites sont visités. AdGuard Home (gratuit, installable sur ce PC Windows) note les noms de domaine demandés par chaque appareil, bloque publicités et sites malveillants, et permet à Sentinelle de couper le DNS d\'un appareil isolé. Ensuite, indiquez l\'IP de ce PC comme serveur DNS dans Freebox OS › Paramètres › DHCP.'),
      h('label', { class: 'check', style: { margin: '14px 0' } }, h('span', { class: 'switch' }, agOn, h('span')), 'Utiliser AdGuard Home'),
      h('div', { class: 'form-grid' }, h('label', { class: 'field' }, 'Adresse de l\'interface AdGuard Home', agUrl), h('label', { class: 'field' }, 'Identifiant', agUser), h('label', { class: 'field' }, 'Mot de passe', agPass.wrap)),
      h('label', { class: 'check', style: { marginTop: '12px' } }, h('span', { class: 'switch' }, dnsVis, h('span')), 'Afficher l\'activité de navigation dans Sentinelle'),
      h('p', { class: 'faint', style: { fontSize: '12.5px' } }, 'Loi : informez les personnes du foyer. Surveiller à son insu la navigation d\'un adulte, conjoint compris, peut constituer un délit (art. 226-1 et 226-15 du Code pénal ; peines aggravées entre conjoints). Pour un enfant mineur, le contrôle parental est légitime : la CNIL recommande de l\'en informer clairement et de préférer les outils qui restent en local, comme Sentinelle.'),
      h('div', { class: 'btns' },
        h('button', { class: 'btn', type: 'button', onclick: () => save({ dns_visibility: dnsVis.checked, adguard: { enabled: agOn.checked, url: agUrl.value, user: agUser.value, pass: agPass.input.value } }) }, icon('check'), 'Enregistrer'),
        h('button', { class: 'btn ghost', type: 'button', onclick: async () => { try { const t = await api.post('adguard_test'); toast('AdGuard Home répond', 'ok', `Version ${t.version} · protection ${t.protection ? 'active' : 'inactive'}`); } catch (e) { fail(e); } } }, icon('zap'), 'Tester')));

    /* Notifications */
    const n = st.notify;
    const nType = h('select', { class: 'input' }, [['none', 'Aucune'], ['ntfy', 'ntfy (application gratuite)'], ['telegram', 'Telegram'], ['discord', 'Discord'], ['gotify', 'Gotify'], ['webhook', 'Webhook (JSON)']].map(([k, l]) => h('option', { value: k, selected: n.type === k }, l)));
    const nUrl = h('input', { class: 'input mono', value: n.url, placeholder: 'https://ntfy.sh/sentinelle-7k2q9x' });
    const nTok = pwField({ value: n.token, autocomplete: 'off' });
    const nChat = h('input', { class: 'input', value: n.chat_id, placeholder: 'Telegram uniquement' });
    const nMin = h('select', { class: 'input' }, [['critique', 'Critiques seulement'], ['elevee', 'Élevées et critiques'], ['moyenne', 'Moyennes et plus'], ['faible', 'Toutes (sauf info)']].map(([k, l]) => h('option', { value: k, selected: n.min_severity === k }, l)));
    const notifyCard = h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('bell'), 'Notifications sur le téléphone')),
      h('div', { class: 'form-grid' }, h('label', { class: 'field' }, 'Service', nType), h('label', { class: 'field' }, 'Adresse (URL)', nUrl), h('label', { class: 'field' }, 'Jeton', nTok.wrap),
        h('label', { class: 'field' }, 'Identifiant de discussion', nChat), h('label', { class: 'field' }, 'Prévenir pour', nMin)),
      h('p', { class: 'faint', style: { fontSize: '12.5px' } }, 'ntfy.sh : un sujet est lisible par quiconque connaît son nom. Choisissez un nom long et imprévisible, ou hébergez ntfy chez vous.'),
      h('div', { class: 'btns' },
        h('button', { class: 'btn', type: 'button', onclick: () => save({ notify: { type: nType.value, url: nUrl.value, token: nTok.input.value, chat_id: nChat.value, min_severity: nMin.value } }) }, icon('check'), 'Enregistrer'),
        h('button', { class: 'btn ghost', type: 'button', onclick: async () => { try { await api.post('notify_test'); toast('Notification envoyée'); } catch (e) { fail(e); } } }, icon('bell'), 'Envoyer un test')));

    /* Actualités */
    const srcRows = h('div', { class: 'stack', style: { gap: '8px' } });
    const addSrc = (name = '', url = '') => srcRows.append(h('div', { class: 'form-grid', style: { gridTemplateColumns: '1fr 2fr auto' } },
      h('input', { class: 'input', value: name, placeholder: 'Nom', 'aria-label': 'Nom de la source' }), h('input', { class: 'input mono', value: url, placeholder: 'https://…/feed', 'aria-label': 'Adresse du flux' }),
      h('button', { class: 'btn ghost icon', type: 'button', 'aria-label': 'Retirer', onclick: e => e.currentTarget.parentElement.remove() }, icon('trash'))));
    st.news_sources.forEach(x => addSrc(x.name, x.url));
    const newsCard = h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('news'), 'Sources d\'actualités (flux RSS)')), srcRows,
      h('div', { class: 'btns', style: { marginTop: '12px' } }, h('button', { class: 'btn ghost', type: 'button', onclick: () => addSrc() }, icon('plus'), 'Ajouter une source'),
        h('button', { class: 'btn', type: 'button', onclick: () => save({ news_sources: [...srcRows.children].map(row => ({ name: row.children[0].value, url: row.children[1].value })).filter(x => x.name && x.url) }) }, icon('check'), 'Enregistrer')));

    /* Surveillance continue */
    const cronUrl = `http://127.0.0.1${location.protocol === 'http:' && location.port ? ':' + location.port : ''}${r.cron.path}?action=cron&key=${r.cron.key}`;
    const cronCard = h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('clock'), 'Surveillance continue'), h('div', { class: 'grow' }),
      r.cron.last > Date.now() / 1000 - 900 ? h('span', { class: 'chip ok' }, icon('check'), 'Active (' + fmt.ago(r.cron.last) + ')') : h('span', { class: 'chip warn' }, icon('alert'), 'Inactive')),
      h('p', { class: 'muted', style: { marginTop: 0, fontSize: '13.5px' } }, 'Une tâche planifiée Windows appelle Sentinelle chaque minute : inventaire, détection, horaires et notifications tournent même page fermée. Le plus simple : lancez tools\\Installer-XDR.cmd en administrateur. Sinon, créez la tâche avec cette commande (invite de commandes administrateur) :'),
      h('div', { class: 'tile mono', style: { fontSize: '12px', overflowWrap: 'anywhere' } },
        `schtasks /Create /TN "Sentinelle XDR" /SC MINUTE /MO 1 /RU SYSTEM /F /TR "curl.exe -s -m 50 -o NUL \\"${cronUrl}\\""`),
      h('div', { class: 'btns', style: { marginTop: '12px' } }, h('button', { class: 'btn ghost sm', type: 'button', onclick: async () => {
        const ok = await confirmBox({ title: 'Changer la clé de la tâche planifiée ?', lead: 'L\'ancienne tâche cessera de fonctionner : il faudra la recréer avec la nouvelle commande.', ok: 'Changer' });
        if (!ok) return;
        try { await api.post('cron_key_regen'); VIEWS.settings(root); } catch (e) { fail(e); }
      } }, icon('refresh', 'sm'), 'Nouvelle clé')));

    /* Diagnostic */
    const diag = r.diag;
    const diagCard = h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h2', null, icon('info'), 'Diagnostic de l\'installation'), h('p', { class: 'grow' }, `${diag.server || 'Serveur web'} · ${diag.os} · votre adresse : ${diag.client_ip}`)),
      h('div', { class: 'stack', style: { gap: '8px' } }, diag.checks.map(c => h('div', { class: 'note-box' }, icon(c.ok ? 'check' : c.warn ? 'info' : 'alert'),
        h('div', null, h('b', null, c.label), c.ok ? null : h('div', null, c.fix))))),
      h('p', { class: 'faint', style: { fontSize: '12.5px' } }, 'Données : ', h('span', { class: 'mono' }, diag.data_dir)),
      r.mode === 'demo' ? h('div', { class: 'btns' }, h('button', { class: 'btn ghost', type: 'button', onclick: async () => {
        const ok = await confirmBox({ title: 'Réinitialiser la démonstration ?', lead: 'Les appareils simulés, alertes et historiques de démonstration repartent de zéro.', ok: 'Réinitialiser' });
        if (!ok) return;
        try { await api.post('demo_reset'); state.live = []; toast('Démonstration réinitialisée'); location.hash = '#/dashboard'; } catch (e) { fail(e); }
      } }, icon('refresh'), 'Réinitialiser la démonstration')) : null);

    put(root, h('div', { class: 'grid g-2' }, h('div', { class: 'stack' }, freeboxCard, securityCard, cronCard), h('div', { class: 'stack' }, detectCard, dnsCard, notifyCard, newsCard, diagCard)));
  };

  boot();
})();
