<?php
/**
 * Admin side: the Content Powered > Google Business Profile Post Sync page,
 * the Google connect/disconnect flow and the Plugins-screen link.
 *
 * @package GBP_Post_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class GBP_Post_Sync_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'gbp-post-sync';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'store';

	/**
	 * Settings tabs: id => Lucide icon.
	 */
	const TABS = array(
		'connection' => 'plug',
		'posting'    => 'megaphone',
		'activity'   => 'history',
	);

	/**
	 * Per-user transient holding the pending OAuth state and PKCE verifier.
	 */
	const OAUTH_TRANSIENT = 'gbp_post_sync_oauth_';

	/**
	 * Per-user transient holding the last connection error, shown once.
	 */
	const ERROR_TRANSIENT = 'gbp_post_sync_error_';

	/**
	 * Plugin instance.
	 *
	 * @var GBP_Post_Sync_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param GBP_Post_Sync_Plugin $plugin Plugin instance.
	 */
	public function __construct( GBP_Post_Sync_Plugin $plugin ) {
		$this->plugin = $plugin;

		GBP_Post_Sync_Menu::init();
		GBP_Post_Sync_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'admin_notices', array( $this, 'connection_lost_notice' ) );

		add_action( 'admin_post_gbp_post_sync_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_gbp_post_sync_oauth', array( $this, 'handle_oauth' ) );
		add_action( 'admin_post_gbp_post_sync_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_gbp_post_sync_locations', array( $this, 'handle_refresh_locations' ) );
		add_action( 'admin_post_gbp_post_sync_clear_log', array( $this, 'handle_clear_log' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( GBP_POST_SYNC_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::page_url() ),
			esc_html__( 'Settings', 'gbp-post-sync' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = GBP_Post_Sync_Icons::get( self::ICON, 17, 'gbp-post-sync-menu-icon' );
		$menu_title = $icon . '<span class="gbp-post-sync-menu-label">' . esc_html__( 'Google Business Profile Post Sync', 'gbp-post-sync' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			GBP_Post_Sync_Menu::SLUG,
			__( 'Google Business Profile Post Sync', 'gbp-post-sync' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the settings page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' gbpps-settings-page' : $classes;
	}

	/**
	 * Register the setting. The tabs are switched in the browser, so one form
	 * saves every tab at once.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			GBP_Post_Sync_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => GBP_Post_Sync_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * WordPress runs this twice when the option is first created, the second
	 * time on its own output, so every branch accepts what it returns.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$in       = is_array( $input ) ? $input : array();
		$old      = $this->plugin->get_settings();
		$defaults = GBP_Post_Sync_Plugin::defaults();
		$flag     = function ( $key ) use ( $in ) {
			return empty( $in[ $key ] ) ? 0 : 1;
		};

		$out = array(
			'client_id'     => $old['client_id'],
			'client_secret' => $old['client_secret'],
			'locations'     => $old['locations'],
			'post_types'    => array(),
			'auto_share'    => $flag( 'auto_share' ),
			'template'      => $defaults['template'],
			'cta'           => $defaults['cta'],
			'image'         => $flag( 'image' ),
			'utm'           => $flag( 'utm' ),
			'sync_updates'  => $flag( 'sync_updates' ),
			'remove'        => $flag( 'remove' ),
		);

		// OAuth client. The fields are left out of the form while connected.
		if ( isset( $in['client_id'] ) && is_string( $in['client_id'] ) ) {
			$out['client_id'] = substr( preg_replace( '/[^A-Za-z0-9._-]/', '', $in['client_id'] ), 0, 200 );
		}

		if ( isset( $in['client_secret'] ) && is_string( $in['client_secret'] ) ) {
			$secret = trim( $in['client_secret'] );

			if ( GBP_Post_Sync_Crypto::is_encrypted( $secret ) ) {
				// Only a value this site encrypted itself can be read back.
				$out['client_secret'] = '' !== GBP_Post_Sync_Crypto::decrypt( $secret ) ? $secret : $old['client_secret'];
			} elseif ( '' !== $secret ) {
				$out['client_secret'] = GBP_Post_Sync_Crypto::encrypt( substr( preg_replace( '/[^\x21-\x7E]/', '', $secret ), 0, 200 ) );
			}
		}

		// A token belongs to the client that issued it.
		if ( $out['client_id'] !== $old['client_id'] && '' !== $old['client_id'] && $this->plugin->google->is_connected() ) {
			$this->plugin->google->disconnect();
		}

		// Locations: only ones the connected account really manages.
		if ( isset( $in['locations'] ) && is_array( $in['locations'] ) ) {
			$out['locations'] = $this->sanitize_locations( $in['locations'], $old['locations'] );
		} elseif ( ! empty( $in['locations_present'] ) ) {
			$out['locations'] = array();
		}

		if ( isset( $in['post_types'] ) && is_array( $in['post_types'] ) ) {
			$out['post_types'] = array_values( array_intersect( array_keys( GBP_Post_Sync_Plugin::available_post_types() ), $in['post_types'] ) );
		}

		if ( isset( $in['template'] ) && is_string( $in['template'] ) ) {
			$template = mb_substr( trim( sanitize_textarea_field( $in['template'] ) ), 0, GBP_Post_Sync_Plugin::SUMMARY_MAX );

			$out['template'] = '' !== $template ? $template : $defaults['template'];
		}

		if ( isset( $in['cta'] ) && is_string( $in['cta'] ) && isset( GBP_Post_Sync_Plugin::cta_types()[ $in['cta'] ] ) ) {
			$out['cta'] = $in['cta'];
		}

		return $out;
	}

	/**
	 * Keep submitted locations the account can manage, with their titles.
	 *
	 * @param array $submitted Location names (from the form) or saved entries.
	 * @param array $old       Saved entries.
	 * @return array<int,array{name:string,title:string}>
	 */
	private function sanitize_locations( array $submitted, array $old ) {
		$known = $this->plugin->google->is_connected() ? get_transient( GBP_Post_Sync_Google::LOCATIONS_TRANSIENT ) : false;
		$saved = array();
		$out   = array();

		foreach ( $old as $entry ) {
			if ( is_array( $entry ) && isset( $entry['name'], $entry['title'] ) ) {
				$saved[ $entry['name'] ] = $entry['title'];
			}
		}

		foreach ( array_slice( $submitted, 0, 100 ) as $entry ) {
			$name  = is_array( $entry ) && isset( $entry['name'] ) ? $entry['name'] : $entry;
			$title = '';

			if ( ! GBP_Post_Sync_Google::valid_location( $name ) || isset( $out[ $name ] ) ) {
				continue;
			}

			if ( is_array( $known ) && isset( $known[ $name ] ) ) {
				$title = $known[ $name ]['title'];
			} elseif ( isset( $saved[ $name ] ) ) {
				$title = $saved[ $name ];
			} elseif ( is_array( $entry ) && isset( $entry['title'] ) && ! is_array( $known ) ) {
				// Our own output, on WordPress's second pass.
				$title = sanitize_text_field( $entry['title'] );
			} else {
				continue;
			}

			$out[ $name ] = array(
				'name'  => $name,
				'title' => $title,
			);
		}

		return array_values( $out );
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="gbp-post-sync-menu-icon-style">'
			. '#adminmenu a:has(>.gbp-post-sync-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .gbp-post-sync-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .gbp-post-sync-menu-icon,#adminmenu a:hover .gbp-post-sync-menu-icon,#adminmenu a:focus .gbp-post-sync-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'gbp-post-sync-admin', GBP_POST_SYNC_URL . 'assets/admin.css', array(), GBP_POST_SYNC_VERSION );
		wp_enqueue_script( 'gbp-post-sync-admin', GBP_POST_SYNC_URL . 'assets/admin.js', array(), GBP_POST_SYNC_VERSION, true );

		wp_localize_script(
			'gbp-post-sync-admin',
			'gbp_post_sync_admin',
			array(
				'i18n' => array(
					'copied'     => __( 'Copied', 'gbp-post-sync' ),
					'disconnect' => __( 'Disconnect this Google account? Posts already on Google stay there, and nothing new is shared until you connect again.', 'gbp-post-sync' ),
					'clearLog'   => __( 'Clear the activity log?', 'gbp-post-sync' ),
					'saveFirst'  => __( 'Save your changes first, then connect.', 'gbp-post-sync' ),
				),
			)
		);
	}

	/* Google connection --------------------------------------------------- */

	/**
	 * Start the OAuth flow: remember a single-use state and PKCE verifier for
	 * this admin, then send them to Google's consent screen.
	 *
	 * @return void
	 */
	public function handle_connect() {
		self::guard( 'gbp_post_sync_connect' );

		if ( '' === $this->plugin->client_id() || '' === $this->plugin->client_secret() ) {
			self::back( 'no_client', 'connection' );
		}

		$state    = wp_generate_password( 40, false );
		$verifier = wp_generate_password( 64, false );

		set_transient(
			self::OAUTH_TRANSIENT . get_current_user_id(),
			array(
				'state'    => hash( 'sha256', $state ),
				'verifier' => GBP_Post_Sync_Crypto::encrypt( $verifier ),
			),
			15 * MINUTE_IN_SECONDS
		);

		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE challenge.

		wp_redirect( $this->plugin->google->authorize_url( $state, $challenge ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Fixed Google URL.
		exit;
	}

	/**
	 * Google sends the admin back here. The state has to match the one stored
	 * for this same user (single use, 15 minutes), which stops anyone from
	 * planting their own Google account on the site through a crafted link.
	 *
	 * @return void
	 */
	public function handle_oauth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'gbp-post-sync' ), 403 );
		}

		$key     = self::OAUTH_TRANSIENT . get_current_user_id();
		$pending = get_transient( $key );
		delete_transient( $key );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The OAuth state is the CSRF check.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable

		if ( ! is_array( $pending ) || empty( $pending['state'] ) || '' === $state || ! hash_equals( $pending['state'], hash( 'sha256', $state ) ) ) {
			self::back( 'state', 'connection' );
		}

		if ( 'access_denied' === $error ) {
			self::back( 'denied', 'connection' );
		}

		if ( '' !== $error || '' === $code ) {
			/* translators: %s: error code from Google. */
			self::back_with_error( sprintf( __( 'Google returned "%s".', 'gbp-post-sync' ), '' !== $error ? $error : 'no_code' ) );
		}

		$result = $this->plugin->google->exchange_code( $code, GBP_Post_Sync_Crypto::decrypt( isset( $pending['verifier'] ) ? $pending['verifier'] : '' ) );

		if ( is_wp_error( $result ) ) {
			self::back_with_error( $result->get_error_message() );
		}

		self::back( 'connected', 'connection' );
	}

	/**
	 * Disconnect the Google account.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		self::guard( 'gbp_post_sync_disconnect' );

		$this->plugin->google->disconnect();

		self::back( 'disconnected', 'connection' );
	}

	/**
	 * Fetch the location list again.
	 *
	 * @return void
	 */
	public function handle_refresh_locations() {
		self::guard( 'gbp_post_sync_locations' );

		delete_transient( GBP_Post_Sync_Google::LOCATIONS_TRANSIENT );

		self::back( 'refreshed', 'connection' );
	}

	/**
	 * Empty the activity log.
	 *
	 * @return void
	 */
	public function handle_clear_log() {
		self::guard( 'gbp_post_sync_clear_log' );

		GBP_Post_Sync_Log::clear();

		self::back( 'cleared', 'activity' );
	}

	/**
	 * Tell admins when Google ended the connection, on the Dashboard, the
	 * Plugins screen and this plugin's page.
	 *
	 * @return void
	 */
	public function connection_lost_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', $this->hook_suffix ), true ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$reason = $this->plugin->google->lost_reason();

		if ( '' === $reason ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Google Business Profile Post Sync:', 'gbp-post-sync' ),
			esc_html( $reason ),
			esc_url( self::page_url() . '#connection' ),
			esc_html__( 'Reconnect', 'gbp-post-sync' )
		);
	}

	/**
	 * Stop unless the current user may manage the plugin and the nonce is good.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'gbp-post-sync' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * Back to the settings page with a notice.
	 *
	 * @param string $notice Notice code from {@see notices()}.
	 * @param string $tab    Tab to open.
	 * @return void
	 */
	private static function back( $notice, $tab ) {
		wp_safe_redirect( add_query_arg( 'gbpps_notice', $notice, self::page_url() ) . '#' . $tab );
		exit;
	}

	/**
	 * Back to the settings page with an error message for this admin.
	 *
	 * @param string $message Error.
	 * @return void
	 */
	private static function back_with_error( $message ) {
		set_transient( self::ERROR_TRANSIENT . get_current_user_id(), sanitize_text_field( $message ), 5 * MINUTE_IN_SECONDS );
		self::back( 'error', 'connection' );
	}

	/**
	 * Settings page URL.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	private static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Nonced admin-post.php link.
	 *
	 * @param string $action Action (also the nonce action).
	 * @return string
	 */
	private static function action_url( $action ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
	}

	/* Page ------------------------------------------------------------------ */

	/**
	 * Inline icon.
	 *
	 * @param string $name Lucide icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return GBP_Post_Sync_Icons::get( $name, $size );
	}

	/**
	 * The page's boxes, in order: the tab each one is on, its icon, title,
	 * intro and what draws it.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function sections() {
		$setup = array(
			'panel'  => 'connection',
			'icon'   => 'list-ordered',
			'title'  => __( 'Set up Google access (one time)', 'gbp-post-sync' ),
			'intro'  => __( 'Each site connects through its own Google Cloud project, so no one else (us included) ever handles your Google login or tokens. It takes about ten minutes, plus Google\'s review of your API access request.', 'gbp-post-sync' ),
			'render' => 'render_setup',
		);

		$sections = array(
			'account'   => array(
				'panel'  => 'connection',
				'icon'   => 'simpleicons/google',
				'title'  => __( 'Google account', 'gbp-post-sync' ),
				'intro'  => '',
				'render' => 'render_account',
			),
			'locations' => array(
				'panel'  => 'connection',
				'icon'   => 'map-pin',
				'title'  => __( 'Business Profile locations', 'gbp-post-sync' ),
				'intro'  => __( 'Posts are shared to every location you tick here.', 'gbp-post-sync' ),
				'render' => 'render_locations',
			),
			'setup'     => $setup,
			'what'      => array(
				'panel'  => 'posting',
				'icon'   => 'files',
				'title'  => __( 'What gets shared', 'gbp-post-sync' ),
				'intro'  => '',
				'render' => 'render_what',
			),
			'content'   => array(
				'panel'  => 'posting',
				'icon'   => 'square-pen',
				'title'  => __( 'What the update says', 'gbp-post-sync' ),
				'intro'  => __( 'Each shared post becomes a "What\'s new" update on your Business Profile, shown in Google Search and Maps.', 'gbp-post-sync' ),
				'render' => 'render_content',
			),
			'sync'      => array(
				'panel'  => 'posting',
				'icon'   => 'refresh-cw',
				'title'  => __( 'Keep Google in step', 'gbp-post-sync' ),
				'intro'  => '',
				'render' => 'render_sync',
			),
			'stats'     => array(
				'panel'  => 'activity',
				'icon'   => 'chart-column',
				'title'  => __( 'Last 30 days', 'gbp-post-sync' ),
				'intro'  => '',
				'render' => 'render_stats',
			),
			'log'       => array(
				'panel'  => 'activity',
				'icon'   => 'history',
				'title'  => __( 'Recent activity', 'gbp-post-sync' ),
				'intro'  => '',
				'render' => 'render_log',
			),
		);

		// Until there's a connection, the setup steps come first.
		if ( ! $this->plugin->google->is_connected() ) {
			unset( $sections['setup'] );
			$sections = array_merge( array( 'setup' => $setup ), $sections );
		}

		return $sections;
	}

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'connection' => __( 'Connection', 'gbp-post-sync' ),
			'posting'    => __( 'Posting', 'gbp-post-sync' ),
			'activity'   => __( 'Activity', 'gbp-post-sync' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * Render the settings page: a header with the tabs, then WordPress's
	 * two-column box layout (settings on the left, Save and status on the right).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="gbpps-header">
			<div class="gbpps-header-title">
				<span class="gbpps-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="gbpps-version"><?php echo esc_html( 'v' . GBP_POST_SYNC_VERSION ); ?></span>
			</div>
			<nav class="gbpps-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'gbp-post-sync' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="gbpps-tab" role="tab" data-gbpps-tab="<?php echo esc_attr( $id ); ?>">
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap gbpps-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();
			$this->render_notice();
			?>

			<form action="options.php" method="post" class="gbpps-form">
				<?php settings_fields( self::PAGE ); ?>

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<?php
							foreach ( $this->sections() as $id => $section ) {
								$this->render_section( $id, $section );
							}
							?>
						</div>

						<div id="postbox-container-1" class="postbox-container">
							<div class="gbpps-sidebar">
								<?php $this->render_sidebar(); ?>
							</div>
						</div>
					</div>
					<br class="clear">
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * The notice for a finished connect, disconnect, refresh or clear.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only; the code picks a fixed message.
		$code    = isset( $_GET['gbpps_notice'] ) ? sanitize_key( wp_unslash( $_GET['gbpps_notice'] ) ) : '';
		$notices = array(
			'connected'    => array( 'success', __( 'Google account connected. Now pick the locations to share to.', 'gbp-post-sync' ) ),
			'disconnected' => array( 'success', __( 'Google account disconnected.', 'gbp-post-sync' ) ),
			'refreshed'    => array( 'success', __( 'Location list refreshed.', 'gbp-post-sync' ) ),
			'cleared'      => array( 'success', __( 'Activity log cleared.', 'gbp-post-sync' ) ),
			'denied'       => array( 'warning', __( 'Google access wasn\'t granted, so nothing changed.', 'gbp-post-sync' ) ),
			'state'        => array( 'error', __( 'That sign-in link expired or wasn\'t started from this page. Press Connect with Google again.', 'gbp-post-sync' ) ),
			'no_client'    => array( 'error', __( 'Add your OAuth client ID and secret, save, then connect.', 'gbp-post-sync' ) ),
			'error'        => array( 'error', __( 'Couldn\'t connect to Google.', 'gbp-post-sync' ) ),
		);

		if ( ! isset( $notices[ $code ] ) ) {
			return;
		}

		$message = $notices[ $code ][1];

		if ( 'error' === $code ) {
			$key    = self::ERROR_TRANSIENT . get_current_user_id();
			$detail = (string) get_transient( $key );
			delete_transient( $key );

			if ( '' !== $detail ) {
				$message .= ' ' . $detail;
			}
		}

		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notices[ $code ][0] ), esc_html( $message ) );
	}

	/**
	 * One settings box.
	 *
	 * @param string               $id      Section ID.
	 * @param array<string,string> $section Section details from {@see sections()}.
	 * @return void
	 */
	private function render_section( $id, array $section ) {
		?>
		<div class="postbox gbpps-box" id="gbpps-<?php echo esc_attr( $id ); ?>" data-gbpps-panel="<?php echo esc_attr( $section['panel'] ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $section['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $section['title'] ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php if ( '' !== $section['intro'] ) : ?>
					<p class="gbpps-intro"><?php echo esc_html( $section['intro'] ); ?></p>
				<?php endif; ?>
				<?php call_user_func( array( $this, $section['render'] ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return GBP_Post_Sync_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Checkbox with a label.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description under it.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '' ) {
		printf(
			'<label class="gbpps-check"><input type="checkbox" id="gbpps-%1$s" name="%2$s" value="1" %3$s> <span>%4$s</span></label>',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Form table row.
	 *
	 * @param string   $label     Row label.
	 * @param callable $content   Prints the row's controls.
	 * @param string   $label_for ID the label points at.
	 * @return void
	 */
	private function row( $label, $content, $label_for = '' ) {
		echo '<tr><th scope="row">';
		echo '' !== $label_for ? '<label for="' . esc_attr( $label_for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</th><td>';
		call_user_func( $content );
		echo '</td></tr>';
	}

	/**
	 * Callout line.
	 *
	 * @param string $icon    Lucide icon.
	 * @param string $message Plain text.
	 * @param string $type    '', 'is-warning', 'is-error' or 'is-success'.
	 * @return void
	 */
	private static function callout( $icon, $message, $type = '' ) {
		printf(
			'<p class="gbpps-callout %1$s">%2$s<span>%3$s</span></p>',
			esc_attr( $type ),
			self::icon( $icon, 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			esc_html( $message )
		);
	}

	/**
	 * Connection: the Google account and OAuth client.
	 *
	 * @return void
	 */
	public function render_account() {
		$google = $this->plugin->google;

		if ( $google->is_connected() ) {
			$auth  = $google->get_auth();
			$email = $google->account_email();
			?>
			<div class="gbpps-account">
				<span class="gbpps-account-icon"><?php echo self::icon( 'simpleicons/google', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<span class="gbpps-account-text">
					<strong><?php echo esc_html( '' !== $email ? $email : __( 'Google account connected', 'gbp-post-sync' ) ); ?></strong>
					<span>
						<?php
						if ( ! empty( $auth['connected_at'] ) ) {
							/* translators: %s: date the account was connected. */
							echo esc_html( sprintf( __( 'Connected %s', 'gbp-post-sync' ), wp_date( get_option( 'date_format' ), (int) $auth['connected_at'] ) ) );
						}
						?>
					</span>
				</span>
				<span class="gbpps-tag is-good"><?php echo self::icon( 'circle-check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Connected', 'gbp-post-sync' ); ?></span>
			</div>
			<p class="gbpps-inline">
				<a class="button" href="<?php echo esc_url( self::action_url( 'gbp_post_sync_disconnect' ) ); ?>" data-gbpps-confirm="disconnect">
					<?php echo self::icon( 'unplug', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Disconnect', 'gbp-post-sync' ); ?>
				</a>
				<span class="description"><?php esc_html_e( 'Disconnecting also revokes this site\'s access in your Google account.', 'gbp-post-sync' ); ?></span>
			</p>
			<?php
			return;
		}

		$in_config  = GBP_Post_Sync_Plugin::client_in_config();
		$has_secret = '' !== $this->plugin->client_secret();
		$ready      = '' !== $this->plugin->client_id() && $has_secret;
		?>
		<p class="gbpps-intro"><?php esc_html_e( 'Paste the OAuth client from your Google Cloud project (see the setup steps), save, then connect the Google account that manages your Business Profile.', 'gbp-post-sync' ); ?></p>

		<table class="form-table" role="presentation">
			<tbody>
				<?php
				$this->row(
					__( 'Client ID', 'gbp-post-sync' ),
					function () use ( $in_config ) {
						printf(
							'<input type="text" class="large-text code" id="gbpps-client-id" name="%1$s" value="%2$s" autocomplete="off" spellcheck="false" placeholder="1234567890-abc.apps.googleusercontent.com" %3$s>',
							esc_attr( self::name( 'client_id' ) ),
							esc_attr( $this->plugin->client_id() ),
							disabled( $in_config, true, false )
						);
					},
					'gbpps-client-id'
				);
				$this->row(
					__( 'Client secret', 'gbp-post-sync' ),
					function () use ( $in_config, $has_secret ) {
						printf(
							'<input type="password" class="regular-text code" id="gbpps-client-secret" name="%1$s" value="" autocomplete="new-password" spellcheck="false" placeholder="%2$s" %3$s>',
							esc_attr( self::name( 'client_secret' ) ),
							esc_attr( $has_secret ? __( 'Saved. Leave empty to keep it.', 'gbp-post-sync' ) : 'GOCSPX-…' ),
							disabled( $in_config, true, false )
						);

						if ( $in_config ) {
							echo '<p class="description">' . esc_html__( 'Set in wp-config.php (GBP_POST_SYNC_CLIENT_ID and GBP_POST_SYNC_CLIENT_SECRET).', 'gbp-post-sync' ) . '</p>';
						} else {
							echo '<p class="description">' . esc_html__( 'Stored encrypted. You can also define GBP_POST_SYNC_CLIENT_ID and GBP_POST_SYNC_CLIENT_SECRET in wp-config.php instead.', 'gbp-post-sync' ) . '</p>';
						}
					},
					'gbpps-client-secret'
				);
				?>
			</tbody>
		</table>

		<?php
		$lost = $this->plugin->google->lost_reason();

		if ( '' !== $lost ) {
			self::callout( 'triangle-alert', $lost, 'is-warning' );
		}
		?>

		<p class="gbpps-connect-row">
			<?php if ( $ready ) : ?>
				<a class="button button-primary button-hero gbpps-connect" href="<?php echo esc_url( self::action_url( 'gbp_post_sync_connect' ) ); ?>" data-gbpps-connect>
					<?php echo self::icon( 'simpleicons/google', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Connect with Google', 'gbp-post-sync' ); ?>
				</a>
			<?php else : ?>
				<button type="button" class="button button-hero gbpps-connect" disabled>
					<?php echo self::icon( 'simpleicons/google', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Connect with Google', 'gbp-post-sync' ); ?>
				</button>
				<span class="description"><?php esc_html_e( 'Save your client ID and secret first.', 'gbp-post-sync' ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Connection: which locations to share to.
	 *
	 * @return void
	 */
	public function render_locations() {
		if ( ! $this->plugin->google->is_connected() ) {
			self::callout( 'map-pin-off', __( 'Connect a Google account first, and its Business Profile locations will be listed here.', 'gbp-post-sync' ) );
			return;
		}

		$locations = $this->plugin->google->locations();
		$refresh   = '<a href="' . esc_url( self::action_url( 'gbp_post_sync_locations' ) ) . '">' . esc_html__( 'Refresh list', 'gbp-post-sync' ) . '</a>';

		if ( is_wp_error( $locations ) ) {
			self::callout( 'cloud-off', $locations->get_error_message(), 'is-error' );
			echo '<p>' . $refresh . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			return;
		}

		if ( array() === $locations ) {
			self::callout( 'map-pin-off', __( 'This Google account doesn\'t manage any Business Profile locations. Connect the account that owns or manages the profile.', 'gbp-post-sync' ), 'is-warning' );
			echo '<p>' . $refresh . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			return;
		}

		$selected = $this->plugin->locations();
		?>
		<input type="hidden" name="<?php echo esc_attr( self::name( 'locations_present' ) ); ?>" value="1">
		<div class="gbpps-options">
			<?php foreach ( $locations as $name => $location ) : ?>
				<label class="gbpps-option">
					<input type="checkbox" name="<?php echo esc_attr( self::name( 'locations' ) ); ?>[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( isset( $selected[ $name ] ) ); ?>>
					<span class="gbpps-option-icon"><?php echo self::icon( 'store', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="gbpps-option-text">
						<strong><?php echo esc_html( $location['title'] ); ?></strong>
						<?php if ( '' !== $location['address'] ) : ?>
							<span><?php echo esc_html( $location['address'] ); ?></span>
						<?php endif; ?>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="gbpps-muted gbpps-small">
			<?php
			/* translators: %d: number of locations. */
			echo esc_html( sprintf( _n( '%d location found.', '%d locations found.', count( $locations ), 'gbp-post-sync' ), count( $locations ) ) ) . ' ' . $refresh; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			?>
		</p>
		<?php
	}

	/**
	 * Connection: step-by-step Google Cloud setup.
	 *
	 * @return void
	 */
	public function render_setup() {
		$redirect = GBP_Post_Sync_Google::redirect_uri();
		$host     = (string) wp_parse_url( $redirect, PHP_URL_HOST );
		$insecure = 0 !== strpos( $redirect, 'https://' ) && ! in_array( $host, array( 'localhost', '127.0.0.1' ), true );
		$link     = function ( $url, $text ) {
			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . GBP_Post_Sync_Icons::get( 'external-link', 12, 'gbpps-ext' ) . '</a>';
		};
		$steps    = array(
			array(
				'folder-plus',
				/* translators: %s: link to Google Cloud. */
				sprintf( esc_html__( 'Create a project in %s (or pick an existing one).', 'gbp-post-sync' ), $link( 'https://console.cloud.google.com/projectcreate', 'Google Cloud' ) ),
			),
			array(
				'badge-check',
				/* translators: %s: link to Google's access request page. */
				sprintf( esc_html__( 'Request access to the Business Profile APIs for that project: %s. Google reviews each request; until it\'s approved, the APIs answer with a quota of 0.', 'gbp-post-sync' ), $link( 'https://developers.google.com/my-business/content/prereqs', __( 'access request form', 'gbp-post-sync' ) ) ),
			),
			array(
				'plug-zap',
				sprintf(
					/* translators: 1, 2, 3: links to the three APIs. */
					esc_html__( 'Enable these three APIs: %1$s, %2$s and %3$s.', 'gbp-post-sync' ),
					$link( 'https://console.cloud.google.com/apis/library/mybusinessaccountmanagement.googleapis.com', 'My Business Account Management API' ),
					$link( 'https://console.cloud.google.com/apis/library/mybusinessbusinessinformation.googleapis.com', 'My Business Business Information API' ),
					$link( 'https://console.cloud.google.com/apis/library/mybusiness.googleapis.com', 'Google My Business API' )
				),
			),
			array(
				'shield-check',
				/* translators: %s: link to the OAuth consent screen. */
				sprintf( esc_html__( 'Set up the %s (External, or Internal on Google Workspace) and publish it to "In production". Apps left in Testing lose their connection every 7 days.', 'gbp-post-sync' ), $link( 'https://console.cloud.google.com/apis/credentials/consent', __( 'OAuth consent screen', 'gbp-post-sync' ) ) ),
			),
			array(
				'key-round',
				/* translators: %s: link to the Credentials page. */
				sprintf( esc_html__( 'In %s, create an OAuth client ID of type "Web application" and add this authorized redirect URI:', 'gbp-post-sync' ), $link( 'https://console.cloud.google.com/apis/credentials', __( 'Credentials', 'gbp-post-sync' ) ) ),
			),
			array(
				'plug',
				esc_html__( 'Paste the client ID and secret into the Google account box, save, and press Connect with Google.', 'gbp-post-sync' ),
			),
		);
		?>
		<ol class="gbpps-steps">
			<?php foreach ( $steps as $i => $step ) : ?>
				<li>
					<span class="gbpps-step-num"><?php echo (int) $i + 1; ?></span>
					<span class="gbpps-step-text">
						<?php echo $step[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?>
						<?php if ( 'key-round' === $step[0] ) : ?>
							<span class="gbpps-copy">
								<input type="text" class="large-text code" id="gbpps-redirect" value="<?php echo esc_attr( $redirect ); ?>" readonly>
								<button type="button" class="button" data-gbpps-copy="gbpps-redirect"><?php echo self::icon( 'copy', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Copy', 'gbp-post-sync' ); ?></button>
							</span>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
		if ( $insecure ) {
			self::callout( 'triangle-alert', __( 'Google only accepts https:// redirect URIs (except on localhost). Switch the site to HTTPS before connecting.', 'gbp-post-sync' ), 'is-warning' );
		}
	}

	/**
	 * Posting: post types and automatic sharing.
	 *
	 * @return void
	 */
	public function render_what() {
		$types = $this->plugin->post_types();
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<?php
				$this->row(
					__( 'Post types', 'gbp-post-sync' ),
					function () use ( $types ) {
						echo '<div class="gbpps-chips">';
						foreach ( GBP_Post_Sync_Plugin::available_post_types() as $name => $label ) {
							printf(
								'<label class="gbpps-chip"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> <span>%4$s</span></label>',
								esc_attr( self::name( 'post_types' ) ),
								esc_attr( $name ),
								checked( in_array( $name, $types, true ), true, false ),
								esc_html( $label )
							);
						}
						echo '</div>';
						echo '<p class="description">' . esc_html__( 'These get the Google Business Profile box in the editor.', 'gbp-post-sync' ) . '</p>';
					}
				);
				$this->row(
					__( 'Automatic sharing', 'gbp-post-sync' ),
					function () {
						$this->checkbox(
							'auto_share',
							__( 'Share new posts when they\'re published', 'gbp-post-sync' ),
							__( 'Authors can switch it off for a single post in the editor. Imported or backdated posts are only shared when someone asks for it.', 'gbp-post-sync' )
						);
					}
				);
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Posting: template, button, image and link tracking.
	 *
	 * @return void
	 */
	public function render_content() {
		?>
		<div class="gbpps-compose">
		<div class="gbpps-compose-form">
		<table class="form-table" role="presentation">
			<tbody>
				<?php
				$this->row(
					__( 'Text', 'gbp-post-sync' ),
					function () {
						printf(
							'<textarea class="large-text" rows="5" id="gbpps-template" name="%1$s" maxlength="%2$d">%3$s</textarea>',
							esc_attr( self::name( 'template' ) ),
							(int) GBP_Post_Sync_Plugin::SUMMARY_MAX,
							esc_textarea( (string) $this->plugin->get_setting( 'template' ) )
						);

						$tokens = array(
							'{title}'   => __( 'Post title', 'gbp-post-sync' ),
							'{excerpt}' => __( 'Excerpt, or the first 55 words', 'gbp-post-sync' ),
							'{content}' => __( 'Full text', 'gbp-post-sync' ),
							'{url}'     => __( 'Link to the post', 'gbp-post-sync' ),
							'{site}'    => __( 'Site name', 'gbp-post-sync' ),
						);

						echo '<div class="gbpps-tokens">';
						foreach ( $tokens as $token => $label ) {
							printf( '<button type="button" class="gbpps-token" data-gbpps-token="%1$s" title="%2$s">%1$s</button>', esc_attr( $token ), esc_attr( $label ) );
						}
						echo '</div>';
						echo '<p class="description">' . esc_html__( 'Up to 1,500 characters; longer text is cut at a word. Authors can write their own text per post. Google may reject posts that contain a phone number.', 'gbp-post-sync' ) . '</p>';
					},
					'gbpps-template'
				);
				$this->row(
					__( 'Button', 'gbp-post-sync' ),
					function () {
						echo '<select id="gbpps-cta" name="' . esc_attr( self::name( 'cta' ) ) . '">';
						foreach ( GBP_Post_Sync_Plugin::cta_types() as $value => $label ) {
							printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $value, $this->plugin->get_setting( 'cta' ), false ), esc_html( $label ) );
						}
						echo '</select>';
						echo '<p class="description">' . esc_html__( 'The button links to the post. "Call now" dials the phone number on your profile instead.', 'gbp-post-sync' ) . '</p>';
					},
					'gbpps-cta'
				);
				$this->row(
					__( 'Image', 'gbp-post-sync' ),
					function () {
						$this->checkbox( 'image', __( 'Use the featured image', 'gbp-post-sync' ), __( 'JPG or PNG, at least 250 × 250 px. Google fetches it from your site, so it has to be publicly reachable.', 'gbp-post-sync' ) );
					}
				);
				$this->row(
					__( 'Link tracking', 'gbp-post-sync' ),
					function () {
						$this->checkbox( 'utm', __( 'Add UTM tags to the link', 'gbp-post-sync' ), __( 'utm_source=google, utm_medium=organic, utm_campaign=gbp-post, so these visits show up in your analytics.', 'gbp-post-sync' ) );
					}
				);
				?>
			</tbody>
		</table>
		</div>
		<div class="gbpps-compose-preview">
			<h3 class="gbpps-compose-label"><?php echo self::icon( 'eye', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Preview', 'gbp-post-sync' ); ?></h3>
			<?php $this->render_preview(); ?>
		</div>
		</div>
		<?php
	}

	/**
	 * Posting: how the latest post would look on Google, updated as the
	 * template and button change. Sits beside the template.
	 *
	 * @return void
	 */
	private function render_preview() {
		$types  = $this->plugin->post_types();
		$sample = array() !== $types ? get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => 1,
				'suppress_filters' => false,
			)
		) : array();

		if ( array() === $sample ) {
			self::callout( 'file-text', __( 'Publish a post (of a type that\'s switched on) to see a preview here.', 'gbp-post-sync' ) );
			return;
		}

		$post      = $sample[0];
		$sync      = $this->plugin->sync;
		$tokens    = $sync->tokens( $post );
		$image     = GBP_Post_Sync_Sync::image_url( $post );
		$cta       = (string) $this->plugin->get_setting( 'cta' );
		$ctas      = GBP_Post_Sync_Plugin::cta_types();
		$locations = $this->plugin->locations();
		$business  = array() !== $locations ? reset( $locations ) : get_bloginfo( 'name' );
		$text      = GBP_Post_Sync_Sync::clean_text( strtr( (string) $this->plugin->get_setting( 'template' ), $tokens ), GBP_Post_Sync_Plugin::SUMMARY_MAX );
		$data      = array(
			'tokens' => $tokens,
			'max'    => GBP_Post_Sync_Plugin::SUMMARY_MAX,
			'ctas'   => $ctas,
		);
		?>
		<div class="gbpps-preview-wrap">
			<div class="gbpps-preview" aria-label="<?php esc_attr_e( 'Preview of the update on Google', 'gbp-post-sync' ); ?>">
				<div class="gbpps-preview-head">
					<span class="gbpps-preview-avatar"><?php echo self::icon( 'store', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span>
						<strong><?php echo esc_html( $business ); ?></strong>
						<span><?php esc_html_e( 'Just now', 'gbp-post-sync' ); ?></span>
					</span>
				</div>
				<div class="gbpps-preview-image" data-gbpps-preview-image <?php echo '' === $image || ! $this->plugin->get_setting( 'image' ) ? 'hidden' : ''; ?>>
					<?php if ( '' !== $image ) : ?>
						<img src="<?php echo esc_url( $image ); ?>" alt="">
					<?php endif; ?>
				</div>
				<p class="gbpps-preview-text" data-gbpps-preview-text><?php echo esc_html( $text ); ?></p>
				<p class="gbpps-preview-cta" data-gbpps-preview-cta <?php echo 'none' === $cta ? 'hidden' : ''; ?>>
					<span><?php echo esc_html( isset( $ctas[ $cta ] ) ? $ctas[ $cta ] : '' ); ?></span>
				</p>
			</div>
			<div class="gbpps-preview-notes">
				<p>
					<?php
					/* translators: %s: post title. */
					echo esc_html( sprintf( __( 'Your latest post, "%s", as it would look with these settings.', 'gbp-post-sync' ), $tokens['{title}'] ) );
					?>
				</p>
				<p class="gbpps-muted"><?php esc_html_e( 'Google shows the first few lines with a "More" link, so put the important part first.', 'gbp-post-sync' ); ?></p>
				<?php if ( '' === $image ) : ?>
					<p class="gbpps-muted"><?php esc_html_e( 'This post has no JPG or PNG featured image, so it would go out as text only.', 'gbp-post-sync' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<script type="application/json" id="gbpps-preview-data"><?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
		<?php
	}

	/**
	 * Posting: updates and removal.
	 *
	 * @return void
	 */
	public function render_sync() {
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<?php
				$this->row(
					__( 'Edits', 'gbp-post-sync' ),
					function () {
						$this->checkbox( 'sync_updates', __( 'Update the post on Google when the WordPress post changes', 'gbp-post-sync' ), __( 'Only the text, button and image are sent, and only when one of them changed.', 'gbp-post-sync' ) );
					}
				);
				$this->row(
					__( 'Removal', 'gbp-post-sync' ),
					function () {
						$this->checkbox( 'remove', __( 'Remove it from Google when the post is unpublished, trashed or deleted', 'gbp-post-sync' ), __( 'Off by default. Authors can always remove a post with the button in the editor.', 'gbp-post-sync' ) );
					}
				);
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Activity: counts for the last 30 days.
	 *
	 * @return void
	 */
	public function render_stats() {
		$counts = GBP_Post_Sync_Log::counts( 30 );
		$tiles  = array(
			array( 'send', $counts['share'], __( 'Shared', 'gbp-post-sync' ), '' ),
			array( 'refresh-cw', $counts['update'], __( 'Updated', 'gbp-post-sync' ), '' ),
			array( 'trash-2', $counts['remove'], __( 'Removed', 'gbp-post-sync' ), '' ),
			array( 'circle-x', $counts['failed'], __( 'Failed', 'gbp-post-sync' ), $counts['failed'] ? 'bad' : '' ),
		);
		?>
		<div class="gbpps-stats">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="gbpps-stat<?php echo '' !== $tile[3] ? ' is-' . esc_attr( $tile[3] ) : ''; ?>">
					<span class="gbpps-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="gbpps-stat-num"><?php echo esc_html( number_format_i18n( (int) $tile[1] ) ); ?></span>
					<span class="gbpps-stat-label"><?php echo esc_html( $tile[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Activity: the log table.
	 *
	 * @return void
	 */
	public function render_log() {
		$log     = array_slice( GBP_Post_Sync_Log::all(), 0, 50 );
		$actions = array(
			'share'  => array( 'send', __( 'Shared', 'gbp-post-sync' ) ),
			'update' => array( 'refresh-cw', __( 'Updated', 'gbp-post-sync' ) ),
			'remove' => array( 'trash-2', __( 'Removed', 'gbp-post-sync' ) ),
		);

		if ( array() === $log ) {
			echo '<p class="gbpps-empty">' . self::icon( 'circle-dot', 16 ) . '<span>' . esc_html__( 'Nothing has been sent to Google yet. Shares, updates and removals will be listed here.', 'gbp-post-sync' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}
		?>
		<table class="widefat striped gbpps-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'gbp-post-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Post', 'gbp-post-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Location', 'gbp-post-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'gbp-post-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<?php
					$action    = isset( $actions[ $entry['action'] ] ) ? $actions[ $entry['action'] ] : array( 'circle-dot', $entry['action'] );
					$edit_link = $entry['post_id'] && current_user_can( 'edit_post', $entry['post_id'] ) ? get_edit_post_link( $entry['post_id'] ) : '';
					?>
					<tr>
						<td class="gbpps-nowrap">
							<?php
							/* translators: %s: time since, e.g. "5 mins". */
							echo esc_html( sprintf( __( '%s ago', 'gbp-post-sync' ), human_time_diff( (int) $entry['time'] ) ) );
							?>
							<span class="gbpps-muted"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?></span>
						</td>
						<td>
							<?php if ( $edit_link ) : ?>
								<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( '' !== $entry['title'] ? $entry['title'] : __( '(no title)', 'gbp-post-sync' ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( '' !== $entry['title'] ? $entry['title'] : __( '(deleted post)', 'gbp-post-sync' ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $entry['location'] ); ?></td>
						<td>
							<span class="gbpps-tag <?php echo $entry['ok'] ? 'is-good' : 'is-bad'; ?>">
								<?php echo self::icon( $entry['ok'] ? $action[0] : 'circle-x', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
								<?php echo esc_html( $entry['ok'] ? $action[1] : __( 'Failed', 'gbp-post-sync' ) ); ?>
							</span>
							<?php if ( ! empty( $entry['url'] ) && $entry['ok'] && 'remove' !== $entry['action'] ) : ?>
								<a class="gbpps-view" href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View on Google', 'gbp-post-sync' ); ?><?php echo self::icon( 'external-link', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></a>
							<?php endif; ?>
							<?php if ( '' !== $entry['message'] ) : ?>
								<span class="gbpps-muted gbpps-message"><?php echo esc_html( $entry['message'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="gbpps-small">
			<a class="button-link button-link-delete" href="<?php echo esc_url( self::action_url( 'gbp_post_sync_clear_log' ) ); ?>" data-gbpps-confirm="clearLog"><?php esc_html_e( 'Clear log', 'gbp-post-sync' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Sidebar: Save, status, and where updates come from.
	 *
	 * @return void
	 */
	private function render_sidebar() {
		$plugin    = $this->plugin;
		$google    = $plugin->google;
		$locations = $plugin->locations();
		$types     = array_intersect_key( GBP_Post_Sync_Plugin::available_post_types(), array_flip( $plugin->post_types() ) );
		$email     = $google->account_email();
		$status    = array(
			array( $google->is_connected(), $google->is_connected() ? ( '' !== $email ? $email : __( 'Google connected', 'gbp-post-sync' ) ) : __( 'Google not connected', 'gbp-post-sync' ) ),
			/* translators: %d: number of locations. */
			array( $plugin->is_ready(), $plugin->is_ready() ? sprintf( _n( 'Sharing to %d location', 'Sharing to %d locations', count( $locations ), 'gbp-post-sync' ), count( $locations ) ) : ( $google->is_connected() ? __( 'No location selected', 'gbp-post-sync' ) : __( 'Locations: connect first', 'gbp-post-sync' ) ) ),
			/* translators: %s: list of post types, e.g. "Posts, Pages". */
			array( array() !== $types, array() !== $types ? sprintf( __( 'Shares %s', 'gbp-post-sync' ), implode( ', ', $types ) ) : __( 'No post types switched on', 'gbp-post-sync' ) ),
			array( (bool) $plugin->get_setting( 'auto_share' ), $plugin->get_setting( 'auto_share' ) ? __( 'New posts shared automatically', 'gbp-post-sync' ) : __( 'Shared only when asked', 'gbp-post-sync' ) ),
			array( (bool) $plugin->get_setting( 'sync_updates' ), $plugin->get_setting( 'sync_updates' ) ? __( 'Edits are synced', 'gbp-post-sync' ) : __( 'Edits aren\'t synced', 'gbp-post-sync' ) ),
		);
		?>
		<div class="postbox gbpps-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'gbp-post-sync' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p><?php esc_html_e( 'Settings on every tab are saved together.', 'gbp-post-sync' ); ?></p>
				<?php submit_button( __( 'Save Changes', 'gbp-post-sync' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'gbp-post-sync' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="gbpps-checklist">
					<?php foreach ( $status as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
				<?php if ( ! $plugin->is_ready() ) : ?>
					<p class="gbpps-callout is-warning"><?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Nothing is shared until a Google account is connected and a location is ticked.', 'gbp-post-sync' ); ?></span></p>
				<?php endif; ?>
			</div>
		</div>

		<?php
		$this->render_updates_box();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( GBP_POST_SYNC_REPO ) );
		$check_url  = GBP_Post_Sync_Updater::check_now_url();
		?>
		<div class="postbox gbpps-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'gbp-post-sync' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'gbp-post-sync' ), '<strong>' . esc_html( GBP_POST_SYNC_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! GBP_Post_Sync_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'gbp-post-sync' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( GBP_POST_SYNC_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( GBP_Post_Sync_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'gbp-post-sync' ),
							'<a href="' . esc_url( GBP_POST_SYNC_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>GBP_POST_SYNC_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'gbp-post-sync' ),
							'<a href="' . esc_url( GBP_POST_SYNC_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'gbp-post-sync' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
