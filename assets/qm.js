/*!
 * Quiet Metrics qm.js : tracker d'audience sans cookie de pistage.
 * (c) La Boîte à Code (laboiteacode.fr) · https://quietmetrics.dev · Licence MIT.
 * Source de vérité : packages/tracker-js/tracker.js (la copie servie est resynchronisée).
 * Servi TEL QUEL, sans minification : environ 3,9 Ko compresses, pour un
 * plafond annonce de 4 Ko. Les commentaires partent donc chez chaque
 * visiteur : les garder courts n'est pas une coquetterie de style.
 * ES5, aucun build requis.
 *
 * Aucun cookie d'identification ni de traçabilité : les deux cookies écrits
 * valent la même chose chez tout le monde, ils ne distinguent personne.
 * `qm_ignore` est le marqueur de refus, posé par la personne et jamais
 * transmis ; `qm_visit` dit qu'une visite est en cours sur ce navigateur.
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

  /* -- Marqueur d'exclusion -------------------------------------------------
   * Le seul écrit que ce traceur fasse à la demande de la personne, et il
   * sert à NE PAS compter. Posé en visitant ?qm_ignore=1, retiré par
   * ?qm_ignore=0. Il ne contient aucun identifiant, n'est jamais transmis à
   * Quiet Metrics, et n'existe que pour arrêter la mesure : c'est ce qui le
   * sépare d'un cookie d'identification ou de traçabilité, et ce qui le rend
   * exempté de consentement (c'est le marqueur de refus).
   *
   * Écrit des DEUX côtés à dessein : le cookie est le seul marqueur que les
   * SDK serveur sachent lire, et localStorage prend le relais là où le cookie
   * est refusé ou expiré. Une seule visite couvre donc les deux modes de
   * suivi, y compris le mode « les deux » du plugin WordPress.
   */

  function marked() {
    try {
      if (win.localStorage && win.localStorage.getItem('qm_ignore') === '1') return true;
    } catch (e) {}
    return /(?:^|;\s*)qm_ignore=1(?:\s*;|\s*$)/.test(doc.cookie);
  }

  function mark(on) {
    try {
      if (win.localStorage) {
        if (on) win.localStorage.setItem('qm_ignore', '1');
        else win.localStorage.removeItem('qm_ignore');
      }
    } catch (e) {}
    // Cinq ans, ou une expiration immédiate pour retirer le marqueur.
    doc.cookie = 'qm_ignore=' + (on ? '1' : '') + ';path=/;max-age=' +
      (on ? 157680000 : 0) + ';samesite=lax' +
      (loc.protocol === 'https:' ? ';secure' : '');
  }

  var signal = /[?&]qm_ignore=([01])(?:&|$)/.exec(loc.search);
  if (signal) mark(signal[1] === '1');

  /* -- Continuité de visite -------------------------------------------------
   * `qm_visit=1` dit qu'une visite est en cours sur ce navigateur, ce que le
   * hit reporte dans `c` : sans lui, une empreinte qui change en cours de
   * visite (4G puis wifi) compte deux visiteurs pour une personne. Fenêtre
   * glissante de 10 minutes, lue avant d'être repoussée, jamais chez un exclu.
   */
  function openVisit() {
    var ongoing = /(?:^|;\s*)qm_visit=1(?:\s*;|\s*$)/.test(doc.cookie);
    doc.cookie = 'qm_visit=1;path=/;max-age=600;samesite=lax' +
      (loc.protocol === 'https:' ? ';secure' : '');
    return ongoing;
  }

  /* -- Garde-fous --------------------------------------------------------- */

  function shouldIgnore() {
    if (win.__qmDisable) return true;                    // kill switch manuel
    if (marked()) return true;                           // refus posé par la personne
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
    if (openVisit()) payload.c = 1;   // une visite était déjà en cours ici

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
