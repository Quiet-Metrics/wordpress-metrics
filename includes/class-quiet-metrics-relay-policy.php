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
	 * NON sans clé secrète. Le mode script relaie le hit en posant l'en-tête
	 * X-Forwarded-For, que la plateforme neutralise par construction (liste de
	 * proxys de confiance vide, décision de sécurité assumée : la remplir
	 * laisserait n'importe qui usurper l'IP du visiteur). Sans requête signée,
	 * $request->ip() côté service vaut donc l'IP du serveur WordPress :
	 * visiteurs uniques effondrés à une identité par navigateur, géolocalisation
	 * au datacenter, et un seul seau de limitation de débit pour tout le site.
	 * Le seul mécanisme correct est la signature HMAC, qui fait honorer par le
	 * service l'IP et le User-Agent transmis dans le payload, et qui exige une
	 * clé secrète. Mieux vaut ne rien envoyer et le dire que compter faux.
	 *
	 * @param string|null $secret_key Clé secrète du site.
	 * @return bool
	 */
	public static function can_relay( $secret_key ) {
		return is_string( $secret_key ) && '' !== trim( $secret_key );
	}

	/**
	 * Faut-il alerter l'administrateur d'une clé secrète manquante ?
	 *
	 * OUI dès qu'un mode qui relaie via le navigateur (script ou les deux) est
	 * actif, avec une clé publique posée mais sans clé secrète : c'est
	 * exactement la configuration PAR DÉFAUT du plugin, et c'est celle qui
	 * produit la corruption silencieuse.
	 *
	 * @param string      $mode       Mode de collecte (script, server, both).
	 * @param string|null $site_key   Clé publique du site.
	 * @param string|null $secret_key Clé secrète du site.
	 * @return bool
	 */
	public static function should_warn_missing_secret( $mode, $site_key, $secret_key ) {
		$relays_via_browser = in_array( $mode, array( 'script', 'both' ), true );
		$has_site_key       = is_string( $site_key ) && '' !== $site_key;

		return $relays_via_browser && $has_site_key && ! self::can_relay( $secret_key );
	}
}
