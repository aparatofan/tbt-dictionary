<?php
/**
 * The Points bridge: a new word earns through tbt_points_record_event()
 * (TBT Points 0.1.4+).
 *
 * Words are NEVER recorded in the Notes activity log, so they stay out of
 * TBT Results and the Class Progress panel (Mariusz's decision, 20 Nov
 * 2026). The reference is the word itself (md5 of its key), not the row ID,
 * so a word earns once per student ever: delete and re-add earns nothing.
 */

defined( 'ABSPATH' ) || exit;

class TBTDIC_Points {

	const TOOL = 'dictionary_word';

	/**
	 * Award a new word. Called on word create only. Never throws.
	 *
	 * @param int    $user_id  The session user.
	 * @param string $term_key TBTDIC_Words::key() of the term.
	 * @param string $term     The term, as the ledger row's title.
	 * @return int Points actually added (0 when Points is missing, the
	 *             student is a teacher, the word earned before, the cap is
	 *             reached, …).
	 */
	public static function award_new_word( $user_id, $term_key, $term ) {
		if ( ! function_exists( 'tbt_points_record_event' ) ) {
			return 0;
		}
		try {
			return max(
				0,
				(int) tbt_points_record_event(
					array(
						'user_id'      => (int) $user_id,
						'tool'         => self::TOOL,
						'object_ref'   => self::object_ref( $term_key ),
						'object_title' => (string) $term,
					)
				)
			);
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * The ledger reference for a word: 'word:' . md5( term_key ).
	 *
	 * @param string $term_key Term key.
	 * @return string
	 */
	public static function object_ref( $term_key ) {
		return 'word:' . md5( (string) $term_key );
	}
}
