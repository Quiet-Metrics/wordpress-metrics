=== Quiet Metrics ===
Contributors: quietmetrics
Tags: analytics, statistiques, audience, rgpd, privacy
Requires at least: 5.5
Tested up to: 6.8
Stable tag: 0.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mesure d'audience sans cookie de pistage pour WordPress : script first-party, tracking serveur imblocable, ou les deux.

== Description ==

Quiet Metrics connecte votre site WordPress au service de mesure d'audience Quiet Metrics, édité par La Boîte à Code. La mesure se fait sans cookie d'identification ni de traçabilité : rien n'est écrit chez le visiteur pour le compter, ni cookie, ni localStorage, ni empreinte persistante. La seule écriture possible est le marqueur d'exclusion que le visiteur pose lui-même pour cesser d'être compté (voir la FAQ). Cette absence de cookie de mesure ne vaut pas à elle seule exemption de consentement : évaluez vos finalités, les fonctions activées et votre base légale.

* **Mode script (first-party)** : le fichier qm.js est une copie locale servie par votre propre site, et les hits transiteraient par la REST API de votre site (route quiet-metrics/v1/collect) avant d'être relayés au service. Aucun domaine tiers côté navigateur. Ce mode n'est pas encore disponible : le relais exigerait une signature du site qui n'est pas encore implémentée, quelle que soit la clé secrète renseignée (voir la FAQ ci-dessous). Utilisez le mode serveur en attendant.
* **Mode serveur (imblocable)** : les pages vues sont envoyées par PHP en fin de requête, sans JavaScript. Invisible pour les bloqueurs de publicité. Avec la clé secrète, les hits sont signés (HMAC) et le service prend en compte l'IP et le navigateur du visiteur.
* **Exclusions** : rôles connectés (administrateurs et éditeurs par défaut) et préfixes de chemins (un par ligne).
* **Exclusion à la demande du visiteur** : une visite sur `?qm_ignore=1` et il cesse d'être compté, `?qm_ignore=0` le remet dans la mesure. Rien à régler : le plugin s'en charge pour les trois modes de collecte.
* **Événements personnalisés** : qm('inscription', {plan: 'pro'}) côté navigateur, quiet_metrics_event('achat', array('montant' => 49)) côté PHP.

= Service externe =

Ce plugin envoie les données de mesure au service Quiet Metrics configuré dans Réglages > Quiet Metrics (par défaut https://quietmetrics.dev), sur le endpoint /api/v1/collect. Sont transmis : URL de la page vue, referrer, langue, largeur d'écran (mode script), nom et propriétés d'événement, ainsi que l'adresse IP et le User-Agent du visiteur, utilisés par le service pour la déduction technique (visiteur unique, appareil, pays) puis jetés, jamais stockés en clair.

Aucune donnée n'est envoyée tant que la clé publique du site n'est pas renseignée dans les réglages. Éditeur : La Boîte à Code (https://laboiteacode.fr). Service : https://quietmetrics.dev.

== Installation ==

1. Téléversez le dossier du plugin dans wp-content/plugins/, ou installez-le depuis l'écran Extensions.
2. Activez « Quiet Metrics ».
3. Ouvrez Réglages > Quiet Metrics et collez la clé publique de votre site (qm_pub_...), disponible dans votre tableau de bord Quiet Metrics.
4. Choisissez le mode de collecte : script, serveur, ou les deux.

== Frequently Asked Questions ==

= Le plugin dépose-t-il des cookies ? =

Aucun pour mesurer. Ni cookie, ni localStorage, ni empreinte persistante ne sont écrits chez le visiteur pour le compter : le comptage repose sur une empreinte pseudonyme quotidienne calculée par le service, jamais stockée sur l'appareil.

La seule écriture possible est le marqueur d'exclusion, et c'est le visiteur qui la demande : en chargeant n'importe quelle page de votre site avec `?qm_ignore=1`, il cesse d'être compté ; avec `?qm_ignore=0`, il revient dans la mesure. Le marqueur est alors un cookie propriétaire de votre site, nommé `qm_ignore` et valant `1` (`path=/`, `samesite=lax`, `secure` en https, cinq ans), doublé en `localStorage` par le tracker. Il ne contient aucun identifiant, sa valeur étant la même chez tout le monde, il n'est jamais transmis à Quiet Metrics, et il n'existe que pour arrêter la mesure : c'est un marqueur de refus, pas un traceur.

Voir la documentation du service pour le détail de la méthode de comptage.

= Un visiteur peut-il demander à ne plus être compté ? =

Oui, sans compte et sans écrire à personne : il charge n'importe quelle page de votre site avec `?qm_ignore=1` (par exemple `https://votresite.fr/?qm_ignore=1`) et il cesse d'être compté ; `?qm_ignore=0` le remet dans la mesure. Une seule visite couvre les trois modes de collecte, script, serveur ou les deux. C'est aussi le moyen le plus simple de vous exclure vous-même de vos propres statistiques, depuis n'importe quel navigateur.

Cela ne remplace pas les rôles et les chemins exclus des réglages : ceux-là appartiennent à l'administrateur du site, le marqueur appartient au visiteur.

= À quoi sert la clé secrète ? =

Elle permet de signer les hits (HMAC SHA-256) pour que le service fasse foi de l'IP, du User-Agent et de l'horodatage du visiteur transmis dans le payload, plutôt que de ceux de votre serveur. Le mode script en aurait besoin, mais son relais n'est pas encore signé : ce mode reste indisponible quelle que soit cette clé, et un message vous le signale dans l'administration ; ce sera corrigé à la publication du plugin. Le mode serveur, lui, fonctionne dès aujourd'hui et se signe avec cette clé ; sans elle, il compte avec les informations de la connexion sortante de votre serveur, la clé reste donc fortement recommandée.

= Puis-je exclure certaines pages ou certains utilisateurs ? =

Oui. Les rôles cochés dans les réglages (administrateurs et éditeurs par défaut) ne sont jamais comptés, et le champ « chemins exclus » accepte un préfixe d'URL par ligne (par exemple /preprod).

= Le mode « les deux » ne compte-t-il pas double ? =

Non : le service déduplique les hits identiques rapprochés (même page, même visiteur, moins de 2 secondes d'écart).

== Changelog ==

= 0.1.1 =
* Bannière, visuels du répertoire et icône repris à la charte Quiet Metrics.
* L'icône utilise désormais le sceau de la marque, lisible en petite taille.
* Aucun changement de code.

= 0.1.0 =
* Version initiale.
* Mode script first-party : qm.js servi localement, relais des hits par la route REST quiet-metrics/v1/collect.
* Mode serveur : pageviews envoyées en PHP via le SDK embarqué (non bloquant, signé HMAC avec la clé secrète).
* Réglages : clés, URL du service, mode de collecte, rôles exclus, chemins exclus.
* Fonction quiet_metrics_event() pour les événements personnalisés côté PHP.
