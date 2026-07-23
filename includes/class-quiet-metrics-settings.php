<?php
/**
 * Page de réglages (Réglages > Quiet Metrics), via l'API Settings de WordPress.
 *
 * @package Quiet_Metrics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enregistre et affiche la page de réglages du plugin.
 */
class Quiet_Metrics_Settings {

	const OPTION_GROUP = 'quiet_metrics_settings_group';
	const OPTION_NAME  = 'quiet_metrics_settings';
	const PAGE_SLUG    = 'quiet-metrics';

	/**
	 * Branche les hooks d'administration.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( QUIET_METRICS_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Lien « Réglages » sur la ligne du plugin (écran Extensions).
	 *
	 * @param string[] $links Liens existants.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Réglages', 'quiet-metrics' ) . '</a>' );
		return $links;
	}

	/**
	 * Entrée de menu : Réglages > Quiet Metrics.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Quiet Metrics', 'quiet-metrics' ),
			__( 'Quiet Metrics', 'quiet-metrics' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Déclare l'option, les sections et les champs.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => quiet_metrics_default_settings(),
			)
		);

		add_settings_section(
			'quiet_metrics_section_service',
			__( 'Connexion au service', 'quiet-metrics' ),
			array( $this, 'render_service_section' ),
			self::PAGE_SLUG
		);
		add_settings_field(
			'quiet_metrics_site_key',
			__( 'Clé publique du site', 'quiet-metrics' ),
			array( $this, 'render_site_key_field' ),
			self::PAGE_SLUG,
			'quiet_metrics_section_service'
		);
		add_settings_field(
			'quiet_metrics_secret_key',
			__( 'Clé secrète', 'quiet-metrics' ),
			array( $this, 'render_secret_key_field' ),
			self::PAGE_SLUG,
			'quiet_metrics_section_service'
		);
		add_settings_field(
			'quiet_metrics_service_url',
			__( 'URL du service', 'quiet-metrics' ),
			array( $this, 'render_service_url_field' ),
			self::PAGE_SLUG,
			'quiet_metrics_section_service'
		);

		add_settings_section(
			'quiet_metrics_section_collect',
			__( 'Collecte', 'quiet-metrics' ),
			array( $this, 'render_collect_section' ),
			self::PAGE_SLUG
		);
		add_settings_field(
			'quiet_metrics_mode',
			__( 'Mode de collecte', 'quiet-metrics' ),
			array( $this, 'render_mode_field' ),
			self::PAGE_SLUG,
			'quiet_metrics_section_collect'
		);
		add_settings_field(
			'quiet_metrics_excluded_roles',
			__( 'Rôles exclus', 'quiet-metrics' ),
			array( $this, 'render_excluded_roles_field' ),
			self::PAGE_SLUG,
			'quiet_metrics_section_collect'
		);
		add_settings_field(
			'quiet_metrics_excluded_paths',
			__( 'Chemins exclus', 'quiet-metrics' ),
			array( $this, 'render_excluded_paths_field' ),
			self::PAGE_SLUG,
			'quiet_metrics_section_collect'
		);
	}

	/**
	 * Nettoyage complet des réglages avant enregistrement.
	 *
	 * @param mixed $input Valeur brute soumise.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$defaults = quiet_metrics_default_settings();
		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$clean = array();

		$clean['site_key']   = isset( $input['site_key'] ) ? sanitize_text_field( $input['site_key'] ) : '';
		$clean['secret_key'] = isset( $input['secret_key'] ) ? sanitize_text_field( $input['secret_key'] ) : '';

		$url                  = isset( $input['service_url'] ) ? esc_url_raw( trim( (string) $input['service_url'] ) ) : '';
		$clean['service_url'] = '' !== $url ? untrailingslashit( $url ) : $defaults['service_url'];

		$mode          = isset( $input['mode'] ) ? (string) $input['mode'] : $defaults['mode'];
		$clean['mode'] = in_array( $mode, array( 'script', 'server', 'both' ), true ) ? $mode : $defaults['mode'];

		$roles                   = ( isset( $input['excluded_roles'] ) && is_array( $input['excluded_roles'] ) ) ? $input['excluded_roles'] : array();
		$roles                   = array_map( 'sanitize_key', $roles );
		$clean['excluded_roles'] = array_values( array_intersect( $roles, array_keys( wp_roles()->roles ) ) );

		$clean['excluded_paths'] = isset( $input['excluded_paths'] ) ? sanitize_textarea_field( $input['excluded_paths'] ) : '';

		return $clean;
	}

	/**
	 * Intro de la section « Connexion au service ».
	 *
	 * @return void
	 */
	public function render_service_section() {
		echo '<p>' . esc_html__( 'Les clés du site se trouvent dans votre tableau de bord Quiet Metrics (réglages du site). Les données de mesure sont envoyées au service configuré ci-dessous, sur le endpoint /api/v1/collect.', 'quiet-metrics' ) . '</p>';
	}

