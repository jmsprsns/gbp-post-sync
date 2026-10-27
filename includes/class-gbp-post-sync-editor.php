<?php
/**
 * The "Google Business Profile" box in the post editor sidebar: the share
 * checkbox, custom text, where the post is on Google, Share now and Remove.
 *
 * It's a classic meta box, so it works the same in the block editor and the
 * classic editor on every WordPress version the plugin supports.
 *
 * @package GBP_Post_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Editor box controller.
 */
final class GBP_Post_Sync_Editor {

	/**
	 * AJAX action for Share now, Remove and status checks.
	 */
	const AJAX = 'gbp_post_sync_post';

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

		add_action( 'add_meta_boxes', array( $this, 'add_box' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX, array( $this, 'ajax' ) );
	}

	/**
	 * Add the box to post types that are switched on.
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 * @return void
	 */
	public function add_box( $post_type, $post = null ) {
		if ( ! $post instanceof WP_Post || ! $this->plugin->sync->is_shareable_type( $post_type ) ) {
			return;
		}

		add_meta_box(
			'gbp-post-sync',
			__( 'Google Business Profile', 'gbp-post-sync' ),
			array( $this, 'render_box' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Load the box's styles and script on the editor for switched-on post types.
	 *
	 * @param string $hook Admin page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! $this->plugin->sync->is_shareable_type( $screen->post_type ) ) {
			return;
		}

		wp_enqueue_style( 'gbp-post-sync-editor', GBP_POST_SYNC_URL . 'assets/editor.css', array(), GBP_POST_SYNC_VERSION );
		wp_enqueue_script( 'gbp-post-sync-editor', GBP_POST_SYNC_URL . 'assets/editor.js', array(), GBP_POST_SYNC_VERSION, true );

		wp_localize_script(
			'gbp-post-sync-editor',
			'gbp_post_sync_editor',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX,
				'i18n'    => array(
					'working' => __( 'Sending to Google…', 'gbp-post-sync' ),
					'confirm' => __( 'Delete this post from your Business Profile? The WordPress post stays as it is.', 'gbp-post-sync' ),
					'failed'  => __( 'Something went wrong. Reload the page and try again.', 'gbp-post-sync' ),
				),
			)
		);
	}

