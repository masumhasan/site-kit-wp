<?php
/**
 * Class Google\Site_Kit\Core\Authentication\Authentication
 *
 * @package   Google\Site_Kit
 * @copyright 2019 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Core\Authentication;

use Google\Site_Kit\Context;
use Google\Site_Kit\Core\Authentication\Clients\OAuth_Client;
use Google\Site_Kit\Core\Permissions\Permissions;
use Google\Site_Kit\Core\Storage\Options;
use Google\Site_Kit\Core\Storage\User_Options;
use Google\Site_Kit\Core\Storage\Transients;
use Google\Site_Kit\Core\Admin\Notice;
use Exception;

/**
 * Authentication Class.
 *
 * @since 1.0.0
 * @access private
 * @ignore
 */
final class Authentication {

	/**
	 * Plugin context.
	 *
	 * @since 1.0.0
	 * @var Context
	 */
	private $context;

	/**
	 * Options object.
	 *
	 * @since 1.0.0
	 *
	 * @var Options
	 */
	private $options = null;

	/**
	 * User_Options object.
	 *
	 * @since 1.0.0
	 *
	 * @var User_Options
	 */
	private $user_options = null;

	/**
	 * Transients object.
	 *
	 * @since 1.0.0
	 *
	 * @var Transients
	 */
	private $transients = null;

	/**
	 * OAuth client object.
	 *
	 * @since 1.0.0
	 *
	 * @var Clients\OAuth_Client
	 */
	private $auth_client = null;

	/**
	 * OAuth credentials instance.
	 *
	 * @since 1.0.0
	 * @var Credentials
	 */
	protected $credentials;

	/**
	 * Verification instance.
	 *
	 * @since 1.0.0
	 * @var Verification
	 */
	protected $verification;

	/**
	 * Verification meta instance.
	 *
	 * @since 1.1.0
	 * @var Verification_Meta
	 */
	protected $verification_meta;

	/**
	 * Verification file instance.
	 *
	 * @since 1.1.0
	 * @var Verification_File
	 */
	protected $verification_file;

	/**
	 * Profile instance.
	 *
	 * @since 1.0.0
	 * @var Profile
	 */
	protected $profile;

	/**
	 * First_Admin instance.
	 *
	 * @since 1.0.0
	 * @var First_Admin
	 */
	protected $first_admin;

	/**
	 * Google_Proxy instance.
	 *
	 * @since 1.1.2
	 * @var Google_Proxy
	 */
	protected $google_proxy;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Context      $context      Plugin context.
	 * @param Options      $options      Optional. Option API instance. Default is a new instance.
	 * @param User_Options $user_options Optional. User Option API instance. Default is a new instance.
	 * @param Transients   $transients   Optional. Transient API instance. Default is a new instance.
	 */
	public function __construct(
		Context $context,
		Options $options = null,
		User_Options $user_options = null,
		Transients $transients = null
	) {
		$this->context = $context;

		if ( ! $options ) {
			$options = new Options( $this->context );
		}
		$this->options = $options;

		if ( ! $user_options ) {
			$user_options = new User_Options( $this->context );
		}
		$this->user_options = $user_options;

		if ( ! $transients ) {
			$transients = new Transients( $this->context );
		}
		$this->transients = $transients;

		$this->google_proxy      = new Google_Proxy( $this->context );
		$this->credentials       = new Credentials( $this->options );
		$this->verification      = new Verification( $this->user_options );
		$this->verification_meta = new Verification_Meta( $this->user_options, $this->transients );
		$this->verification_file = new Verification_File( $this->user_options );
		$this->profile           = new Profile( $user_options, $this->get_oauth_client() );
		$this->first_admin       = new First_Admin( $this->options );
	}

