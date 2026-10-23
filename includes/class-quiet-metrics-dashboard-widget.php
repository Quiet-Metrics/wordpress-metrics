<?php
/**
 * Widget du tableau de bord WordPress : les chiffres du site lus sur l'API de
 * lecture de Quiet Metrics, avec un jeton de lecture distinct des clés de
 * collecte.
 *
 * Le jeton est un secret : il vit dans sa propre option, non chargée
 * automatiquement, n'est jamais réécrit dans une page (ni HTML ni JS), et ne
 * sert qu'aux appels serveur. Les réponses sont gardées dix minutes en
 * transient, sous une clé qui n'est qu'une empreinte.
 *
 * @package Quiet_Metrics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jeton de lecture (réglages) et widget du tableau de bord.
 */
class Quiet_Metrics_Dashboard_Widget {

	const TOKEN_OPTION = 'quiet_metrics_read_token';
	const CACHE_PREFIX = 'quiet_metrics_dash_';
	const CACHE_TTL    = 600;

	/**
	 * Un échec est gardé une minute : un service en panne ou un 429 ne doit
	 * pas coûter cinq secondes de délai à chaque affichage du tableau de bord.
	 */
	const FAILURE_CACHE_TTL = 60;
	const PERIOD       = '30d';

	/**
	 * Branche les hooks d'administration.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
	}

	/**
	 * Capacité requise pour voir le widget, filtrable.
	 *
	 * @return string
	 */
	public static function capability() {
		return (string) apply_filters( 'quiet_metrics_dashboard_capability', 'manage_options' );
	}

	/**
	 * Déclare le jeton dans le même groupe que les autres réglages : le
	 * formulaire existant (nonce et capacité gérés par options.php) l'enregistre.
	 *
	 * @return void
	 */
	public function register_setting() {
		// L'option est créée NON autoloadée avant tout enregistrement :
		// update_option() garde ensuite ce réglage, alors qu'une première
		// écriture par options.php la chargerait à chaque page du site.
		if ( false === get_option( self::TOKEN_OPTION ) ) {
			add_option( self::TOKEN_OPTION, '', '', false );
		}

		register_setting(
			Quiet_Metrics_Settings::OPTION_GROUP,
			self::TOKEN_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_token' ),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'quiet_metrics_section_dashboard',
			__( 'Tableau de bord', 'quiet-metrics' ),
			array( $this, 'render_section' ),
			Quiet_Metrics_Settings::PAGE_SLUG
		);
		add_settings_field(
			'quiet_metrics_read_token',
			__( 'Jeton de lecture', 'quiet-metrics' ),
			array( $this, 'render_token_field' ),
			Quiet_Metrics_Settings::PAGE_SLUG,
			'quiet_metrics_section_dashboard',
			array( 'label_for' => 'quiet_metrics_read_token' )
		);
	}

	/**
	 * Champ vide laissé tel quel : le jeton enregistré est conservé, puisqu'il
	 * n'est jamais réaffiché. La case « supprimer » le retire.
	 *
	 * @param mixed $input Valeur soumise : array( 'token' => ..., 'clear' => ... ).
	 * @return string
	 */
	public function sanitize_token( $input ) {
		$current = (string) get_option( self::TOKEN_OPTION, '' );
		if ( ! is_array( $input ) ) {
			return is_string( $input ) ? sanitize_text_field( $input ) : $current;
		}
		if ( ! empty( $input['clear'] ) ) {
			return '';
		}
		$token = isset( $input['token'] ) ? sanitize_text_field( (string) $input['token'] ) : '';
		return '' !== $token ? $token : $current;
	}

	/**
	 * Intro de la section « Tableau de bord ».
	 *
	 * @return void
	 */
	public function render_section() {
		echo '<p>' . esc_html__( 'Pour afficher vos chiffres dans le tableau de bord de WordPress, créez dans Quiet Metrics (Jetons API) un jeton restreint à ce seul site, puis collez-le ici. Il ne sert qu\'à lire les statistiques et reste distinct des clés de collecte ci-dessus.', 'quiet-metrics' ) . '</p>';
	}

	/**
	 * Champ masqué : la valeur enregistrée n'est jamais réécrite dans la page.
	 *
	 * @return void
	 */
	public function render_token_field() {
		$saved = '' !== (string) get_option( self::TOKEN_OPTION, '' );
		printf(
			'<input type="password" id="quiet_metrics_read_token" class="regular-text code" name="%s[token]" value="" autocomplete="new-password" spellcheck="false" />',
			esc_attr( self::TOKEN_OPTION )
		);
		if ( $saved ) {
			echo '<p class="description">' . esc_html__( 'Un jeton est enregistré. Laissez le champ vide pour le conserver.', 'quiet-metrics' ) . '</p>';
			printf(
				'<p><label><input type="checkbox" name="%s[clear]" value="1" /> %s</label></p>',
				esc_attr( self::TOKEN_OPTION ),
				esc_html__( 'Supprimer le jeton enregistré', 'quiet-metrics' )
			);
		} else {
			echo '<p class="description">' . esc_html__( 'Facultatif. Sans jeton, le widget n\'affiche rien.', 'quiet-metrics' ) . '</p>';
		}
	}

