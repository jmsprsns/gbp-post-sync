<?php
/**
 * Activity log: what was shared, updated or removed on Google, and what failed.
 *
 * Kept in one non-autoloaded option, newest first, capped at MAX entries.
 *
 * @package GBP_Post_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activity log storage.
 */
final class GBP_Post_Sync_Log {

	/**
	 * Option holding the entries.
	 */
	const OPTION = 'gbp_post_sync_log';

	/**
	 * Entries kept.
	 */
	const MAX = 200;

	/**
	 * Record one event.
	 *
	 * @param array<string,mixed> $entry action (share|update|remove), ok (bool), post_id, title,
	 *                                   location (title), message, url.
	 * @return void
	 */
	public static function add( array $entry ) {
		$entry = array(
			'time'     => time(),
			'action'   => isset( $entry['action'] ) ? sanitize_key( $entry['action'] ) : '',
			'ok'       => ! empty( $entry['ok'] ),
			'post_id'  => isset( $entry['post_id'] ) ? absint( $entry['post_id'] ) : 0,
			'title'    => isset( $entry['title'] ) ? self::clip( $entry['title'], 200 ) : '',
			'location' => isset( $entry['location'] ) ? self::clip( $entry['location'], 200 ) : '',
			'message'  => isset( $entry['message'] ) ? self::clip( $entry['message'], 500 ) : '',
			'url'      => isset( $entry['url'] ) ? esc_url_raw( (string) $entry['url'] ) : '',
		);

		$log = self::all();
		array_unshift( $log, $entry );

		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	/**
	 * Every entry, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		$log = get_option( self::OPTION, array() );

		return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
	}

	/**
	 * Counts by result over the last few days.
	 *
	 * @param int $days Days back.
	 * @return array{share:int,update:int,remove:int,failed:int}
	 */
	public static function counts( $days ) {
		$since  = time() - $days * DAY_IN_SECONDS;
		$counts = array(
			'share'  => 0,
			'update' => 0,
			'remove' => 0,
			'failed' => 0,
		);

		foreach ( self::all() as $entry ) {
			if ( empty( $entry['time'] ) || $entry['time'] < $since ) {
				continue;
			}

			if ( empty( $entry['ok'] ) ) {
				++$counts['failed'];
			} elseif ( isset( $entry['action'], $counts[ $entry['action'] ] ) ) {
				++$counts[ $entry['action'] ];
			}
		}

		return $counts;
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Plain, single-line text cut to a length.
	 *
	 * @param mixed $text Text.
	 * @param int   $max  Characters kept.
	 * @return string
	 */
	private static function clip( $text, $max ) {
		$text = sanitize_text_field( (string) $text );

		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
	}
}
