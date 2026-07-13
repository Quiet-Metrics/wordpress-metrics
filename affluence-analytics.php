<?php
/**
 * Plugin Name:       Affluence Analytics
 * Plugin URI:        https://app.affluence.fr
 * Description:       Mesure d'audience sans cookies pour WordPress : script first-party, tracking serveur imblocable, ou les deux. Les données de mesure sont envoyées au service Affluence configuré dans les réglages.
 * Version:           1.0.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            La Boîte à Code
 * Author URI:        https://laboiteacode.fr
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       affluence-analytics
 *
 * @package Affluence_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AFFLUENCE_VERSION', '1.0.0' );
define( 'AFFLUENCE_PLUGIN_FILE', __FILE__ );
define( 'AFFLUENCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFFLUENCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AFFLUENCE_PLUGIN_DIR . 'includes/class-affluence-settings.php';
require_once AFFLUENCE_PLUGIN_DIR . 'includes/class-affluence-tracker.php';
require_once AFFLUENCE_PLUGIN_DIR . 'includes/class-affluence-server.php';

/**
 * Réglages par défaut : mode script, administrateurs et éditeurs exclus.
 *
 * @return array<string,mixed>
 */
function affluence_default_settings() {
	return array(
		'site_key'       => '',
		'secret_key'     => '',
		'service_url'    => 'https://app.affluence.fr',
		'mode'           => 'script',
		'excluded_roles' => array( 'administrator', 'editor' ),
		'excluded_paths' => '',
	);
}

/**
 * Réglages du plugin, complétés par les défauts (option unique affluence_settings).
 *
 * @return array<string,mixed>
 */
function affluence_get_settings() {
	$settings = get_option( 'affluence_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}
	return wp_parse_args( $settings, affluence_default_settings() );
}

/**
 * Chemins exclus normalisés (un par ligne dans les réglages, slash initial garanti).
 *
 * @return string[]
 */
function affluence_excluded_paths() {
	$settings = affluence_get_settings();
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
function affluence_path_is_excluded( $path ) {
	foreach ( affluence_excluded_paths() as $prefix ) {
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
function affluence_user_is_excluded() {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$settings = affluence_get_settings();
	$excluded = (array) $settings['excluded_roles'];
	foreach ( (array) wp_get_current_user()->roles as $role ) {
		if ( in_array( $role, $excluded, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Client du SDK embarqué, configuré depuis les réglages.
 *
 * Retourne null tant que la clé publique n'est pas renseignée : rien
 * n'est jamais envoyé sans clé.
 *
 * @return \LaBoiteACode\WebAnalytics\Client|null
 */
function affluence_client() {
	$settings = affluence_get_settings();
	if ( '' === $settings['site_key'] ) {
		return null;
	}
	if ( ! class_exists( 'LaBoiteACode\\WebAnalytics\\Client' ) ) {
		require_once AFFLUENCE_PLUGIN_DIR . 'includes/Client.php';
	}
	return new \LaBoiteACode\WebAnalytics\Client(
		$settings['site_key'],
		'' !== $settings['secret_key'] ? $settings['secret_key'] : null,
		array( 'endpoint' => untrailingslashit( $settings['service_url'] ) . '/api/v1/collect' )
	);
}

/**
 * Événement personnalisé côté serveur, pour les thèmes et plugins :
 *
 *     affluence_event( 'achat', array( 'montant' => 49 ) );
 *
 * Non bloquant, échecs silencieux : n'impacte jamais le site hôte.
 *
 * @param string $name  Nom de l'événement.
 * @param array  $props Propriétés scalaires (30 clés max, tronquées côté service).
 * @return void
 */
function affluence_event( $name, array $props = array() ) {
	$client = affluence_client();
	if ( null !== $client ) {
		$client->event( (string) $name, $props );
	}
}

/**
 * Activation : pose les réglages par défaut sans écraser un réglage existant.
 *
 * @return void
 */
function affluence_activate() {
	add_option( 'affluence_settings', affluence_default_settings() );
}
register_activation_hook( __FILE__, 'affluence_activate' );

/**
 * Démarrage du plugin : traductions puis composants (réglages, script, serveur).
 *
 * @return void
 */
function affluence_boot() {
	load_plugin_textdomain( 'affluence-analytics' );
	new Affluence_Settings();
	new Affluence_Tracker();
	new Affluence_Server();
}
add_action( 'plugins_loaded', 'affluence_boot' );
