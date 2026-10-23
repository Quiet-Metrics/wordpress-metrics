<?php
/**
 * Désinstallation : supprime les options du plugin (réglages et jeton de
 * lecture) et les chiffres du widget gardés en transient, sur le site et sur
 * chaque site d'un réseau multisite. Avec un cache d'objets persistant, ces
 * transients n'y sont pas énumérables : ils expirent d'eux-mêmes en dix minutes.
 *
 * @package Quiet_Metrics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Supprime les données du plugin pour le site courant.
 *
 * @return void
 */
function quiet_metrics_uninstall_site() {
	global $wpdb;

	delete_option( 'quiet_metrics_settings' );
	delete_option( 'quiet_metrics_read_token' );
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_quiet_metrics_dash_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_quiet_metrics_dash_' ) . '%'
		)
	);
}

quiet_metrics_uninstall_site();

if ( is_multisite() ) {
	$quiet_metrics_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $quiet_metrics_site_ids as $quiet_metrics_site_id ) {
		switch_to_blog( (int) $quiet_metrics_site_id );
		quiet_metrics_uninstall_site();
		restore_current_blog();
	}
}
