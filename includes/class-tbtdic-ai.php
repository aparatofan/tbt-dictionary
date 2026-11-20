<?php
/**
 * The AI fill-in: translation (only when the student left it empty),
 * British IPA and one example sentence, from the OpenAI Chat Completions API.
 *
 * Translation Drill's TBTD_API pattern: the key never leaves the server;
 * key and model fall back to TBT Swipe's settings when this plugin's own are
 * empty. It fails open: no key, a timeout, an HTTP error or bad JSON leaves
 * the word as typed, with ai_status `failed`.
 *
 * Daily limit: `tbtdic_ai_per_day` fill-ins per student per site-time-zone
 * day (0 = no limit), counted in user meta only after a successful answer,
 * as TBTD_Translator counts. Over the limit the word is `skipped`. The
 * background job (TBTDIC_Fill) neither checks nor counts the limit.
 *
 * Only a `pending` word is filled, and the answer is saved only while it is
 * still `pending` (TBTDIC_DB::save_fill()), so the page and the job never
 * both store a fill-in for the same word: the first answer wins.
 */

defined( 'ABSPATH' ) || exit;

class TBTDIC_AI {

	const ENDPOINT      = 'https://api.openai.com/v1/chat/completions';
	const DEFAULT_MODEL = 'gpt-4o-mini';
	const TIMEOUT       = 15;

	/** Swipe's option names, read only as a fallback. Never written. */
	const SWIPE_KEY_OPTION   = 'tbts_api_key';
	const SWIPE_MODEL_OPTION = 'tbts_model';

	/** Fill-ins per student per day, out of the box. 0 means no limit. */
	const DEFAULT_PER_DAY = 100;
	const MAX_PER_DAY     = 1000;

	/** Prefix for the per-day counter user meta key. */
	const COUNT_META_PREFIX = 'tbtdic_ai_count_';

	/**
	 * The key in use: this plugin's own, else Swipe's, else ''.
	 *
	 * @return string
	 */
	public static function api_key() {
		$own = (string) get_option( 'tbtdic_api_key', '' );
		if ( '' !== $own ) {
			return $own;
		}
		return (string) get_option( self::SWIPE_KEY_OPTION, '' );
	}

	/**
	 * Where the key in use comes from: 'own', 'swipe' or ''.
	 *
	 * @return string
	 */
	public static function key_source() {
		if ( '' !== (string) get_option( 'tbtdic_api_key', '' ) ) {
			return 'own';
		}
		return '' !== (string) get_option( self::SWIPE_KEY_OPTION, '' ) ? 'swipe' : '';
	}

	/**
	 * The model in use: this plugin's own setting, else Swipe's, else the default.
	 *
	 * @return string
	 */
	public static function model() {
		$own = trim( (string) get_option( 'tbtdic_model', '' ) );
		if ( '' !== $own ) {
			return $own;
		}
		$swipe = trim( (string) get_option( self::SWIPE_MODEL_OPTION, '' ) );
		return '' !== $swipe ? $swipe : self::DEFAULT_MODEL;
	}

	/**
	 * The daily limit. 0 means no limit.
	 *
	 * @return int
	 */
	public static function per_day() {
		return max( 0, min( self::MAX_PER_DAY, (int) get_option( 'tbtdic_ai_per_day', self::DEFAULT_PER_DAY ) ) );
	}

	/**
	 * Today's counter key, in site time.
	 *
	 * @return string
	 */
	private static function count_meta_key() {
		return self::COUNT_META_PREFIX . current_time( 'Y-m-d' );
	}

	/**
	 * Has this student used up today's fill-ins?
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function over_limit( $user_id ) {
		$limit = self::per_day();
		if ( 0 === $limit ) {
			return false;
		}
		return (int) get_user_meta( (int) $user_id, self::count_meta_key(), true ) >= $limit;
	}

	/**
	 * Count one successful fill-in. Yesterday's counters are removed so the
	 * meta table does not grow a row a day.
	 *
	 * @param int $user_id User ID.
	 */
	private static function count( $user_id ) {
		$key  = self::count_meta_key();
		$used = (int) get_user_meta( (int) $user_id, $key, true );
		update_user_meta( (int) $user_id, $key, $used + 1 );
		if ( 0 === $used ) {
			foreach ( array_keys( (array) get_user_meta( (int) $user_id ) ) as $meta_key ) {
				if ( 0 === strpos( (string) $meta_key, self::COUNT_META_PREFIX ) && $meta_key !== $key ) {
					delete_user_meta( (int) $user_id, $meta_key );
				}
			}
		}
	}

