<?php
/**
 * Google side: OAuth connection, access tokens, and the Business Profile APIs.
 *
 * Every request goes to a fixed Google host. Resource names that end up in a
 * URL (locations, posts) are checked against strict patterns first, so stored
 * or submitted values can never point a request anywhere else.
 *
 * @package GBP_Post_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Google API client.
 */
final class GBP_Post_Sync_Google {

	/**
	 * Option holding the connection (encrypted refresh token, account email).
	 */
	const AUTH_OPTION = 'gbp_post_sync_auth';

	/**
	 * Transient caching the short-lived access token (encrypted).
	 */
	const TOKEN_TRANSIENT = 'gbp_post_sync_token';

	/**
	 * Transient caching the list of locations the account can manage.
	 */
	const LOCATIONS_TRANSIENT = 'gbp_post_sync_locations';

	/**
	 * OAuth endpoints.
	 */
	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	/**
	 * Business Profile management, plus the account email to show who's connected.
	 */
	const SCOPE = 'https://www.googleapis.com/auth/business.manage openid email';

	/**
	 * API endpoints.
	 */
	const ACCOUNTS_URL  = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';
	const LOCATIONS_URL = 'https://mybusinessbusinessinformation.googleapis.com/v1/';
	const POSTS_URL     = 'https://mybusiness.googleapis.com/v4/';

	/**
	 * Plugin instance.
	 *
	 * @var GBP_Post_Sync_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param GBP_Post_Sync_Plugin $plugin Plugin instance.
	 */
	public function __construct( GBP_Post_Sync_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Where Google sends the admin back after they approve access. It has to be
	 * added, exactly, to the OAuth client in Google Cloud.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=gbp_post_sync_oauth' );
	}

	/**
	 * Full location name, as used by the posts API.
	 *
	 * @param mixed $name Value to check.
	 * @return bool
	 */
	public static function valid_location( $name ) {
		return is_string( $name ) && 1 === preg_match( '#^accounts/\d{1,30}/locations/\d{1,30}$#D', $name );
	}

	/**
	 * Full post name, as returned when a post is created.
	 *
	 * @param mixed $name Value to check.
	 * @return bool
	 */
	public static function valid_post_name( $name ) {
		return is_string( $name ) && 1 === preg_match( '#^accounts/\d{1,30}/locations/\d{1,30}/localPosts/[A-Za-z0-9_-]{1,100}$#D', $name );
	}

	/**
	 * Stored connection details.
	 *
	 * @return array<string,mixed>
	 */
	public function get_auth() {
		$auth = get_option( self::AUTH_OPTION, array() );

		return is_array( $auth ) ? $auth : array();
	}

	/**
	 * Whether a Google account is connected and its token can be read.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== $this->refresh_token();
	}

	/**
	 * Email of the connected Google account.
	 *
	 * @return string
	 */
	public function account_email() {
		$auth = $this->get_auth();

		return isset( $auth['email'] ) ? (string) $auth['email'] : '';
	}

	/**
	 * Why the last connection stopped working, if Google revoked it.
	 *
	 * @return string Empty when there's nothing to report.
	 */
	public function lost_reason() {
		$auth = $this->get_auth();

		return ! $this->is_connected() && ! empty( $auth['lost'] ) ? (string) $auth['lost'] : '';
	}

	/**
	 * Google's consent screen URL.
	 *
	 * @param string $state     Single-use value tied to this admin.
	 * @param string $challenge PKCE code challenge.
	 * @return string
	 */
	public function authorize_url( $state, $challenge ) {
		return add_query_arg(
			rawurlencode_deep(
				array(
					'client_id'              => $this->plugin->client_id(),
					'redirect_uri'           => self::redirect_uri(),
					'response_type'          => 'code',
					'scope'                  => self::SCOPE,
					'access_type'            => 'offline',
					'prompt'                 => 'consent',
					'include_granted_scopes' => 'true',
					'state'                  => $state,
					'code_challenge'         => $challenge,
					'code_challenge_method'  => 'S256',
				)
			),
			self::AUTH_URL
		);
	}