	/**
	 * Intro de la section « Collecte ».
	 *
	 * @return void
	 */
	public function render_collect_section() {
		echo '<p>' . esc_html__( 'Le mode script charge un fichier qm.js servi par votre propre site (first-party). Le mode serveur envoie les pages vues en PHP, sans JavaScript : il est invisible pour les bloqueurs.', 'quiet-metrics' ) . '</p>';
	}

	/**
	 * Champ : clé publique du site.
	 *
	 * @return void
	 */
	public function render_site_key_field() {
		$settings = quiet_metrics_get_settings();
		printf(
			'<input type="text" class="regular-text code" name="%s[site_key]" value="%s" placeholder="qm_pub_..." autocomplete="off" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $settings['site_key'] )
		);
		echo '<p class="description">' . esc_html__( 'Obligatoire. Rien n\'est envoyé tant que cette clé est vide.', 'quiet-metrics' ) . '</p>';
	}

	/**
	 * Champ : clé secrète (mode serveur signé).
	 *
	 * @return void
	 */
	public function render_secret_key_field() {
		$settings = quiet_metrics_get_settings();
		printf(
			'<input type="password" class="regular-text code" name="%s[secret_key]" value="%s" placeholder="qm_sec_..." autocomplete="new-password" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $settings['secret_key'] )
		);
		echo '<p class="description">' . esc_html__( 'Recommandée pour le mode serveur : les hits sont signés (HMAC) et le service prend en compte l\'IP et le navigateur du visiteur plutôt que ceux de votre serveur. Le mode script reste indisponible pour l\'instant quelle que soit cette clé (son relais n\'est pas encore signé) : utilisez le mode serveur en attendant.', 'quiet-metrics' ) . '</p>';
	}

	/**
	 * Champ : URL du service.
	 *
	 * @return void
	 */
	public function render_service_url_field() {
		$settings = quiet_metrics_get_settings();
		printf(
			'<input type="url" class="regular-text code" name="%s[service_url]" value="%s" placeholder="https://quietmetrics.dev" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $settings['service_url'] )
		);
		echo '<p class="description">' . esc_html__( 'À modifier uniquement pour une instance dédiée ou un environnement de test.', 'quiet-metrics' ) . '</p>';
	}

	/**
	 * Champ : mode de collecte.
	 *
	 * @return void
	 */
	public function render_mode_field() {
		$settings = quiet_metrics_get_settings();
		$choices  = array(
			'script' => __( 'Script (navigateur, first-party)', 'quiet-metrics' ),
			'server' => __( 'Serveur (PHP, imblocable)', 'quiet-metrics' ),
			'both'   => __( 'Les deux', 'quiet-metrics' ),
		);
		printf( '<select name="%s[mode]">', esc_attr( self::OPTION_NAME ) );
		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $settings['mode'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Le mode « Script » n\'est pas encore disponible : son relais depuis votre serveur exigerait une signature qui n\'est pas encore implémentée, quelle que soit la clé secrète. Le mode « Serveur » fonctionne dès aujourd\'hui, signé avec la clé secrète ci-dessus. Avec « Les deux », seul le serveur mesure pour l\'instant ; une fois le script disponible, le service dédupliquera les pages vues reçues en double.', 'quiet-metrics' ) . '</p>';
	}

	/**
	 * Champ : rôles exclus de la mesure.
	 *
	 * @return void
	 */
	public function render_excluded_roles_field() {
		$settings = quiet_metrics_get_settings();
		$excluded = (array) $settings['excluded_roles'];
		echo '<fieldset>';
		foreach ( wp_roles()->roles as $slug => $role ) {
			printf(
				'<label><input type="checkbox" name="%s[excluded_roles][]" value="%s"%s /> %s</label><br />',
				esc_attr( self::OPTION_NAME ),
				esc_attr( $slug ),
				checked( in_array( $slug, $excluded, true ), true, false ),
				esc_html( translate_user_role( $role['name'] ) )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Les utilisateurs connectés avec un rôle coché ne sont pas comptés (modes script et serveur).', 'quiet-metrics' ) . '</p>';
	}

	/**
	 * Champ : chemins exclus (préfixes, un par ligne).
	 *
	 * @return void
	 */
	public function render_excluded_paths_field() {
		$settings = quiet_metrics_get_settings();
		printf(
			'<textarea name="%s[excluded_paths]" rows="4" class="large-text code" placeholder="/preprod&#10;/espace-prive">%s</textarea>',
			esc_attr( self::OPTION_NAME ),
			esc_textarea( $settings['excluded_paths'] )
		);
		echo '<p class="description">' . esc_html__( 'Un préfixe de chemin par ligne : toute URL qui commence par un de ces préfixes est ignorée.', 'quiet-metrics' ) . '</p>';
	}

	/**
	 * Rendu de la page de réglages.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Quiet Metrics', 'quiet-metrics' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::PAGE_SLUG );
		submit_button();
		echo '</form>';
		echo '</div>';
	}
}