	/**
	 * Fill one pending word and save the answer, unless the word stopped
	 * being pending meanwhile. Never throws.
	 *
	 * @param int   $user_id   The word's owner.
	 * @param array $word      The word row (id, term, translation, ai_status).
	 * @param bool  $use_limit False for the background job: its fills do not
	 *                         count against the student's daily limit.
	 * @return bool True when this call saved a fill-in.
	 */
	public static function fill_and_save( $user_id, array $word, $use_limit = true ) {
		if ( 'pending' !== (string) ( $word['ai_status'] ?? '' ) ) {
			return false;
		}
		$fields = self::fill( $user_id, $word, $use_limit );
		// The student may have typed a translation while the AI worked.
		$now = TBTDIC_DB::word( $user_id, (int) $word['id'] );
		if ( ! $now || 'pending' !== (string) $now['ai_status'] ) {
			return false;
		}
		if ( '' !== (string) $now['translation'] ) {
			unset( $fields['translation'] );
		}
		return TBTDIC_DB::save_fill( $user_id, (int) $word['id'], $fields );
	}

	/**
	 * Work out the fill-in for one pending word. Never throws.
	 *
	 * @param int   $user_id   The word's owner.
	 * @param array $word      The word row.
	 * @param bool  $use_limit False skips the daily limit and its count.
	 * @return array Columns to store: ai_status, and phonetic / example /
	 *               translation when the AI supplied them.
	 */
	public static function fill( $user_id, array $word, $use_limit = true ) {
		try {
			if ( '' === self::api_key() ) {
				return array( 'ai_status' => 'failed' );
			}
			if ( $use_limit && self::over_limit( $user_id ) ) {
				return array( 'ai_status' => 'skipped' );
			}

			$typed = trim( (string) ( $word['translation'] ?? '' ) );
			$text  = self::chat( self::system_prompt(), self::user_prompt( (string) $word['term'], $typed ) );
			if ( null === $text ) {
				return array( 'ai_status' => 'failed' );
			}

			$ai = TBTDIC_Words::parse_ai( $text );
			if ( null === $ai ) {
				return array( 'ai_status' => 'failed' );
			}

			if ( $use_limit ) {
				self::count( $user_id );
			}

			$out = array( 'ai_status' => 'done' );
			if ( '' !== $ai['phonetic'] ) {
				$out['phonetic'] = $ai['phonetic'];
			}
			if ( '' !== $ai['example'] ) {
				$out['example'] = $ai['example'];
			}
			// Never overwrite what the student typed.
			if ( '' === $typed && '' !== $ai['translation'] ) {
				$out['translation'] = $ai['translation'];
			}
			return $out;
		} catch ( \Throwable $e ) {
			return array( 'ai_status' => 'failed' );
		}
	}

	/**
	 * One Chat Completions call with a JSON-object answer.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User prompt.
	 * @return string|null The message content, null on any failure.
	 */
	private static function chat( $system, $user ) {
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . self::api_key(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'                 => self::model(),
						'temperature'           => 0.2,
						'max_completion_tokens' => 400,
						'response_format'       => array( 'type' => 'json_object' ),
						'messages'              => array(
							array(
								'role'    => 'system',
								'content' => $system,
							),
							array(
								'role'    => 'user',
								'content' => $user,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return isset( $body['choices'][0]['message']['content'] ) && is_string( $body['choices'][0]['message']['content'] )
			? $body['choices'][0]['message']['content']
			: null;
	}

	/**
	 * The target language, filterable.
	 *
	 * @return string
	 */
	private static function language() {
		$lang = trim( (string) apply_filters( 'tbtdic_translation_language', 'Polish' ) );
		return '' !== $lang ? $lang : 'Polish';
	}

	/**
	 * @return string
	 */
	private static function system_prompt() {
		$lang = self::language();
		return "You help adult {$lang} learners of English (around B1–B2) build their personal dictionary.\n"
			. "For the English word or phrase you are given, return:\n"
			. "1. \"translation\": a short {$lang} translation, at most two meanings separated by a comma. "
			. "If the student already gave a translation, use it to tell which meaning they mean.\n"
			. "2. \"phonetic\": the British English pronunciation in IPA, between slashes, e.g. /rɪˈzɪl.i.ənt/. "
			. "For a phrase, the whole phrase. Ignore notes in brackets such as (n.) or (v.).\n"
			. "3. \"example\": one natural example sentence, B1–B2 level, at most 15 words, that uses the word or phrase "
			. "in the meaning of the translation. Name no real person and no real place.\n"
			. "Return ONLY a JSON object, no markdown: {\"translation\":\"...\",\"phonetic\":\"/.../\",\"example\":\"...\"}";
	}

	/**
	 * @param string $term  The term.
	 * @param string $typed The student's translation, or ''.
	 * @return string
	 */
	private static function user_prompt( $term, $typed ) {
		$out = 'English: ' . $term;
		if ( '' !== $typed ) {
			$out .= "\nThe student's translation: " . $typed;
		}
		return $out;
	}
}
