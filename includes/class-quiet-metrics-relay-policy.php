<?php
/**
 * Politique de relais : décide si le mode script peut relayer un hit, et si
 * l'administrateur doit être alerté d'une configuration qui corromprait les
 * mesures en silence.
 *
 * PHP pur, sans aucune dépendance WordPress (aucun appel de fonction WP), pour
 * rester éprouvable hors runtime : c'est ici que vit la décision, pas dans les
 * hooks.
 *
 * @package Quiet_Metrics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Décisions de relais du mode script.
 */
class Quiet_Metrics_Relay_Policy {

	/**
	 * Le relais peut-il envoyer un hit ?
	 *
	 * TOUJOURS NON, quelle que soit la clé secrète fournie, tant que la
	 * signature HMAC du relais n'est pas livrée (reportée à la publication du
	 * plugin). Le mode script relaie le hit en posant l'en-tête
	 * X-Forwarded-For, que la plateforme neutralise par construction (liste de
	 * proxys de confiance vide, décision de sécurité assumée : la remplir
	 * laisserait n'importe qui usurper l'IP du visiteur). Sans requête signée,
	 * $request->ip() côté service vaut donc l'IP du serveur WordPress :
	 * visiteurs uniques effondrés à une identité par navigateur, géolocalisation
	 * au datacenter, et un seul seau de limitation de débit pour tout le site.
	 * Le seul mécanisme correct est la signature HMAC, qui fait honorer par le
	 * service l'IP et le User-Agent transmis dans le payload, et qui exige une
	 * clé secrète.
	 *
	 * CORRIGÉ (relecture adversariale du 22/07/2026) : une première version
	 * autorisait le relais dès qu'une clé secrète non vide était fournie, en
	 * anticipant une signature qui n'existe pas encore côté plugin (Client.php
	 * ne signe que le SDK serveur/proxy, pas cette route REST). Résultat :
	 * poser la clé secrète réactivait le relais SANS le signer, et
	 * l'administrateur perdait dans le même geste l'avertissement qui le
	 * prévenait, exactement la mauvaise attribution silencieuse que ce refus
	 * doit empêcher. Mieux vaut ne rien envoyer et le dire que compter faux.
	 *
	 * @param string|null $secret_key Clé secrète du site (sans effet sur cette
	 *                                décision tant que la signature n'existe
	 *                                pas ; le paramètre est conservé pour que
	 *                                l'implémentation de la signature n'ait
	 *                                qu'à changer ce corps de méthode).
	 * @return bool
	 */
	public static function can_relay( $secret_key ) {
		unset( $secret_key );

		return false;
	}

	/**
	 * Faut-il alerter l'administrateur que le relais du mode script est
	 * indisponible ?
	 *
	 * OUI dès qu'un mode qui relaie via le navigateur (script ou les deux) est
	 * actif, avec une clé publique posée : c'est la configuration qui, sans
	 * cette alerte, laisserait croire qu'aucune visite n'est comptée par
	 * accident plutôt que par construction. `can_relay()` refusant désormais
	 * TOUJOURS (voir ci-dessus), cette alerte n'est plus conditionnée par la
	 * présence de la clé secrète : avant le 22/07/2026, poser cette clé
	 * faisait taire l'avertissement sans corriger le relais, ce qui était la
	 * seconde moitié du même bug.
	 *
	 * @param string      $mode       Mode de collecte (script, server, both).
	 * @param string|null $site_key   Clé publique du site.
	 * @param string|null $secret_key Clé secrète du site (n'a plus d'effet sur
	 *                                cette alerte, voir can_relay()).
	 * @return bool
	 */
	public static function should_warn_relay_unavailable( $mode, $site_key, $secret_key ) {
		$relays_via_browser = in_array( $mode, array( 'script', 'both' ), true );
		$has_site_key       = is_string( $site_key ) && '' !== $site_key;

		return $relays_via_browser && $has_site_key && ! self::can_relay( $secret_key );
	}
}
