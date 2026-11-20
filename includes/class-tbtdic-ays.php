<?php
/**
 * Copy every student's groups and words, with their progress, from AYS
 * Personal Dictionary into TBT Dictionary (0.1.1).
 *
 * AYS is only read, never changed. The copy is safe to run again: a word the
 * user already has (same term_key, any group) is skipped. Copied words earn
 * no points and are never recorded in Notes: the copy writes through
 * TBTDIC_DB only, never through the REST create path. Their phonetics and
 * examples come later from the background job (TBTDIC_Fill).
 *
 * Every AYS word ends up either copied or skipped (already there, empty, no
 * account, or over a per-student limit), so copied + skipped is always the
 * number of AYS words.
 */

defined( 'ABSPATH' ) || exit;

class TBTDIC_AYS {

	/** The last run's result and time. */
	const OPTION = 'tbtdic_ays_last_copy';

	/** Stops two copies running at once (a double click). */
	const LOCK = 'tbtdic_ays_copy_lock';

	/** AYS rows per query. */
	const BATCH = 500;

	/** The group for a word whose AYS group is missing or nameless. */
	const FALLBACK_GROUP = 'My words';

	public static function categories_table() {
		global $wpdb;
		return $wpdb->prefix . 'ayspd_categories';
	}

	public static function words_table() {
		global $wpdb;
		return $wpdb->prefix . 'ayspd_words';
	}