	/**
	 * Le widget n'est déclaré que pour qui a la capacité requise.
	 *
	 * @return void
	 */
	public function register_widget() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		wp_add_dashboard_widget( 'quiet_metrics_dashboard', __( 'Quiet Metrics', 'quiet-metrics' ), array( $this, 'render' ) );
	}

	/**
	 * Chiffres du site, depuis le cache ou l'API de lecture.
	 *
	 * @return array<string,mixed> Toujours un `status` : ok, not_configured, insecure, rejected (401, 404), forbidden (403), unavailable.
	 */
	public function stats() {
		$settings = quiet_metrics_get_settings();
		$token    = (string) get_option( self::TOKEN_OPTION, '' );
		$origin   = untrailingslashit( (string) $settings['service_url'] );
		if ( '' === $token || '' === (string) $settings['site_key'] || '' === $origin ) {
			return array( 'status' => 'not_configured' );
		}
		if ( ! self::is_secure( $origin ) ) {
			return array( 'status' => 'insecure' );
		}

		// Empreinte et non jeton en clair dans le nom du transient.
		$key    = self::CACHE_PREFIX . substr( hash( 'sha256', $origin . '|' . $settings['site_key'] . '|' . $token . '|' . self::PERIOD ), 0, 32 );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$stats = $this->fetch( $origin, (string) $settings['site_key'], $token );
		set_transient( $key, $stats, 'ok' === $stats['status'] ? self::CACHE_TTL : self::FAILURE_CACHE_TTL );
		return $stats;
	}

	/**
	 * Le jeton ne part jamais en clair : https obligatoire, sauf vers la
	 * machine elle-même (développement local).
	 *
	 * @param string $origin URL du service.
	 * @return bool
	 */
	public static function is_secure( $origin ) {
		$scheme = wp_parse_url( $origin, PHP_URL_SCHEME );
		$host   = wp_parse_url( $origin, PHP_URL_HOST );
		return 'https' === $scheme || in_array( $host, array( 'localhost', '127.0.0.1' ), true );
	}

	/**
	 * Trois appels à l'API de lecture, côté serveur.
	 *
	 * @param string $origin     URL du service.
	 * @param string $public_key Clé publique du site.
	 * @param string $token      Jeton de lecture.
	 * @return array<string,mixed>
	 */
	private function fetch( $origin, $public_key, $token ) {
		$base    = $origin . '/api/v1/stats/' . rawurlencode( $public_key ) . '/';
		$queries = array(
			'summary'    => array( 'period' => self::PERIOD ),
			'timeseries' => array(
				'period'      => self::PERIOD,
				'granularity' => 'day',
			),
			'breakdown'  => array(
				'period'    => self::PERIOD,
				'dimension' => 'page',
				'limit'     => 5,
			),
		);

		$bodies = array();
		foreach ( $queries as $endpoint => $query ) {
			$response = wp_remote_get(
				$base . $endpoint . '?' . http_build_query( $query ),
				array(
					'timeout'     => 5,
					// Pas de redirection suivie : elle emporterait l'en-tête du jeton ailleurs.
					'redirection' => 0,
					'headers'     => array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				return array( 'status' => 'unavailable' );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 401, 404 ), true ) ) {
				return array( 'status' => 'rejected' );
			}
			if ( 403 === $code ) {
				return array( 'status' => 'forbidden' );
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
				return array( 'status' => 'unavailable' );
			}
			$bodies[ $endpoint ] = $body;
		}

		return self::shape( $bodies['summary'], $bodies['timeseries'], $bodies['breakdown'], $origin );
	}

	/**
	 * Réduit les trois réponses à ce que le widget affiche.
	 *
	 * @param array<string,mixed> $summary    Réponse de summary.
	 * @param array<string,mixed> $timeseries Réponse de timeseries.
	 * @param array<string,mixed> $breakdown  Réponse de breakdown.
	 * @param string              $origin     URL du service.
	 * @return array<string,mixed>
	 */
	private static function shape( array $summary, array $timeseries, array $breakdown, $origin ) {
		// Une réponse 200 malformée vaut une panne.
		$from = $summary['period']['from'] ?? null;
		$to   = $summary['period']['to'] ?? null;
		if ( ! self::is_date( $from ) || ! self::is_date( $to ) ) {
			return array( 'status' => 'unavailable' );
		}

		// Une fenêtre précédente non mesurée n'est pas une base de comparaison.
		$measured = false !== ( $summary['comparison']['measured'] ?? true );

		$kpis = array();
		foreach ( array( 'visitors', 'pageviews', 'sessions' ) as $metric ) {
			$current          = $summary['kpis'][ $metric ] ?? null;
			$previous         = $measured ? ( $summary['previous'][ $metric ] ?? null ) : null;
			$kpis[ $metric ]  = array(
				'value'  => is_numeric( $current ) ? (int) $current : null,
				'change' => self::change( $current, $previous ),
			);
		}

		$pages = array();
		foreach ( (array) ( $breakdown['data'] ?? array() ) as $row ) {
			if ( is_string( $row['label'] ?? null ) ) {
				$pages[] = array(
					'label' => $row['label'],
					'value' => (int) ( $row['value'] ?? 0 ),
				);
			}
		}

		$trend = array();
		foreach ( (array) ( $timeseries['data'] ?? array() ) as $point ) {
			$trend[] = (int) ( $point['visitors'] ?? 0 );
		}

		return array(
			'status' => 'ok',
			'from'   => $from,
			'to'     => $to,
			'kpis'   => $kpis,
			'trend'  => $trend,
			'pages'  => $pages,
			'capped' => ! empty( $breakdown['capped']['any'] ),
			'url'    => $origin . '/app',
		);
	}

	/**
	 * Date de l'API au format AAAA-MM-JJ, et qui existe.
	 *
	 * @param mixed $value Valeur reçue.
	 * @return bool
	 */
	private static function is_date( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Évolution en pourcentage entier, ou null quand il n'y a rien à comparer :
	 * précédent absent ou nul. Jamais un -100 % fabriqué depuis un vide.
	 *
	 * @param mixed $current  Valeur de la période.
	 * @param mixed $previous Valeur de la période précédente.
	 * @return int|null
	 */
	public static function change( $current, $previous ) {
		if ( ! is_numeric( $current ) || ! is_numeric( $previous ) || 0.0 === (float) $previous ) {
			return null;
		}
		return (int) round( ( (float) $current - (float) $previous ) / (float) $previous * 100 );
	}

	/**
	 * Date de l'API (AAAA-MM-JJ) au format de date du site.
	 *
	 * @param string $day Date ISO.
	 * @return string
	 */
	private static function date( $day ) {
		$time = strtotime( $day );
		return false === $time ? '' : date_i18n( (string) get_option( 'date_format' ), $time );
	}

	/**
	 * Rendu du widget. Toute valeur passe par esc_html / esc_attr / esc_url.
	 *
	 * @return void
	 */
	public function render() {
		$stats = $this->stats();

		if ( 'ok' !== $stats['status'] ) {
			$messages = array(
				'not_configured' => __( 'Renseignez la clé publique du site et un jeton de lecture dans Réglages > Quiet Metrics pour afficher vos chiffres ici.', 'quiet-metrics' ),
				'insecure'       => __( 'L\'URL du service doit être en https : le jeton de lecture ne part jamais sur une connexion non chiffrée.', 'quiet-metrics' ),
				'rejected'       => __( 'Quiet Metrics n\'a pas accepté le jeton de lecture : vérifiez qu\'il est toujours valide (ni expiré, ni révoqué) et que la clé publique est bien celle de ce site.', 'quiet-metrics' ),
				'forbidden'      => __( 'Quiet Metrics a refusé l\'accès : l\'API de lecture demande le plan Pro ou Agence (ou un essai en cours), et le jeton doit couvrir ce site.', 'quiet-metrics' ),
				'unavailable'    => __( 'Les chiffres sont indisponibles pour le moment. Réessayez dans quelques minutes.', 'quiet-metrics' ),
			);
			echo '<p role="status">' . esc_html( $messages[ $stats['status'] ] ?? $messages['unavailable'] ) . '</p>';
			return;
		}

		$from = self::date( $stats['from'] );
		$to   = self::date( $stats['to'] );

		/* translators: 1: first day, 2: last day of the period. */
		echo '<p>' . esc_html( sprintf( __( 'Du %1$s au %2$s', 'quiet-metrics' ), $from, $to ) ) . '</p>';

		$labels = array(
			'visitors'  => __( 'Visiteurs par jour, cumulés', 'quiet-metrics' ),
			'pageviews' => __( 'Pages vues', 'quiet-metrics' ),
			'sessions'  => __( 'Sessions', 'quiet-metrics' ),
		);
		echo '<dl style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));margin:0;">';
		foreach ( $labels as $metric => $label ) {
			$kpi = $stats['kpis'][ $metric ];
			if ( null === $kpi['change'] ) {
				$change = __( 'Pas de comparaison', 'quiet-metrics' );
			} elseif ( 0 === $kpi['change'] ) {
				$change = __( 'Stable par rapport à la période précédente', 'quiet-metrics' );
			} elseif ( $kpi['change'] > 0 ) {
				/* translators: %s: percentage. */
				$change = sprintf( __( 'En hausse de %s %% sur la période précédente', 'quiet-metrics' ), number_format_i18n( $kpi['change'] ) );
			} else {
				/* translators: %s: percentage. */
				$change = sprintf( __( 'En baisse de %s %% sur la période précédente', 'quiet-metrics' ), number_format_i18n( abs( $kpi['change'] ) ) );
			}
			echo '<div>';
			echo '<dt>' . esc_html( $label ) . '</dt>';
			echo '<dd style="margin:4px 0 0;font-size:20px;font-weight:600;">' . esc_html( null === $kpi['value'] ? '' : number_format_i18n( $kpi['value'] ) ) . '</dd>';
			echo '<dd style="margin:4px 0 0;">' . esc_html( $change ) . '</dd>';
			if ( 'visitors' === $metric ) {
				echo '<dd style="margin:4px 0 0;font-size:12px;">' . esc_html__( 'Somme des visiteurs uniques de chaque journée : une personne revenue un autre jour est recomptée.', 'quiet-metrics' ) . '</dd>';
			}
			echo '</div>';
		}
		echo '</dl>';

		$points = self::sparkline( $stats['trend'] );
		if ( '' !== $points ) {
			/* translators: 1: first day, 2: last day, 3: lowest, 4: highest daily visitors. */
			$summary = sprintf( __( 'Visiteurs par jour, du %1$s au %2$s : entre %3$s et %4$s.', 'quiet-metrics' ), $from, $to, number_format_i18n( min( $stats['trend'] ) ), number_format_i18n( max( $stats['trend'] ) ) );
			printf(
				'<svg role="img" aria-label="%s" viewBox="0 0 100 24" preserveAspectRatio="none" style="display:block;width:100%%;height:48px;margin-top:16px;"><polyline points="%s" fill="none" stroke="currentColor" stroke-width="1.5" vector-effect="non-scaling-stroke" /></svg>',
				esc_attr( $summary ),
				esc_attr( $points )
			);
		}

		echo '<table class="widefat striped" style="margin-top:16px;">';
		echo '<caption style="text-align:start;font-weight:600;padding-bottom:8px;">' . esc_html__( 'Pages les plus vues', 'quiet-metrics' ) . '</caption>';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Page', 'quiet-metrics' ) . '</th><th scope="col" style="text-align:end;">' . esc_html__( 'Vues', 'quiet-metrics' ) . '</th></tr></thead><tbody>';
		if ( array() === $stats['pages'] ) {
			echo '<tr><td colspan="2">' . esc_html__( 'Aucune page vue sur la période.', 'quiet-metrics' ) . '</td></tr>';
		}
		foreach ( $stats['pages'] as $page ) {
			$label = '~autres' === $page['label'] ? __( 'Autres pages, regroupées', 'quiet-metrics' ) : $page['label'];
			echo '<tr><td style="overflow-wrap:anywhere;">' . esc_html( $label ) . '</td><td style="text-align:end;">' . esc_html( number_format_i18n( $page['value'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $stats['capped'] ) {
			echo '<p>' . esc_html__( 'Au-delà du plafond quotidien, les pages les moins vues sont regroupées sur une ligne : aucune visite n\'est perdue.', 'quiet-metrics' ) . '</p>';
		}

		echo '<p><a href="' . esc_url( $stats['url'] ) . '">' . esc_html__( 'Ouvrir dans Quiet Metrics', 'quiet-metrics' ) . '</a></p>';
	}

	/**
	 * Points d'une polyligne SVG de 100 x 24, sans JavaScript.
	 *
	 * @param int[] $values Visiteurs par jour.
	 * @return string
	 */
	private static function sparkline( array $values ) {
		$count = count( $values );
		if ( $count < 2 ) {
			return '';
		}
		$max    = max( max( $values ), 1 );
		$points = array();
		foreach ( array_values( $values ) as $i => $value ) {
			$points[] = round( $i * 100 / ( $count - 1 ), 2 ) . ',' . round( 23 - $value * 22 / $max, 2 );
		}
		return implode( ' ', $points );
	}
}
