<?php
/**
 * Sharing engine: decides when a post goes to Google, builds the update and
 * keeps it in step with the post.
 *
 * Nothing talks to Google while a post is being saved. Publishing queues a
 * WP-Cron job a few seconds out, which gives the block editor time to save the
 * sidebar box (the share checkbox and custom text arrive in a second request).
 * The editor box also runs a due job itself when it checks the status, so
 * sharing doesn't wait on the next visitor on quiet sites.
 *
 * @package GBP_Post_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post sharing.
 */
final class GBP_Post_Sync_Sync {

	/**
	 * Cron hooks: share a newly published post, push an edit, remove posts.
	 */
	const HOOK_SHARE  = 'gbp_post_sync_share';
	const HOOK_UPDATE = 'gbp_post_sync_update';
	const HOOK_REMOVE = 'gbp_post_sync_remove';
	const CRON_HOOKS  = array( self::HOOK_SHARE, self::HOOK_UPDATE, self::HOOK_REMOVE );

	/**
	 * Post meta: the author's choice ('1' share, '0' don't; missing = setting).
	 */
	const META_SHARE = '_gbp_post_sync_share';

	/**
	 * Post meta: custom text for the update, instead of the template.
	 */
	const META_SUMMARY = '_gbp_post_sync_summary';

	/**
	 * Post meta: what's on Google. Location name => post name, link, time, hash.
	 */
	const META_POSTS = '_gbp_post_sync_posts';

	/**
	 * Post meta: latest state (queued, shared, partial, failed, removed) and message.
	 */
	const META_STATUS = '_gbp_post_sync_status';

	/**
	 * Seconds between publishing and sharing.
	 */
	const DELAY = 10;

