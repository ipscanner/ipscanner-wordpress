<?php
/**
 * Settings page, onboarding, connection test, dashboard widget and notices.
 *
 * @package IPScanner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens.
 */
final class IPScanner_Admin {

	const PAGE          = 'ipscanner';
	const DASHBOARD_URL = 'https://ipscanner.io/dashboard/sites';
	const KEYS_URL      = 'https://ipscanner.io/dashboard/apikeys';
	const NEW_SITE_URL  = 'https://ipscanner.io/dashboard/sites?new=wordpress';
	const ONBOARDING    = 'ipscanner_onboarding';
	const USAGE         = 'ipscanner_usage';
	const LOG_ROWS      = 10;

	/**
	 * Areas offered in the onboarding card.
	 */
	const QUICK_AREAS = array( 'login', 'register', 'comments', 'woo_checkout' );

	/**
	 * Settings.
	 *
	 * @var IPScanner_Settings
	 */
	private $settings;

	/**
	 * API client.
	 *
	 * @var IPScanner_API
	 */
	private $api;

	/**
	 * Usage events.
	 *
	 * @var IPScanner_Telemetry
	 */
	private $telemetry;

	/**
	 * Constructor.
	 *
	 * @param IPScanner_Settings  $settings  Settings.
	 * @param IPScanner_API       $api       API client.
	 * @param IPScanner_Telemetry $telemetry Usage events.
	 */
	public function __construct( IPScanner_Settings $settings, IPScanner_API $api, IPScanner_Telemetry $telemetry ) {
		$this->settings  = $settings;
		$this->api       = $api;
		$this->telemetry = $telemetry;
	}

