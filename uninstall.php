<?php
/**
 * Désinstallation : supprime les options du plugin (réglages et jeton de
 * lecture), sur le site et sur chaque site d'un réseau multisite. Les seules
 * autres données locales sont les chiffres du widget, gardés en transient :
 * ils expirent d'eux-mêmes au bout de dix minutes.
 *
 * @package Quiet_Metrics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'quiet_metrics_settings' );
delete_option( 'quiet_metrics_read_token' );

if ( is_multisite() ) {
	$quiet_metrics_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $quiet_metrics_site_ids as $quiet_metrics_site_id ) {
		switch_to_blog( (int) $quiet_metrics_site_id );
		delete_option( 'quiet_metrics_settings' );
		delete_option( 'quiet_metrics_read_token' );
		restore_current_blog();
	}
}
