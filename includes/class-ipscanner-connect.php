<?php
/**
 * "Connect with IPScanner": OAuth 2.0 authorization code with PKCE in a popup,
 * so nobody copies an API key by hand.
 *
 * @package IPScanner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connect and disconnect.
 */
final class IPScanner_Connect {

	const START      = 'ipscanner_connect';
	const CALLBACK   = 'ipscanner_connect_callback';
	const STATUS     = 'ipscanner_connect_status';
	const DISCONNECT = 'ipscanner_disconnect';
	const ACCOUNT    = 'ipscanner_account';
	const STATE_TTL  = 900;

	/**
	 * Settings.
	 *
	 * @var IPScanner_Settings
	 */
	private $settings;

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
	 * @param IPScanner_Telemetry $telemetry Usage events.
	 */
	public function __construct( IPScanner_Settings $settings, IPScanner_Telemetry $telemetry ) {
		$this->settings  = $settings;
		$this->telemetry = $telemetry;
	}

	/**
	 * Adds the hooks.
	 */
	public function register() {
		add_action( 'admin_post_' . self::START, array( $this, 'start' ) );
		add_action( 'admin_post_' . self::CALLBACK, array( $this, 'callback' ) );
		add_action( 'admin_post_' . self::DISCONNECT, array( $this, 'disconnect' ) );
		add_action( 'wp_ajax_' . self::STATUS, array( $this, 'status' ) );
	}

	/**
	 * The IPScanner site that hosts the consent page and the token endpoint.
	 * The IPSCANNER_APP_BASE constant overrides it for testing.
	 *
	 * @return string
	 */
	public static function app_base() {
		$base = defined( 'IPSCANNER_APP_BASE' ) && is_string( IPSCANNER_APP_BASE ) && '' !== IPSCANNER_APP_BASE ? IPSCANNER_APP_BASE : IPScanner_API::DEFAULT_BASE;
		return rtrim( $base, '/' );
	}

	/**
	 * The link behind the Connect button.
	 *
	 * @return string
	 */
	public static function start_url() {
		return wp_nonce_url( add_query_arg( 'action', self::START, admin_url( 'admin-post.php' ) ), self::START );
	}