	/**
	 * Posts published longer ago than this (backdated, imported, created by
	 * code) are only shared when someone asks for it in the editor box.
	 */
	const RECENT = 2 * DAY_IN_SECONDS;

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

		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );

		add_action( self::HOOK_SHARE, array( $this, 'run_share' ) );
		add_action( self::HOOK_UPDATE, array( $this, 'run_update' ) );
		add_action( self::HOOK_REMOVE, array( $this, 'run_remove' ), 10, 3 );
	}

	/* Hooks --------------------------------------------------------------- */

	/**
	 * Queue a share when a post is first published, and a removal when it's
	 * unpublished or trashed (if that's switched on).
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || ! $this->is_shareable_type( $post->post_type ) ) {
			return;
		}

		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
				return;
			}

			if ( $this->plugin->is_ready() && array() === $this->get_shares( $post->ID ) ) {
				$this->set_status( $post->ID, 'queued' );
				$this->schedule( self::HOOK_SHARE, array( $post->ID ) );
			}
			return;
		}

		if ( 'publish' === $old_status && 'publish' !== $new_status && $this->plugin->get_setting( 'remove' ) ) {
			$this->queue_removal( $post );
		}
	}

	/**
	 * Queue an update when a shared post is edited.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! $this->plugin->get_setting( 'sync_updates' ) ) {
			return;
		}

		if ( array() !== $this->get_shares( $post_id ) && $this->is_shareable_type( $post->post_type ) ) {
			$this->schedule( self::HOOK_UPDATE, array( (int) $post_id ) );
		}
	}

	/**
	 * Queue a removal when a shared post is deleted for good (if that's switched
	 * on). The job gets the Google post names, since the post meta is about to go.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_delete( $post_id ) {
		$post = get_post( $post_id );

		if ( $post && $this->plugin->get_setting( 'remove' ) ) {
			$this->queue_removal( $post );
		}
	}

	/* Jobs ---------------------------------------------------------------- */

	/**
	 * Share a post on every selected location it isn't on yet.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $manual  Someone pressed "Share now" (skips the auto-share rules).
	 * @return true|WP_Error
	 */
	public function run_share( $post_id, $manual = false ) {
		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return new WP_Error( 'gbp_no_post', __( 'That post doesn\'t exist.', 'gbp-post-sync' ) );
		}

		$check = $this->can_share( $post );

		if ( is_wp_error( $check ) ) {
			$this->finish_without_sharing( $post->ID, $check );
			return $check;
		}

		if ( ! $manual && ! $this->share_wanted( $post ) ) {
			delete_post_meta( $post->ID, self::META_STATUS );
			return true;
		}

		if ( ! self::lock( $post->ID ) ) {
			return new WP_Error( 'gbp_busy', __( 'This post is being sent to Google right now.', 'gbp-post-sync' ) );
		}

		$shares  = $this->get_shares( $post->ID );
		$payload = $this->payload( $post );
		$hash    = self::hash( $payload );
		$title   = self::plain_title( $post );
		$errors  = array();

		foreach ( $this->plugin->locations() as $location => $location_title ) {
			if ( isset( $shares[ $location ] ) ) {
				continue;
			}

			$result = $this->plugin->google->create_post( $location, $payload );

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				$shares[ $location ] = array(
					'name'  => $result['name'],
					'url'   => isset( $result['searchUrl'] ) ? esc_url_raw( $result['searchUrl'] ) : '',
					'time'  => time(),
					'hash'  => $hash,
					'title' => $location_title,
				);
			}

			GBP_Post_Sync_Log::add(
				array(
					'action'   => 'share',
					'ok'       => ! is_wp_error( $result ),
					'post_id'  => $post->ID,
					'title'    => $title,
					'location' => $location_title,
					'message'  => is_wp_error( $result ) ? $result->get_error_message() : '',
					'url'      => isset( $shares[ $location ]['url'] ) ? $shares[ $location ]['url'] : '',
				)
			);
		}

		$this->save_shares( $post->ID, $shares );
		self::unlock( $post->ID );

		if ( array() === $errors ) {
			$this->set_status( $post->ID, 'shared' );
			return true;
		}

		$this->set_status( $post->ID, array() === $shares ? 'failed' : 'partial', $errors[0] );

		return new WP_Error( 'gbp_share_failed', $errors[0] );
	}

	/**
	 * Push a shared post's current text, button and image to Google, where they changed.
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	public function run_update( $post_id ) {
		$post   = get_post( (int) $post_id );
		$shares = $post ? $this->get_shares( $post->ID ) : array();

		if ( ! $post || array() === $shares || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return true;
		}

		if ( ! self::lock( $post->ID ) ) {
			// Try again shortly rather than lose the edit.
			$this->schedule( self::HOOK_UPDATE, array( $post->ID ), 60 );
			return true;
		}

		$payload = $this->payload( $post );
		$hash    = self::hash( $payload );
		$title   = self::plain_title( $post );
		$error   = '';

		foreach ( $shares as $location => $share ) {
			if ( isset( $share['hash'] ) && $share['hash'] === $hash ) {
				continue;
			}

			$result = $this->plugin->google->update_post( $share['name'], $payload );

			if ( ! is_wp_error( $result ) ) {
				$shares[ $location ]['hash'] = $hash;

				if ( ! empty( $result['searchUrl'] ) ) {
					$shares[ $location ]['url'] = esc_url_raw( $result['searchUrl'] );
				}
			} elseif ( 404 === GBP_Post_Sync_Google::error_status( $result ) ) {
				// Deleted on Google's side: forget it, so "Share now" can post it again.
				unset( $shares[ $location ] );
			} else {
				$error = $result->get_error_message();
			}

			GBP_Post_Sync_Log::add(
				array(
					'action'   => 'update',
					'ok'       => ! is_wp_error( $result ),
					'post_id'  => $post->ID,
					'title'    => $title,
					'location' => isset( $share['title'] ) ? $share['title'] : $location,
					'message'  => is_wp_error( $result ) ? ( 404 === GBP_Post_Sync_Google::error_status( $result ) ? __( 'The post was deleted on Google.', 'gbp-post-sync' ) : $result->get_error_message() ) : '',
					'url'      => isset( $shares[ $location ]['url'] ) ? $shares[ $location ]['url'] : '',
				)
			);
		}

		$this->save_shares( $post->ID, $shares );
		self::unlock( $post->ID );

		if ( '' !== $error ) {
			$this->set_status( $post->ID, 'partial', $error );
			return new WP_Error( 'gbp_update_failed', $error );
		}

		if ( array() !== $shares ) {
			$this->set_status( $post->ID, 'shared' );
		}

		return true;
	}

	/**
	 * Delete posts from Google.
	 *
	 * @param string[] $names   Google post names.
	 * @param int      $post_id WordPress post they came from (may be deleted by now).
	 * @param string   $title   Its title, for the log.
	 * @return true|WP_Error
	 */
	public function run_remove( $names, $post_id = 0, $title = '' ) {
		$names = array_filter( (array) $names, array( 'GBP_Post_Sync_Google', 'valid_post_name' ) );
		$post  = $post_id ? get_post( (int) $post_id ) : null;
		$error = '';
		$gone  = array();

		foreach ( $names as $name ) {
			$result = $this->plugin->google->delete_post( $name );

			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$gone[] = $name;
			}

			GBP_Post_Sync_Log::add(
				array(
					'action'   => 'remove',
					'ok'       => ! is_wp_error( $result ),
					'post_id'  => $post ? $post->ID : 0,
					'title'    => $post ? self::plain_title( $post ) : (string) $title,
					'location' => $this->location_title( $name ),
					'message'  => is_wp_error( $result ) ? $result->get_error_message() : '',
				)
			);
		}

		if ( $post ) {
			$shares = array();

			foreach ( $this->get_shares( $post->ID ) as $location => $share ) {
				if ( ! in_array( $share['name'], $gone, true ) ) {
					$shares[ $location ] = $share;
				}
			}

			$this->save_shares( $post->ID, $shares );

			if ( '' !== $error ) {
				$this->set_status( $post->ID, 'failed', $error );
			} elseif ( array() === $shares ) {
				$this->set_status( $post->ID, 'removed' );
			}
		}

		return '' === $error ? true : new WP_Error( 'gbp_remove_failed', $error );
	}

	/**
	 * Run a share job now if it's queued and due (or lost its cron event).
	 * Called when the editor box checks the status.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function run_due( $post_id ) {
		$status = $this->get_status( $post_id );

		if ( 'queued' !== $status['state'] ) {
			return;
		}

		$next = wp_next_scheduled( self::HOOK_SHARE, array( (int) $post_id ) );

		if ( false === $next || $next <= time() ) {
			wp_clear_scheduled_hook( self::HOOK_SHARE, array( (int) $post_id ) );
			$this->run_share( $post_id );
		}
	}

	/* Building the update -------------------------------------------------- */

	/**
	 * The Business Profile post for a WordPress post.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed> LocalPost fields.
	 */
	public function payload( WP_Post $post ) {
		$cta     = (string) $this->plugin->get_setting( 'cta' );
		$payload = array(
			'languageCode' => self::language(),
			'summary'      => $this->summary( $post ),
			'topicType'    => 'STANDARD',
		);

		if ( 'CALL' === $cta ) {
			$payload['callToAction'] = array( 'actionType' => 'CALL' );
		} elseif ( 'none' !== $cta && isset( GBP_Post_Sync_Plugin::cta_types()[ $cta ] ) ) {
			$payload['callToAction'] = array(
				'actionType' => $cta,
				'url'        => $this->link( $post ),
			);
		}

		$image = $this->plugin->get_setting( 'image' ) ? self::image_url( $post ) : '';

		if ( '' !== $image ) {
			$payload['media'] = array(
				array(
					'mediaFormat' => 'PHOTO',
					'sourceUrl'   => $image,
				),
			);
		}

		/**
		 * Filters the Business Profile post sent to Google for a WordPress post.
		 *
		 * @param array   $payload LocalPost fields (languageCode, summary, topicType, callToAction, media).
		 * @param WP_Post $post    The WordPress post.
		 */
		$filtered = apply_filters( 'gbp_post_sync_payload', $payload, $post );

		if ( is_array( $filtered ) && isset( $filtered['summary'] ) && is_string( $filtered['summary'] ) ) {
			$filtered['summary'] = self::clean_text( $filtered['summary'], GBP_Post_Sync_Plugin::SUMMARY_MAX );
			$payload             = $filtered;
		}

		return $payload;
	}

	/**
	 * The post's text on Google: its custom text, else the template filled in.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function summary( WP_Post $post ) {
		$custom = (string) get_post_meta( $post->ID, self::META_SUMMARY, true );

		if ( '' !== trim( $custom ) ) {
			return self::clean_text( $custom, GBP_Post_Sync_Plugin::SUMMARY_MAX );
		}

		return self::clean_text( strtr( (string) $this->plugin->get_setting( 'template' ), $this->tokens( $post ) ), GBP_Post_Sync_Plugin::SUMMARY_MAX );
	}

	/**
	 * Template tokens and their values for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,string>
	 */
	public function tokens( WP_Post $post ) {
		$content = self::plain_text( $post->post_content );
		$excerpt = '' !== trim( $post->post_excerpt ) ? self::plain_text( $post->post_excerpt ) : wp_trim_words( $content, 55, '…' );

		return array(
			'{title}'   => self::plain_title( $post ),
			'{excerpt}' => $excerpt,
			'{content}' => $content,
			'{url}'     => $this->link( $post ),
			'{site}'    => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
		);
	}

	/**
	 * Link to the post, with UTM tags if that's on.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function link( WP_Post $post ) {
		$url = (string) get_permalink( $post );

		if ( '' !== $url && $this->plugin->get_setting( 'utm' ) ) {
			$url = add_query_arg(
				array(
					'utm_source'   => 'google',
					'utm_medium'   => 'organic',
					'utm_campaign' => 'gbp-post',
				),
				$url
			);
		}

		return $url;
	}

	/**
	 * The featured image, if Google can use it (JPG or PNG, at least 250px).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function image_url( WP_Post $post ) {
		$id = (int) get_post_thumbnail_id( $post );

		if ( ! $id || ! in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png' ), true ) ) {
			return '';
		}

		foreach ( array( 'large', 'full' ) as $size ) {
			$src = wp_get_attachment_image_src( $id, $size );

			if ( $src && $src[1] >= 250 && $src[2] >= 250 && wp_http_validate_url( $src[0] ) ) {
				return $src[0];
			}
		}

		return '';
	}

	/* State ---------------------------------------------------------------- */

	/**
	 * Whether a post can go to Google at all right now.
	 *
	 * @param WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public function can_share( WP_Post $post ) {
		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'gbp_not_published', __( 'Only published posts can be shared.', 'gbp-post-sync' ) );
		}

		if ( '' !== $post->post_password ) {
			return new WP_Error( 'gbp_password', __( 'Password-protected posts are never shared.', 'gbp-post-sync' ) );
		}

		if ( ! $this->is_shareable_type( $post->post_type ) ) {
			return new WP_Error( 'gbp_type', __( 'Sharing is off for this post type.', 'gbp-post-sync' ) );
		}

		if ( ! $this->plugin->google->is_connected() ) {
			return new WP_Error( 'gbp_not_connected', __( 'No Google account is connected.', 'gbp-post-sync' ) );
		}

		if ( array() === $this->plugin->locations() ) {
			return new WP_Error( 'gbp_no_locations', __( 'No Business Profile location is selected.', 'gbp-post-sync' ) );
		}

		return true;
	}

	/**
	 * Whether a newly published post should be shared without anyone asking.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function share_wanted( WP_Post $post ) {
		$choice = get_post_meta( $post->ID, self::META_SHARE, true );

		if ( '1' === $choice || '0' === $choice ) {
			return '1' === $choice;
		}

		// Falls back to the local date when post_date_gmt is still 0000-00-00,
		// which wp_publish_post() leaves on drafts that never had a date set.
		$published = get_post_timestamp( $post );

		return (bool) $this->plugin->get_setting( 'auto_share' ) && $published && time() - $published < self::RECENT;
	}

	/**
	 * Whether a post type is switched on.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public function is_shareable_type( $type ) {
		return in_array( $type, $this->plugin->post_types(), true );
	}

	/**
	 * What's on Google for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array<string,mixed>> Location name => details.
	 */
	public function get_shares( $post_id ) {
		$shares = get_post_meta( (int) $post_id, self::META_POSTS, true );
		$out    = array();

		foreach ( is_array( $shares ) ? $shares : array() as $location => $share ) {
			if ( GBP_Post_Sync_Google::valid_location( $location ) && is_array( $share ) && GBP_Post_Sync_Google::valid_post_name( isset( $share['name'] ) ? $share['name'] : '' ) ) {
				$out[ $location ] = $share;
			}
		}

		return $out;
	}

	/**
	 * Latest state for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{state:string,message:string,time:int}
	 */
	public function get_status( $post_id ) {
		$status = get_post_meta( (int) $post_id, self::META_STATUS, true );
		$status = is_array( $status ) ? $status : array();

		return array(
			'state'   => isset( $status['state'] ) ? (string) $status['state'] : '',
			'message' => isset( $status['message'] ) ? (string) $status['message'] : '',
			'time'    => isset( $status['time'] ) ? (int) $status['time'] : 0,
		);
	}

	/**
	 * Record a post's state.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $state   queued, shared, partial, failed or removed.
	 * @param string $message Error to show.
	 * @return void
	 */
	public function set_status( $post_id, $state, $message = '' ) {
		update_post_meta(
			(int) $post_id,
			self::META_STATUS,
			array(
				'state'   => $state,
				'message' => sanitize_text_field( $message ),
				'time'    => time(),
			)
		);
	}

	/**
	 * Cancel a queued share (the author unticked the box after publishing).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function cancel_share( $post_id ) {
		wp_clear_scheduled_hook( self::HOOK_SHARE, array( (int) $post_id ) );

		if ( 'queued' === $this->get_status( $post_id )['state'] ) {
			delete_post_meta( (int) $post_id, self::META_STATUS );
		}
	}

	/**
	 * Remove a post from Google.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function queue_removal( WP_Post $post ) {
		$names = wp_list_pluck( $this->get_shares( $post->ID ), 'name' );

		wp_clear_scheduled_hook( self::HOOK_SHARE, array( $post->ID ) );

		if ( array() !== $names ) {
			wp_schedule_single_event( time(), self::HOOK_REMOVE, array( array_values( $names ), $post->ID, self::plain_title( $post ) ) );
		}
	}

	/* Helpers -------------------------------------------------------------- */

	/**
	 * Schedule a job once (an identical pending job is kept).
	 *
	 * @param string $hook  Cron hook.
	 * @param array  $args  Arguments.
	 * @param int    $delay Seconds from now.
	 * @return void
	 */
	private function schedule( $hook, array $args, $delay = self::DELAY ) {
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + $delay, $hook, $args );
		}
	}

	/**
	 * Save what's on Google for a post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $shares  Location name => details.
	 * @return void
	 */
	private function save_shares( $post_id, array $shares ) {
		if ( array() === $shares ) {
			delete_post_meta( $post_id, self::META_POSTS );
		} else {
			update_post_meta( $post_id, self::META_POSTS, $shares );
		}
	}

	/**
	 * Record why a queued share didn't go out.
	 *
	 * @param int      $post_id Post ID.
	 * @param WP_Error $error   Reason.
	 * @return void
	 */
	private function finish_without_sharing( $post_id, WP_Error $error ) {
		if ( 'queued' === $this->get_status( $post_id )['state'] ) {
			$this->set_status( $post_id, 'failed', $error->get_error_message() );
		}
	}

	/**
	 * Selected location's title for a Google post name.
	 *
	 * @param string $name Google post name.
	 * @return string
	 */
	private function location_title( $name ) {
		$location  = preg_replace( '#/localPosts/.*$#', '', $name );
		$locations = $this->plugin->locations();

		return isset( $locations[ $location ] ) ? $locations[ $location ] : $location;
	}

	/**
	 * Take the per-post lock, so two jobs can't post the same thing twice.
	 * INSERT IGNORE is atomic, unlike add_option(); a lock older than two
	 * minutes is stale and taken over with a compare-and-swap.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function lock( $post_id ) {
		global $wpdb;

		$name = 'gbp_post_sync_lock_' . (int) $post_id;
		$now  = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock row.
		if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $now ) ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock row.
		$since = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		if ( null === $since || $now - (int) $since < 2 * MINUTE_IN_SECONDS ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock row.
		return 1 === $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $now ),
			array(
				'option_name'  => $name,
				'option_value' => $since,
			)
		);
	}

	/**
	 * Release the per-post lock.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function unlock( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock row.
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'gbp_post_sync_lock_' . (int) $post_id ) );
	}

	/**
	 * Fingerprint of a payload, to skip updates that change nothing.
	 *
	 * @param array $payload LocalPost fields.
	 * @return string
	 */
	private static function hash( array $payload ) {
		return md5( (string) wp_json_encode( $payload ) );
	}

	/**
	 * The post title as plain text.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function plain_title( WP_Post $post ) {
		return html_entity_decode( self::strip_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Post content as plain text: no blocks, shortcodes, tags or entities.
	 *
	 * @param string $html Content.
	 * @return string
	 */
	public static function plain_text( $html ) {
		// Only the start can make it into a 1,500-character update.
		$html = mb_substr( (string) $html, 0, 30000 );
		$html = strip_shortcodes( excerpt_remove_blocks( $html ) );
		$html = preg_replace( '#</(p|div|h[1-6]|li|blockquote)>|<br\s*/?>#i', '$0' . "\n", $html );

		return html_entity_decode( self::strip_tags( $html ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Remove script and style blocks (with their contents) and every tag.
	 *
	 * Does what wp_strip_all_tags() does in linear time. Core's lazy
	 * `<script.*?</script>` regex is quadratic: a post made of thousands of
	 * unclosed <style> tags took over a second per pass. An unclosed block
	 * drops the rest of the text, as a browser would.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	public static function strip_tags( $html ) {
		$html = (string) $html;

		foreach ( array( 'script', 'style' ) as $tag ) {
			$out = '';
			$pos = 0;

			while ( false !== ( $start = stripos( $html, '<' . $tag, $pos ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$out .= substr( $html, $pos, $start - $pos );
				$end  = stripos( $html, '</' . $tag, $start );
				$gt   = false === $end ? false : strpos( $html, '>', $end );
				$pos  = false === $gt ? strlen( $html ) : $gt + 1;
			}

			$html = $out . substr( $html, $pos );
		}

		return strip_tags( $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Script and style blocks are already gone.
	}

	/**
	 * Tidy text and fit it to a length, cutting at a word.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters allowed.
	 * @return string
	 */
	public static function clean_text( $text, $max ) {
		$text = html_entity_decode( self::strip_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = preg_replace( '/ *\n */', "\n", (string) $text );
		$text = trim( preg_replace( '/\n{3,}/', "\n\n", (string) $text ) );

		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $max - 1 );
		$space = max( mb_strrpos( $cut, ' ' ), mb_strrpos( $cut, "\n" ) );

		if ( false !== $space && $space > $max * 0.8 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, " \n.,;:-" ) . '…';
	}

	/**
	 * Site language as a BCP 47 tag (en-US).
	 *
	 * @return string
	 */
	private static function language() {
		if ( preg_match( '/^([a-z]{2,3})(?:_([A-Z]{2}))?/', get_locale(), $m ) ) {
			return isset( $m[2] ) ? $m[1] . '-' . $m[2] : $m[1];
		}

		return 'en';
	}
}
