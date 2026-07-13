=== Affluence Analytics ===
Contributors: laboiteacode
Tags: analytics, statistiques, audience, rgpd, privacy
Requires at least: 5.5
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mesure d'audience sans cookies pour WordPress : script first-party, tracking serveur imblocable, ou les deux.

== Description ==

Affluence Analytics connecte votre site WordPress au service de mesure d'audience Affluence, édité par La Boîte à Code. La mesure se fait sans cookie et sans stockage chez le visiteur : pas de bannière de consentement dédiée à l'analytics.

* **Mode script (first-party)** : le fichier wa.js est une copie locale servie par votre propre site, et les hits transitent par la REST API de votre site (route affluence/v1/collect) avant d'être relayés au service. Aucun domaine tiers côté navigateur.
* **Mode serveur (imblocable)** : les pages vues sont envoyées par PHP en fin de requête, sans JavaScript. Invisible pour les bloqueurs de publicité. Avec la clé secrète, les hits sont signés (HMAC) et le service prend en compte l'IP et le navigateur du visiteur.
* **Exclusions** : rôles connectés (administrateurs et éditeurs par défaut) et préfixes de chemins (un par ligne).
* **Événements personnalisés** : wa('inscription', {plan: 'pro'}) côté navigateur, affluence_event('achat', array('montant' => 49)) côté PHP.

= Service externe =

Ce plugin envoie les données de mesure au service Affluence configuré dans Réglages > Affluence (par défaut https://app.affluence.fr), sur le endpoint /api/v1/collect. Sont transmis : URL de la page vue, referrer, langue, largeur d'écran (mode script), nom et propriétés d'événement, ainsi que l'adresse IP et le User-Agent du visiteur, utilisés par le service pour la déduction technique (visiteur unique, appareil, pays) puis jetés, jamais stockés en clair.

Aucune donnée n'est envoyée tant que la clé publique du site n'est pas renseignée dans les réglages. Éditeur : La Boîte à Code (https://laboiteacode.fr). Service : https://app.affluence.fr.

== Installation ==

1. Téléversez le dossier du plugin dans wp-content/plugins/, ou installez-le depuis l'écran Extensions.
2. Activez « Affluence Analytics ».
3. Ouvrez Réglages > Affluence et collez la clé publique de votre site (wa_pub_...), disponible dans votre tableau de bord Affluence.
4. Choisissez le mode de collecte : script, serveur, ou les deux.

== Frequently Asked Questions ==

= Le plugin dépose-t-il des cookies ? =

Non. Ni cookie, ni localStorage, ni empreinte persistante côté visiteur. Voir la documentation du service pour le détail de la méthode de comptage.

= À quoi sert la clé secrète ? =

Elle est optionnelle et ne sert qu'au mode serveur : les hits sont alors signés (HMAC SHA-256) et le service fait foi de l'IP, du User-Agent et de l'horodatage du visiteur transmis dans le payload. Sans elle, le mode serveur fonctionne aussi, mais avec les informations de la connexion sortante de votre serveur.

= Puis-je exclure certaines pages ou certains utilisateurs ? =

Oui. Les rôles cochés dans les réglages (administrateurs et éditeurs par défaut) ne sont jamais comptés, et le champ « chemins exclus » accepte un préfixe d'URL par ligne (par exemple /preprod).

= Le mode « les deux » ne compte-t-il pas double ? =

Non : le service déduplique les hits identiques rapprochés (même page, même visiteur, moins de 2 secondes d'écart).

== Changelog ==

= 1.0.0 =
* Version initiale.
* Mode script first-party : wa.js servi localement, relais des hits par la route REST affluence/v1/collect.
* Mode serveur : pageviews envoyées en PHP via le SDK embarqué (non bloquant, signé HMAC avec la clé secrète).
* Réglages : clés, URL du service, mode de collecte, rôles exclus, chemins exclus.
* Fonction affluence_event() pour les événements personnalisés côté PHP.
