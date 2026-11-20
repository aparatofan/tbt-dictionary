<?php
/**
 * The background AI fill-in (WP-Cron, every 2 minutes, while the plugin is
 * active).
 *
 * Each run takes the 10 oldest `pending` words of any user that are older
 * than 10 minutes, and fills them one by one with TBTDIC_AI, without the
 * daily limit. It stops starting new words after about 20 seconds, so a run
 * stays inside a host's PHP time limit; the rest wait for the next run. With
 * nothing pending, a run is a single SELECT.
 *
 * Words younger than 10 minutes belong to the page (a student's new word,
 * filled as it is added); older ones belong to this job: words copied from
 * AYS, and fill-ins the page never finished. Fails open as the page does: a
 * failed word becomes `failed` and is not retried.
 *
 * WP-Cron runs when the site has visitors, so the pace depends on traffic.
 */

defined( 'ABSPATH' ) || exit;

class TBTDIC_Fill {

	const HOOK     = 'tbtdic_fill_pending';
	const SCHEDULE = 'tbtdic_two_minutes';
	const INTERVAL = 120;

	/** Words per run. */
	const BATCH = 10;

	/** Seconds after which a run starts no new word. */
	const BUDGET = 20;

	/** Seconds during which a new pending word belongs to the page. */
	const PAGE_WINDOW = 600;

	/**
	 * Hooks. The schedule filter is added before anything schedules.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * @param array $schedules WP-Cron schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules                   = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::SCHEDULE ] = array(
			'interval' => self::INTERVAL,
			'display'  => __( 'Every 2 minutes (TBT Dictionary)', 'tbt-dictionary' ),
		);
		return $schedules;
	}

	/**
	 * Keep the job scheduled while the plugin is active (FTP deploys never
	 * fire activation, so this runs on every request; a cheap check).
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + self::INTERVAL, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * On deactivation.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * May the page fill this pending word? Only when it was created in the
	 * last 10 minutes.
	 *
	 * @param string $created_at The word's created_at (UTC).
	 * @return bool
	 */
	public static function page_may_fill( $created_at ) {
		$ts = strtotime( (string) $created_at . ' UTC' );
		return false !== $ts && $ts >= time() - self::PAGE_WINDOW;
	}

	/**
	 * One run.
	 */
	public static function run() {
		if ( ! TBTDIC_DB::tables_exist() ) {
			return;
		}
		$words = TBTDIC_DB::pending_words( self::BATCH, gmdate( 'Y-m-d H:i:s', time() - self::PAGE_WINDOW ) );
		if ( ! $words ) {
			return;
		}
		$start = microtime( true );
		foreach ( $words as $word ) {
			if ( microtime( true ) - $start > self::BUDGET ) {
				break;
			}
			TBTDIC_AI::fill_and_save( (int) $word['user_id'], $word, false );
		}
	}
}
