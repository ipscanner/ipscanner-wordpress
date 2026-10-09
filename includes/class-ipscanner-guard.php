<?php
/**
 * Visitor checks on the front end and on form submissions.
 *
 * @package IPScanner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the edge check into WordPress, WooCommerce and form plugins.
 */
final class IPScanner_Guard {

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
	 * Result of the first check in this request.
	 *
	 * @var array|null|false False until a check ran.
	 */
	private $result = false;

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
	 * Adds the hooks for the protected areas.
	 */
	public function register() {
		$s = $this->settings;
		if ( $s->protects( 'site' ) ) {
			add_action( 'template_redirect', array( $this, 'check_site' ), 0 );
		}
		if ( $s->protects( 'login' ) ) {
			add_filter( 'authenticate', array( $this, 'check_login' ), 30, 2 );
		}
		if ( $s->protects( 'register' ) ) {
			add_filter( 'registration_errors', array( $this, 'check_errors' ), 10, 1 );
		}
		if ( $s->protects( 'lostpassword' ) ) {
			add_action( 'lostpassword_post', array( $this, 'check_errors' ), 10, 1 );
		}
		if ( $s->protects( 'comments' ) ) {
			add_filter( 'pre_comment_approved', array( $this, 'check_comment' ), 99, 1 );
		}
		if ( $s->protects( 'woo_checkout' ) ) {
			add_action( 'woocommerce_after_checkout_validation', array( $this, 'check_woo_checkout' ), 10, 2 );
			add_action( 'woocommerce_checkout_validate_order_before_payment', array( $this, 'check_woo_checkout' ), 10, 2 );
		}
		if ( $s->protects( 'woo_register' ) ) {
			add_filter( 'woocommerce_registration_errors', array( $this, 'check_errors' ), 10, 1 );
		}
		if ( $s->protects( 'forms' ) ) {
			add_filter( 'wpcf7_spam', array( $this, 'check_cf7' ), 10, 1 );
			add_action( 'wpforms_process_before', array( $this, 'check_wpforms' ), 10, 2 );
		}
	}

	/**
	 * The message shown to blocked visitors.
	 *
	 * @return string
	 */
	public function message() {
		$custom = trim( (string) $this->settings->get( 'block_message' ) );
		return '' !== $custom ? $custom : __( 'Your request was blocked. If you think this is a mistake, contact the site owner.', 'ipscanner' );
	}

	/**
	 * Checks the current visitor once per request.
	 *
	 * @param string $area Protected area, for the log.
	 * @return array|null Null when the visitor is not checked.
	 */
	public function check( $area ) {
		if ( false !== $this->result ) {
			return $this->result;
		}
		$this->result = $this->run( $area );
		return $this->result;
	}

	/**
	 * Runs the check.
	 *
	 * @param string $area Protected area.
	 * @return array|null
	 */
	private function run( $area ) {
		if ( '' === $this->settings->api_key() ) {
			return null;
		}
		if ( $this->settings->get( 'allow_admins' ) && current_user_can( 'manage_options' ) ) {
			return null;
		}

		$server = IPScanner_Request::server_vars();
		$ip     = IPScanner_Request::client_ip( $server, (string) $this->settings->get( 'ip_source' ), $this->settings->trusted_proxies() );
		if ( '' === $ip || ! IPScanner_IP::is_public( $ip ) ) {
			return null;
		}
		$path = $this->path();
		if ( IPScanner_IP::in_list( $ip, $this->settings->allowlist() ) ) {
			if ( 'site' !== $area ) {
				IPScanner_Log::add(
					array(
						'ip'       => $ip,
						'area'     => $area,
						'path'     => $path,
						'decision' => 'allow',
						'status'   => 'allowlist',
					)
				);
			}
			return null;
		}

		/**
		 * Filters whether to skip the check for this request.
		 *
		 * @param bool   $skip Skip the check.
		 * @param string $ip   Visitor address.
		 * @param string $area Protected area.
		 */
		if ( apply_filters( 'ipscanner_skip', false, $ip, $area ) ) {
			return null;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '';
		$outcome    = $this->api->edge_check( $ip, $user_agent, IPScanner_Request::forwarded_headers( $server ) );

		if ( ! $outcome['ok'] ) {
			if ( 'backoff' !== $outcome['status'] ) {
				IPScanner_Log::add(
					array(
						'ip'       => $ip,
						'area'     => $area,
						'path'     => $path,
						'decision' => 'allow',
						'status'   => $outcome['status'],
					)
				);
			}
			return array(
				'class'    => '',
				'action'   => 'allow',
				'decision' => 'allow',
				'status'   => $outcome['status'],
			);
		}

		$verdict = $outcome['data'];
		$site    = (string) $this->settings->get( 'site_id' );
		if ( '' !== $site ) {
			$min    = isset( $verdict['site']['policyVersion'] ) ? (int) $verdict['site']['policyVersion'] : 0;
			$loaded = $this->api->site_policy( $min );
			$rules  = $loaded['policy'];
			$mode   = $rules ? $rules['mode'] : IPScanner_Policy::MODE_MONITOR;
			$result = IPScanner_Policy::decide( $verdict, $mode, $rules ? $rules['policy'] : array(), false );
		} else {
			$policy = IPScanner_Policy::local_policy( (array) $this->settings->get( 'block_classes' ) );
			$result = IPScanner_Policy::decide( $verdict, (string) $this->settings->get( 'mode' ), $policy, true );
		}
		$result['status'] = 'ok';
		$network          = isset( $verdict['network']['networkClass'] ) && is_string( $verdict['network']['networkClass'] ) ? $verdict['network']['networkClass'] : '';

		/**
		 * Filters the decision for a visitor.
		 *
		 * @param array  $result  class, action, decision and status.
		 * @param array  $verdict Edge check response.
		 * @param string $area    Protected area.
		 */
		$result = apply_filters( 'ipscanner_decision', $result, $verdict, $area );

		if ( ! $outcome['cached'] || 'site' !== $area ) {
			IPScanner_Log::add(
				array(
					'ip'       => $ip,
					'area'     => $area,
					'path'     => $path,
					'class'    => $result['class'],
					'network'  => $network,
					'decision' => $result['decision'],
				)
			);
		}
		return $result;
	}

	/**
	 * Request path without the query string.
	 *
	 * @return string
	 */
	private function path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}