	/**
	 * Swap the code Google sent back for tokens, and save the connection.
	 *
	 * @param string $code     Authorization code.
	 * @param string $verifier PKCE code verifier.
	 * @return true|WP_Error
	 */
	public function exchange_code( $code, $verifier ) {
		$data = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'code_verifier' => $verifier,
				'redirect_uri'  => self::redirect_uri(),
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['refresh_token'] ) || ! is_string( $data['refresh_token'] ) ) {
			return new WP_Error( 'gbp_no_refresh', __( 'Google didn\'t return a refresh token. Remove this site\'s access at myaccount.google.com/permissions and connect again.', 'gbp-post-sync' ) );
		}

		$scopes = isset( $data['scope'] ) ? explode( ' ', (string) $data['scope'] ) : array();

		if ( ! in_array( 'https://www.googleapis.com/auth/business.manage', $scopes, true ) ) {
			return new WP_Error( 'gbp_scope', __( 'Permission to manage your Business Profile wasn\'t granted. Connect again and leave that box ticked.', 'gbp-post-sync' ) );
		}

		$stored = GBP_Post_Sync_Crypto::encrypt( $data['refresh_token'] );

		if ( '' === $stored ) {
			return new WP_Error( 'gbp_crypto', __( 'This server can\'t encrypt the Google token, so it wasn\'t saved.', 'gbp-post-sync' ) );
		}

		update_option(
			self::AUTH_OPTION,
			array(
				'refresh_token' => $stored,
				'email'         => self::email_from_id_token( isset( $data['id_token'] ) ? $data['id_token'] : '' ),
				'connected_at'  => time(),
				'connected_by'  => get_current_user_id(),
			),
			false
		);

		$this->save_access_token( $data );
		delete_transient( self::LOCATIONS_TRANSIENT );

		return true;
	}

	/**
	 * Forget the connection, and ask Google to revoke the token too.
	 *
	 * @param string $lost Reason to show admins, when Google ended the connection itself.
	 * @return void
	 */
	public function disconnect( $lost = '' ) {
		$token = $this->refresh_token();

		if ( '' !== $token && '' === $lost ) {
			wp_remote_post(
				self::REVOKE_URL,
				array(
					'timeout'     => 8,
					'redirection' => 0,
					'body'        => array( 'token' => $token ),
				)
			);
		}

		if ( '' !== $lost ) {
			update_option( self::AUTH_OPTION, array( 'lost' => $lost ), false );
		} else {
			delete_option( self::AUTH_OPTION );
		}

		delete_transient( self::TOKEN_TRANSIENT );
		delete_transient( self::LOCATIONS_TRANSIENT );
	}

	/**
	 * A valid access token, refreshed when needed.
	 *
	 * @param bool $fresh Skip the cached token.
	 * @return string|WP_Error
	 */
	public function access_token( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = GBP_Post_Sync_Crypto::decrypt( (string) get_transient( self::TOKEN_TRANSIENT ) );

			if ( '' !== $cached ) {
				return $cached;
			}
		}

		$refresh = $this->refresh_token();

		if ( '' === $refresh ) {
			return new WP_Error( 'gbp_not_connected', __( 'No Google account is connected.', 'gbp-post-sync' ) );
		}

		$data = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			)
		);

		if ( is_wp_error( $data ) ) {
			// The user removed access, or the OAuth app is still in "Testing"
			// (Google expires those tokens after 7 days): stop trying.
			if ( 'invalid_grant' === $data->get_error_code() ) {
				$this->disconnect( __( 'Google ended the connection (access was removed, the password changed, or the OAuth app is still in Testing mode, where tokens expire after 7 days). Connect again to resume sharing.', 'gbp-post-sync' ) );
			}

			return $data;
		}

		return $this->save_access_token( $data );
	}

	/**
	 * Every location the connected account can manage, cached for six hours.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array<string,array<string,string>>|WP_Error Full name => title, address.
	 */
	public function locations( $refresh = false ) {
		$cached = get_transient( self::LOCATIONS_TRANSIENT );

		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}

		$accounts = $this->paged( self::ACCOUNTS_URL, array( 'pageSize' => 20 ), 'accounts' );

		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		$out  = array();
		$seen = array();

		foreach ( $accounts as $account ) {
			$account_name = isset( $account['name'] ) ? (string) $account['name'] : '';

			if ( ! preg_match( '#^accounts/\d{1,30}$#D', $account_name ) ) {
				continue;
			}

			$locations = $this->paged(
				self::LOCATIONS_URL . $account_name . '/locations',
				array(
					'pageSize' => 100,
					'readMask' => 'name,title,storefrontAddress',
				),
				'locations'
			);

			if ( is_wp_error( $locations ) ) {
				return $locations;
			}

			foreach ( $locations as $location ) {
				$id   = isset( $location['name'] ) ? (string) $location['name'] : '';
				$full = $account_name . '/' . $id;

				// A location shared through a group shows up under both accounts.
				if ( isset( $seen[ $id ] ) || ! self::valid_location( $full ) ) {
					continue;
				}

				$seen[ $id ]  = true;
				$out[ $full ] = array(
					'title'   => isset( $location['title'] ) ? sanitize_text_field( $location['title'] ) : $id,
					'address' => self::format_address( isset( $location['storefrontAddress'] ) ? $location['storefrontAddress'] : array() ),
				);
			}
		}

		set_transient( self::LOCATIONS_TRANSIENT, $out, 6 * HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Publish a post on one location.
	 *
	 * @param string $location Full location name.
	 * @param array  $payload  LocalPost fields.
	 * @return array|WP_Error The created post.
	 */
	public function create_post( $location, array $payload ) {
		if ( ! self::valid_location( $location ) ) {
			return new WP_Error( 'gbp_bad_location', __( 'Unknown location.', 'gbp-post-sync' ) );
		}

		$post = $this->request( 'POST', self::POSTS_URL . $location . '/localPosts', $payload );

		if ( ! is_wp_error( $post ) && ! self::valid_post_name( isset( $post['name'] ) ? $post['name'] : '' ) ) {
			return new WP_Error( 'gbp_bad_response', __( 'Google accepted the post but didn\'t say where it is.', 'gbp-post-sync' ) );
		}

		return $post;
	}

	/**
	 * Change a published post.
	 *
	 * @param string $name    Full post name.
	 * @param array  $payload LocalPost fields.
	 * @return array|WP_Error The updated post.
	 */
	public function update_post( $name, array $payload ) {
		if ( ! self::valid_post_name( $name ) ) {
			return new WP_Error( 'gbp_bad_post', __( 'Unknown Google post.', 'gbp-post-sync' ) );
		}

		$url = add_query_arg( 'updateMask', 'summary,callToAction,media', self::POSTS_URL . $name );

		return $this->request( 'PATCH', $url, $payload );
	}

	/**
	 * Delete a post. One that's already gone counts as deleted.
	 *
	 * @param string $name Full post name.
	 * @return true|WP_Error
	 */
	public function delete_post( $name ) {
		if ( ! self::valid_post_name( $name ) ) {
			return new WP_Error( 'gbp_bad_post', __( 'Unknown Google post.', 'gbp-post-sync' ) );
		}

		$result = $this->request( 'DELETE', self::POSTS_URL . $name );

		if ( is_wp_error( $result ) && 404 !== self::error_status( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * HTTP status Google answered with, from an error this class returned.
	 *
	 * @param WP_Error $error Error.
	 * @return int 0 when the request never got an answer.
	 */
	public static function error_status( WP_Error $error ) {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * Authorised JSON request to a Google API. Retries once with a new token on a 401.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $url    Full URL (always a fixed Google host).
	 * @param array|null $body   JSON body.
	 * @param bool       $retry  Whether a 401 may be retried.
	 * @return array|WP_Error Decoded response.
	 */
	private function request( $method, $url, $body = null, $retry = true ) {
		$token = $this->access_token( ! $retry );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: connection error. */
			return new WP_Error( 'gbp_http', sprintf( __( 'Couldn\'t reach Google: %s', 'gbp-post-sync' ), $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 401 === $status && $retry ) {
			delete_transient( self::TOKEN_TRANSIENT );
			return $this->request( $method, $url, $body, false );
		}

		if ( $status < 200 || $status >= 300 ) {
			return self::api_error( $status, $data );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Every item of a paged list, up to 20 pages.
	 *
	 * @param string $url   Endpoint.
	 * @param array  $query Query arguments.
	 * @param string $key   Key holding the items.
	 * @return array|WP_Error
	 */
	private function paged( $url, array $query, $key ) {
		$items = array();
		$token = '';

		for ( $page = 0; $page < 20; $page++ ) {
			$args = '' !== $token ? array_merge( $query, array( 'pageToken' => $token ) ) : $query;
			$data = $this->request( 'GET', add_query_arg( rawurlencode_deep( $args ), $url ) );

			if ( is_wp_error( $data ) ) {
				return $data;
			}

			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$items = array_merge( $items, array_filter( $data[ $key ], 'is_array' ) );
			}

			$token = isset( $data['nextPageToken'] ) && is_string( $data['nextPageToken'] ) ? $data['nextPageToken'] : '';

			if ( '' === $token ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * POST to Google's token endpoint with this site's client credentials.
	 *
	 * @param array $fields Grant fields.
	 * @return array|WP_Error
	 */
	private function token_request( array $fields ) {
		$client_id     = $this->plugin->client_id();
		$client_secret = $this->plugin->client_secret();

		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'gbp_no_client', __( 'Add your OAuth client ID and secret first.', 'gbp-post-sync' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'body'        => array_merge(
					$fields,
					array(
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: connection error. */
			return new WP_Error( 'gbp_http', sprintf( __( 'Couldn\'t reach Google: %s', 'gbp-post-sync' ), $response->get_error_message() ) );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			$code = is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : 'gbp_token';
			$text = is_array( $data ) && isset( $data['error_description'] ) && is_string( $data['error_description'] ) ? $data['error_description'] : $code;

			/* translators: %s: Google's error message. */
			return new WP_Error( $code, sprintf( __( 'Google refused the sign-in: %s', 'gbp-post-sync' ), sanitize_text_field( $text ) ) );
		}

		return $data;
	}

	/**
	 * Cache an access token until just before it expires.
	 *
	 * @param array $data Token response.
	 * @return string The token.
	 */
	private function save_access_token( array $data ) {
		$token   = (string) $data['access_token'];
		$expires = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;

		set_transient( self::TOKEN_TRANSIENT, GBP_Post_Sync_Crypto::encrypt( $token ), max( 60, min( 3600, $expires - 120 ) ) );

		return $token;
	}

	/**
	 * The stored refresh token, decrypted.
	 *
	 * @return string
	 */
	private function refresh_token() {
		$auth = $this->get_auth();

		return isset( $auth['refresh_token'] ) ? GBP_Post_Sync_Crypto::decrypt( $auth['refresh_token'] ) : '';
	}

	/**
	 * Turn a Google error response into a readable WP_Error.
	 *
	 * @param int        $status HTTP status.
	 * @param mixed|null $data   Decoded body.
	 * @return WP_Error
	 */
	private static function api_error( $status, $data ) {
		$message = '';

		if ( is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
			$message = sanitize_text_field( $data['error']['message'] );
		}

		if ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'Google answered with HTTP %d.', 'gbp-post-sync' ), $status );
		}

		if ( 429 === $status || false !== stripos( $message, 'quota' ) ) {
			$message .= ' ' . __( 'New Google Cloud projects get a quota of 0 for the Business Profile APIs until Google approves your API access request.', 'gbp-post-sync' );
		} elseif ( 403 === $status && false !== stripos( $message, 'has not been used' ) ) {
			$message .= ' ' . __( 'Enable the three Business Profile APIs listed in the setup steps.', 'gbp-post-sync' );
		}

		return new WP_Error( 'gbp_api', $message, array( 'status' => $status ) );
	}

	/**
	 * Email from the ID token in a token response. The token came straight from
	 * Google over TLS, so its claims can be read without checking the signature.
	 *
	 * @param mixed $id_token JWT.
	 * @return string
	 */
	private static function email_from_id_token( $id_token ) {
		$parts = is_string( $id_token ) ? explode( '.', $id_token ) : array();

		if ( 3 !== count( $parts ) ) {
			return '';
		}

		$claims = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT payload.
		$email  = is_array( $claims ) && isset( $claims['email'] ) ? sanitize_email( $claims['email'] ) : '';

		return is_email( $email ) ? $email : '';
	}

	/**
	 * One-line address.
	 *
	 * @param mixed $address PostalAddress.
	 * @return string
	 */
	private static function format_address( $address ) {
		if ( ! is_array( $address ) ) {
			return '';
		}

		$parts = array();

		if ( ! empty( $address['addressLines'] ) && is_array( $address['addressLines'] ) ) {
			$parts[] = (string) reset( $address['addressLines'] );
		}

		foreach ( array( 'locality', 'administrativeArea' ) as $key ) {
			if ( ! empty( $address[ $key ] ) && is_string( $address[ $key ] ) ) {
				$parts[] = $address[ $key ];
			}
		}

		return sanitize_text_field( implode( ', ', array_filter( $parts, 'is_string' ) ) );
	}
}
