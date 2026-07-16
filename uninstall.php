<?php
/**
 * Désinstallation : supprime les options du plugin, sur le site et sur
 * chaque site d'un réseau multisite. Aucune autre donnée n'est stockée
 * localement par le plugin.
 *
 * @package Quiet_Metrics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'quiet_metrics_settings' );

if ( is_multisite() ) {
	$quiet_metrics_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $quiet_metrics_site_ids as $quiet_metrics_site_id ) {
		switch_to_blog( (int) $quiet_metrics_site_id );
		delete_option( 'quiet_metrics_settings' );
		restore_current_blog();
	}
}