	/**
	 * Registers functionality through WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		add_action(
			'init',
			function() {
				$this->handle_oauth();
			}
		);

		add_filter(
			'googlesitekit_admin_data',
			function ( $data ) {
				return $this->inline_js_admin_data( $data );
			}
		);

		add_filter(
			'googlesitekit_setup_data',
			function ( $data ) {
				return $this->inline_js_setup_data( $data );
			}
		);

		add_filter(
			'allowed_redirect_hosts',
			function ( $hosts ) {
				return $this->allowed_redirect_hosts( $hosts );
			}
		);

		add_filter(
			'googlesitekit_admin_notices',
			function ( $notices ) {
				return $this->authentication_admin_notices( $notices );
			}
		);

		add_action(
			'admin_action_' . Google_Proxy::ACTION_SETUP,
			function () {
				$this->verify_proxy_setup_nonce();
			},
			-1
		);

		add_action(
			'admin_action_' . Google_Proxy::ACTION_SETUP,
			function () {
				$code      = $this->context->input()->filter( INPUT_GET, 'googlesitekit_code', FILTER_SANITIZE_STRING );
				$site_code = $this->context->input()->filter( INPUT_GET, 'googlesitekit_site_code', FILTER_SANITIZE_STRING );

				$this->handle_site_code( $code, $site_code );
				$this->redirect_to_proxy( $code );
			}
		);
	}

	/**
	 * Gets the OAuth credentials object.
	 *
	 * @since 1.0.0
	 *
	 * @return Credentials Credentials instance.
	 */
	public function credentials() {
		return $this->credentials;
	}

	/**
	 * Gets the verification instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Verification Verification instance.
	 */
	public function verification() {
		return $this->verification;
	}

	/**
	 * Gets the verification tag instance.
	 *
	 * @since 1.0.0
	 * @deprecated 1.1.0
	 *
	 * @return Verification_Meta Verification tag instance.
	 */
	public function verification_tag() {
		_deprecated_function( __METHOD__, '1.1.0', __CLASS__ . '::verification_meta()' );
		return $this->verification_meta;
	}

	/**
	 * Gets the verification meta instance.
	 *
	 * @since 1.1.0
	 *
	 * @return Verification_Meta Verification tag instance.
	 */
	public function verification_meta() {
		return $this->verification_meta;
	}

	/**
	 * Gets the verification file instance.
	 *
	 * @since 1.1.0
	 *
	 * @return Verification_File Verification file instance.
	 */
	public function verification_file() {
		return $this->verification_file;
	}

	/**
	 * Gets the Profile instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Profile Profile instance.
	 */
	public function profile() {
		return $this->profile;
	}

	/**
	 * Gets the OAuth client instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Clients\OAuth_Client OAuth client instance.
	 */
	public function get_oauth_client() {
		if ( ! $this->auth_client instanceof OAuth_Client ) {
			$this->auth_client = new OAuth_Client( $this->context, $this->options, $this->user_options, $this->credentials, $this->google_proxy );
		}
		return $this->auth_client;
	}

	/**
	 * Revokes authentication along with user options settings.
	 *
	 * @since 1.0.0
	 */
	public function disconnect() {
		global $wpdb;

		// Revoke token via API call.
		$this->get_oauth_client()->revoke_token();

		// Delete all user data.
		$user_id = $this->user_options->get_user_id();
		$prefix  = 'googlesitekit_%';
		if ( ! $this->context->is_network_mode() ) {
			$prefix = $wpdb->get_blog_prefix() . $prefix;
		}

		$wpdb->query( // phpcs:ignore WordPress.VIP.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM $wpdb->usermeta WHERE user_id = %d AND meta_key LIKE %s", $user_id, $prefix )
		);
		wp_cache_delete( $user_id, 'user_meta' );
	}

	/**
	 * Gets the URL for connecting to Site Kit.
	 *
	 * @since 1.0.0
	 *
	 * @return string Connect URL.
	 */
	public function get_connect_url() {
		return $this->context->admin_url(
			'splash',
			array(
				'googlesitekit_connect' => 1,
				'nonce'                 => wp_create_nonce( 'connect' ),
			)
		);
	}

	/**
	 * Gets the URL for disconnecting from Site Kit.
	 *
	 * @since 1.0.0
	 *
	 * @return string Disconnect URL.
	 */
	public function get_disconnect_url() {
		return $this->context->admin_url(
			'splash',
			array(
				'googlesitekit_disconnect' => 1,
				'nonce'                    => wp_create_nonce( 'disconnect' ),
			)
		);
	}

	/**
	 * Check if the current user is authenticated.
	 *
	 * @since 1.0.0
	 *
	 * @return boolean True if the user is authenticated, false otherwise.
	 */
	public function is_authenticated() {
		$auth_client = $this->get_oauth_client();

		$access_token = $auth_client->get_access_token();

		return ! empty( $access_token );
	}