	/**
	 * Adds the admin hooks.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_ipscanner_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_ipscanner_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_ipscanner_onboard_key', array( $this, 'handle_onboard_key' ) );
		add_action( 'admin_post_ipscanner_onboard_areas', array( $this, 'handle_onboard_areas' ) );
		add_action( 'admin_post_ipscanner_onboard_dismiss', array( $this, 'handle_onboard_dismiss' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( IPSCANNER_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Registers the option with its sanitizer.
	 */
	public function register_setting() {
		register_setting(
			self::PAGE,
			IPScanner_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'IPScanner_Settings', 'sanitize' ),
				'default'           => IPScanner_Settings::defaults(),
			)
		);
	}

	/**
	 * Adds Settings > IPScanner.
	 */
	public function add_page() {
		add_options_page( 'IPScanner', 'IPScanner', 'manage_options', self::PAGE, array( $this, 'render_page' ) );
	}

	/**
	 * Loads the admin stylesheet on the plugin page and the dashboard.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ) {
		if ( in_array( $hook, array( 'settings_page_' . self::PAGE, 'index.php' ), true ) ) {
			wp_enqueue_style( 'ipscanner-admin', plugins_url( 'css/admin.css', IPSCANNER_FILE ), array(), IPSCANNER_VERSION );
		}
		if ( 'settings_page_' . self::PAGE === $hook ) {
			wp_enqueue_script( 'ipscanner-admin', plugins_url( 'js/admin.js', IPSCANNER_FILE ), array(), IPSCANNER_VERSION, true );
			wp_localize_script(
				'ipscanner-admin',
				'ipscannerConnect',
				array(
					'status'  => wp_nonce_url( add_query_arg( 'action', IPScanner_Connect::STATUS, admin_url( 'admin-ajax.php' ) ), IPScanner_Connect::STATUS ),
					'waiting' => __( 'Finish in the IPScanner window…', 'ipscanner-bot-blocker' ),
				)
			);
		}
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Settings', 'ipscanner-bot-blocker' ) . '</a>' );
		return $links;
	}

	/**
	 * Plugin page URL.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	private function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * The open tab.
	 *
	 * @return string overview or settings.
	 */
	private function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only tab switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return 'settings' === $tab ? 'settings' : 'overview';
	}

	/**
	 * Area labels.
	 *
	 * @return array<string,string>
	 */
	private function area_labels() {
		return array(
			'site'         => __( 'Whole site (front end)', 'ipscanner-bot-blocker' ),
			'login'        => __( 'Login', 'ipscanner-bot-blocker' ),
			'register'     => __( 'Registration', 'ipscanner-bot-blocker' ),
			'lostpassword' => __( 'Password reset', 'ipscanner-bot-blocker' ),
			'comments'     => __( 'Comments', 'ipscanner-bot-blocker' ),
			'woo_checkout' => __( 'WooCommerce checkout', 'ipscanner-bot-blocker' ),
			'woo_register' => __( 'WooCommerce account registration', 'ipscanner-bot-blocker' ),
			'forms'        => __( 'Contact Form 7 and WPForms', 'ipscanner-bot-blocker' ),
		);
	}

	/**
	 * Traffic class labels.
	 *
	 * @return array<string,string>
	 */
	private function class_labels() {
		return array(
			'human'                => __( 'Human', 'ipscanner-bot-blocker' ),
			'verified_bot'         => __( 'Verified crawler', 'ipscanner-bot-blocker' ),
			'ai_agent'             => __( 'AI agent', 'ipscanner-bot-blocker' ),
			'malicious_automation' => __( 'Malicious automation', 'ipscanner-bot-blocker' ),
			'tor'                  => __( 'Tor', 'ipscanner-bot-blocker' ),
			'vpn'                  => __( 'VPN', 'ipscanner-bot-blocker' ),
			'proxy'                => __( 'Proxy', 'ipscanner-bot-blocker' ),
			'hosting'              => __( 'Hosting or data center', 'ipscanner-bot-blocker' ),
			'relay'                => __( 'Privacy relay', 'ipscanner-bot-blocker' ),
		);
	}

	/**
	 * Label for a traffic class, or a dash.
	 *
	 * @param string $traffic_class Traffic class.
	 * @return string
	 */
	private function class_label( $traffic_class ) {
		$labels = $this->class_labels();
		if ( '' === (string) $traffic_class ) {
			return '-';
		}
		return isset( $labels[ $traffic_class ] ) ? $labels[ $traffic_class ] : (string) $traffic_class;
	}

	/**
	 * Decision labels.
	 *
	 * @return array<string,string>
	 */
	private function decision_labels() {
		return array(
			'allow'       => __( 'Allowed', 'ipscanner-bot-blocker' ),
			'flag'        => __( 'Flagged', 'ipscanner-bot-blocker' ),
			'block'       => __( 'Blocked', 'ipscanner-bot-blocker' ),
			'would_flag'  => __( 'Would flag', 'ipscanner-bot-blocker' ),
			'would_block' => __( 'Would block', 'ipscanner-bot-blocker' ),
		);
	}

	/**
	 * Cached plan and usage. Fetched at most every 5 minutes, and only when
	 * $fetch is set (the plugin page), never on other admin pages.
	 *
	 * @param bool $fetch Call the API when nothing is cached.
	 * @return array|null Result of IPScanner_API::request(), or null.
	 */
	private function usage( $fetch ) {
		$cached = get_transient( self::USAGE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		return $fetch && '' !== $this->settings->api_key() ? $this->refresh_usage() : null;
	}

	/**
	 * Calls the usage endpoint and caches the result.
	 *
	 * @return array Result of IPScanner_API::request().
	 */
	private function refresh_usage() {
		$res = $this->api->usage();
		if ( $res['ok'] ) {
			delete_option( 'ipscanner_last_error' );
			delete_transient( 'ipscanner_backoff' );
		}
		set_transient( self::USAGE, $res, $res['ok'] ? 5 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );
		return $res;
	}

	/**
	 * Plan name and percentage used, from a usage result.
	 *
	 * @param array $usage Result of IPScanner_API::request().
	 * @return array{plan:string,percent:int|null}
	 */
	private static function plan( array $usage ) {
		$d       = isset( $usage['data'] ) && is_array( $usage['data'] ) ? $usage['data'] : array();
		$limit   = isset( $d['limit'] ) ? (int) $d['limit'] : 0;
		$percent = null;
		if ( empty( $d['unlimited'] ) && $limit > 0 ) {
			$percent = (int) min( 100, round( ( isset( $d['used'] ) ? (int) $d['used'] : 0 ) * 100 / $limit ) );
		}
		return array(
			'plan'    => isset( $d['plan'] ) ? (string) $d['plan'] : '',
			'percent' => $percent,
		);
	}

	/**
	 * The mode in effect: the dashboard policy's mode when a Site ID is set.
	 *
	 * @return string
	 */
	private function mode() {
		$site = (string) $this->settings->get( 'site_id' );
		if ( '' === $site ) {
			return (string) $this->settings->get( 'mode' );
		}
		$entry = get_option( 'ipscanner_policy_cache', array() );
		if ( is_array( $entry ) && isset( $entry['site'], $entry['policy']['mode'] ) && $entry['site'] === $site ) {
			return (string) $entry['policy']['mode'];
		}
		return IPScanner_Policy::MODE_MONITOR;
	}

	/**
	 * Whether the onboarding card replaces the overview.
	 *
	 * @return bool
	 */
	private function onboarding() {
		return '' === $this->settings->api_key() || 'areas' === get_option( self::ONBOARDING );
	}

	/**
	 * Prints the IPScanner mark.
	 *
	 * @param int $size Size in pixels.
	 */
	private function mark( $size ) {
		?>
		<svg class="ipscanner-mark" width="<?php echo esc_attr( $size ); ?>" height="<?php echo esc_attr( $size ); ?>" viewBox="0 0 64 64" aria-hidden="true" focusable="false"><rect x="2" y="2" width="60" height="60" rx="16" fill="#1C2E1E"/><g transform="translate(32 32)" fill="#FAFBF9"><rect x="-4" y="-21" width="8" height="42" rx="4"/><rect x="-4" y="-21" width="8" height="42" rx="4" transform="rotate(60)"/><rect x="-4" y="-21" width="8" height="42" rx="4" transform="rotate(120)"/></g></svg>
		<?php
	}

	/**
	 * Renders Settings > IPScanner.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = $this->current_tab();
		$this->track_view( $tab );
		?>
		<div class="wrap ipscanner">
			<h1 class="ipscanner-title"><?php $this->mark( 28 ); ?> IPScanner</h1>
			<hr class="wp-header-end">
			<?php $this->render_test_result(); ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'IPScanner', 'ipscanner-bot-blocker' ); ?>">
				<a href="<?php echo esc_url( $this->page_url() ); ?>" class="nav-tab<?php echo 'overview' === $tab ? ' nav-tab-active' : ''; ?>"<?php echo 'overview' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Overview', 'ipscanner-bot-blocker' ); ?></a>
				<a href="<?php echo esc_url( $this->page_url( array( 'tab' => 'settings' ) ) ); ?>" class="nav-tab<?php echo 'settings' === $tab ? ' nav-tab-active' : ''; ?>"<?php echo 'settings' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Settings', 'ipscanner-bot-blocker' ); ?></a>
			</nav>
			<?php
			if ( 'settings' === $tab ) {
				$this->render_settings();
			} elseif ( $this->onboarding() ) {
				$this->render_onboarding();
			} else {
				$this->render_overview();
			}
			?>
		</div>
		<?php
	}

	/**
	 * One wp_admin_viewed per tab every 12 hours.
	 *
	 * @param string $tab Open tab.
	 */
	private function track_view( $tab ) {
		$view = 'overview' === $tab && $this->onboarding() ? 'onboarding' : $tab;
		$key  = 'ipscanner_viewed_' . $view;
		if ( ! $this->telemetry->enabled() || get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 12 * HOUR_IN_SECONDS );
		$this->telemetry->send( 'wp_admin_viewed', array( 'tab' => $view ) );
	}

	/**
	 * The Connect button. Opens the IPScanner consent page in a popup (js/admin.js),
	 * or in this tab without JavaScript or when popups are blocked.
	 */
	private function connect_button() {
		?>
		<p class="ipscanner-connect-row">
			<a class="button button-primary ipscanner-connect" href="<?php echo esc_url( IPScanner_Connect::start_url() ); ?>" data-ipscanner-connect><?php esc_html_e( 'Connect with IPScanner', 'ipscanner-bot-blocker' ); ?></a>
			<span class="ipscanner-connect-wait" aria-live="polite"></span>
		</p>
		<?php
	}

	/**
	 * "Connected as ..." with the account and plan when they are known.
	 *
	 * @param array|null $usage Usage result, for the plan.
	 * @return string
	 */
	private function connected_text( $usage ) {
		$account = get_option( IPScanner_Connect::ACCOUNT );
		$email   = is_array( $account ) && ! empty( $account['email'] ) ? (string) $account['email'] : '';
		$plan    = is_array( $usage ) && $usage['ok'] ? self::plan( $usage )['plan'] : '';
		if ( '' !== $email && '' !== $plan ) {
			/* translators: 1: account email, 2: plan name */
			return sprintf( __( 'Connected as %1$s, %2$s plan', 'ipscanner-bot-blocker' ), $email, $plan );
		}
		if ( '' !== $email ) {
			/* translators: %s: account email */
			return sprintf( __( 'Connected as %s', 'ipscanner-bot-blocker' ), $email );
		}
		/* translators: %s: plan name */
		return '' === $plan ? __( 'Connected', 'ipscanner-bot-blocker' ) : sprintf( __( 'Connected, %s plan', 'ipscanner-bot-blocker' ), $plan );
	}

	/**
	 * The welcome card shown until a key is connected and the areas are chosen.
	 */
	private function render_onboarding() {
		$key   = $this->settings->api_key();
		$usage = '' === $key ? null : $this->usage( true );
		$ok    = is_array( $usage ) && $usage['ok'];
		$step  = $ok ? 2 : 1;
		$state = function ( $n ) use ( $step ) {
			if ( $n < $step ) {
				return 'is-done';
			}
			return $n === $step ? 'is-current' : 'is-upcoming';
		};
		$areas = (array) $this->settings->get( 'areas' );
		$woo   = class_exists( 'WooCommerce' );
		$quick = array(
			'login'        => __( 'Login', 'ipscanner-bot-blocker' ),
			'register'     => __( 'Registration', 'ipscanner-bot-blocker' ),
			'comments'     => __( 'Comments', 'ipscanner-bot-blocker' ),
			'woo_checkout' => __( 'Checkout', 'ipscanner-bot-blocker' ),
		);
		if ( ! $woo ) {
			unset( $quick['woo_checkout'] );
		}
		?>
		<div class="ipscanner-welcome">
			<div class="ipscanner-welcome-head">
				<?php $this->mark( 40 ); ?>
				<h2><?php esc_html_e( 'Protect logins, forms and checkout', 'ipscanner-bot-blocker' ); ?></h2>
				<?php if ( $step >= 2 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ipscanner-welcome-skip">
						<input type="hidden" name="action" value="ipscanner_onboard_dismiss">
						<?php wp_nonce_field( 'ipscanner_onboard_dismiss' ); ?>
						<button type="submit" class="button-link"><?php esc_html_e( 'Skip', 'ipscanner-bot-blocker' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
			<ol class="ipscanner-steps is-two">
				<li class="ipscanner-step <?php echo esc_attr( $state( 1 ) ); ?>">
					<span class="ipscanner-step-icon dashicons <?php echo esc_attr( 1 < $step ? 'dashicons-yes-alt' : 'dashicons-admin-links' ); ?>" aria-hidden="true"></span>
					<h3><?php esc_html_e( 'Connect your IPScanner account', 'ipscanner-bot-blocker' ); ?></h3>
					<?php if ( 1 < $step ) : ?>
						<p class="ipscanner-step-note"><?php echo esc_html( $this->connected_text( $usage ) ); ?></p>
					<?php else : ?>
						<?php $this->connect_button(); ?>
						<p class="ipscanner-step-note"><?php esc_html_e( 'Sign up or sign in, free, no credit card.', 'ipscanner-bot-blocker' ); ?></p>
						<?php if ( is_array( $usage ) && ! $ok ) : ?>
							<p class="ipscanner-step-error"><?php echo esc_html( $this->failure_text( $usage ) ); ?></p>
						<?php endif; ?>
						<details class="ipscanner-paste">
							<summary><?php esc_html_e( 'Use an API key instead', 'ipscanner-bot-blocker' ); ?></summary>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ipscanner-inline-form">
								<input type="hidden" name="action" value="ipscanner_onboard_key">
								<?php wp_nonce_field( 'ipscanner_onboard_key' ); ?>
								<label for="ipscanner-onboard-key" class="screen-reader-text"><?php esc_html_e( 'API key', 'ipscanner-bot-blocker' ); ?></label>
								<input type="password" id="ipscanner-onboard-key" name="api_key" class="regular-text" placeholder="pk_live_..." autocomplete="new-password" spellcheck="false" required>
								<button type="submit" class="button"><?php esc_html_e( 'Save', 'ipscanner-bot-blocker' ); ?></button>
							</form>
						</details>
					<?php endif; ?>
				</li>
				<li class="ipscanner-step <?php echo esc_attr( $state( 2 ) ); ?>">
					<span class="ipscanner-step-icon dashicons dashicons-shield" aria-hidden="true"></span>
					<h3><?php esc_html_e( 'Choose what to protect', 'ipscanner-bot-blocker' ); ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ipscanner_onboard_areas">
						<?php wp_nonce_field( 'ipscanner_onboard_areas' ); ?>
						<fieldset <?php disabled( ! $ok ); ?>>
							<legend class="screen-reader-text"><?php esc_html_e( 'Choose what to protect', 'ipscanner-bot-blocker' ); ?></legend>
							<?php foreach ( $quick as $area => $label ) : ?>
								<label><input type="checkbox" name="areas[]" value="<?php echo esc_attr( $area ); ?>" <?php checked( in_array( $area, $areas, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
							<p><button type="submit" class="button <?php echo esc_attr( 2 === $step ? 'button-primary' : '' ); ?>"><?php esc_html_e( 'Save', 'ipscanner-bot-blocker' ); ?></button></p>
						</fieldset>
					</form>
				</li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Status, last 24 hours and recent decisions.
	 */
	private function render_overview() {
		$usage   = $this->usage( true );
		$mode    = $this->mode();
		$site    = (string) $this->settings->get( 'site_id' );
		$totals  = IPScanner_Stats::last_day();
		$enforce = IPScanner_Policy::MODE_ENFORCE === $mode;
		?>
		<div class="ipscanner-panel ipscanner-status">
			<?php $this->render_status( $usage ); ?>
			<p class="ipscanner-status-mode">
				<?php
				echo esc_html( $enforce ? __( 'Enforce mode', 'ipscanner-bot-blocker' ) : __( 'Monitor mode', 'ipscanner-bot-blocker' ) );
				if ( '' !== $site ) {
					printf(
						' <a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
						esc_url( self::DASHBOARD_URL . '/' . rawurlencode( $site ) ),
						esc_html__( 'Policy from dashboard', 'ipscanner-bot-blocker' )
					);
				} else {
					printf( ' <a href="%1$s">%2$s</a>', esc_url( $this->page_url( array( 'tab' => 'settings' ) ) ), esc_html__( 'Change', 'ipscanner-bot-blocker' ) );
				}
				?>
			</p>
			<?php if ( '' === $site ) : ?>
				<p class="ipscanner-muted">
					<a href="<?php echo esc_url( self::NEW_SITE_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Add this site to the IPScanner dashboard for traffic charts', 'ipscanner-bot-blocker' ); ?></a>
				</p>
			<?php endif; ?>
		</div>

		<h2><?php esc_html_e( 'Last 24 hours', 'ipscanner-bot-blocker' ); ?></h2>
		<div class="ipscanner-numbers">
			<div class="ipscanner-number">
				<span class="ipscanner-number-value"><?php echo esc_html( number_format_i18n( $totals['checked'] ) ); ?></span>
				<span class="ipscanner-number-label"><?php esc_html_e( 'Checked', 'ipscanner-bot-blocker' ); ?></span>
			</div>
			<div class="ipscanner-number">
				<span class="ipscanner-number-value"><?php echo esc_html( number_format_i18n( $enforce ? $totals['blocked'] : $totals['would'] ) ); ?></span>
				<span class="ipscanner-number-label"><?php echo esc_html( $enforce ? __( 'Blocked', 'ipscanner-bot-blocker' ) : __( 'Would block', 'ipscanner-bot-blocker' ) ); ?></span>
			</div>
			<div class="ipscanner-number">
				<span class="ipscanner-number-value is-text"><?php echo esc_html( $this->class_label( $enforce ? $totals['top'] : $totals['top_would'] ) ); ?></span>
				<span class="ipscanner-number-label"><?php esc_html_e( 'Top reason', 'ipscanner-bot-blocker' ); ?></span>
			</div>
		</div>

		<h2><?php esc_html_e( 'Recent decisions', 'ipscanner-bot-blocker' ); ?></h2>
		<?php $this->render_log( array_slice( IPScanner_Log::all(), 0, self::LOG_ROWS ) ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ipscanner_clear_log">
			<?php wp_nonce_field( 'ipscanner_clear_log' ); ?>
			<p><?php submit_button( __( 'Clear', 'ipscanner-bot-blocker' ), 'secondary', 'ipscanner_clear', false ); ?></p>
		</form>
		<?php
	}

	/**
	 * The connection line: status, plan and usage.
	 *
	 * @param array|null $usage Cached usage result, or null.
	 */
	private function render_status( $usage ) {
		$error = get_option( 'ipscanner_last_error' );
		if ( is_array( $usage ) && ! $usage['ok'] ) {
			$state = 'is-error';
			$text  = $this->failure_text( $usage );
		} elseif ( null === $usage && is_array( $error ) ) {
			$state = 'is-error';
			$text  = $this->failure_text(
				array(
					'code'    => (int) $error['code'],
					'message' => (string) $error['message'],
				)
			);
		} else {
			$state = 'is-ok';
			$text  = __( 'Connected', 'ipscanner-bot-blocker' );
		}
		$plan = is_array( $usage ) && $usage['ok'] ? self::plan( $usage ) : array(
			'plan'    => '',
			'percent' => null,
		);
		?>
		<p class="ipscanner-status-line">
			<span class="ipscanner-dot <?php echo esc_attr( $state ); ?>" aria-hidden="true"></span>
			<strong><?php echo esc_html( $text ); ?></strong>
			<?php if ( '' !== $plan['plan'] ) : ?>
				<?php /* translators: %s: plan name */ ?>
				<span><?php echo esc_html( sprintf( __( '%s plan', 'ipscanner-bot-blocker' ), $plan['plan'] ) ); ?></span>
			<?php endif; ?>
			<?php if ( null !== $plan['percent'] ) : ?>
				<span class="ipscanner-usage">
					<span class="ipscanner-bar" aria-hidden="true"><span style="width:<?php echo esc_attr( max( 1, $plan['percent'] ) ); ?>%"></span></span>
					<?php /* translators: %d: percentage of the monthly requests used */ ?>
					<?php echo esc_html( sprintf( __( '%d%% used this month', 'ipscanner-bot-blocker' ), $plan['percent'] ) ); ?>
				</span>
			<?php endif; ?>
			<?php if ( 'is-error' === $state ) : ?>
				<a href="<?php echo esc_url( $this->page_url( array( 'tab' => 'settings' ) ) ); ?>"><?php esc_html_e( 'Settings', 'ipscanner-bot-blocker' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * The settings form, in sections.
	 */
	private function render_settings() {
		$s        = $this->settings->all();
		$name     = IPScanner_Settings::OPTION;
		$key      = $this->settings->api_key();
		$by_const = defined( 'IPSCANNER_API_KEY' ) && '' !== $key && $key !== $s['api_key'];
		$woo      = class_exists( 'WooCommerce' );
		$ip       = IPScanner_Request::client_ip( IPScanner_Request::server_vars(), (string) $s['ip_source'], $this->settings->trusted_proxies() );
		?>
		<form method="post" action="options.php" class="ipscanner-settings">
			<?php settings_fields( self::PAGE ); ?>

			<h2><?php esc_html_e( 'Connection', 'ipscanner-bot-blocker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ipscanner-api-key"><?php esc_html_e( 'API key', 'ipscanner-bot-blocker' ); ?></label></th>
					<td>
						<?php if ( $by_const ) : ?>
							<p><?php esc_html_e( 'Set by the IPSCANNER_API_KEY constant.', 'ipscanner-bot-blocker' ); ?></p>
						<?php elseif ( '' !== $s['api_key'] ) : ?>
							<p class="ipscanner-connected">
								<span class="ipscanner-dot is-ok" aria-hidden="true"></span>
								<?php echo esc_html( $this->connected_text( $this->usage( false ) ) ); ?>
								<button type="submit" form="ipscanner-disconnect" class="button-link ipscanner-disconnect"><?php esc_html_e( 'Disconnect', 'ipscanner-bot-blocker' ); ?></button>
							</p>
							<details class="ipscanner-paste">
								<summary><?php esc_html_e( 'Use a different API key', 'ipscanner-bot-blocker' ); ?></summary>
								<input type="password" id="ipscanner-api-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" class="regular-text" autocomplete="new-password" spellcheck="false"
									placeholder="<?php echo esc_attr( sprintf( /* translators: %s: last 4 characters of the key */ __( 'Saved, ends in %s', 'ipscanner-bot-blocker' ), substr( $s['api_key'], -4 ) ) ); ?>">
							</details>
						<?php else : ?>
							<?php $this->connect_button(); ?>
							<p class="description"><?php esc_html_e( 'Or paste an API key:', 'ipscanner-bot-blocker' ); ?></p>
							<input type="password" id="ipscanner-api-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" class="regular-text" autocomplete="new-password" spellcheck="false"
								placeholder="<?php echo esc_attr( '' === $s['api_key'] ? 'pk_live_...' : sprintf( /* translators: %s: last 4 characters of the key */ __( 'Saved, ends in %s', 'ipscanner-bot-blocker' ), substr( $s['api_key'], -4 ) ) ); ?>">
							<?php if ( '' !== $s['api_key'] ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[api_key_clear]" value="1"> <?php esc_html_e( 'Remove', 'ipscanner-bot-blocker' ); ?></label>
							<?php endif; ?>
							<p class="description">
								<?php
								if ( '' === $key ) {
									printf(
										/* translators: %s: link to the API keys page */
										esc_html__( 'Get a key at %s.', 'ipscanner-bot-blocker' ),
										'<a href="' . esc_url( self::KEYS_URL ) . '" target="_blank" rel="noopener">ipscanner.io</a>'
									);
									echo ' ';
								}
								esc_html_e( 'Each new visitor check uses 2 requests. Results are cached for 10 minutes per visitor.', 'ipscanner-bot-blocker' );
								?>
							</p>
						<?php endif; ?>
						<?php if ( '' !== $key ) : ?>
							<p><button type="submit" form="ipscanner-test" class="button"><?php esc_html_e( 'Test connection', 'ipscanner-bot-blocker' ); ?></button></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ipscanner-site-id"><?php esc_html_e( 'Site ID', 'ipscanner-bot-blocker' ); ?></label></th>
					<td>
						<input type="text" id="ipscanner-site-id" name="<?php echo esc_attr( $name ); ?>[site_id]" value="<?php echo esc_attr( $s['site_id'] ); ?>" class="regular-text" placeholder="site_..." spellcheck="false">
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to the IPScanner dashboard */
								esc_html__( 'Optional. With a Site ID, mode and per class policy come from the %s and the mode and block settings below are ignored.', 'ipscanner-bot-blocker' ),
								'<a href="' . esc_url( self::DASHBOARD_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'IPScanner dashboard', 'ipscanner-bot-blocker' ) . '</a>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Protection', 'ipscanner-bot-blocker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Mode', 'ipscanner-bot-blocker' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="monitor" <?php checked( $s['mode'], 'monitor' ); ?>> <?php esc_html_e( 'Monitor: record decisions, block nothing', 'ipscanner-bot-blocker' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="enforce" <?php checked( $s['mode'], 'enforce' ); ?>> <?php esc_html_e( 'Enforce: block the classes selected below', 'ipscanner-bot-blocker' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Protected areas', 'ipscanner-bot-blocker' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( $this->area_labels() as $area => $label ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[areas][]" value="<?php echo esc_attr( $area ); ?>" <?php checked( in_array( $area, (array) $s['areas'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php if ( 0 === strpos( $area, 'woo_' ) && ! $woo ) : ?>
									<span class="description"><?php esc_html_e( '(WooCommerce is not active)', 'ipscanner-bot-blocker' ); ?></span>
								<?php endif; ?>
								<br>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Forms are checked when submitted. The whole site option checks every page view.', 'ipscanner-bot-blocker' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Block', 'ipscanner-bot-blocker' ); ?></th>
					<td>
						<fieldset>
							<?php
							$labels = $this->class_labels();
							foreach ( IPScanner_Policy::BLOCKABLE as $class ) :
								?>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[block_classes][]" value="<?php echo esc_attr( $class ); ?>" <?php checked( in_array( $class, (array) $s['block_classes'], true ) ); ?>> <?php echo esc_html( $labels[ $class ] ); ?></label><br>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Verified search engine and AI crawlers always pass.', 'ipscanner-bot-blocker' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Visitors and IPs', 'ipscanner-bot-blocker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ipscanner-allowlist"><?php esc_html_e( 'Always allow', 'ipscanner-bot-blocker' ); ?></label></th>
					<td>
						<textarea id="ipscanner-allowlist" name="<?php echo esc_attr( $name ); ?>[allowlist]" rows="4" class="large-text code" placeholder="203.0.113.7&#10;2001:db8::/32"><?php echo esc_textarea( $s['allowlist'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'IP addresses or CIDR ranges, one per line. Not checked and never blocked.', 'ipscanner-bot-blocker' ); ?></p>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[allow_admins]" value="1" <?php checked( $s['allow_admins'] ); ?>> <?php esc_html_e( 'Always allow administrators', 'ipscanner-bot-blocker' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ipscanner-ip-source"><?php esc_html_e( 'Visitor IP', 'ipscanner-bot-blocker' ); ?></label></th>
					<td>
						<select id="ipscanner-ip-source" name="<?php echo esc_attr( $name ); ?>[ip_source]">
							<option value="remote_addr" <?php selected( $s['ip_source'], 'remote_addr' ); ?>><?php esc_html_e( 'Direct connection (REMOTE_ADDR)', 'ipscanner-bot-blocker' ); ?></option>
							<option value="cloudflare" <?php selected( $s['ip_source'], 'cloudflare' ); ?>><?php esc_html_e( 'Cloudflare (CF-Connecting-IP)', 'ipscanner-bot-blocker' ); ?></option>
							<option value="x_forwarded_for" <?php selected( $s['ip_source'], 'x_forwarded_for' ); ?>><?php esc_html_e( 'Proxy or load balancer (X-Forwarded-For)', 'ipscanner-bot-blocker' ); ?></option>
						</select>
						<p class="description">
							<?php
							/* translators: %s: visitor IP address */
							printf( esc_html__( 'Your IP with this setting: %s', 'ipscanner-bot-blocker' ), '<code>' . esc_html( '' === $ip ? '-' : $ip ) . '</code>' );
							?>
						</p>
						<p><label for="ipscanner-trusted"><?php esc_html_e( 'Trusted proxies', 'ipscanner-bot-blocker' ); ?></label></p>
						<textarea id="ipscanner-trusted" name="<?php echo esc_attr( $name ); ?>[trusted_proxies]" rows="3" class="large-text code" placeholder="10.0.0.0/8"><?php echo esc_textarea( $s['trusted_proxies'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Proxy headers are read only from these addresses. Cloudflare ranges are built in.', 'ipscanner-bot-blocker' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Block page', 'ipscanner-bot-blocker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ipscanner-message"><?php esc_html_e( 'Block message', 'ipscanner-bot-blocker' ); ?></label></th>
					<td>
						<textarea id="ipscanner-message" name="<?php echo esc_attr( $name ); ?>[block_message]" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Your request was blocked. If you think this is a mistake, contact the site owner.', 'ipscanner-bot-blocker' ); ?>"><?php echo esc_textarea( $s['block_message'] ); ?></textarea>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_credit]" value="1" <?php checked( $s['show_credit'] ); ?>> <?php esc_html_e( 'Show "Protected by IPScanner" on the block page', 'ipscanner-bot-blocker' ); ?></label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Advanced', 'ipscanner-bot-blocker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ipscanner-timeout"><?php esc_html_e( 'Timeout', 'ipscanner-bot-blocker' ); ?></label></th>
					<td>
						<input type="number" id="ipscanner-timeout" name="<?php echo esc_attr( $name ); ?>[timeout]" value="<?php echo esc_attr( $s['timeout'] ); ?>" min="0.5" max="5" step="0.1" class="small-text"> <?php esc_html_e( 'seconds', 'ipscanner-bot-blocker' ); ?>
						<p class="description"><?php esc_html_e( 'If IPScanner does not answer in time, the visitor is allowed.', 'ipscanner-bot-blocker' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Usage stats', 'ipscanner-bot-blocker' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[share_usage]" value="1" <?php checked( $s['share_usage'] ); ?>> <?php esc_html_e( 'Send plugin usage stats to IPScanner', 'ipscanner-bot-blocker' ); ?></label>
						<p class="description"><?php esc_html_e( 'Settings changes, connection tests and daily totals. No visitor data.', 'ipscanner-bot-blocker' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ipscanner-test">
			<input type="hidden" name="action" value="ipscanner_test">
			<?php wp_nonce_field( 'ipscanner_test' ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ipscanner-disconnect">
			<input type="hidden" name="action" value="<?php echo esc_attr( IPScanner_Connect::DISCONNECT ); ?>">
			<?php wp_nonce_field( IPScanner_Connect::DISCONNECT ); ?>
		</form>
		<?php
	}

	/**
	 * Renders the decisions table.
	 *
	 * @param array[] $log Entries.
	 */
	private function render_log( array $log ) {
		if ( ! $log ) {
			echo '<p class="ipscanner-muted">' . esc_html__( 'No decisions yet.', 'ipscanner-bot-blocker' ) . '</p>';
			return;
		}
		$areas     = $this->area_labels();
		$decisions = $this->decision_labels();
		$statuses  = array(
			'timeout'   => __( 'API timeout, allowed', 'ipscanner-bot-blocker' ),
			'error'     => __( 'API error, allowed', 'ipscanner-bot-blocker' ),
			'allowlist' => __( 'Allowlist', 'ipscanner-bot-blocker' ),
		);
		?>
		<div class="ipscanner-table-wrap">
			<table class="widefat striped ipscanner-log">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Time', 'ipscanner-bot-blocker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'IP', 'ipscanner-bot-blocker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Class', 'ipscanner-bot-blocker' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'ipscanner-bot-blocker' ); ?></th>
						<th scope="col" class="column-where"><?php esc_html_e( 'Where', 'ipscanner-bot-blocker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $log as $row ) : ?>
						<?php
						$status   = isset( $row['status'] ) ? (string) $row['status'] : 'ok';
						$decision = isset( $row['decision'] ) ? (string) $row['decision'] : '';
						if ( isset( $statuses[ $status ] ) ) {
							$action = $statuses[ $status ];
							$tone   = 'is-neutral';
						} else {
							$action = isset( $decisions[ $decision ] ) ? $decisions[ $decision ] : $decision;
							$tone   = 'block' === $decision ? 'is-block' : ( in_array( $decision, array( 'would_block', 'flag', 'would_flag' ), true ) ? 'is-warn' : 'is-allow' );
						}
						$where = isset( $areas[ $row['area'] ] ) ? $areas[ $row['area'] ] : (string) $row['area'];
						?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, H:i', (int) $row['time'] ) ); ?></td>
							<td><code><?php echo esc_html( $row['ip'] ); ?></code></td>
							<td><?php echo esc_html( $this->class_label( $row['class'] ) ); ?></td>
							<td><span class="ipscanner-chip <?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $action ); ?></span></td>
							<td class="column-where" title="<?php echo esc_attr( $row['path'] ); ?>"><?php echo esc_html( $where ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Shows queued notices for this user (connection test, onboarding).
	 */
	private function render_test_result() {
		$key    = 'ipscanner_test_' . get_current_user_id();
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $result as $line ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $line['ok'] ? 'success' : 'error' ),
				esc_html( $line['text'] )
			);
		}
	}

	/**
	 * Queues notices for the next page load.
	 *
	 * @param array[] $lines Lines with ok and text.
	 */
	private function queue_notices( array $lines ) {
		set_transient( 'ipscanner_test_' . get_current_user_id(), $lines, 120 );
	}

	/**
	 * Stops unless the user may manage the plugin.
	 */
	private function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ipscanner-bot-blocker' ), 403 );
		}
	}

	/**
	 * Calls the API and stores a readable result for the next page load.
	 */
	public function handle_test() {
		$this->require_cap();
		check_admin_referer( 'ipscanner_test' );

		$lines = array();
		$usage = $this->refresh_usage();
		if ( $usage['ok'] ) {
			$d    = $usage['data'];
			$plan = isset( $d['plan'] ) ? (string) $d['plan'] : '';
			$used = isset( $d['used'] ) ? (int) $d['used'] : 0;
			if ( ! empty( $d['unlimited'] ) ) {
				/* translators: 1: plan name, 2: requests used */
				$text = sprintf( __( 'Connected. Plan: %1$s. Used this month: %2$s requests.', 'ipscanner-bot-blocker' ), $plan, number_format_i18n( $used ) );
			} else {
				$text = sprintf(
					/* translators: 1: plan name, 2: requests used, 3: monthly limit, 4: reset date */
					__( 'Connected. Plan: %1$s. Used %2$s of %3$s requests this month, resets %4$s.', 'ipscanner-bot-blocker' ),
					$plan,
					number_format_i18n( $used ),
					number_format_i18n( isset( $d['limit'] ) ? (int) $d['limit'] : 0 ),
					isset( $d['resetDate'] ) ? (string) $d['resetDate'] : '-'
				);
			}
			$lines[] = array(
				'ok'   => true,
				'text' => $text,
			);
		} else {
			$lines[] = array(
				'ok'   => false,
				'text' => $this->failure_text( $usage ),
			);
		}

		$site = (string) $this->settings->get( 'site_id' );
		if ( '' !== $site && $usage['ok'] ) {
			$res = $this->api->fetch_policy();
			if ( $res['ok'] ) {
				$policy  = IPScanner_API::to_policy( $res['data'] );
				$blocks  = array_keys( $policy['policy'], 'block', true );
				$lines[] = array(
					'ok'   => true,
					'text' => sprintf(
						/* translators: 1: site ID, 2: mode, 3: policy version, 4: blocked classes */
						__( 'Site %1$s: %2$s mode, policy version %3$d. Blocked classes: %4$s.', 'ipscanner-bot-blocker' ),
						$site,
						$policy['mode'],
						$policy['version'],
						$blocks ? implode( ', ', $blocks ) : __( 'none', 'ipscanner-bot-blocker' )
					),
				);
			} elseif ( 404 === $res['code'] ) {
				$lines[] = array(
					'ok'   => false,
					/* translators: %s: site ID */
					'text' => sprintf( __( 'Site %s was not found on this account.', 'ipscanner-bot-blocker' ), $site ),
				);
			} else {
				$lines[] = array(
					'ok'   => false,
					'text' => $this->failure_text( $res ),
				);
			}
		}

		$this->telemetry->send(
			'wp_test_connection',
			array(
				'ok'   => (bool) $usage['ok'],
				'code' => (int) $usage['code'],
			)
		);
		$this->queue_notices( $lines );
		wp_safe_redirect( $this->page_url( array( 'tab' => 'settings' ) ) );
		exit;
	}

	/**
	 * Onboarding step 2: saves the key and tests it.
	 */
	public function handle_onboard_key() {
		$this->require_cap();
		check_admin_referer( 'ipscanner_onboard_key' );
		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( '' !== $key ) {
			$values            = $this->settings->all();
			$values['api_key'] = $key;
			update_option( IPScanner_Settings::OPTION, $values );
			$this->settings->flush();
			if ( '' !== $this->settings->api_key() ) {
				update_option( self::ONBOARDING, 'areas', false );
				$this->refresh_usage();
			}
		}
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Onboarding step 3: saves the chosen areas and ends the onboarding.
	 */
	public function handle_onboard_areas() {
		$this->require_cap();
		check_admin_referer( 'ipscanner_onboard_areas' );
		$picked = isset( $_POST['areas'] ) && is_array( $_POST['areas'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['areas'] ) ) : array();
		$values = $this->settings->all();
		$keep   = array_diff( (array) $values['areas'], self::QUICK_AREAS );
		if ( ! class_exists( 'WooCommerce' ) && in_array( 'woo_checkout', (array) $values['areas'], true ) ) {
			$keep[] = 'woo_checkout';
		}
		$values['areas']            = array_values( array_unique( array_merge( $keep, array_intersect( self::QUICK_AREAS, $picked ) ) ) );
		IPScanner_Telemetry::$quiet = true;
		update_option( IPScanner_Settings::OPTION, $values );
		IPScanner_Telemetry::$quiet = false;
		$this->settings->flush();
		delete_option( self::ONBOARDING );
		$this->telemetry->send( 'wp_onboarding_done', array_merge( array( 'skipped' => false ), IPScanner_Telemetry::summary( $this->settings->all() ) ) );
		$this->queue_notices(
			array(
				array(
					'ok'   => true,
					'text' => IPScanner_Policy::MODE_ENFORCE === $this->mode()
						? __( 'Saved. Enforce mode is on.', 'ipscanner-bot-blocker' )
						: __( 'Saved. Monitor mode is on: decisions are recorded, nothing is blocked.', 'ipscanner-bot-blocker' ),
				),
			)
		);
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Hides the onboarding card once the key works.
	 */
	public function handle_onboard_dismiss() {
		$this->require_cap();
		check_admin_referer( 'ipscanner_onboard_dismiss' );
		delete_option( self::ONBOARDING );
		$this->telemetry->send( 'wp_onboarding_done', array_merge( array( 'skipped' => true ), IPScanner_Telemetry::summary( $this->settings->all() ) ) );
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Readable text for a failed request.
	 *
	 * @param array $res Result of IPScanner_API::request().
	 * @return string
	 */
	private function failure_text( array $res ) {
		if ( 401 === $res['code'] ) {
			return __( 'The API key was rejected.', 'ipscanner-bot-blocker' );
		}
		if ( 429 === $res['code'] ) {
			return __( 'The monthly allowance is used up. Visitors are allowed until it resets.', 'ipscanner-bot-blocker' );
		}
		if ( 0 === $res['code'] ) {
			/* translators: %s: error message */
			return sprintf( __( 'Could not reach IPScanner: %s', 'ipscanner-bot-blocker' ), $res['message'] );
		}
		/* translators: 1: HTTP status, 2: error message */
		return trim( sprintf( __( 'IPScanner answered %1$d. %2$s', 'ipscanner-bot-blocker' ), $res['code'], $res['message'] ) );
	}

	/**
	 * Clears the decisions log.
	 */
	public function handle_clear_log() {
		$this->require_cap();
		check_admin_referer( 'ipscanner_clear_log' );
		IPScanner_Log::clear();
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Adds the dashboard widget for administrators.
	 */
	public function add_widget() {
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'ipscanner', 'IPScanner', array( $this, 'render_widget' ) );
		}
	}

	/**
	 * Renders the dashboard widget: status line and the last 24 hours.
	 * Uses cached usage only, so the dashboard never waits for the API.
	 */
	public function render_widget() {
		if ( '' === $this->settings->api_key() ) {
			printf(
				'<p>%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'Not connected.', 'ipscanner-bot-blocker' ),
				esc_url( $this->page_url() ),
				esc_html__( 'Set up', 'ipscanner-bot-blocker' )
			);
			return;
		}
		$totals  = IPScanner_Stats::last_day();
		$enforce = IPScanner_Policy::MODE_ENFORCE === $this->mode();
		?>
		<div class="ipscanner-widget">
			<?php $this->render_status( $this->usage( false ) ); ?>
			<p class="ipscanner-status-mode"><?php echo esc_html( $enforce ? __( 'Enforce mode', 'ipscanner-bot-blocker' ) : __( 'Monitor mode', 'ipscanner-bot-blocker' ) ); ?></p>
			<div class="ipscanner-numbers">
				<div class="ipscanner-number">
					<span class="ipscanner-number-value"><?php echo esc_html( number_format_i18n( $totals['checked'] ) ); ?></span>
					<span class="ipscanner-number-label"><?php esc_html_e( 'Checked, 24h', 'ipscanner-bot-blocker' ); ?></span>
				</div>
				<div class="ipscanner-number">
					<span class="ipscanner-number-value"><?php echo esc_html( number_format_i18n( $enforce ? $totals['blocked'] : $totals['would'] ) ); ?></span>
					<span class="ipscanner-number-label"><?php echo esc_html( $enforce ? __( 'Blocked', 'ipscanner-bot-blocker' ) : __( 'Would block', 'ipscanner-bot-blocker' ) ); ?></span>
				</div>
				<div class="ipscanner-number">
					<span class="ipscanner-number-value is-text"><?php echo esc_html( $this->class_label( $enforce ? $totals['top'] : $totals['top_would'] ) ); ?></span>
					<span class="ipscanner-number-label"><?php esc_html_e( 'Top reason', 'ipscanner-bot-blocker' ); ?></span>
				</div>
			</div>
			<p><a href="<?php echo esc_url( $this->page_url() ); ?>"><?php esc_html_e( 'Overview', 'ipscanner-bot-blocker' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Missing key notice on the plugins screen, and a rejected key or used up
	 * allowance on the dashboard. Both go away once resolved.
	 */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		if ( '' === $this->settings->api_key() ) {
			if ( 'plugins' === $screen->id ) {
				printf(
					'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
					esc_html__( 'IPScanner is not checking visitors yet.', 'ipscanner-bot-blocker' ),
					esc_url( $this->page_url() ),
					esc_html__( 'Add your API key', 'ipscanner-bot-blocker' )
				);
			}
			return;
		}
		$error = get_option( 'ipscanner_last_error' );
		if ( ! is_array( $error ) || 'dashboard' !== $screen->id ) {
			return;
		}
		$res = array(
			'code'    => (int) $error['code'],
			'message' => (string) $error['message'],
		);
		printf(
			'<div class="notice notice-error"><p>%1$s %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'IPScanner:', 'ipscanner-bot-blocker' ),
			esc_html( $this->failure_text( $res ) ),
			esc_url( $this->page_url( array( 'tab' => 'settings' ) ) ),
			esc_html__( 'Settings', 'ipscanner-bot-blocker' )
		);
	}
}
