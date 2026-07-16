<?php
/**
 * Mode serveur : pages vues envoyées en PHP via le SDK embarqué, sans JavaScript.
 *
 * La décision de tracker se prend sur template_redirect (le contexte WordPress
 * est complet), l'envoi part sur le hook shutdown : jamais bloquant pour le
 * visiteur, timeout court, erreurs silencieuses.
 *
 * @package Quiet_Metrics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracking serveur des pages vues (imblocable par les bloqueurs).
 */
class Quiet_Metrics_Server {

	/**
	 * Branche le hook si le mode serveur est actif et la clé publique posée.
	 */
	public function __construct() {
		$settings = quiet_metrics_get_settings();
		if ( ! in_array( $settings['mode'], array( 'server', 'both' ), true ) || '' === $settings['site_key'] ) {
			return;
		}
		add_action( 'template_redirect', array( $this, 'maybe_queue_pageview' ) );
	}

	/**
	 * Programme l'envoi en shutdown si la requête correspond à un vrai visiteur.
	 *
	 * @return void
	 */
	public function maybe_queue_pageview() {
		if ( ! $this->is_trackable_request() ) {
			return;
		}
		add_action( 'shutdown', array( $this, 'send_pageview' ), 0 );
	}

	/**
	 * Exclusions : admin, AJAX, cron, REST, XML-RPC, prévisualisations, flux,
	 * robots, rôle exclu, chemin exclu.
	 *
	 * @return bool
	 */
	private function is_trackable_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		if ( is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( is_feed() || is_robots() ) {
			return false;
		}
		if ( function_exists( 'is_favicon' ) && is_favicon() ) {
			return false;
		}
		if ( quiet_metrics_user_is_excluded() ) {
			return false;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( quiet_metrics_path_is_excluded( $path ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Envoi de la page vue via le client embarqué (socket fire-and-forget,
	 * repli cURL court). Le contexte (URL, referrer, IP et User-Agent du
	 * visiteur, langue) est déduit de la requête courante par le SDK.
	 *
	 * @return void
	 */
	public function send_pageview() {
		try {
			$client = quiet_metrics_client();
			if ( null !== $client ) {
				$client->pageview();
			}
		} catch ( \Throwable $e ) {
			// Silencieux par contrat : l'analytics ne casse jamais le site hôte.
			unset( $e );
		}
	}
}