	/**
	 * Draw the box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_box( $post ) {
		$summary   = (string) get_post_meta( $post->ID, GBP_Post_Sync_Sync::META_SUMMARY, true );
		$published = 'publish' === $post->post_status;

		wp_nonce_field( 'gbp_post_sync_save_' . $post->ID, 'gbp_post_sync_nonce' );
		?>
		<div class="gbpps-box" data-post-id="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX . '_' . $post->ID ) ); ?>">
			<?php if ( ! $this->plugin->is_ready() ) : ?>
				<p class="gbpps-note">
					<?php echo GBP_Post_Sync_Icons::get( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span>
						<?php
						if ( current_user_can( 'manage_options' ) ) {
							printf(
								/* translators: %s: link to the settings page. */
								esc_html__( 'Connect a Business Profile in %s to share posts there.', 'gbp-post-sync' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=' . GBP_Post_Sync_Admin::PAGE ) ) . '">' . esc_html__( 'Content Powered > Google Business Profile Post Sync', 'gbp-post-sync' ) . '</a>'
							);
						} else {
							esc_html_e( 'Sharing to Google isn\'t set up on this site yet.', 'gbp-post-sync' );
						}
						?>
					</span>
				</p>
			<?php endif; ?>

			<?php if ( ! $published && array() === $this->plugin->sync->get_shares( $post->ID ) ) : ?>
				<input type="hidden" name="gbp_post_sync_share_present" value="1">
				<label class="gbpps-share-option">
					<input type="checkbox" name="gbp_post_sync_share" value="1" <?php checked( $this->share_checked( $post ) ); ?>>
					<span><?php esc_html_e( 'Share to Google Business Profile when published', 'gbp-post-sync' ); ?></span>
				</label>
			<?php endif; ?>

			<div class="gbpps-status-wrap">
				<?php $this->render_status( $post ); ?>
			</div>

			<p class="gbpps-field">
				<label for="gbpps-summary"><?php esc_html_e( 'Text on Google', 'gbp-post-sync' ); ?></label>
				<textarea id="gbpps-summary" name="gbp_post_sync_summary" rows="4" maxlength="<?php echo (int) GBP_Post_Sync_Plugin::SUMMARY_MAX; ?>" placeholder="<?php esc_attr_e( 'Leave empty to use the template from the settings.', 'gbp-post-sync' ); ?>"><?php echo esc_textarea( $summary ); ?></textarea>
				<span class="gbpps-counter" aria-live="polite"><span data-gbpps-count><?php echo (int) mb_strlen( $summary ); ?></span> / <?php echo (int) GBP_Post_Sync_Plugin::SUMMARY_MAX; ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * The status line and buttons. Redrawn after Share now, Remove and saves.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_status( WP_Post $post ) {
		$sync      = $this->plugin->sync;
		$status    = $sync->get_status( $post->ID );
		$shares    = $sync->get_shares( $post->ID );
		$missing   = array_diff_key( $this->plugin->locations(), $shares );
		$published = 'publish' === $post->post_status;
		$state     = $status['state'];
		$can_act   = $published && $this->can_publish( $post ) && $this->plugin->is_ready() && '' === $post->post_password;

		if ( '' === $state && array() !== $shares ) {
			$state = 'shared';
		}
		?>
		<div class="gbpps-status" data-state="<?php echo esc_attr( $state ); ?>" data-published="<?php echo $published ? '1' : '0'; ?>">
			<?php if ( 'queued' === $state ) : ?>
				<p class="gbpps-line is-queued"><?php echo GBP_Post_Sync_Icons::get( 'loader-circle', 16, 'gbpps-spin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Sending to Google…', 'gbp-post-sync' ); ?></span></p>
			<?php elseif ( 'failed' === $state || 'partial' === $state ) : ?>
				<p class="gbpps-line is-error"><?php echo GBP_Post_Sync_Icons::get( 'circle-x', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( '' !== $status['message'] ? $status['message'] : __( 'Google didn\'t accept the post.', 'gbp-post-sync' ) ); ?></span></p>
			<?php elseif ( 'removed' === $state && array() === $shares ) : ?>
				<p class="gbpps-line"><?php echo GBP_Post_Sync_Icons::get( 'trash-2', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Removed from Google.', 'gbp-post-sync' ); ?></span></p>
			<?php elseif ( $published && array() === $shares && '' === $post->post_password ) : ?>
				<p class="gbpps-line"><?php echo GBP_Post_Sync_Icons::get( 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Not on Google yet.', 'gbp-post-sync' ); ?></span></p>
			<?php elseif ( '' !== $post->post_password ) : ?>
				<p class="gbpps-line"><?php echo GBP_Post_Sync_Icons::get( 'lock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Password-protected posts are never shared.', 'gbp-post-sync' ); ?></span></p>
			<?php endif; ?>

			<?php if ( array() !== $shares ) : ?>
				<ul class="gbpps-shares">
					<?php foreach ( $shares as $share ) : ?>
						<li>
							<?php echo GBP_Post_Sync_Icons::get( 'circle-check', 16, 'gbpps-ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<span>
								<strong><?php echo esc_html( isset( $share['title'] ) ? $share['title'] : '' ); ?></strong>
								<span class="gbpps-muted">
									<?php
									/* translators: %s: time since the post was shared, e.g. "5 mins". */
									echo esc_html( sprintf( __( 'Shared %s ago', 'gbp-post-sync' ), human_time_diff( (int) $share['time'] ) ) );
									?>
									<?php if ( ! empty( $share['url'] ) ) : ?>
										&middot; <a href="<?php echo esc_url( $share['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'gbp-post-sync' ); ?></a>
									<?php endif; ?>
								</span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $can_act && 'queued' !== $state && ( array() !== $missing || array() !== $shares ) ) : ?>
				<p class="gbpps-actions">
					<?php if ( array() !== $missing ) : ?>
						<button type="button" class="button <?php echo array() === $shares ? 'button-primary' : ''; ?>" data-gbpps-op="share">
							<?php echo GBP_Post_Sync_Icons::get( 'send', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<?php
							if ( array() === $shares ) {
								esc_html_e( 'Share now', 'gbp-post-sync' );
							} else {
								/* translators: %d: number of locations the post isn't on yet. */
								echo esc_html( sprintf( _n( 'Share to %d more location', 'Share to %d more locations', count( $missing ), 'gbp-post-sync' ), count( $missing ) ) );
							}
							?>
						</button>
					<?php endif; ?>
					<?php if ( array() !== $shares ) : ?>
						<button type="button" class="button-link button-link-delete" data-gbpps-op="remove"><?php esc_html_e( 'Remove from Google', 'gbp-post-sync' ); ?></button>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save the share choice and custom text.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save( $post_id ) {
		$nonce = isset( $_POST['gbp_post_sync_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gbp_post_sync_nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'gbp_post_sync_save_' . $post_id ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['gbp_post_sync_share_present'] ) ) {
			$share = empty( $_POST['gbp_post_sync_share'] ) ? '0' : '1';

			update_post_meta( $post_id, GBP_Post_Sync_Sync::META_SHARE, $share );

			if ( '0' === $share ) {
				$this->plugin->sync->cancel_share( $post_id );
			}
		}

		if ( isset( $_POST['gbp_post_sync_summary'] ) ) {
			self::save_summary( $post_id, sanitize_textarea_field( wp_unslash( $_POST['gbp_post_sync_summary'] ) ) );
		}
	}

	/**
	 * AJAX: Share now, Remove from Google, or a status check.
	 *
	 * @return void
	 */
	public function ajax() {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		check_ajax_referer( self::AJAX . '_' . $post_id, 'nonce' );

		$post = get_post( $post_id );
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) || ! $this->plugin->sync->is_shareable_type( $post->post_type ) ) {
			wp_send_json_error( array( 'message' => __( 'You can\'t change this post.', 'gbp-post-sync' ) ), 403 );
		}

		// Putting something on (or taking it off) the business's public profile
		// is publishing, so it takes the same permission.
		if ( in_array( $op, array( 'share', 'remove' ), true ) && ! $this->can_publish( $post ) ) {
			wp_send_json_error( array( 'message' => __( 'You need permission to publish posts to do that.', 'gbp-post-sync' ) ), 403 );
		}

		$result = true;

		if ( 'share' === $op ) {
			if ( isset( $_POST['summary'] ) ) {
				self::save_summary( $post_id, sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) );
			}

			wp_clear_scheduled_hook( GBP_Post_Sync_Sync::HOOK_SHARE, array( $post_id ) );
			$result = $this->plugin->sync->run_share( $post_id, true );
		} elseif ( 'remove' === $op ) {
			$names  = array_values( wp_list_pluck( $this->plugin->sync->get_shares( $post_id ), 'name' ) );
			$result = $this->plugin->sync->run_remove( $names, $post_id );
		} elseif ( 'status' === $op ) {
			$this->plugin->sync->run_due( $post_id );
		} else {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'gbp-post-sync' ) ), 400 );
		}

		$post = get_post( $post_id );

		ob_start();
		$this->render_status( $post );

		wp_send_json_success(
			array(
				'html'    => ob_get_clean(),
				'message' => is_wp_error( $result ) ? $result->get_error_message() : '',
			)
		);
	}

	/**
	 * Whether the current user may publish posts of this type.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private function can_publish( WP_Post $post ) {
		$type = get_post_type_object( $post->post_type );

		return $type && current_user_can( $type->cap->publish_posts ) && current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Whether the share box starts ticked.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private function share_checked( WP_Post $post ) {
		$choice = get_post_meta( $post->ID, GBP_Post_Sync_Sync::META_SHARE, true );

		return '' === $choice ? (bool) $this->plugin->get_setting( 'auto_share' ) : '1' === $choice;
	}

	/**
	 * Store (or clear) a post's custom text.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $summary Sanitized text.
	 * @return void
	 */
	private static function save_summary( $post_id, $summary ) {
		$summary = mb_substr( trim( $summary ), 0, GBP_Post_Sync_Plugin::SUMMARY_MAX );

		if ( '' === $summary ) {
			delete_post_meta( $post_id, GBP_Post_Sync_Sync::META_SUMMARY );
		} else {
			update_post_meta( $post_id, GBP_Post_Sync_Sync::META_SUMMARY, wp_slash( $summary ) );
		}
	}
}
