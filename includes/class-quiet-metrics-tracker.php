<?php
/**
 * Mode script : enqueue du tracker local (first-party) et route REST de relais.
 *
 * Le fichier assets/qm.js est servi par le site du client, avec data-endpoint
 * pointant vers la route REST quiet-metrics/v1/collect du même site : le hit part
 * du navigateur vers le domaine du client, puis le serveur le relaie au
 * service Quiet Metrics. Toute la collecte reste first-party, les listes de
 * blocage par domaine sont inopérantes.
 *
 * @package Quiet_Metrics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue du tracker et relais REST des hits vers le service.
 */
class Quiet_Metrics_Tracker {

	const HANDLE = 'quiet-metrics';

	/**
	 * Branche les hooks si le mode script est actif.
	 *
	 * L'avertissement d'administration est branché AVANT le garde-fou de clé
	 * secrète : c'est justement quand le relais refuse d'envoyer qu'il faut le
	 * dire à l'administrateur. Sans clé secrète, on ne branche NI l'injection
	 * du script NI la route de relais : le mode script reste inerte plutôt que
	 * de compter tous les visiteurs avec l'IP du serveur.
	 */
	public function __construct() {
		$settings = quiet_metrics_get_settings();
		if ( ! in_array( $settings['mode'], array( 'script', 'both' ), true ) ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'render_missing_secret_notice' ) );

		if ( ! Quiet_Metrics_Relay_Policy::can_relay( $settings['secret_key'] ) ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_collect_route' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracker' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_tracker_attributes' ), 10, 3 );
	}

	/**
	 * Avertit l'administrateur, dans l'interface d'administration, quand le mode
	 * script est actif sans clé secrète : le relais est alors désactivé et rien
	 * n'est mesuré, plutôt que de compter tous les visiteurs avec l'IP du
	 * serveur WordPress. Réservé aux comptes qui peuvent corriger le réglage.
	 *
	 * @return void
	 */
	public function render_missing_secret_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = quiet_metrics_get_settings();
		if ( ! Quiet_Metrics_Relay_Policy::should_warn_missing_secret( $settings['mode'], $settings['site_key'], $settings['secret_key'] ) ) {
			return;
		}

		$url = admin_url( 'options-general.php?page=' . Quiet_Metrics_Settings::PAGE_SLUG );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a href="%s">%s</a></p></div>',
			esc_html__( 'Quiet Metrics : mode script désactivé.', 'quiet-metrics' ),
			esc_html__( 'Le mode script relaie les visites depuis votre serveur : sans clé secrète, le service ne peut pas distinguer vos visiteurs et les compterait tous avec l\'adresse IP de votre serveur. Le relais est donc désactivé et aucune visite n\'est mesurée pour l\'instant. Renseignez la clé secrète du site, ou choisissez le mode serveur.', 'quiet-metrics' ),
			esc_url( $url ),
			esc_html__( 'Ouvrir les réglages Quiet Metrics', 'quiet-metrics' )
		);
	}

	/**
	 * Enqueue de la copie locale de qm.js (jamais pour les rôles exclus).
	 *
	 * @return void
	 */
	public function enqueue_tracker() {
		$settings = quiet_metrics_get_settings();
		if ( '' === $settings['site_key'] || quiet_metrics_user_is_excluded() ) {
			return;
		}
		wp_enqueue_script( self::HANDLE, QUIET_METRICS_PLUGIN_URL . 'assets/qm.js', array(), QUIET_METRICS_VERSION, true );
		// File d'attente : qm('evenement', {...}) reste appelable avant le chargement du script.
		wp_add_inline_script( self::HANDLE, 'window.qm=window.qm||function(){(window.qm.q=window.qm.q||[]).push(arguments)};', 'before' );
	}

	/**
	 * Ajoute defer + attributs data-* attendus par qm.js sur la balise script.
	 *
	 * @param string $tag    Balise script générée.
	 * @param string $handle Handle du script.
	 * @param string $src    URL du script.
	 * @return string
	 */
	public function add_tracker_attributes( $tag, $handle, $src ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		$settings   = quiet_metrics_get_settings();
		$attributes = sprintf(
			' defer data-site="%s" data-endpoint="%s"',
			esc_attr( $settings['site_key'] ),
			esc_attr( rest_url( 'quiet-metrics/v1/collect' ) )
		);
		$paths = quiet_metrics_excluded_paths();
		if ( array() !== $paths ) {
			$attributes .= sprintf( ' data-exclude="%s"', esc_attr( implode( ',', $paths ) ) );
		}
		return str_replace( ' src=', $attributes . ' src=', $tag );
	}

	/**
	 * Route REST publique de collecte : POST /wp-json/quiet-metrics/v1/collect.
	 *
	 * @return void
	 */
	public function register_collect_route() {
		register_rest_route(
			'quiet-metrics/v1',
			'/collect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'relay_hit' ),
				// Endpoint public de collecte, comme /api/v1/collect côté service :
				// pas de nonce, les garde-fous (clé, rate limit, bots) sont côté service.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Relaie le corps brut du hit vers {service}/api/v1/collect.
	 *
	 * Best-effort et non bloquant : la réponse est toujours 202, le visiteur
	 * ne doit jamais voir d'erreur. L'IP et le User-Agent d'origine sont
	 * transmis en en-têtes (X-Forwarded-For, User-Agent) pour que le service
	 * ne compte pas tous les hits comme venant du serveur WordPress.
	 *
	 * @param WP_REST_Request $request Requête REST entrante.
	 * @return WP_REST_Response
	 */
	public function relay_hit( WP_REST_Request $request ) {
		$response = new WP_REST_Response( (object) array(), 202 );

		$body = $request->get_body();
		if ( '' === $body || strlen( $body ) > 4096 ) {
			return $response;
		}

		$settings = quiet_metrics_get_settings();

		// Garde-fou explicite au point d'envoi : sans clé secrète, on ne relaie
		// pas un hit qui serait attribué à l'IP du serveur. Le constructeur
		// n'enregistre déjà pas cette route dans ce cas ; ce refus reste ici
		// pour que l'intention tienne même si le câblage change. L'administrateur
		// est prévenu par render_missing_secret_notice(), pas par cette réponse
		// (le visiteur ne doit jamais voir d'erreur : on renvoie 202).
		if ( ! Quiet_Metrics_Relay_Policy::can_relay( $settings['secret_key'] ) ) {
			return $response;
		}

		$endpoint = untrailingslashit( $settings['service_url'] ) . '/api/v1/collect';

		$headers = array( 'Content-Type' => 'text/plain' );

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$xff                        = sanitize_text_field( (string) $request->get_header( 'x_forwarded_for' ) );
			$headers['X-Forwarded-For'] = '' !== $xff ? $xff . ', ' . $ip : $ip;
		}

		$user_agent = sanitize_text_field( (string) $request->get_header( 'user_agent' ) );

		wp_remote_post(
			$endpoint,
			array(
				'timeout'    => 2,
				'blocking'   => false,
				'headers'    => $headers,
				'body'       => $body,
				'user-agent' => '' !== $user_agent ? $user_agent : 'Quiet MetricsAnalytics-WordPress/' . QUIET_METRICS_VERSION,
			)
		);

		return $response;
	}
}
