<?php
/**
 * University of Michigan OIDC
 *
 * @package    UMich_OIDC_Login\Core
 * @copyright  2022 Regents of the University of Michigan
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GPLv3 or later
 * @since      1.0.0
 */

namespace UMich_OIDC_Login\Core;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function UMich_OIDC_Login\Core\log_message as log_message;

/**
 * University of Michigan OIDC
 *
 * @package    UMich_OIDC_Login\Core
 * @since      1.0.0
 */
class OIDC {

	/**
	 * Context for this WordPress request / this run of the plugin.
	 *
	 * @var      object    $ctx    Context passed to us by our creator.
	 *
	 * @since    1.0.0
	 */
	private $ctx;

	/**
	 * Create and initialize the OIDC object.
	 *
	 * @param object $ctx Context for this WordPress request / this run of the plugin.
	 *
	 * @since 1.0.0
	 */
	public function __construct( $ctx ) {
		$this->ctx = $ctx;
	}

	/**
	 * Filter for array of hosts that it is safe to redirect to.
	 *
	 * @param array $hosts  Array of hostnames that are allowed in redirect URLs.
	 *
	 * @return array
	 *
	 * @since 1.0.0
	 */
	public function allowed_redirect_hosts( $hosts ) {

		$options = $this->ctx->options;

		foreach ( array( 'login', 'logout' ) as $type ) {
			$key = $type . '_return_url';
			$url = ( \array_key_exists( $key, $options ) ) ? $options[ $key ] : '';
			if ( '' !== $url ) {
				$new_host = \wp_parse_url( $url, PHP_URL_HOST );
				if ( \is_string( $new_host ) && '' !== $new_host && ! \in_array( $new_host, $hosts, true ) ) {
					$hosts[] = $new_host;
				}
			}
		}

		return $hosts;

	}

