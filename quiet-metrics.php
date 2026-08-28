<?php
/**
 * Plugin Name:       Quiet Metrics
 * Plugin URI:        https://quietmetrics.dev
 * Description:       Mesure d'audience sans cookie de pistage pour WordPress : script first-party, tracking serveur imblocable, ou les deux. Les données de mesure sont envoyées au service Quiet Metrics configuré dans les réglages.
 * Version:           0.3.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            La Boîte à Code
 * Author URI:        https://laboiteacode.fr
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       quiet-metrics
 *
 * @package Quiet_Metrics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QUIET_METRICS_VERSION', '0.3.0' );
define( 'QUIET_METRICS_PLUGIN_FILE', __FILE__ );
define( 'QUIET_METRICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'QUIET_METRICS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once QUIET_METRICS_PLUGIN_DIR . 'includes/class-quiet-metrics-relay-policy.php';
require_once QUIET_METRICS_PLUGIN_DIR . 'includes/class-quiet-metrics-settings.php';
require_once QUIET_METRICS_PLUGIN_DIR . 'includes/class-quiet-metrics-tracker.php';
require_once QUIET_METRICS_PLUGIN_DIR . 'includes/class-quiet-metrics-server.php';

/**
 * Réglages par défaut : mode script, administrateurs et éditeurs exclus.
 *
 * @return array<string,mixed>
 */
function quiet_metrics_default_settings() {
	return array(
		'site_key'       => '',
		'secret_key'     => '',
		'service_url'    => 'https://quietmetrics.dev',
		'mode'           => 'script',
		'excluded_roles' => array( 'administrator', 'editor' ),
		'excluded_paths' => '',
	);
}

/**
 * Réglages du plugin, complétés par les défauts (option unique quiet_metrics_settings).
 *
 * @return array<string,mixed>
 */
function quiet_metrics_get_settings() {
	$settings = get_option( 'quiet_metrics_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}
	return wp_parse_args( $settings, quiet_metrics_default_settings() );
}

/**
 * Chemins exclus normalisés (un par ligne dans les réglages, slash initial garanti).
 *
 * @return string[]
 */
function quiet_metrics_excluded_paths() {
	$settings = quiet_metrics_get_settings();
	$paths    = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $settings['excluded_paths'] ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		if ( '/' !== substr( $line, 0, 1 ) ) {
			$line = '/' . $line;
		}
		$paths[] = $line;
	}
	return $paths;
}

/**
 * Le chemin demandé commence-t-il par un des préfixes exclus ?
 *
 * @param string $path Chemin de la requête (sans query string).
 * @return bool
 */
function quiet_metrics_path_is_excluded( $path ) {
	foreach ( quiet_metrics_excluded_paths() as $prefix ) {
		if ( 0 === strpos( (string) $path, $prefix ) ) {
			return true;
		}
	}
	return false;
}

/**
 * L'utilisateur courant a-t-il un rôle exclu de la mesure ?
 *
 * @return bool
 */
function quiet_metrics_user_is_excluded() {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$settings = quiet_metrics_get_settings();
	$excluded = (array) $settings['excluded_roles'];
	foreach ( (array) wp_get_current_user()->roles as $role ) {
		if ( in_array( $role, $excluded, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Charge le SDK embarqué, une seule fois par requête.
 *
 * @return void
 */
function quiet_metrics_require_client() {
	if ( ! class_exists( 'QuietMetrics\\Client' ) ) {
		require_once QUIET_METRICS_PLUGIN_DIR . 'includes/Client.php';
	}
}

/**
 * Marqueur d'exclusion : pose ou retrait demandé par l'URL courante.
 *
 * La personne se retire elle-même de la mesure en visitant n'importe quelle
 * URL du site avec ?qm_ignore=1, et y revient avec ?qm_ignore=0. Le marqueur
 * ne contient aucun identifiant, n'est jamais transmis à Quiet Metrics, et
 * n'existe que pour ARRÊTER la mesure : c'est le marqueur de refus, et c'est
 * ce qui le sépare d'un cookie d'identification ou de traçabilité.
 *
 * Branché sur init, donc avant template_redirect (où se décide la mesure) et
 * avant tout envoi de sortie : poser un cookie écrit un en-tête HTTP, et il
 * serait trop tard une fois le gabarit commencé.
 *
 * @return void
 */
function quiet_metrics_handle_opt_out() {
	quiet_metrics_require_client();
	\QuietMetrics\Client::handleOptOutRequest();
}
add_action( 'init', 'quiet_metrics_handle_opt_out' );

/**
 * Le visiteur a-t-il posé le marqueur d'exclusion ?
 *
 * Le mode script pose la même question côté navigateur (assets/qm.js) : les
 * deux modes doivent honorer le même refus, sinon une seule visite en
 * ?qm_ignore=1 n'arrêterait que la moitié de la mesure.
 *
 * @return bool
 */
function quiet_metrics_visitor_opted_out() {
	quiet_metrics_require_client();

	$marker = isset( $_COOKIE[ \QuietMetrics\Client::OPT_OUT_MARKER ] )
		? sanitize_text_field( wp_unslash( $_COOKIE[ \QuietMetrics\Client::OPT_OUT_MARKER ] ) )
		: null;

	return \QuietMetrics\Client::isOptedOut( $marker );
}

/**
 * Client du SDK embarqué, configuré depuis les réglages.
 *
 * Retourne null tant que la clé publique n'est pas renseignée : rien
 * n'est jamais envoyé sans clé.
 *
 * @return \QuietMetrics\Client|null
 */
function quiet_metrics_client() {
	$settings = quiet_metrics_get_settings();
	if ( '' === $settings['site_key'] ) {
		return null;
	}
	quiet_metrics_require_client();
	return new \QuietMetrics\Client(
		$settings['site_key'],
		'' !== $settings['secret_key'] ? $settings['secret_key'] : null,
		array( 'endpoint' => untrailingslashit( $settings['service_url'] ) . '/api/v1/collect' )
	);
}

/**
 * Événement personnalisé côté serveur, pour les thèmes et plugins :
 *
 *     quiet_metrics_event( 'achat', array( 'montant' => 49 ) );
 *
 * Non bloquant, échecs silencieux : n'impacte jamais le site hôte.
 *
 * @param string $name  Nom de l'événement.
 * @param array  $props Propriétés scalaires (30 clés max, tronquées côté service).
 * @return void
 */
function quiet_metrics_event( $name, array $props = array() ) {
	$client = quiet_metrics_client();
	if ( null !== $client ) {
		$client->event( (string) $name, $props );
	}
}

/**
 * Activation : pose les réglages par défaut sans écraser un réglage existant.
 *
 * @return void
 */
function quiet_metrics_activate() {
	add_option( 'quiet_metrics_settings', quiet_metrics_default_settings() );
}
register_activation_hook( __FILE__, 'quiet_metrics_activate' );

/**
 * Démarrage du plugin : traductions puis composants (réglages, script, serveur).
 *
 * @return void
 */
function quiet_metrics_boot() {
	load_plugin_textdomain( 'quiet-metrics' );
	new Quiet_Metrics_Settings();
	new Quiet_Metrics_Tracker();
	new Quiet_Metrics_Server();
}
add_action( 'plugins_loaded', 'quiet_metrics_boot' );
