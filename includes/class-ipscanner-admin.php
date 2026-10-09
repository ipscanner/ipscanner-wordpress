<?php
/**
 * Settings page, connection test, dashboard widget and notices.
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
	 * Constructor.
	 *
	 * @param IPScanner_Settings $settings Settings.
	 * @param IPScanner_API      $api      API client.
	 */
	public function __construct( IPScanner_Settings $settings, IPScanner_API $api ) {
		$this->settings = $settings;
		$this->api      = $api;
	}

	/**
	 * Adds the admin hooks.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_ipscanner_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_ipscanner_clear_log', array( $this, 'handle_clear_log' ) );
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
	 * Settings link on the plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Settings', 'ipscanner' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings page URL.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	private function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Area labels.
	 *
	 * @return array<string,string>
	 */
	private function area_labels() {
		return array(
			'site'         => __( 'Whole site (front end)', 'ipscanner' ),
			'login'        => __( 'Login', 'ipscanner' ),
			'register'     => __( 'Registration', 'ipscanner' ),
			'lostpassword' => __( 'Password reset', 'ipscanner' ),
			'comments'     => __( 'Comments', 'ipscanner' ),
			'woo_checkout' => __( 'WooCommerce checkout', 'ipscanner' ),
			'woo_register' => __( 'WooCommerce account registration', 'ipscanner' ),
			'forms'        => __( 'Contact Form 7 and WPForms', 'ipscanner' ),
		);
	}

	/**
	 * Traffic class labels.
	 *
	 * @return array<string,string>
	 */
	private function class_labels() {
		return array(
			'human'                => __( 'Human', 'ipscanner' ),
			'verified_bot'         => __( 'Verified crawler', 'ipscanner' ),
			'ai_agent'             => __( 'AI agent', 'ipscanner' ),
			'malicious_automation' => __( 'Malicious automation', 'ipscanner' ),
			'tor'                  => __( 'Tor', 'ipscanner' ),
			'vpn'                  => __( 'VPN', 'ipscanner' ),
			'proxy'                => __( 'Proxy', 'ipscanner' ),
			'hosting'              => __( 'Hosting or data center', 'ipscanner' ),
			'relay'                => __( 'Privacy relay', 'ipscanner' ),
		);
	}

	/**
	 * Decision labels.
	 *
	 * @return array<string,string>
	 */
	private function decision_labels() {
		return array(
			'allow'       => __( 'Allowed', 'ipscanner' ),
			'flag'        => __( 'Flagged', 'ipscanner' ),
			'block'       => __( 'Blocked', 'ipscanner' ),
			'would_flag'  => __( 'Would flag', 'ipscanner' ),
			'would_block' => __( 'Would block', 'ipscanner' ),
		);
	}

	/**
	 * Renders Settings > IPScanner.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = $this->settings->all();
		$name     = IPScanner_Settings::OPTION;
		$key      = $this->settings->api_key();
		$by_const = defined( 'IPSCANNER_API_KEY' ) && '' !== $key && $key !== $s['api_key'];
		$woo      = class_exists( 'WooCommerce' );
		$ip       = IPScanner_Request::client_ip( $_SERVER, (string) $s['ip_source'], $this->settings->trusted_proxies() );
		?>
		<div class="wrap">
			<h1>IPScanner</h1>
			<?php $this->render_test_result(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ipscanner_test">
				<?php wp_nonce_field( 'ipscanner_test' ); ?>
				<p>
					<?php
					if ( '' === $key ) {
						printf(
							/* translators: %s: link to ipscanner.io */
							esc_html__( 'No API key yet. Visitors are not checked. Get a key at %s.', 'ipscanner' ),
							'<a href="https://ipscanner.io/dashboard" target="_blank" rel="noopener">ipscanner.io</a>'
						);
					} else {
						submit_button( __( 'Test connection', 'ipscanner' ), 'secondary', 'ipscanner_test', false );
					}
					?>
				</p>
			</form>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ipscanner-api-key"><?php esc_html_e( 'API key', 'ipscanner' ); ?></label></th>
						<td>
							<?php if ( $by_const ) : ?>
								<p><?php esc_html_e( 'Set by the IPSCANNER_API_KEY constant.', 'ipscanner' ); ?></p>
							<?php else : ?>
								<input type="password" id="ipscanner-api-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" class="regular-text" autocomplete="new-password" spellcheck="false"
									placeholder="<?php echo esc_attr( '' === $s['api_key'] ? 'pk_live_...' : sprintf( /* translators: %s: last 4 characters of the key */ __( 'Saved, ends in %s', 'ipscanner' ), substr( $s['api_key'], -4 ) ) ); ?>">
								<?php if ( '' !== $s['api_key'] ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[api_key_clear]" value="1"> <?php esc_html_e( 'Remove', 'ipscanner' ); ?></label>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Each new visitor check uses 2 requests. Results are cached for 10 minutes per visitor.', 'ipscanner' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ipscanner-site-id"><?php esc_html_e( 'Site ID', 'ipscanner' ); ?></label></th>
						<td>
							<input type="text" id="ipscanner-site-id" name="<?php echo esc_attr( $name ); ?>[site_id]" value="<?php echo esc_attr( $s['site_id'] ); ?>" class="regular-text" placeholder="site_..." spellcheck="false">
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the IPScanner dashboard */
									esc_html__( 'Optional. With a Site ID, mode and per class policy come from the %s and the mode and block settings below are ignored.', 'ipscanner' ),
									'<a href="' . esc_url( self::DASHBOARD_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'IPScanner dashboard', 'ipscanner' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'ipscanner' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="monitor" <?php checked( $s['mode'], 'monitor' ); ?>> <?php esc_html_e( 'Monitor: record decisions, block nothing', 'ipscanner' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="enforce" <?php checked( $s['mode'], 'enforce' ); ?>> <?php esc_html_e( 'Enforce: block the classes selected below', 'ipscanner' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Protected areas', 'ipscanner' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $this->area_labels() as $area => $label ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[areas][]" value="<?php echo esc_attr( $area ); ?>" <?php checked( in_array( $area, (array) $s['areas'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
									<?php if ( 0 === strpos( $area, 'woo_' ) && ! $woo ) : ?>
										<span class="description"><?php esc_html_e( '(WooCommerce is not active)', 'ipscanner' ); ?></span>
									<?php endif; ?>
									<br>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Forms are checked when submitted. The whole site option checks every page view.', 'ipscanner' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Block', 'ipscanner' ); ?></th>
						<td>
							<fieldset>
								<?php
								$labels = $this->class_labels();
								foreach ( IPScanner_Policy::BLOCKABLE as $class ) :
									?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[block_classes][]" value="<?php echo esc_attr( $class ); ?>" <?php checked( in_array( $class, (array) $s['block_classes'], true ) ); ?>> <?php echo esc_html( $labels[ $class ] ); ?></label><br>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Verified search engine and AI crawlers always pass.', 'ipscanner' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ipscanner-allowlist"><?php esc_html_e( 'Always allow', 'ipscanner' ); ?></label></th>
						<td>
							<textarea id="ipscanner-allowlist" name="<?php echo esc_attr( $name ); ?>[allowlist]" rows="4" class="large-text code" placeholder="203.0.113.7&#10;2001:db8::/32"><?php echo esc_textarea( $s['allowlist'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'IP addresses or CIDR ranges, one per line. Not checked and never blocked.', 'ipscanner' ); ?></p>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[allow_admins]" value="1" <?php checked( $s['allow_admins'] ); ?>> <?php esc_html_e( 'Always allow administrators', 'ipscanner' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ipscanner-ip-source"><?php esc_html_e( 'Visitor IP', 'ipscanner' ); ?></label></th>
						<td>
							<select id="ipscanner-ip-source" name="<?php echo esc_attr( $name ); ?>[ip_source]">
								<option value="remote_addr" <?php selected( $s['ip_source'], 'remote_addr' ); ?>><?php esc_html_e( 'Direct connection (REMOTE_ADDR)', 'ipscanner' ); ?></option>
								<option value="cloudflare" <?php selected( $s['ip_source'], 'cloudflare' ); ?>><?php esc_html_e( 'Cloudflare (CF-Connecting-IP)', 'ipscanner' ); ?></option>
								<option value="x_forwarded_for" <?php selected( $s['ip_source'], 'x_forwarded_for' ); ?>><?php esc_html_e( 'Proxy or load balancer (X-Forwarded-For)', 'ipscanner' ); ?></option>
							</select>
							<p class="description">
								<?php
								/* translators: %s: visitor IP address */
								printf( esc_html__( 'Your IP with this setting: %s', 'ipscanner' ), '<code>' . esc_html( '' === $ip ? '-' : $ip ) . '</code>' );
								?>
							</p>
							<p><label for="ipscanner-trusted"><?php esc_html_e( 'Trusted proxies', 'ipscanner' ); ?></label></p>
							<textarea id="ipscanner-trusted" name="<?php echo esc_attr( $name ); ?>[trusted_proxies]" rows="3" class="large-text code" placeholder="10.0.0.0/8"><?php echo esc_textarea( $s['trusted_proxies'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Proxy headers are read only from these addresses. Cloudflare ranges are built in.', 'ipscanner' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ipscanner-message"><?php esc_html_e( 'Block message', 'ipscanner' ); ?></label></th>
						<td>
							<textarea id="ipscanner-message" name="<?php echo esc_attr( $name ); ?>[block_message]" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Your request was blocked. If you think this is a mistake, contact the site owner.', 'ipscanner' ); ?>"><?php echo esc_textarea( $s['block_message'] ); ?></textarea>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_credit]" value="1" <?php checked( $s['show_credit'] ); ?>> <?php esc_html_e( 'Show "Protected by IPScanner" on the block page', 'ipscanner' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ipscanner-timeout"><?php esc_html_e( 'Timeout', 'ipscanner' ); ?></label></th>
						<td>
							<input type="number" id="ipscanner-timeout" name="<?php echo esc_attr( $name ); ?>[timeout]" value="<?php echo esc_attr( $s['timeout'] ); ?>" min="0.5" max="5" step="0.1" class="small-text"> <?php esc_html_e( 'seconds', 'ipscanner' ); ?>
							<p class="description"><?php esc_html_e( 'If IPScanner does not answer in time, the visitor is allowed.', 'ipscanner' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Recent decisions', 'ipscanner' ); ?></h2>
			<?php $this->render_log( IPScanner_Log::all() ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ipscanner_clear_log">
				<?php wp_nonce_field( 'ipscanner_clear_log' ); ?>
				<p>
					<?php submit_button( __( 'Clear', 'ipscanner' ), 'secondary', 'ipscanner_clear', false ); ?>
					<a class="button-link" href="<?php echo esc_url( self::DASHBOARD_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'All traffic in the IPScanner dashboard', 'ipscanner' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the decisions table.
	 *
	 * @param array[] $log     Entries.
	 * @param bool    $compact Fewer columns, for the dashboard widget.
	 */
	private function render_log( array $log, $compact = false ) {
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'No decisions yet.', 'ipscanner' ) . '</p>';
			return;
		}
		$areas     = $this->area_labels();
		$classes   = $this->class_labels();
		$decisions = $this->decision_labels();
		$statuses  = array(
			'timeout'   => __( 'API timeout, allowed', 'ipscanner' ),
			'error'     => __( 'API error, allowed', 'ipscanner' ),
			'allowlist' => __( 'Allowlist', 'ipscanner' ),
		);
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'ipscanner' ); ?></th>
					<th><?php esc_html_e( 'IP', 'ipscanner' ); ?></th>
					<?php if ( ! $compact ) : ?>
						<th><?php esc_html_e( 'Area', 'ipscanner' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Class', 'ipscanner' ); ?></th>
					<?php if ( ! $compact ) : ?>
						<th><?php esc_html_e( 'Network', 'ipscanner' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Decision', 'ipscanner' ); ?></th>
					<?php if ( ! $compact ) : ?>
						<th><?php esc_html_e( 'Path', 'ipscanner' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log as $row ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( $compact ? 'H:i' : 'Y-m-d H:i:s', (int) $row['time'] ) ); ?></td>
						<td><code><?php echo esc_html( $row['ip'] ); ?></code></td>
						<?php if ( ! $compact ) : ?>
							<td><?php echo esc_html( isset( $areas[ $row['area'] ] ) ? $areas[ $row['area'] ] : $row['area'] ); ?></td>
						<?php endif; ?>
						<td><?php echo esc_html( isset( $classes[ $row['class'] ] ) ? $classes[ $row['class'] ] : $row['class'] ); ?></td>
						<?php if ( ! $compact ) : ?>
							<td><?php echo esc_html( $row['network'] ); ?></td>
						<?php endif; ?>
						<td>
							<?php
							if ( isset( $statuses[ $row['status'] ] ) ) {
								echo esc_html( $statuses[ $row['status'] ] );
							} else {
								echo esc_html( isset( $decisions[ $row['decision'] ] ) ? $decisions[ $row['decision'] ] : $row['decision'] );
							}
							?>
						</td>
						<?php if ( ! $compact ) : ?>
							<td><code><?php echo esc_html( $row['path'] ); ?></code></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Shows the result of the last connection test.
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
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( $line['ok'] ? 'success' : 'error' ),
				esc_html( $line['text'] )
			);
		}
	}

	/**
	 * Calls the API and stores a readable result for the next page load.
	 */
	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ipscanner' ), 403 );
		}
		check_admin_referer( 'ipscanner_test' );

		$lines = array();
		$usage = $this->api->usage();
		if ( $usage['ok'] ) {
			delete_option( 'ipscanner_last_error' );
			delete_transient( 'ipscanner_backoff' );
			$d    = $usage['data'];
			$plan = isset( $d['plan'] ) ? (string) $d['plan'] : '';
			$used = isset( $d['used'] ) ? (int) $d['used'] : 0;
			if ( ! empty( $d['unlimited'] ) ) {
				/* translators: 1: plan name, 2: requests used */
				$text = sprintf( __( 'Connected. Plan: %1$s. Used this month: %2$s requests.', 'ipscanner' ), $plan, number_format_i18n( $used ) );
			} else {
				$text = sprintf(
					/* translators: 1: plan name, 2: requests used, 3: monthly limit, 4: reset date */
					__( 'Connected. Plan: %1$s. Used %2$s of %3$s requests this month, resets %4$s.', 'ipscanner' ),
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
						__( 'Site %1$s: %2$s mode, policy version %3$d. Blocked classes: %4$s.', 'ipscanner' ),
						$site,
						$policy['mode'],
						$policy['version'],
						$blocks ? implode( ', ', $blocks ) : __( 'none', 'ipscanner' )
					),
				);
			} elseif ( 404 === $res['code'] ) {
				$lines[] = array(
					'ok'   => false,
					/* translators: %s: site ID */
					'text' => sprintf( __( 'Site %s was not found on this account.', 'ipscanner' ), $site ),
				);
			} else {
				$lines[] = array(
					'ok'   => false,
					'text' => $this->failure_text( $res ),
				);
			}
		}

		set_transient( 'ipscanner_test_' . get_current_user_id(), $lines, 120 );
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
			return __( 'The API key was rejected.', 'ipscanner' );
		}
		if ( 429 === $res['code'] ) {
			return __( 'The monthly allowance is used up. Visitors are allowed until it resets.', 'ipscanner' );
		}
		if ( 0 === $res['code'] ) {
			/* translators: %s: error message */
			return sprintf( __( 'Could not reach IPScanner: %s', 'ipscanner' ), $res['message'] );
		}
		/* translators: 1: HTTP status, 2: error message */
		return trim( sprintf( __( 'IPScanner answered %1$d. %2$s', 'ipscanner' ), $res['code'], $res['message'] ) );
	}

	/**
	 * Clears the decisions log.
	 */
	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ipscanner' ), 403 );
		}
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
	 * Renders the dashboard widget.
	 */
	public function render_widget() {
		$log     = IPScanner_Log::all();
		$blocked = 0;
		foreach ( $log as $row ) {
			if ( in_array( $row['decision'], array( 'block', 'would_block' ), true ) ) {
				++$blocked;
			}
		}
		if ( '' === $this->settings->api_key() ) {
			echo '<p>' . esc_html__( 'Add an API key to start checking visitors.', 'ipscanner' ) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: blocked or would block count, 2: number of decisions */
					__( 'Blocked or would block: %1$d of the last %2$d decisions.', 'ipscanner' ),
					$blocked,
					count( $log )
				)
			) . '</p>';
			$this->render_log( array_slice( $log, 0, 5 ), true );
		}
		printf(
			'<p><a href="%1$s">%2$s</a> | <a href="%3$s" target="_blank" rel="noopener">%4$s</a></p>',
			esc_url( $this->page_url() ),
			esc_html__( 'Settings', 'ipscanner' ),
			esc_url( self::DASHBOARD_URL ),
			esc_html__( 'IPScanner dashboard', 'ipscanner' )
		);
	}

	/**
	 * Notices for a missing key and for a rejected key or used up allowance.
	 */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		$ours   = $screen && 'settings_page_' . self::PAGE === $screen->id;
		if ( '' === $this->settings->api_key() ) {
			if ( $screen && in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) ) {
				printf(
					'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
					esc_html__( 'IPScanner is not checking visitors yet.', 'ipscanner' ),
					esc_url( $this->page_url() ),
					esc_html__( 'Add your API key', 'ipscanner' )
				);
			}
			return;
		}
		$error = get_option( 'ipscanner_last_error' );
		if ( ! is_array( $error ) || ! ( $ours || ( $screen && 'dashboard' === $screen->id ) ) ) {
			return;
		}
		$res = array(
			'code'    => (int) $error['code'],
			'message' => (string) $error['message'],
		);
		printf(
			'<div class="notice notice-error"><p>%1$s %2$s</p></div>',
			esc_html__( 'IPScanner:', 'ipscanner' ),
			esc_html( $this->failure_text( $res ) )
		);
	}
}