	/**
	 * Whether the current visitor is blocked in this area.
	 *
	 * @param string $area Protected area.
	 * @return bool
	 */
	public function blocked( $area ) {
		$result = $this->check( $area );
		return is_array( $result ) && 'block' === $result['decision'];
	}

	/**
	 * Front end: blocks the page with a 403 in enforce mode.
	 */
	public function check_site() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'OPTIONS' === $method || IPScanner_Request::is_static_path( $this->path() ) ) {
			return;
		}

		$result = $this->check( 'site' );
		if ( null === $result ) {
			return;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! headers_sent() ) {
			header( 'X-IPScanner-Status: ' . sanitize_key( $result['status'] ) );
			header( 'X-IPScanner-Class: ' . sanitize_key( $result['class'] ) );
			header( 'X-IPScanner-Action: ' . sanitize_key( $result['decision'] ) );
		}
		if ( 'block' === $result['decision'] ) {
			$this->render_block_page();
		}
	}

	/**
	 * Sends the block page and stops.
	 */
	private function render_block_page() {
		status_header( 403 );
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		}
		wp_register_style( 'ipscanner-block-page', plugins_url( 'css/block-page.css', IPSCANNER_FILE ), array(), IPSCANNER_VERSION );
		$ipscanner_message = $this->message();
		$ipscanner_credit  = (bool) $this->settings->get( 'show_credit' );
		include IPSCANNER_DIR . 'templates/block-page.php';
		exit;
	}

	/**
	 * Login: runs after the password check, so a valid administrator login can
	 * be let through when "always allow administrators" is on.
	 *
	 * @param WP_User|WP_Error|null $user     Result so far.
	 * @param string                $username Submitted username.
	 * @return WP_User|WP_Error|null
	 */
	public function check_login( $user, $username = '' ) {
		if ( ! is_string( $username ) || '' === $username ) {
			return $user;
		}
		if ( $user instanceof WP_User && $this->settings->get( 'allow_admins' ) && user_can( $user, 'manage_options' ) ) {
			return $user;
		}
		if ( $this->blocked( 'login' ) ) {
			return new WP_Error( 'ipscanner_blocked', esc_html( $this->message() ) );
		}
		return $user;
	}

	/**
	 * Registration, password reset and WooCommerce registration.
	 *
	 * @param WP_Error $errors Errors so far.
	 * @return WP_Error
	 */
	public function check_errors( $errors ) {
		$area = 'register';
		if ( 'lostpassword_post' === current_action() ) {
			$area = 'lostpassword';
		} elseif ( 'woocommerce_registration_errors' === current_filter() ) {
			$area = 'woo_register';
		}
		if ( $errors instanceof WP_Error && $this->blocked( $area ) ) {
			$errors->add( 'ipscanner_blocked', esc_html( $this->message() ) );
		}
		return $errors;
	}

	/**
	 * Comments: a blocked visitor gets an error instead of a held comment.
	 *
	 * @param int|string|WP_Error $approved Approval status.
	 * @return int|string|WP_Error
	 */
	public function check_comment( $approved ) {
		if ( is_wp_error( $approved ) || 'spam' === $approved || 'trash' === $approved ) {
			return $approved;
		}
		if ( $this->blocked( 'comments' ) ) {
			return new WP_Error( 'ipscanner_blocked', esc_html( $this->message() ), 403 );
		}
		return $approved;
	}

	/**
	 * WooCommerce checkout, classic and block (Store API).
	 *
	 * @param array|WC_Order $data   Posted data or the draft order.
	 * @param WP_Error       $errors Validation errors.
	 */
	public function check_woo_checkout( $data, $errors ) {
		if ( $errors instanceof WP_Error && $this->blocked( 'woo_checkout' ) ) {
			$errors->add( 'ipscanner_blocked', esc_html( $this->message() ) );
		}
	}

	/**
	 * Contact Form 7: a blocked visitor's submission is treated as spam.
	 *
	 * @param bool $spam Spam so far.
	 * @return bool
	 */
	public function check_cf7( $spam ) {
		if ( $spam ) {
			return $spam;
		}
		return $this->blocked( 'forms' );
	}

	/**
	 * WPForms: a blocked visitor gets a form error.
	 *
	 * @param array $entry     Entry fields.
	 * @param array $form_data Form settings.
	 */
	public function check_wpforms( $entry, $form_data ) {
		if ( ! function_exists( 'wpforms' ) || ! isset( $form_data['id'] ) || ! $this->blocked( 'forms' ) ) {
			return;
		}
		$wpforms = wpforms();
		$process = method_exists( $wpforms, 'obj' ) ? $wpforms->obj( 'process' ) : ( isset( $wpforms->process ) ? $wpforms->process : null );
		if ( is_object( $process ) ) {
			$process->errors[ $form_data['id'] ]['header'] = esc_html( $this->message() );
		}
	}
}
