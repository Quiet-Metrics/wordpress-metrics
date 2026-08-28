# Quiet Metrics pour WordPress

![Quiet Metrics : plugin WordPress](art/banner.png)

> 🇬🇧 [English version](README.md)

Plugin WordPress officiel de Quiet Metrics (La Boîte à Code) : mesure d'audience sans cookie d'identification ni de traçabilité, avec collecte first-party par script, tracking 100 % serveur imblocable, ou les deux. Aucune dépendance Composer chez l'utilisateur final : le SDK PHP et le tracker JS sont embarqués.

## Installation

Installation manuelle (développeurs, agences) :

```bash
cp -R packages/wordpress-plugin /chemin/du/site/wp-content/plugins/quiet-metrics
```

Puis :

1. Activez « Quiet Metrics » dans Extensions.
2. Ouvrez Réglages > Quiet Metrics et collez la clé publique du site (`qm_pub_...`).
3. Choisissez le mode de collecte.

Aucun `composer install` : `includes/Client.php` est une copie embarquée du SDK cœur ([php-metrics](https://github.com/Quiet-Metrics/php-metrics)) et `assets/qm.js` une copie du tracker ([tracker-js](https://github.com/Quiet-Metrics/tracker-js)).

## Configuration

Tout se passe dans Réglages > Quiet Metrics (option unique `quiet_metrics_settings`) :

| Réglage | Défaut | Rôle |
|---|---|---|
| Clé publique du site | vide | identifie le site (`qm_pub_...`), rien n'est envoyé sans elle |
| Clé secrète | vide | mode serveur signé (HMAC) : IP, User-Agent et horodatage du visiteur font foi |
| URL du service | `https://quietmetrics.dev` | instance Quiet Metrics qui reçoit les hits sur `/api/v1/collect` |
| Mode de collecte | script | `script`, `server` ou `both` |
| Rôles exclus | administrateur, éditeur | utilisateurs connectés jamais comptés (les deux modes) |
| Chemins exclus | vide | préfixes d'URL, un par ligne (ex. `/preprod`) |

## Usage

### Mode script (first-party)

Le plugin enqueue la copie locale `assets/qm.js` avec `defer` et les attributs `data-site` et `data-endpoint`. Le `data-endpoint` pointe vers la route REST du site lui-même (`POST /wp-json/quiet-metrics/v1/collect`), qui relaie le corps brut vers `{service}/api/v1/collect` (timeout 2 s, non bloquant, en-têtes `X-Forwarded-For` et `User-Agent` d'origine transmis) : le navigateur ne parle jamais à un domaine tiers.

Événement personnalisé côté navigateur (file d'attente intégrée, appelable avant le chargement du script) :

```html
<script>
  qm('inscription', { plan: 'pro' });
</script>
```

### Mode serveur (imblocable)

Chaque page vue d'un visiteur non exclu est envoyée par PHP : décision sur `template_redirect`, envoi sur `shutdown` via le SDK embarqué (socket fire-and-forget, repli cURL 400 ms, erreurs silencieuses). Zéro JavaScript, rien à bloquer côté navigateur.

Événement personnalisé côté PHP, dans un thème ou un plugin :

```php
add_action( 'woocommerce_thankyou', function ( $order_id ) {
    quiet_metrics_event( 'achat', array( 'commande' => $order_id ) );
} );
```

Le SDK embarqué reste utilisable directement si besoin d'options avancées (mêmes signatures que `quiet-metrics/php-metrics`) :

```php
$client = quiet_metrics_client(); // \QuietMetrics\Client|null
if ( $client !== null ) {
    $client->pageview( array( 'url' => 'https://monsite.fr/page-virtuelle' ) );
}
```

## S'exclure de la mesure

Un visiteur peut demander à ne plus être compté, sans compte et sans écrire à personne : il visite une page de votre site avec `?qm_ignore=1`, et `?qm_ignore=0` le remet dans la mesure.

```
https://monsite.fr/?qm_ignore=1     ne plus être compté
https://monsite.fr/?qm_ignore=0     être compté à nouveau
```

Le marqueur est un **cookie propriétaire de votre site**, nommé `qm_ignore` et valant `1` (`path=/`, `samesite=lax`, `secure` en https, cinq ans). Le plugin s'en charge, sans réglage à toucher : il pose ou retire le marqueur, et plus rien ne part tant qu'il est là.

Il ne contient aucun identifiant (sa valeur est la même chez tout le monde), il n'est jamais transmis à Quiet Metrics, et il n'existe que pour arrêter la mesure : c'est un marqueur de refus, pas un traceur. Le tracker JS écrit en plus la même valeur en `localStorage`, mais un SDK serveur ne lit que le cookie : une seule visite suffit donc pour les trois modes de collecte du plugin, script, serveur et les deux.

Cela ne remplace pas les rôles et les chemins exclus des réglages : ceux-là appartiennent à l'administrateur du site, le marqueur appartient au visiteur.

## Continuité de visite

Quand l'empreinte visiteur change en cours de visite (4G puis wifi), la même personne compterait sinon pour deux visiteurs uniques le même jour. Un second **cookie propriétaire de votre site** ferme cet écart : `qm_visit`, valant `1` (`path=/`, `samesite=lax`, `secure` en https), sur une fenêtre glissante de dix minutes repoussée à chaque hit mesuré. Chaque hit reporte dans la clé `c` du payload s'il était déjà là.

Sa valeur est constante, la même chez tout le monde : elle n'identifie personne, elle dit seulement qu'une visite est déjà en cours sur ce navigateur. Il n'est jamais écrit chez quelqu'un qui a posé le marqueur d'exclusion, ni quand rien n'est mesuré. Le plugin s'en charge sans réglage à toucher, en mode serveur comme en mode script.

À savoir si votre site est mis en cache : une réponse mesurée porte désormais un en-tête `Set-Cookie`, que certains reverse proxys et CDN prennent comme une raison de ne pas stocker la réponse.

## Comment ça marche

- Sont ignorés en mode serveur : requêtes admin, AJAX, cron, REST, XML-RPC, prévisualisations, flux, robots, rôles exclus et chemins exclus. En mode script, les rôles exclus ne reçoivent pas le script et les chemins exclus sont passés au tracker via `data-exclude`.
- Avec la clé secrète, le SDK signe chaque hit : en-têtes `X-QM-Timestamp` et `X-QM-Signature` (HMAC SHA-256 de `{timestamp}.{corps}`), conformément à la spec `docs/05-api-et-sdk.md` du monorepo.
- La désinstallation (`uninstall.php`) supprime l'option `quiet_metrics_settings`, y compris en multisite. Le plugin ne crée aucune table.

## Assets du répertoire wordpress.org

Le dossier [`.wordpress-org/`](.wordpress-org/) contient les visuels aux formats officiels du répertoire de plugins, prêts pour le dossier SVN `assets/` lors de la soumission : `banner-1544x500.png` (retina), `banner-772x250.png`, `icon-256x256.png`, `icon-128x128.png` (source `icon.svg`).

## Licence

GPLv2 ou ultérieure (exigence du répertoire wordpress.org). Le SDK embarqué provient du package `quiet-metrics/php-metrics`, publié sous licence MIT, compatible GPL.

Un plugin [La Boîte à Code](https://laboiteacode.fr) pour [Quiet Metrics](https://quietmetrics.dev).
