<?php
/**
 * Pure helpers: normalising terms and names, limits, progress maths and the
 * AI answer parser.
 *
 * No WordPress here, so tests/test-words.php runs it with plain PHP. The
 * accent fold is a fixed table (not the intl Normalizer, which a host may
 * lack), so a stored term_key is the same on every server.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'TBTDIC_LEARNT_DEFAULT' ) ) {
	/** Progress points at which a word counts as learned (a setting from 0.2.0). */
	define( 'TBTDIC_LEARNT_DEFAULT', 10 );
}

class TBTDIC_Words {

	/** Column lengths. */
	const TERM_MAX        = 120;
	const NAME_MAX        = 60;
	const TRANSLATION_MAX = 190;
	const PHONETIC_MAX    = 80;
	const EXAMPLE_MAX     = 300;

	/** Per-user limits. */
	const MAX_GROUPS = 200;
	const MAX_WORDS  = 5000;

	/** Lower-case accented letter → its base letter (the prototype's fold()). */
	const FOLD = array(
		'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
		'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
		'ď' => 'd', 'đ' => 'd',
		'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
		'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
		'ĥ' => 'h', 'ħ' => 'h',
		'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
		'ĵ' => 'j',
		'ķ' => 'k',
		'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
		'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
		'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
		'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
		'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's',
		'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ț' => 't',
		'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
		'ŵ' => 'w',
		'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
		'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
	);

	/**
	 * Trim, strip control characters, collapse whitespace, cut to $max.
	 *
	 * @param mixed $s   Raw text.
	 * @param int   $max Maximum length in characters.
	 * @return string
	 */
	public static function clean( $s, $max ) {
		$s = is_scalar( $s ) ? (string) $s : '';
		if ( ! preg_match( '//u', $s ) ) {
			$s = mb_convert_encoding( $s, 'UTF-8', 'UTF-8' ); // Drop invalid bytes.
		}
		$s = preg_replace( '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', '', $s );
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) );
		return trim( mb_substr( $s, 0, (int) $max ) );
	}

	/**
	 * A term as stored: trimmed, inner spaces collapsed, at most 120.
	 *
	 * @param mixed $s Raw term.
	 * @return string
	 */
	public static function clean_term( $s ) {
		return self::clean( $s, self::TERM_MAX );
	}

	/**
	 * A group name as stored: as clean_term(), at most 60.
	 *
	 * @param mixed $s Raw name.
	 * @return string
	 */
	public static function clean_name( $s ) {
		return self::clean( $s, self::NAME_MAX );
	}

	/**
	 * The duplicate and search key: cleaned, lower-case, accents folded.
	 *
	 * @param mixed $s Raw term.
	 * @return string
	 */
	public static function key( $s ) {
		return self::fold( self::clean_term( $s ) );
	}

	/**
	 * Lower-case and fold accents (ł → l), as the prototype's fold().
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function fold( $s ) {
		return strtr( mb_strtolower( (string) $s, 'UTF-8' ), self::FOLD );
	}

	/**
	 * What the speaker says: the term without bracketed notes, so
	 * "disruption (n.)" is spoken as "disruption".
	 *
	 * @param string $term Term.
	 * @return string
	 */
	public static function speak_text( $term ) {
		$clean = self::clean_term( $term );
		$text  = preg_replace( '/\([^)]*\)|\[[^\]]*\]/u', ' ', $clean );
		$text  = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return '' !== $text ? $text : $clean;
	}

	/**
	 * One word's percent toward "learned": min(100, progress / learnt × 100).
	 *
	 * @param float|int|string $progress Progress points.
	 * @param float|int        $learnt   Points at which a word is learned.
	 * @return float
	 */
	public static function percent( $progress, $learnt = TBTDIC_LEARNT_DEFAULT ) {
		$learnt = (float) $learnt;
		if ( $learnt <= 0 ) {
			return 0.0;
		}
		return (float) max( 0, min( 100, (float) $progress / $learnt * 100 ) );
	}

	/**
	 * A group's percent: the average of its word percents, one decimal, 0 for
	 * an empty group.
	 *
	 * @param array     $progresses Progress points of each word.
	 * @param float|int $learnt     Points at which a word is learned.
	 * @return float
	 */
	public static function group_percent( array $progresses, $learnt = TBTDIC_LEARNT_DEFAULT ) {
		if ( ! $progresses ) {
			return 0.0;
		}
		$sum = 0.0;
		foreach ( $progresses as $progress ) {
			$sum += self::percent( $progress, $learnt );
		}
		return round( $sum / count( $progresses ), 1 );
	}

	/**
	 * Is a word learned?
	 *
	 * @param float|int|string $progress Progress points.
	 * @param float|int        $learnt   Points at which a word is learned.
	 * @return bool
	 */
	public static function is_learned( $progress, $learnt = TBTDIC_LEARNT_DEFAULT ) {
		return (float) $learnt > 0 && (float) $progress >= (float) $learnt;
	}

	/**
	 * A word's progress copied from AYS Personal Dictionary: learned there →
	 * TBTDIC_LEARNT_DEFAULT; otherwise AYS's `percentage` / 10 (its share of
	 * "learnt", so a site that changed AYS's points still copies the right
	 * share), 0–10, two decimals.
	 *
	 * @param mixed $completed  AYS `completed` (1 = learned).
	 * @param mixed $percentage AYS `percentage` (0–100).
	 * @return float
	 */
	public static function ays_progress( $completed, $percentage ) {
		$max = (float) TBTDIC_LEARNT_DEFAULT;
		if ( 1 === (int) $completed ) {
			return $max;
		}
		$p = is_numeric( $percentage ) ? (float) $percentage / 10 : 0.0;
		return round( max( 0.0, min( $max, $p ) ), 2 );
	}

	/**
	 * Read the AI's JSON answer. Keeps translation (≤190), phonetic (≤80, only
	 * when it starts and ends with "/") and example (≤300), tags stripped.
	 *
	 * @param string $json Raw message content.
	 * @return array{translation:string,phonetic:string,example:string}|null
	 *         Null when the answer is not a JSON object with at least one of
	 *         the three fields as a string.
	 */
	public static function parse_ai( $json ) {
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return null;
		}
		$data = json_decode( $json, true );
		// An object, not a list (array_is_list() is PHP 8.1; we support 8.0).
		if ( ! is_array( $data ) || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			return null;
		}

		$found = false;
		$out   = array();
		foreach ( array( 'translation', 'phonetic', 'example' ) as $field ) {
			$value = $data[ $field ] ?? null;
			if ( is_string( $value ) ) {
				$found = true;
			}
			$out[ $field ] = is_string( $value ) ? self::clean( strip_tags( $value ), 1000 ) : '';
		}
		if ( ! $found ) {
			return null;
		}

		$out['translation'] = self::clean( $out['translation'], self::TRANSLATION_MAX );
		$out['example']     = self::clean( $out['example'], self::EXAMPLE_MAX );

		$ph = $out['phonetic'];
		$ok = mb_strlen( $ph ) >= 3 && mb_strlen( $ph ) <= self::PHONETIC_MAX
			&& '/' === mb_substr( $ph, 0, 1 ) && '/' === mb_substr( $ph, -1 );
		$out['phonetic'] = $ok ? $ph : '';

		return $out;
	}
}