	/**
	 * Handles receiving a temporary OAuth code.
	 *
	 * @since 1.0.0
	 */
	private function handle_oauth() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$auth_client = $this->get_oauth_client();
		$input       = $this->context->input();

		// Handles Direct OAuth client request.
		if ( $input->filter( INPUT_GET, 'oauth2callback' ) ) {
			$auth_client->authorize_user();
		}

		if ( ! is_admin() ) {
			return;
		}

		if ( $input->filter( INPUT_GET, 'googlesitekit_disconnect' ) ) {
			$nonce = $input->filter( INPUT_GET, 'nonce' );
			if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'disconnect' ) ) {
				wp_die( esc_html__( 'Invalid nonce.', 'google-site-kit' ), 400 );
			}

			if ( ! current_user_can( Permissions::AUTHENTICATE ) ) {
				wp_die( esc_html__( 'You don\'t have permissions to perform this action.', 'google-site-kit' ), 403 );
			}

			$this->disconnect();

			$redirect_url = $this->context->admin_url(
				'splash',
				array(
					'googlesitekit_reset_session' => 1,
				)
			);

			wp_safe_redirect( $redirect_url );
			exit();
		}

		if ( $input->filter( INPUT_GET, 'googlesitekit_connect' ) ) {
			$nonce = $input->filter( INPUT_GET, 'nonce' );
			if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'connect' ) ) {
				wp_die( esc_html__( 'Invalid nonce.', 'google-site-kit' ), 400 );
			}

			if ( ! current_user_can( Permissions::AUTHENTICATE ) ) {
				wp_die( esc_html__( 'You don\'t have permissions to perform this action.', 'google-site-kit' ), 403 );
			}

			$redirect_url = $input->filter( INPUT_GET, 'redirect', FILTER_VALIDATE_URL );
			if ( $redirect_url ) {
				$redirect_url = esc_url_raw( wp_unslash( $redirect_url ) );
			}

			// User is trying to authenticate, but access token hasn't been set.
			wp_safe_redirect(
				esc_url_raw(
					$auth_client->get_authentication_url( $redirect_url )
				)
			);
			exit();
		}
	}

	/**
	 * Modifies the admin data to pass to JS.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Inline JS data.
	 * @return array Filtered $data.
	 */
	private function inline_js_admin_data( $data ) {
		if ( ! isset( $data['userData'] ) ) {
			$current_user     = wp_get_current_user();
			$data['userData'] = array(
				'email'   => $current_user->user_email,
				'picture' => get_avatar_url( $current_user->user_email ),
			);
		}
		$profile_data = $this->profile->get();
		if ( $profile_data ) {
			$data['userData']['email']   = $profile_data['email'];
			$data['userData']['picture'] = $profile_data['photo'];
		}

		$auth_client = $this->get_oauth_client();
		if ( $auth_client->using_proxy() ) {
			$access_code                 = (string) $this->user_options->get( Clients\OAuth_Client::OPTION_PROXY_ACCESS_CODE );
			$data['proxySetupURL']       = esc_url_raw( $auth_client->get_proxy_setup_url( $access_code ) );
			$data['proxyPermissionsURL'] = esc_url_raw( $auth_client->get_proxy_permissions_url() );
		}

		$data['connectURL']    = esc_url_raw( $this->get_connect_url() );
		$data['disconnectURL'] = esc_url_raw( $this->get_disconnect_url() );

		return $data;
	}

	/**
	 * Modifies the setup data to pass to JS.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Inline JS data.
	 * @return array Filtered $data.
	 */
	private function inline_js_setup_data( $data ) {
		$auth_client = $this->get_oauth_client();

		$access_token = $auth_client->get_client()->getAccessToken();

		$data['isSiteKitConnected'] = $this->credentials->has();
		$data['isResettable']       = (bool) $this->options->get( Credentials::OPTION );
		$data['isAuthenticated']    = ! empty( $access_token );
		$data['requiredScopes']     = $auth_client->get_required_scopes();
		$data['grantedScopes']      = ! empty( $access_token ) ? $auth_client->get_granted_scopes() : array();
		$data['needReauthenticate'] = $data['isAuthenticated'] && $this->need_reauthenticate();

		if ( $auth_client->using_proxy() ) {
			$error_code = $this->user_options->get( OAuth_Client::OPTION_ERROR_CODE );
			if ( ! empty( $error_code ) ) {
				$data['errorMessage'] = $auth_client->get_error_message( $error_code );
			}
		}

		// All admins need to go through site verification process.
		if ( current_user_can( Permissions::MANAGE_OPTIONS ) ) {
			$data['isVerified'] = $this->verification->has();
		} else {
			$data['isVerified'] = false;
		}

		// Flag the first admin user.
		$first_admin_id  = (int) $this->first_admin->get();
		$current_user_id = get_current_user_id();
		if ( ! $first_admin_id && current_user_can( Permissions::MANAGE_OPTIONS ) ) {
			$first_admin_id = $current_user_id;
			$this->first_admin->set( $first_admin_id );
		}
		$data['isFirstAdmin'] = ( $current_user_id === $first_admin_id );

		// The actual data for this is passed in from the Search Console module.
		if ( ! isset( $data['hasSearchConsoleProperty'] ) ) {
			$data['hasSearchConsoleProperty'] = false;
		}

		$data['showModuleSetupWizard'] = $this->context->input()->filter( INPUT_GET, 'reAuth', FILTER_VALIDATE_BOOLEAN );

		$data['moduleToSetup'] = sanitize_key( (string) $this->context->input()->filter( INPUT_GET, 'slug' ) );

		return $data;
	}

	/**
	 * Add allowed redirect host to safe wp_safe_redirect
	 *
	 * @since 1.0.0
	 *
	 * @param array $hosts Array of safe hosts to redirect to.
	 *
	 * @return array
	 */
	private function allowed_redirect_hosts( $hosts ) {
		$hosts[] = 'accounts.google.com';
		$hosts[] = wp_parse_url( $this->google_proxy->url(), PHP_URL_HOST );

		return $hosts;
	}

	/**
	 * Shows admin notification for authentication related issues.
	 *
	 * @since 1.0.0
	 *
	 * @param array $notices Array of admin notices.
	 *
	 * @return array Array of admin notices.
	 */
	private function authentication_admin_notices( $notices ) {

		// Only include notices if in the correct admin panel.
		if ( $this->context->is_network_mode() !== is_network_admin() ) {
			return $notices;
		}

		$notices[] = $this->get_reauthentication_needed_notice();
		$notices[] = $this->get_authentication_oauth_error_notice();

		return $notices;
	}

	/**
	 * Gets re-authentication notice.
	 *
	 * @since 1.0.0
	 *
	 * @return Notice Notice object.
	 */
	private function get_reauthentication_needed_notice() {
		return new Notice(
			'needs_reauthentication',
			array(
				'content'         => function() {
					ob_start();
					?>
					<p>
						<?php esc_html_e( 'You need to reauthenticate your Google account.', 'google-site-kit' ); ?>
						<a
							href="#"
							onclick="clearSiteKitAppStorage()"
						><?php esc_html_e( 'Click here', 'google-site-kit' ); ?></a>
					</p>
					<script>
						function clearSiteKitAppStorage() {
							if ( localStorage ) {
								localStorage.clear();
							}
							if ( sessionStorage ) {
								sessionStorage.clear();
							}
							document.location = '<?php echo esc_url_raw( $this->get_connect_url() ); ?>';
						}
					</script>
					<?php
					return ob_get_clean();
				},
				'type'            => Notice::TYPE_SUCCESS,
				'active_callback' => function() {
					return $this->need_reauthenticate();
				},
			)
		);
	}

	/**
	 * Gets OAuth error notice.
	 *
	 * @since 1.0.0
	 *
	 * @return Notice Notice object.
	 */
	private function get_authentication_oauth_error_notice() {

		return new Notice(
			'oauth_error',
			array(
				'type'            => Notice::TYPE_ERROR,
				'content'         => function() {
					$auth_client = $this->get_oauth_client();
					$error_code  = $this->context->input()->filter( INPUT_GET, 'error', FILTER_SANITIZE_STRING );

					if ( ! $error_code ) {
						$error_code = $this->user_options->get( OAuth_Client::OPTION_ERROR_CODE );
					}

					if ( $error_code ) {
						// Delete error code from database to prevent future notice.
						$this->user_options->delete( OAuth_Client::OPTION_ERROR_CODE );
					} else {
						return '';
					}

					$access_code = $this->user_options->get( OAuth_Client::OPTION_PROXY_ACCESS_CODE );
					if ( $auth_client->using_proxy() && $access_code ) {
						$message = sprintf(
							/* translators: 1: error code from API, 2: URL to re-authenticate */
							__( 'Setup Error (code: %1$s). <a href="%2$s">Re-authenticate with Google</a>', 'google-site-kit' ),
							$error_code,
							esc_url( $auth_client->get_proxy_setup_url( $access_code, $error_code ) )
						);
						$this->user_options->delete( OAuth_Client::OPTION_PROXY_ACCESS_CODE );
					} else {
						$message = $auth_client->get_error_message( $error_code );
					}

					$message = wp_kses(
						$message,
						array(
							'a'      => array(
								'href' => array(),
							),
							'strong' => array(),
							'em'     => array(),
						)
					);

					return '<p>' . $message . '</p>';
				},
				'active_callback' => function() {
					$notification = $this->context->input()->filter( INPUT_GET, 'notification', FILTER_SANITIZE_STRING );
					$error_code   = $this->context->input()->filter( INPUT_GET, 'error', FILTER_SANITIZE_STRING );

					if ( 'authentication_success' === $notification && $error_code ) {
						return true;
					}

					return (bool) $this->user_options->get( OAuth_Client::OPTION_ERROR_CODE );
				},
			)
		);
	}

	/**
	 * Checks if the current user needs to reauthenticate (e.g. because of new requested scopes).
	 *
	 * @since 1.0.0
	 *
	 * @return bool TRUE if need reauthenticate and FALSE otherwise.
	 */
	private function need_reauthenticate() {
		$auth_client = $this->get_oauth_client();

		$access_token = $auth_client->get_access_token();
		if ( empty( $access_token ) ) {
			return false;
		}

		$granted_scopes  = $auth_client->get_granted_scopes();
		$required_scopes = $auth_client->get_required_scopes();

		$required_and_granted_scopes = array_intersect( $granted_scopes, $required_scopes );

		return count( $required_and_granted_scopes ) < count( $required_scopes );
	}

	/**
	 * Verifies the nonce for processing proxy setup.
	 *
	 * @since 1.1.2
	 */
	private function verify_proxy_setup_nonce() {
		$nonce = $this->context->input()->filter( INPUT_GET, 'nonce', FILTER_SANITIZE_STRING );

		if ( ! wp_verify_nonce( $nonce, Google_Proxy::ACTION_SETUP ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'google-site-kit' ), 400 );
		}
	}

	/**
	 * Handles the exchange of a code and site code for client credentials from the proxy.
	 *
	 * @since 1.1.2
	 *
	 * @param string $code      Code ('googlesitekit_code') provided by proxy.
	 * @param string $site_code Site code ('googlesitekit_site_code') provided by proxy.
	 *
	 * phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing
	 */
	private function handle_site_code( $code, $site_code ) {
		if ( ! $code || ! $site_code ) {
			return;
		}

		try {
			$data = $this->google_proxy->exchange_site_code( $site_code, $code );

			$this->credentials->set(
				array(
					'oauth2_client_id'     => $data['site_id'],
					'oauth2_client_secret' => $data['site_secret'],
				)
			);
		} catch ( Exception $exception ) {
			$error_message = $exception->getMessage();

			// If missing verification, rely on the redirect back to the proxy,
			// passing the site code instead of site ID.
			if ( 'missing_verification' === $error_message ) {
				add_filter(
					'googlesitekit_proxy_setup_url_params',
					function ( $params ) use ( $site_code ) {
						$params['site_code'] = $site_code;
						return $params;
					}
				);
				return;
			}

			$this->user_options->set( OAuth_Client::OPTION_ERROR_CODE, $error_message );
			wp_safe_redirect(
				add_query_arg(
					'error',
					rawurlencode( $error_message ),
					$this->context->admin_url( 'splash' )
				)
			);
			exit;
		}
	}

	/**
	 * Redirects back to the authentication service with any added parameters.
	 *
	 * @since 1.1.2
	 *
	 * @param string $code Code ('googlesitekit_code') provided by proxy.
	 */
	private function redirect_to_proxy( $code ) {
		wp_safe_redirect(
			$this->auth_client->get_proxy_setup_url( $code )
		);
		exit;
	}
}