	/**
	 * Are both AYS tables there?
	 *
	 * @return bool
	 */
	public static function available() {
		global $wpdb;
		foreach ( array( self::categories_table(), self::words_table() ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * AYS text as it was typed: entities decoded (in case AYS stored them
	 * escaped), tags and control characters removed, cut to $max.
	 *
	 * @param mixed $s   Raw AYS value.
	 * @param int   $max Maximum length.
	 * @return string
	 */
	public static function text( $s, $max ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return TBTDIC_Words::clean( sanitize_text_field( $s ), $max );
	}

	/**
	 * An AYS date (site time) as UTC; '' when empty or zero.
	 *
	 * @param mixed $date AYS date_created.
	 * @return string
	 */
	private static function utc( $date ) {
		$date = trim( (string) $date );
		if ( '' === $date || 0 === strpos( $date, '0000' ) || false === strtotime( $date ) ) {
			return '';
		}
		return get_gmt_from_date( $date, 'Y-m-d H:i:s' );
	}

	/**
	 * AYS words, AYS students, and how many of those words are already in
	 * TBT Dictionary (same user and term_key).
	 *
	 * @return array{words:int,students:int,already:int}
	 */
	public static function summary() {
		global $wpdb;
		$out = array(
			'words'    => 0,
			'students' => 0,
			'already'  => 0,
		);
		if ( ! self::available() ) {
			return $out;
		}
		$ays             = self::words_table();
		$out['words']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ays}" );
		$out['students'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$ays}" );
		if ( ! TBTDIC_DB::tables_exist() || 0 === $out['words'] ) {
			return $out;
		}

		$have = array();
		$rows = $wpdb->get_results( 'SELECT user_id, term_key FROM ' . TBTDIC_DB::words_table(), ARRAY_N );
		foreach ( (array) $rows as $r ) {
			$have[ (int) $r[0] . '|' . (string) $r[1] ] = true;
		}
		if ( ! $have ) {
			return $out;
		}

		$last_user = 0;
		$last_id   = 0;
		do {
			$batch = self::batch( $last_user, $last_id, false );
			foreach ( $batch as $w ) {
				$last_user = (int) $w['user_id'];
				$last_id   = (int) $w['id'];
				$key       = TBTDIC_Words::key( self::text( $w['word'], TBTDIC_Words::TERM_MAX ) );
				if ( '' !== $key && isset( $have[ $last_user . '|' . $key ] ) ) {
					++$out['already'];
				}
			}
		} while ( count( $batch ) === self::BATCH );

		return $out;
	}

	/**
	 * The next AYS rows after ($user, $id), ordered by user_id, id.
	 *
	 * @param int  $user     Last user_id read.
	 * @param int  $id       Last id read.
	 * @param bool $category With the AYS group's name and date.
	 * @return array[]
	 */
	private static function batch( $user, $id, $category ) {
		global $wpdb;
		$ays  = self::words_table();
		$cols = 'w.id, w.user_id, w.category_id, w.word, w.translation, w.completed, w.percentage, w.date_created';
		$join = '';
		if ( $category ) {
			$cols .= ', c.id AS cat_id, c.name AS cat_name, c.date_created AS cat_created';
			$join  = ' LEFT JOIN ' . self::categories_table() . ' c ON c.id = w.category_id';
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$cols} FROM {$ays} w{$join} WHERE ( w.user_id > %d OR ( w.user_id = %d AND w.id > %d ) ) ORDER BY w.user_id ASC, w.id ASC LIMIT %d",
				$user,
				$user,
				$id,
				self::BATCH
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The copy: one run over all users. Stores the result in OPTION.
	 *
	 * @return array{students:int,groups_created:int,words_copied:int,words_skipped:int,users_missing:int}|null
	 *         Null when AYS or the dictionary tables are missing, or a copy
	 *         is already running.
	 */
	public static function copy() {
		if ( ! self::available() || ! TBTDIC_DB::tables_exist() ) {
			return null;
		}
		if ( get_transient( self::LOCK ) ) {
			return null;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$result = array(
			'students'       => 0,
			'groups_created' => 0,
			'words_copied'   => 0,
			'words_skipped'  => 0,
			'users_missing'  => 0,
		);

		// Decided once: with no key the words are `skipped`, not queued.
		$ai_status = '' !== TBTDIC_AI::api_key() ? 'pending' : 'skipped';

		// Progress in this site's learning points (0.2.0): a word AYS marked
		// learned comes in learned whatever the setting.
		$learnt = TBTDIC_Settings::learning()['learnt'];

		$user      = 0;    // The user whose rows are being read.
		$exists    = false;
		$keys      = array(); // term_key => true, for $user.
		$groups    = array(); // cleaned name => group id, for $user.
		$n_words   = 0;
		$n_groups  = 0;
		$last_user = 0;
		$last_id   = 0;

		try {
			do {
				$batch = self::batch( $last_user, $last_id, true );
				foreach ( $batch as $w ) {
					$last_user = (int) $w['user_id'];
					$last_id   = (int) $w['id'];

					if ( $last_user !== $user ) {
						$user   = $last_user;
						$exists = $user > 0 && false !== get_userdata( $user );
						$groups = array();
						if ( $exists ) {
							++$result['students'];
							$keys     = array_fill_keys( TBTDIC_DB::term_keys( $user ), true );
							$n_words  = TBTDIC_DB::count_words( $user );
							$n_groups = TBTDIC_DB::count_groups( $user );
						} else {
							++$result['users_missing'];
							$keys = array();
						}
					}

					if ( ! $exists ) {
						++$result['words_skipped'];
						continue;
					}

					$term = self::text( $w['word'], TBTDIC_Words::TERM_MAX );
					$key  = TBTDIC_Words::key( $term );
					if ( '' === $term || '' === $key || isset( $keys[ $key ] ) || $n_words >= TBTDIC_Words::MAX_WORDS ) {
						++$result['words_skipped'];
						continue;
					}

					// The group: the AYS group's name, else "My words".
					$name = null !== $w['cat_id'] ? self::text( $w['cat_name'], TBTDIC_Words::NAME_MAX ) : '';
					if ( '' === $name ) {
						$name = self::FALLBACK_GROUP;
					}
					if ( ! isset( $groups[ $name ] ) ) {
						$existing = TBTDIC_DB::group_by_name( $user, $name );
						if ( $existing ) {
							$groups[ $name ] = (int) $existing['id'];
						} elseif ( $n_groups >= TBTDIC_Words::MAX_GROUPS ) {
							++$result['words_skipped'];
							continue;
						} else {
							$created = false;
							$group   = TBTDIC_DB::find_or_create_group( $user, $name, self::utc( $w['cat_created'] ?? '' ), $created );
							if ( ! $group ) {
								++$result['words_skipped'];
								continue;
							}
							if ( $created ) {
								++$result['groups_created'];
								++$n_groups;
							}
							$groups[ $name ] = (int) $group['id'];
						}
					}

					$id = TBTDIC_DB::insert_word(
						$user,
						array(
							'group_id'    => $groups[ $name ],
							'term'        => $term,
							'term_key'    => $key,
							'translation' => self::text( $w['translation'], TBTDIC_Words::TRANSLATION_MAX ),
							'progress'    => TBTDIC_Words::ays_progress( $w['completed'], $w['percentage'], $learnt ),
							'ai_status'   => $ai_status,
							'created_at'  => self::utc( $w['date_created'] ),
						)
					);
					if ( ! $id ) {
						++$result['words_skipped'];
						continue;
					}
					$keys[ $key ] = true;
					++$n_words;
					++$result['words_copied'];
				}
			} while ( count( $batch ) === self::BATCH );
		} finally {
			delete_transient( self::LOCK );
		}

		update_option(
			self::OPTION,
			array_merge( $result, array( 'time' => time() ) ),
			false
		);
		return $result;
	}

	/**
	 * The last run, or null.
	 *
	 * @return array|null
	 */
	public static function last_copy() {
		$last = get_option( self::OPTION, null );
		return is_array( $last ) ? $last : null;
	}
}
