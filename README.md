# Plugin WordPress officiel — v1.x (non commencé)

Canal d'acquisition n°1 à moyen terme (43 % du web) — planifié en **v1.x** après le lancement payant ([roadmap](../../docs/07-roadmap.md), Phase 3).

## Périmètre prévu

- Réglages : clé publique + clé secrète, vérification de l'installation en un clic.
- Injection automatique du snippet (`wp_head`, script servi localement pour rester first-party).
- **Mode « tracking serveur »** (case à cocher) : pageviews envoyées via le SDK PHP en `template_redirect`/`shutdown` — zéro JS, imblocable ; le SDK est embarqué dans le plugin (pas de Composer chez l'utilisateur final).
- Exclusions : rôles (admins/éditeurs), URLs, environnement de staging.
- Événements WooCommerce prêts à l'emploi (commande, ajout panier) — selon demande.
- i18n FR/EN, publication sur wordpress.org.

## Contraintes de conformité wordpress.org à anticiper

Pas d'appel externe non documenté (déclarer le endpoint), opt-in explicite du tracking à l'activation, code GPL-compatible (le SDK MIT convient), `readme.txt` au format du répertoire officiel.
