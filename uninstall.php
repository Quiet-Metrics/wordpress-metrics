<?php
/**
 * Désinstallation : supprime les options du plugin, sur le site et sur
 * chaque site d'un réseau multisite. Aucune autre donnée n'est stockée
 * localement par le plugin.
 *
 * @package Affluence_Analytics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'affluence_settings' );

if ( is_multisite() ) {
	$affluence_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $affluence_site_ids as $affluence_site_id ) {
		switch_to_blog( (int) $affluence_site_id );
		delete_option( 'affluence_settings' );
		restore_current_blog();
	}
}