	/**
	 * Redirect the user to another URL
	 *
	 * @param string $url  Where to send the user.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public function redirect( $url ) {
		log_message( "redirecting to {$url}" );
		// TODO: call wp_validate_redirect() to be safe.  This
		// will require adding a filter for allowed_redirect_hosts
		// to add the IdP endpoints.
		\nocache_headers();
		\wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Display an error message and exit.
	 *
	 * @param string $header    Non-technical error summary (2-5 words).
	 * @param string $details   Error messages (technical details).
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public function fatal_error( $header, $details ) {
		log_message( "{$header}: {$details}" );

		$this->logout();  // Be very safe and clear everyhing.

		// wp_die() functions differently for page/post requests than
		// for AJAX requests.  Force our callback actions to be treated
		// as page/post requests so the user sees a pretty error
		// message.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verification not needed
		if ( \wp_doing_ajax() && isset( $_GET['action'] ) &&
			( 'openid-connect-authorize' === $_GET['action'] || 'umich-oidc-logout' === $_GET['action'] )
		) {
			\add_filter(
				'wp_doing_ajax',
				function ( $value ) {
					return false;
				},
				10000
			);
		}

		$header  = \esc_html( $header );
		$details = \esc_html( $details );
		$home    = \home_url();
		$login   = \site_url() . '/wp-admin/admin-ajax.php?action=openid-connect-authorize';

		$help  = '';
		$email = \get_option( 'admin_email' );
		if ( is_string( $email ) && '' !== $email ) {
			$email = \esc_html( $email );
			$help  = "For assistance or to report a problem, contact <a href='mailto:{$email}'>{$email}</a>.";
		}

		$message = "
			<h1>{$header}</h1>
			<p>We're sorry for the problem.  Please try the options below. ${help}</p>
			<ul>
			<li><a href='{$home}'>Go to the main page</a></li>
			<li><a href='{$login}'>Try logging in again</a></li>
			<li><a href='javascript:history.back()'>Go back to the page you were just on</a></li>
			</ul>
			<p>Technical details:</p>
			<code>${details}</code>
			";
		\wp_die(
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- we escaped everything above.
			$message,
			'Authentication error',
			array(
				'response' => 500,
			)
		);

	}

	/**
	 * Create a verifier used in place of a nonce.
	 *
	 * We can't use WordPress' nonces for verifying return URLs for
	 * UMich OIDC login/logout actions because they incorporate both
	 * a WordPress user numeric ID as well as the WordPress session
	 * token for the currently logged in WordPress user.  This is not
	 * a problem for OIDC users, and it is also not a problem when no
	 * WordPress user is logged in.  But when a WordPress user is
	 * logged in and their session expires, they won't be able to use
	 * the return URL in their "Please log in again" link.  We can
	 * override the user ID by using the WordPress nonce_user_logged_out
	 * filter, but we can't override the session token without jumping
	 * through a lot of hoops.
	 *
	 * So we write our own verifier to use in place of a nonce with
	 * return URLs.  The verifier never expires. The verifier secret
	 * is common to all site visitors and stored as a WordPress option
	 * to avoid the need to create a PHP session (and hence break caching)
	 * for visitors who are not logged in.
	 *
	 * @param string $data Data to verify using the verifer.
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	public function create_verifier( $data ) {

		$internals = \get_option( 'umich_oidc_internals' );
		if ( ! \is_array( $internals ) ) {
			$internals = array();
		}
		if ( ! \array_key_exists( 'verifier_secret', $internals ) ) {
			$internals['verifier_secret'] = \wp_generate_password( 32, true, true );
			\update_option( 'umich_oidc_internals', $internals );
		}
		$verifier_secret = $internals['verifier_secret'];

		return \substr( \wp_hash( $verifier_secret . $data, 'nonce' ), -12, 10 );
	}

	/**
	 * Check a verifier to see if it is good.
	 *
	 * See the comments for create_verifier(), above, for details.
	 *
	 * @param string $verifier Verifier supplied in the return URL query string.
	 * @param string $data Data to verify.
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	public function check_verifier( $verifier, $data ) {
		$internals = \get_option( 'umich_oidc_internals' );
		if ( ! \is_array( $internals ) || ! \array_key_exists( 'verifier_secret', $internals ) ) {
			return false;
		}
		$verifier_secret = $internals['verifier_secret'];

		$correct = \substr( \wp_hash( $verifier_secret . $data, 'nonce' ), -12, 10 );
		return ( $verifier === $correct );
	}

	/**
	 * Check the return URL.
	 *
	 * Get the URL to redirect the user to after successful login/logout.
	 * Make sure the return URL has not been tampered with and has not
	 * been provided by an attacker via CSRF.
	 *
	 * This is only called through the plugin actions
	 *   admin-ajax.php?action=openid-connect-generic
	 *   admin-ajax.php?action=umich-oidc-logout
	 * and so it does not have information about the original context
	 * for the login/logout (what resource was being viewed, what its
	 * access was, and so on).  Higher level decisions need to be made
	 * in get_oidc_url().
	 *
	 * It also does not differentiate between login and logout links,
	 * although if we need that in the future, we could set up two
	 * different filters for those that then call this function with a
	 * login/logout argument.
	 *
	 * @param string $error_header Header to use in error messages. Differs depending on if the return URL is for login or logout.
	 *
	 * @return string
	 *
	 * @since    1.0.0
	 */
	private function check_return_url( $error_header ) {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- we're doing our own verification
		if ( ! isset( $_REQUEST['umich-oidc-return'] ) ) {
			return \home_url();
		}

		if ( ! isset( $_REQUEST['umich-oidc-verifier'] ) ) {
			$this->fatal_error( $error_header, 'Unsafe login/logout link (missing nonce).' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- We're doing our own verification and sanitization.
		$return_url = \rawurldecode( $_REQUEST['umich-oidc-return'] );
		log_message( "check_return_url: {$return_url}" );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- We're doing our own verification and sanitization.
		if ( false === $this->check_verifier( $_REQUEST['umich-oidc-verifier'], $return_url ) ) {
			$this->fatal_error( $error_header, 'Unsafe login/logout link (incorrect nonce).' );
		}

		$return_url = \esc_url_raw( $return_url );
		if ( '' === $return_url || '' === \wp_validate_redirect( $return_url ) ) {
			$this->fatal_error( $error_header, 'Bad Login/Logout Destination URL: ' . \esc_url( $return_url ) );
		}

		// phpcs:enable
		return $return_url;

	}

	/**
	 * Return a login or logout URL.
	 *
	 * @param string $type URL type: "login" or "logout".
	 * @param string $return Where to send the user afterwards: "here" (page they were on), "home" (site home), "setting" (the Login or Logout Destination URL from the plugin options) or a site URI/URL.
	 *
	 * @return string Requested URL, or an empty string if $type is not "login" or "logout".
	 *
	 * @since 1.0.0
	 */
	public function get_oidc_url( $type, $return = '' ) {

		$ctx     = $this->ctx;
		$options = $ctx->options;

		if ( 'login' === $type ) {
			$action = 'openid-connect-authorize';
		} elseif ( 'logout' === $type ) {
			$action = 'umich-oidc-logout';
		} else {
			log_message( "ERROR: get_oidc_url unknown type={$type}" );
			return '';
		}

		if ( '' === $return ) {
			// Use the configured options/settings.
			$key    = $type . '_action';
			$return = ( \array_key_exists( $key, $options ) ) ? $options[ $key ] : '';
			if ( '' === $return ) {
				// Nothing set, use defaults.
				$return = ( 'login' === $type ) ? 'setting' : 'smart';
			}
		}

		if ( 'smart' === $return ) {
			if ( 'logout' === $type ) {
				$return = ( $ctx->public_resource ) ? 'here' : 'setting';
			} else {
				// "smart" can't be used with login URLs.
				$return = 'setting';
			}
		}

		if ( 'setting' === $return ) {
			$key    = $type . '_return_url';
			$return = ( \array_key_exists( $key, $options ) ) ? $options[ $key ] : '';
			if ( '' === $return ) {
				$return = ( 'login' === $type ) ? 'here' : \home_url();
			}
		}

		/*
		 * The conditional logic for determining the type of return
		 * is done.  We should now be left with three simple cases:
		 * here, home, url.
		 */

		// Figure out the $return_url.
		if ( 'here' === $return ) {
			if ( isset( $_SERVER['REQUEST_URI'] ) ) {
				$return_url = \home_url( \esc_url_raw( \wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
			} else {
				$return_url = \home_url();
			}
		} elseif ( 'home' === $return ) {
			$return_url = \home_url();
		} else {
			// $return is a full URL or URL path.
			$return_url = \esc_url_raw( $return );
			if ( '' === $return_url ) {
				$return_url = \home_url();
			}
		}

		if ( 'home' === $return ) {
			$return_query_string = '';
		} else {
			$verifier            = $this->create_verifier( $return_url );
			$return_url          = \rawurlencode( $return_url );
			$return_query_string = '&umich-oidc-verifier=' . $verifier . '&umich-oidc-return=' . $return_url;
		}

		return \esc_url_raw( \admin_url( 'admin-ajax.php?action=' . $action . $return_query_string ) );

	}

	/**
	 * Filter the login_url returned by wp_login_url().
	 *
	 * This is the URL used in the login link displayed for the user
	 * when their session expires.
	 *
	 * @param string $login_url Value from previous filters.
	 * @param string $redirect Value from previous filters.
	 * @param bool   $force_reauth Value from previous filters.
	 *
	 * @returns string
	 *
	 * @since 1.0.0
	 */
	public function login_url( $login_url, $redirect, $force_reauth ) {

		$ctx           = $this->ctx;
		$url           = $login_url;
		$link_accounts = false;

		if ( \array_key_exists( 'link_accounts', $ctx->options ) ) {
			$link_accounts = (bool) $ctx->options['link_accounts'];
		}

		// Use the OIDC login URL if OIDC/WP accounts are linked, or if
		// the user has a valid or expired OIDC session.
		if ( $link_accounts || 'none' !== $ctx->oidc_user->session_state() ) {
			$url = $this->get_oidc_url( 'login', '' );
		}

		log_message( "login_url called:\n    login_url={$login_url}\n    redirect={$redirect}\n    returning: {$url}" );
		return $url;

	}

	/**
	 * Filter the WordPress logout_url.
	 *
	 * @param string $logout_url Value from previous filters.
	 * @param string $redirect Value from previous filters.
	 *
	 * @returns string
	 *
	 * @since 1.0.0
	 */
	public function logout_url( $logout_url, $redirect ) {

		// Don't override the WordPress logout URL unless
		// OIDC accounts are linked to WordPress accounts.
		$options       = $this->ctx->options;
		$link_accounts = false;
		if ( \array_key_exists( 'link_accounts', $options ) ) {
			$link_accounts = (bool) $options['link_accounts'];
		}
		if ( \is_admin() && ! $link_accounts ) {
			return $logout_url;
		}

		// Get the OIDC logout URL.
		return $this->get_oidc_url( 'logout', '' );

	}

	/**
	 * Action hook for login_init to redirect user to OIDC login if
	 * accounts are linked.
	 *
	 * @returns void
	 *
	 * @since 1.0.0
	 */
	public function init_wp_login() {

		// Don't override the WordPress login form unless
		// OIDC accounts are linked to WordPress accounts.
		$options       = $this->ctx->options;
		$link_accounts = false;
		if ( \array_key_exists( 'link_accounts', $options ) ) {
			$link_accounts = (bool) $options['link_accounts'];
		}
		if ( ! $link_accounts ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- wp-login.php doesn't use a nonce for the actions we're interested in.
		$action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login';
		if ( 'logout' === $action || 'postpass' === $action ) {
			// Allow wp-login.php to handle logouts and
			// password-protected posts.
			return;
		}

		$login_url = $this->get_oidc_url( 'login', '' );
		$this->redirect( $login_url ); // Does not return.

	}

	/**
	 * Login / perform OIDC authentication.
	 *
	 * This function is called via the URL
	 * https://sitename/wp-admin/admin-ajax.php?action=openid-connect-authorize
	 *
	 * It will be called once to start the authentication process, will
	 * redirect the user to the IdP, and then the IdP will send the user
	 * back here to finish authenticating.
	 *
	 * The URL for this action can be generated using one of this plugin's shortcodes.
	 *
	 * The action name, openid-connect-authorize, is the same as used by
	 * the Daggerhart OpenID Connect Generic plugin.  This allows the
	 * site owner to switch back and forth between that plugin and this
	 * plugin without needing to modify the authorized redirect URLs on
	 * their IdP.
	 *
	 * @return void
	 *
	 * @since    1.0.0
	 */
	public function login() {

		log_message( 'starting authentication' );
		$ctx = $this->ctx;

		$options          = $ctx->options;
		$required_options = array( 'provider_url', 'client_id', 'client_secret', 'client_auth_method', 'scopes', 'claim_for_username' );
		foreach ( $required_options as $opt ) {
			if ( ! \array_key_exists( $opt, $options ) ||
				'' === $options[ $opt ] ) {
				$this->fatal_error(
					'Login failed (configuration)',
					"Login needs to be configured by the website owner. Required option {$opt} is missing."
				);
			}
		}

		$scopes       = \explode( ' ', $options['scopes'] );
		$redirect_url = \admin_url( 'admin-ajax.php?action=openid-connect-authorize' );

		$session = $ctx->session;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- We're just seeing if it is set, nonce verification and sanitization are done in check_return_url() which is called below.
		if ( isset( $_REQUEST['umich-oidc-return'] ) ) {
			$return_url = $this->check_return_url( 'Login failed (setup)' );
			$session->set( 'return_url', $return_url );
			$session->close();
			log_message( "return URL set in session: {$return_url}" );
		}

		try {
			$jj_oidc = new \UMich_OIDC\Vendor\Jumbojett\OpenIDConnectClient(
				$options['provider_url'],
				$options['client_id'],
				$options['client_secret']
			);
			$jj_oidc->setTokenEndpointAuthMethodsSupported(
				array(
					$options['client_auth_method'],
				)
			);
			$jj_oidc->setRedirectURL( $redirect_url );
			$jj_oidc->addScope( $scopes );
		} catch ( \Exception $e ) {
			$this->fatal_error(
				'Login failed (setup)',
				$e->getMessage()
			);
		}

		try {
			$jj_oidc->authenticate();
		} catch ( \Exception $e ) {
			$this->fatal_error(
				'Login failed',
				$e->getMessage()
			);
		}

		log_message( 'getting userinfo' );
		try {
			$userinfo = $jj_oidc->requestUserInfo();
		} catch ( \Exception $e ) {
			$this->fatal_error(
				'Login failed (userinfo)',
				$e->getMessage()
			);
		}

		$session->set( 'state', 'valid' );
		$session->set( 'id_token', $jj_oidc->getIdTokenPayload() );
		$session->set( 'userinfo', $userinfo );
		$return_url = $session->get( 'return_url' );
		if ( '' === $return_url ) {
			log_message( 'NOTICE: No return URL in session' );
			$return_url = \home_url();
		}
		$session->clear( 'return_url' );
		$session->close();

		log_message( $session->get( 'id_token' ) );
		log_message( $userinfo );

		$username_claim = $options['claim_for_username'];
		if ( ! \property_exists( $userinfo, $username_claim ) ) {
			$this->logout();
			$this->fatal_error(
				'Login failed (userinfo)',
				'OIDC claim mapping for "username" not present in userinfo.'
			);
		}
		$username = $userinfo->$username_claim;
		if ( ! \is_string( $username ) || '' === $username ) {
			$this->logout();
			$this->fatal_error(
				'Login failed (userinfo)',
				'Unable to determine username.'
			);
		}
		log_message( "Logged in OIDC username: {$username}" );

		$link_accounts = false;
		if ( \array_key_exists( 'link_accounts', $options ) ) {
			$link_accounts = (bool) $options['link_accounts'];
		}

		if ( ! $link_accounts ) {
			// Just OIDC, no WordPress?  We're done.
			$this->redirect( $return_url ); // Does not return.
		}

		/*
		 * Log into the corresponding WordPress account.
		 */

		$user = \get_user_by( 'login', $username );
		if ( ! $user ) {
			log_message( "No WordPress account for username {$username}, treating as OIDC-only user." );
			$this->redirect( $return_url ); // Does not return.
		}

		if ( ! is_a( $user, 'WP_User' ) || ! $user->exists() ) {
			$this->logout();
			$this->fatal_error(
				'WordPress user issue',
				'WordPress did not return the expected information for the user.'
			);
		}

		$session_length = \array_key_exists( 'session_length', $options ) ? $options['session_length'] : 86400;
		$expiration     = time() + \apply_filters( 'auth_cookie_expiration', $session_length, $user->ID, false );
		$manager        = \WP_Session_Tokens::get_instance( $user->ID );
		$token          = $manager->create( $expiration );

		\wp_set_auth_cookie( $user->ID, false, '', $token );
		\do_action( 'wp_login', $user->user_login, $user );
		\wp_set_current_user( $user->ID, $user->user_login );

		$this->redirect( $return_url ); // Does not return.

	}

	/**
	 * Logout.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public function logout() {

		log_message( 'logging out' );

		// TODO: call Jumbojett signOut() to let the IdP know that the user has signed out of this RP.  The U-M IdP doesn't currently support this.

		// Clear (and potentially destroy) the session.
		$this->ctx->session->clear_all();

		/*
		 * wp_logout can call the method we are in now, so avoid
		 * repeating work or creating a loop.
		 */

		$user_id = \get_current_user_id();
		if ( 0 === $user_id ) {
			// No WordPress user is logged in.
			return;
		}

		// remove the WordPress logout action that can call this method.
		\UMich_OIDC_Login\Core\patch_wp_logout_action( 'remove' );
		// then logout.
		\wp_logout();
		// finally, add the logout action back in (probably not needed, but let's be safe).
		\UMich_OIDC_Login\Core\patch_wp_logout_action( 'add' );

	}

	/**
	 * Logout and redirect.
	 *
	 * This function is called via the URL
	 * https://sitename/wp-admin/admin-ajax.php?action=umich-oidc-logout
	 *
	 * The URL for this action can be generated using one of this plugin's shortcodes.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public function logout_and_redirect() {

		$return_url = $this->check_return_url( 'Logout failed.' );

		$this->logout();

		log_message( "logout complete, returning to {$return_url}" );
		$this->redirect( $return_url ); // Does not return.

	}

}
