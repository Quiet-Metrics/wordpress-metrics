# Affluence Analytics pour WordPress

Plugin WordPress officiel d'Affluence (La Boîte à Code) : mesure d'audience sans cookies, avec collecte first-party par script, tracking 100 % serveur imblocable, ou les deux. Aucune dépendance Composer chez l'utilisateur final : le SDK PHP et le tracker JS sont embarqués.

## Installation

Installation manuelle (développeurs, agences) :

```bash
cp -R packages/wordpress-plugin /chemin/du/site/wp-content/plugins/affluence-analytics
```

Puis :

1. Activez « Affluence Analytics » dans Extensions.
2. Ouvrez Réglages > Affluence et collez la clé publique du site (`wa_pub_...`).
3. Choisissez le mode de collecte.

Aucun `composer install` : `includes/Client.php` est une copie embarquée du SDK coeur ([packages/php](../php)) et `assets/wa.js` une copie du tracker ([packages/tracker-js](../tracker-js)).

## Configuration

Tout se passe dans Réglages > Affluence (option unique `affluence_settings`) :

| Réglage | Défaut | Rôle |
|---|---|---|
| Clé publique du site | vide | identifie le site (`wa_pub_...`), rien n'est envoyé sans elle |
| Clé secrète | vide | mode serveur signé (HMAC) : IP, User-Agent et horodatage du visiteur font foi |
| URL du service | `https://app.affluence.fr` | instance Affluence qui reçoit les hits sur `/api/v1/collect` |
| Mode de collecte | script | `script`, `server` ou `both` |
| Rôles exclus | administrateur, éditeur | utilisateurs connectés jamais comptés (les deux modes) |
| Chemins exclus | vide | préfixes d'URL, un par ligne (ex. `/preprod`) |

## Usage

### Mode script (first-party)

Le plugin enqueue la copie locale `assets/wa.js` avec `defer` et les attributs `data-site` et `data-endpoint`. Le `data-endpoint` pointe vers la route REST du site lui-même (`POST /wp-json/affluence/v1/collect`), qui relaie le corps brut vers `{service}/api/v1/collect` (timeout 2 s, non bloquant, en-têtes `X-Forwarded-For` et `User-Agent` d'origine transmis) : le navigateur ne parle jamais à un domaine tiers.

Événement personnalisé côté navigateur (file d'attente intégrée, appelable avant le chargement du script) :

```html
<script>
  wa('inscription', { plan: 'pro' });
</script>
```

### Mode serveur (imblocable)

Chaque page vue d'un visiteur non exclu est envoyée par PHP : décision sur `template_redirect`, envoi sur `shutdown` via le SDK embarqué (socket fire-and-forget, repli cURL 400 ms, erreurs silencieuses). Zéro JavaScript, rien à bloquer côté navigateur.

Événement personnalisé côté PHP, dans un thème ou un plugin :

```php
add_action( 'woocommerce_thankyou', function ( $order_id ) {
    affluence_event( 'achat', array( 'commande' => $order_id ) );
} );
```

Le SDK embarqué reste utilisable directement si besoin d'options avancées (mêmes signatures que `laboiteacode/webanalytics-php`) :

```php
$client = affluence_client(); // \LaBoiteACode\WebAnalytics\Client|null
if ( $client !== null ) {
    $client->pageview( array( 'url' => 'https://monsite.fr/page-virtuelle' ) );
}
```

## Comment ça marche

- Sont ignorés en mode serveur : requêtes admin, AJAX, cron, REST, XML-RPC, prévisualisations, flux, robots, rôles exclus et chemins exclus. En mode script, les rôles exclus ne reçoivent pas le script et les chemins exclus sont passés au tracker via `data-exclude`.
- Avec la clé secrète, le SDK signe chaque hit : en-têtes `X-WA-Timestamp` et `X-WA-Signature` (HMAC SHA-256 de `{timestamp}.{corps}`), conformément à la spec [docs/05-api-et-sdk.md](../../docs/05-api-et-sdk.md).
- La désinstallation (`uninstall.php`) supprime l'option `affluence_settings`, y compris en multisite. Le plugin ne crée aucune table.

## Licence

GPLv2 ou ultérieure (exigence du répertoire wordpress.org). Le SDK embarqué provient du package `laboiteacode/webanalytics-php`, publié sous licence MIT, compatible GPL.
