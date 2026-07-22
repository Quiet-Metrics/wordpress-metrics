/*!
 * Quiet Metrics qm.js : tracker d'audience sans cookies.
 * Source de vérité : packages/tracker-js/tracker.js (la copie servie est resynchronisée).
 * Cible : < 2 Ko min+gzip. ES5, aucun build requis.
 *
 * Aucune donnée n'est stockée chez le visiteur (ni cookie, ni localStorage).
 * Spec du payload : docs/05-api-et-sdk.md
 */
(function (win, doc) {
  'use strict';

  var script = doc.currentScript;
  if (!script || win.__qmLoaded) return;
  win.__qmLoaded = true;

  var loc = win.location;
  var nav = win.navigator;

  /* -- Configuration via attributs data-* du <script> ------------------- */

  var siteKey = script.getAttribute('data-site');
  if (!siteKey) return warn('attribut data-site manquant');

  // L'endpoint est déduit de l'origine du script : servi depuis le domaine
  // du client (copie locale ou proxy first-party), la collecte reste
  // first-party elle aussi : les listes de blocage par domaine sont inopérantes.
  var endpoint = script.getAttribute('data-endpoint') ||
    script.src.replace(/\/[^\/]*$/, '/collect');

  var trackSpa = script.getAttribute('data-spa') !== 'false';
  var trackHash = script.getAttribute('data-hash') === 'true';
  var trackOutbound = script.getAttribute('data-outbound') !== 'false';
  // Téléchargements et 404 : opt-in, contrairement aux liens sortants. Les
  // activer par défaut ajouterait des événements au quota de comptes dont le
  // trafic n'a pas bougé : une mise à jour du tracker ne doit pas gonfler une
  // facture. À poser sciemment, et pour le 404 sur le gabarit d'erreur seul.
  var trackDownloads = script.getAttribute('data-downloads') === 'true';
  var track404 = script.getAttribute('data-404') === 'true';
  var devMode = script.getAttribute('data-dev') === 'true';
  var respectDnt = script.getAttribute('data-dnt') === 'respect';
  var excluded = (script.getAttribute('data-exclude') || '')
    .split(',').map(trim).filter(Boolean);

  function trim(s) { return s.replace(/^\s+|\s+$/g, ''); }
  function warn(m) { if (win.console && console.warn) console.warn('[quietmetrics] ' + m); }

  /* -- Garde-fous --------------------------------------------------------- */

  function shouldIgnore() {
    if (win.__qmDisable) return true;                    // kill switch manuel
    if (nav.webdriver) return true;                      // navigateurs pilotés
    if (respectDnt && (nav.doNotTrack === '1' || nav.globalPrivacyControl)) return true;
    if (!devMode && (loc.protocol === 'file:' ||
        /^(localhost$|127\.|0\.0\.0\.0$|192\.168\.|10\.)/.test(loc.hostname))) return true;
    for (var i = 0; i < excluded.length; i++) {
      if (loc.pathname.indexOf(excluded[i]) === 0) return true;   // préfixes
    }
    return false;
  }

  /* -- Envoi ---------------------------------------------------------------
   * Corps JSON en text/plain : pas de préflight CORS, compatible sendBeacon.
   */
  function send(type, name, props) {
    if (shouldIgnore()) return;
    var payload = {
      k: siteKey,
      t: type,                                            // 'pageview' | 'event'
      u: loc.protocol + '//' + loc.host + loc.pathname +
         loc.search + (trackHash ? loc.hash : ''),
      r: doc.referrer || null,
      w: win.innerWidth || null,
      l: (nav.languages && nav.languages[0]) || nav.language || null
    };
    if (name) payload.n = String(name).slice(0, 120);
    if (props) payload.p = props;

    var body = JSON.stringify(payload);
    if (nav.sendBeacon && nav.sendBeacon(endpoint, body)) return;  // survit au unload
    if (win.fetch) {
      fetch(endpoint, {
        method: 'POST', body: body, keepalive: true,
        headers: { 'Content-Type': 'text/plain' }
      })['catch'](function () {});
    } else {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint, true);
      xhr.send(body);
    }
  }

  /* -- Pages vues ----------------------------------------------------------- */

  var lastPath = null;
  function pageview() {
    var path = loc.pathname + loc.search + (trackHash ? loc.hash : '');
    if (path === lastPath) return;                        // double pushState, reload SPA
    lastPath = path;
    send('pageview');
  }

  // SPA : pushState/replaceState + retour navigateur.
  if (trackSpa && win.history && win.history.pushState) {
    var wrap = function (fn) {
      return function () { var r = fn.apply(this, arguments); onNav(); return r; };
    };
    win.history.pushState = wrap(win.history.pushState);
    win.history.replaceState = wrap(win.history.replaceState);
    win.addEventListener('popstate', onNav);
  }
  if (trackHash) win.addEventListener('hashchange', onNav);
  function onNav() { setTimeout(pageview, 0); }           // laisse l'URL se stabiliser

  /* -- Liens sortants et téléchargements ---------------------------------- */

  // Liste volontairement courte : mieux vaut manquer un format exotique que
  // compter un clic de navigation comme un téléchargement.
  var DOWNLOAD_EXT = /\.(pdf|zip|rar|7z|gz|tar|docx?|xlsx?|pptx?|csv|txt|rtf|dmg|pkg|exe|msi|apk|mp3|mp4|wav|avi|mov|epub)($|\?)/i;

  if (trackOutbound || trackDownloads) {
    doc.addEventListener('click', function (e) {
      var el = e.target;
      while (el && el.tagName !== 'A') el = el.parentElement;
      if (!el || !el.href || !/^https?:/.test(el.href)) return;

      // Un téléchargement externe n'émet qu'UN événement, jamais les deux :
      // deux événements pour un clic doubleraient la consommation de quota.
      if (trackDownloads && DOWNLOAD_EXT.test(el.pathname || '')) {
        send('event', 'Téléchargement', { url: el.href });
        return;
      }

      if (trackOutbound && el.host && el.host !== loc.host) {
        send('event', 'Lien sortant', { url: el.href });
      }
    }, true);
  }

  /* -- API publique -------------------------------------------------------------
   * qm('nom_evenement', {prop: 'valeur'})
   * Les appels faits avant le chargement (snippet à file d'attente) sont rejoués.
   */
  var queued = (win.qm && win.qm.q) || [];
  win.qm = function (name, props) { if (name) send('event', name, props); };
  win.qm.pageview = pageview;
  for (var i = 0; i < queued.length; i++) win.qm.apply(null, queued[i]);

  /* -- Premier hit (en ignorant le pré-rendu) ------------------------------- */

  // Le 404 accompagne la page vue plutôt que de la remplacer : la page d'erreur
  // reste comptée comme une page vue, et l'événement dit laquelle a manqué.
  function firstHit() {
    pageview();
    if (track404) send('event', '404', { path: loc.pathname });
  }

  if (doc.visibilityState === 'hidden' && doc.prerendering) {
    doc.addEventListener('prerenderingchange', firstHit, { once: true });
  } else {
    firstHit();
  }
})(window, document);
