<?php
/**
 * Main plugin orchestrator: owns the settings and boots everything else.
 *
 * @package GBP_Post_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class GBP_Post_Sync_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'gbp_post_sync_settings';

	/**
	 * Longest summary Google accepts for a Business Profile post.
	 */
	const SUMMARY_MAX = 1500;

	/**
	 * Single shared instance.
	 *
	 * @var GBP_Post_Sync_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Google API client.
	 *
	 * @var GBP_Post_Sync_Google
	 */
	public $google;

	/**
	 * Sharing engine.
	 *
	 * @var GBP_Post_Sync_Sync
	 */
	public $sync;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return GBP_Post_Sync_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		$this->google = new GBP_Post_Sync_Google( $this );
		$this->sync   = new GBP_Post_Sync_Sync( $this );

		if ( is_admin() ) {
			new GBP_Post_Sync_Admin( $this );
			new GBP_Post_Sync_Editor( $this );
		}
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'client_id'     => '',
			'client_secret' => '',
			'locations'     => array(),
			'post_types'    => array( 'post' ),
			'auto_share'    => 1,
			'template'      => "{title}\n\n{excerpt}",
			'cta'           => 'LEARN_MORE',
			'image'         => 1,
			'utm'           => 1,
			'sync_updates'  => 1,
			'remove'        => 0,
		);
	}

	/**
	 * Button types Google offers on a post, with their labels.
	 *
	 * @return array<string,string>
	 */
	public static function cta_types() {
		return array(
			'none'       => __( 'No button', 'gbp-post-sync' ),
			'LEARN_MORE' => __( 'Learn more', 'gbp-post-sync' ),
			'BOOK'       => __( 'Book', 'gbp-post-sync' ),
			'ORDER'      => __( 'Order online', 'gbp-post-sync' ),
			'SHOP'       => __( 'Buy', 'gbp-post-sync' ),
			'SIGN_UP'    => __( 'Sign up', 'gbp-post-sync' ),
			'CALL'       => __( 'Call now', 'gbp-post-sync' ),
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * OAuth client ID: GBP_POST_SYNC_CLIENT_ID in wp-config.php, else the saved one.
	 *
	 * @return string
	 */
	public function client_id() {
		if ( self::client_in_config() ) {
			return trim( (string) GBP_POST_SYNC_CLIENT_ID );
		}

		return trim( (string) $this->get_setting( 'client_id' ) );
	}

	/**
	 * OAuth client secret: GBP_POST_SYNC_CLIENT_SECRET in wp-config.php, else
	 * the saved one (stored encrypted).
	 *
	 * @return string
	 */
	public function client_secret() {
		if ( self::client_in_config() ) {
			return trim( (string) GBP_POST_SYNC_CLIENT_SECRET );
		}

		return GBP_Post_Sync_Crypto::decrypt( (string) $this->get_setting( 'client_secret' ) );
	}

	/**
	 * Whether the OAuth client is set in wp-config.php.
	 *
	 * @return bool
	 */
	public static function client_in_config() {
		return defined( 'GBP_POST_SYNC_CLIENT_ID' ) && defined( 'GBP_POST_SYNC_CLIENT_SECRET' )
			&& '' !== (string) GBP_POST_SYNC_CLIENT_ID && '' !== (string) GBP_POST_SYNC_CLIENT_SECRET;
	}

	/**
	 * Selected locations: full resource name => display title.
	 *
	 * @return array<string,string>
	 */
	public function locations() {
		$out = array();

		foreach ( (array) $this->get_setting( 'locations' ) as $location ) {
			if ( is_array( $location ) && isset( $location['name'] ) && GBP_Post_Sync_Google::valid_location( $location['name'] ) ) {
				$out[ $location['name'] ] = isset( $location['title'] ) ? (string) $location['title'] : $location['name'];
			}
		}

		return $out;
	}

	/**
	 * Post types that can be shared, limited to ones that still exist.
	 *
	 * @return string[]
	 */
	public function post_types() {
		return array_values( array_intersect( (array) $this->get_setting( 'post_types' ), array_keys( self::available_post_types() ) ) );
	}

	/**
	 * Public post types a site could share: name => label.
	 *
	 * @return array<string,string>
	 */
	public static function available_post_types() {
		$out = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$out[ $type->name ] = $type->labels->name;
			}
		}

		return $out;
	}

	/**
	 * Whether posts can go out: connected, with at least one location.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return $this->google->is_connected() && array() !== $this->locations();
	}
}