	/**
	 * Where IPScanner sends the browser back to.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return add_query_arg( 'action', self::CALLBACK, admin_url( 'admin-post.php' ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	public static function b64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE needs base64url.
	}

	/**
	 * The PKCE S256 challenge for a verifier.
	 *
	 * @param string $verifier Verifier.
	 * @return string
	 */
	public static function challenge( $verifier ) {
		return self::b64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Transient for one connect attempt.
	 *
	 * @param string $state State.
	 * @return string
	 */
	private static function state_key( $state ) {
		return 'ipscanner_connect_' . md5( $state );
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
	 * Settings page URL.
	 *
	 * @return string
	 */
	private static function page_url() {
		return add_query_arg( 'page', IPScanner_Admin::PAGE, admin_url( 'options-general.php' ) );
	}

	/**
	 * Builds the IPScanner consent URL and remembers the attempt.
	 *
	 * @param bool $popup Opened in a popup window.
	 * @return string
	 */
	public function authorize_url( $popup ) {
		$state    = self::b64url( random_bytes( 24 ) );
		$verifier = self::b64url( random_bytes( 48 ) );
		set_transient(
			self::state_key( $state ),
			array(
				'verifier' => $verifier,
				'user'     => get_current_user_id(),
				'popup'    => (bool) $popup,
			),
			self::STATE_TTL
		);
		delete_transient( 'ipscanner_connect_result_' . get_current_user_id() );
		return add_query_arg(
			rawurlencode_deep(
				array(
					'redirect_uri'          => self::redirect_uri(),
					'state'                 => $state,
					'code_challenge'        => self::challenge( $verifier ),
					'code_challenge_method' => 'S256',
					'site'                  => home_url( '/' ),
					'name'                  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'v'                     => IPSCANNER_VERSION,
					'wp'                    => get_bloginfo( 'version' ),
				)
			),
			self::app_base() . '/connect/wordpress'
		);
	}

	/**
	 * The Connect button: sends the browser (popup or tab) to IPScanner.
	 */
	public function start() {
		$this->require_cap();
		check_admin_referer( self::START );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
		$popup = isset( $_GET['popup'] );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The IPScanner consent page is on another host.
		wp_redirect( $this->authorize_url( $popup ) );
		exit;
	}

	/**
	 * IPScanner sends the browser back here with a code (or a refusal). The
	 * state ties it to an attempt this user started; the code is swapped for
	 * the key server to server, with the verifier only this site holds.
	 */
	public function callback() {
		$this->require_cap();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The OAuth state is the CSRF check.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$attempt = '' === $state ? false : get_transient( self::state_key( $state ) );
		if ( ! is_array( $attempt ) || get_current_user_id() !== (int) $attempt['user'] ) {
			$this->finish( false, __( 'This connection attempt has expired. Try again.', 'ipscanner-bot-blocker' ), true );
		}
		delete_transient( self::state_key( $state ) );
		$popup = ! empty( $attempt['popup'] );

		if ( '' !== $error || '' === $code ) {
			$this->finish( false, '', $popup, 'cancelled' );
		}

		$res = $this->exchange( $code, $attempt['verifier'] );
		if ( ! $res['ok'] ) {
			$this->finish( false, $res['message'], $popup );
		}
		$this->save( $res['data'] );
		$this->finish( true, '', $popup );
	}

	/**
	 * Swaps the code for an API key and Site ID.
	 *
	 * @param string $code     Authorization code.
	 * @param string $verifier PKCE verifier.
	 * @return array{ok:bool,data:array,message:string}
	 */
	public function exchange( $code, $verifier ) {
		$response = wp_remote_post(
			self::app_base() . '/api/connect/wordpress/token',
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'user-agent'  => 'ipscanner-wordpress/' . IPSCANNER_VERSION,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'code'          => $code,
						'code_verifier' => $verifier,
						'redirect_uri'  => self::redirect_uri(),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'data'    => array(),
				/* translators: %s: error message */
				'message' => sprintf( __( 'Could not reach IPScanner: %s', 'ipscanner-bot-blocker' ), $response->get_error_message() ),
			);
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$key  = is_array( $data ) && isset( $data['api_key'] ) ? (string) $data['api_key'] : '';
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! preg_match( '/^pk_(live|test)_[0-9a-f]{56}$/', $key ) ) {
			return array(
				'ok'      => false,
				'data'    => array(),
				'message' => __( 'IPScanner did not accept this connection. Try again.', 'ipscanner-bot-blocker' ),
			);
		}
		return array(
			'ok'      => true,
			'data'    => $data,
			'message' => '',
		);
	}

	/**
	 * Saves the key and Site ID and moves the onboarding on to the areas.
	 * Connecting means agreeing to share usage events (the consent page says so).
	 *
	 * @param array $data Token response.
	 */
	public function save( array $data ) {
		$site                  = isset( $data['site_id'] ) && is_string( $data['site_id'] ) && preg_match( '/^site_[A-Za-z0-9]{20}$/', $data['site_id'] ) ? $data['site_id'] : '';
		$values                = $this->settings->all();
		$values['api_key']     = (string) $data['api_key'];
		$values['share_usage'] = true;
		if ( '' !== $site ) {
			$values['site_id'] = $site;
		}
		IPScanner_Telemetry::$quiet = true;
		update_option( IPScanner_Settings::OPTION, $values );
		IPScanner_Telemetry::$quiet = false;
		$this->settings->flush();
		update_option(
			self::ACCOUNT,
			array(
				'email' => isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '',
				'plan'  => isset( $data['plan'] ) ? sanitize_text_field( (string) $data['plan'] ) : '',
			),
			false
		);
		update_option( IPScanner_Admin::ONBOARDING, 'areas', false );
		delete_transient( IPScanner_Admin::USAGE );
		delete_option( 'ipscanner_last_error' );
		delete_option( 'ipscanner_policy_cache' );
		delete_option( IPScanner_API::EDGE_MODE );
		delete_option( IPScanner_API::ENFORCE_PAUSED );
		$this->telemetry->send(
			'wp_connected',
			array(
				'method'   => 'popup',
				'new_site' => '' !== $site,
			)
		);
	}

	/**
	 * Ends the callback: in a popup, a small page that tells the settings tab
	 * and closes; in a tab, back to the settings page with a notice.
	 *
	 * @param bool   $ok      Connected.
	 * @param string $message Error text.
	 * @param bool   $popup   Opened in a popup.
	 * @param string $result  connected, cancelled or failed.
	 */
	private function finish( $ok, $message, $popup, $result = '' ) {
		$result = '' !== $result ? $result : ( $ok ? 'connected' : 'failed' );
		set_transient( 'ipscanner_connect_result_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );
		if ( ! $popup ) {
			if ( '' !== $message || $ok ) {
				set_transient(
					'ipscanner_test_' . get_current_user_id(),
					array(
						array(
							'ok'   => $ok,
							'text' => $ok ? __( 'Connected to IPScanner.', 'ipscanner-bot-blocker' ) : $message,
						),
					),
					120
				);
			}
			wp_safe_redirect( self::page_url() );
			exit;
		}
		$text = $ok ? __( 'Connected. You can close this window.', 'ipscanner-bot-blocker' ) : ( '' !== $message ? $message : __( 'Cancelled. You can close this window.', 'ipscanner-bot-blocker' ) );
		nocache_headers();
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IPScanner</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327;background:#f6f7f7}main{max-width:320px;padding:24px;text-align:center}a{color:#2271b1}</style>
</head>
<body>
<main>
	<p><?php echo esc_html( $text ); ?></p>
	<p><a href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Go to IPScanner settings', 'ipscanner-bot-blocker' ); ?></a></p>
</main>
		<?php
		wp_print_inline_script_tag(
			'try{window.opener&&window.opener.postMessage(' . wp_json_encode(
				array(
					'source' => 'ipscanner-connect',
					'result' => $result,
				)
			) . ',location.origin);}catch(e){}' . ( $ok || '' === $message ? 'setTimeout(function(){window.close();},' . ( $ok ? 600 : 200 ) . ');' : '' )
		);
		?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Polled by the settings tab while the popup is open. A popup that went
	 * through a sign-in page loses its link to this tab, so a message alone
	 * is not enough.
	 */
	public function status() {
		check_ajax_referer( self::STATUS );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success(
			array(
				'result' => (string) get_transient( 'ipscanner_connect_result_' . get_current_user_id() ),
			)
		);
	}

	/**
	 * Removes the key and Site ID. The key itself stays valid on the account.
	 */
	public function disconnect() {
		$this->require_cap();
		check_admin_referer( self::DISCONNECT );
		$values = $this->settings->all();
		$this->telemetry->send_with( $values, 'wp_disconnected', array( 'method' => 'button' ) );
		$values['api_key_clear']    = 1;
		$values['site_id']          = '';
		IPScanner_Telemetry::$quiet = true;
		update_option( IPScanner_Settings::OPTION, $values );
		IPScanner_Telemetry::$quiet = false;
		$this->settings->flush();
		delete_option( self::ACCOUNT );
		delete_option( IPScanner_Admin::ONBOARDING );
		delete_option( 'ipscanner_policy_cache' );
		delete_option( 'ipscanner_last_error' );
		delete_option( IPScanner_API::EDGE_MODE );
		delete_option( IPScanner_API::ENFORCE_PAUSED );
		delete_transient( IPScanner_Admin::USAGE );
		wp_safe_redirect( self::page_url() );
		exit;
	}
}
