<?php
/**
 * Both tables and every query on them.
 *
 *   {prefix}tbtdic_groups  a student's groups; name unique per user
 *   {prefix}tbtdic_words   their words
 *
 * Every method takes the owner's user ID and puts it in the WHERE, so a row
 * of another user is simply not found. Callers pass the session user, never
 * a value from the request.
 *
 * Group names are compared by the table's _ci collation (`name = %s`), the
 * same comparison the unique key uses, so "Work" and "work" (and, on most
 * collations, "Cafe" and "Café") are the same group in the check, in the
 * message and in the database.
 */

defined( 'ABSPATH' ) || exit;

class TBTDIC_DB {

	/** Per-request answer to "do the tables exist?". */
	private static $exists = null;

	/** Word columns returned to the page. */
	const WORD_COLS = 'id, group_id, term, term_key, translation, phonetic, example, progress, ai_status, created_at';

	public static function groups_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbtdic_groups';
	}

	public static function words_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbtdic_words';
	}

	/* ---------- Schema ---------- */

	/**
	 * Create or update both tables. Safe to run more than once.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$groups  = self::groups_table();
		$words   = self::words_table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is particular: two spaces after PRIMARY KEY, one column per line.
		dbDelta(
			"CREATE TABLE {$groups} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(60) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_name (user_id,name),
  KEY user_id (user_id)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$words} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  group_id bigint(20) unsigned NOT NULL DEFAULT 0,
  term varchar(120) NOT NULL DEFAULT '',
  term_key varchar(120) NOT NULL DEFAULT '',
  translation varchar(190) NOT NULL DEFAULT '',
  phonetic varchar(80) NOT NULL DEFAULT '',
  example varchar(300) NOT NULL DEFAULT '',
  progress decimal(6,2) NOT NULL DEFAULT 0,
  ai_status varchar(10) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_group (user_id,group_id),
  KEY user_key (user_id,term_key)
) {$charset};"
		);

		self::$exists = null;
		update_option( 'tbtdic_db_version', TBTDIC_DB_VERSION );
	}

	/**
	 * The upgrade check, from plugins_loaded: FTP deploys never fire the
	 * activation hook, so the tables are created or updated here too.
	 */
	public static function maybe_upgrade() {
		if ( (string) get_option( 'tbtdic_db_version', '' ) !== (string) TBTDIC_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * @return bool
	 */
	public static function tables_exist() {
		if ( null === self::$exists ) {
			global $wpdb;
			$found = 0;
			foreach ( array( self::groups_table(), self::words_table() ) as $table ) {
				$like = $wpdb->esc_like( $table );
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $table ) {
					++$found;
				}
			}
			self::$exists = 2 === $found;
		}
		return self::$exists;
	}

	/* ---------- Groups ---------- */

	/**
	 * A user's groups, oldest first.
	 *
	 * @param int $user_id Owner.
	 * @return array[]
	 */
	public static function groups( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, name, created_at FROM ' . self::groups_table() . ' WHERE user_id = %d ORDER BY created_at ASC, id ASC', $user_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $user_id  Owner.
	 * @param int $group_id Group ID.
	 * @return array|null
	 */
	public static function group( $user_id, $group_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, name, created_at FROM ' . self::groups_table() . ' WHERE user_id = %d AND id = %d', $user_id, $group_id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * The user's group with this name, by the table's _ci collation.
	 *
	 * @param int    $user_id    Owner.
	 * @param string $name       Cleaned name.
	 * @param int    $exclude_id A group to ignore (the one being renamed).
	 * @return array|null
	 */
	public static function group_by_name( $user_id, $name, $exclude_id = 0 ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, name, created_at FROM ' . self::groups_table() . ' WHERE user_id = %d AND name = %s AND id <> %d ORDER BY id ASC LIMIT 1',
				$user_id,
				$name,
				$exclude_id
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * @param int $user_id Owner.
	 * @return int
	 */
	public static function count_groups( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::groups_table() . ' WHERE user_id = %d', $user_id ) );
	}

	/**
	 * @param int    $user_id Owner.
	 * @param string $name    Cleaned name.
	 * @return int New ID, 0 on failure (e.g. the unique key).
	 */
	public static function insert_group( $user_id, $name ) {
		global $wpdb;
		$now      = gmdate( 'Y-m-d H:i:s' );
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert(
			self::groups_table(),
			array(
				'user_id'    => (int) $user_id,
				'name'       => (string) $name,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $suppress );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int    $user_id  Owner.
	 * @param int    $group_id Group ID.
	 * @param string $name     Cleaned name.
	 * @return bool
	 */
	public static function rename_group( $user_id, $group_id, $name ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->update(
			self::groups_table(),
			array(
				'name'       => (string) $name,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'      => (int) $group_id,
				'user_id' => (int) $user_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);
		$wpdb->suppress_errors( $suppress );
		return false !== $ok;
	}

	/**
	 * Delete a group and its words.
	 *
	 * @param int $user_id  Owner.
	 * @param int $group_id Group ID.
	 */
	public static function delete_group( $user_id, $group_id ) {
		global $wpdb;
		$wpdb->delete( self::words_table(), array( 'user_id' => (int) $user_id, 'group_id' => (int) $group_id ), array( '%d', '%d' ) );
		$wpdb->delete( self::groups_table(), array( 'user_id' => (int) $user_id, 'id' => (int) $group_id ), array( '%d', '%d' ) );
	}

	/**
	 * Progress back to 0 for every word in a group.
	 *
	 * @param int $user_id  Owner.
	 * @param int $group_id Group ID.
	 */
	public static function reset_group( $user_id, $group_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::words_table() . ' SET progress = 0, updated_at = %s WHERE user_id = %d AND group_id = %d',
				gmdate( 'Y-m-d H:i:s' ),
				$user_id,
				$group_id
			)
		);
	}

	/**
	 * Progress values of a group's words, for its percent.
	 *
	 * @param int $user_id  Owner.
	 * @param int $group_id Group ID.
	 * @return float[]
	 */
	public static function group_progresses( $user_id, $group_id ) {
		global $wpdb;
		$col = $wpdb->get_col(
			$wpdb->prepare( 'SELECT progress FROM ' . self::words_table() . ' WHERE user_id = %d AND group_id = %d', $user_id, $group_id )
		);
		return array_map( 'floatval', is_array( $col ) ? $col : array() );
	}

	/* ---------- Words ---------- */

	/**
	 * All of a user's words, newest first.
	 *
	 * @param int $user_id Owner.
	 * @return array[]
	 */
	public static function words( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT ' . self::WORD_COLS . ' FROM ' . self::words_table() . ' WHERE user_id = %d ORDER BY created_at DESC, id DESC', $user_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $user_id Owner.
	 * @param int $word_id Word ID.
	 * @return array|null
	 */
	public static function word( $user_id, $word_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . self::WORD_COLS . ' FROM ' . self::words_table() . ' WHERE user_id = %d AND id = %d', $user_id, $word_id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * The user's word with this key, anywhere in their dictionary, with its
	 * group name for the 409 message.
	 *
	 * @param int    $user_id    Owner.
	 * @param string $term_key   TBTDIC_Words::key() of the term.
	 * @param int    $exclude_id A word to ignore (the one being edited).
	 * @return array|null
	 */
	public static function word_by_key( $user_id, $term_key, $exclude_id = 0 ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT w.id, w.group_id, w.term, g.name AS group_name FROM ' . self::words_table() . ' w LEFT JOIN ' . self::groups_table() . ' g ON g.id = w.group_id AND g.user_id = w.user_id'
				. ' WHERE w.user_id = %d AND w.term_key = %s AND w.id <> %d ORDER BY w.id ASC LIMIT 1',
				$user_id,
				$term_key,
				$exclude_id
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * @param int $user_id Owner.
	 * @return int
	 */
	public static function count_words( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::words_table() . ' WHERE user_id = %d', $user_id ) );
	}

	/**
	 * @param int   $user_id Owner.
	 * @param array $data    group_id, term, term_key, translation.
	 * @return int New ID, 0 on failure.
	 */
	public static function insert_word( $user_id, array $data ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			self::words_table(),
			array(
				'user_id'     => (int) $user_id,
				'group_id'    => (int) $data['group_id'],
				'term'        => (string) $data['term'],
				'term_key'    => (string) $data['term_key'],
				'translation' => (string) $data['translation'],
				'phonetic'    => '',
				'example'     => '',
				'progress'    => 0,
				'ai_status'   => 'pending',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update some of a word's columns.
	 *
	 * @param int   $user_id Owner.
	 * @param int   $word_id Word ID.
	 * @param array $fields  Any of group_id, term, term_key, translation,
	 *                       phonetic, example, progress, ai_status.
	 * @return bool
	 */
	public static function update_word( $user_id, $word_id, array $fields ) {
		global $wpdb;
		$formats = array(
			'group_id'    => '%d',
			'term'        => '%s',
			'term_key'    => '%s',
			'translation' => '%s',
			'phonetic'    => '%s',
			'example'     => '%s',
			'progress'    => '%f',
			'ai_status'   => '%s',
		);
		$data   = array();
		$format = array();
		foreach ( $formats as $col => $f ) {
			if ( array_key_exists( $col, $fields ) ) {
				$data[ $col ] = $fields[ $col ];
				$format[]     = $f;
			}
		}
		if ( ! $data ) {
			return true;
		}
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$format[]           = '%s';

		return false !== $wpdb->update(
			self::words_table(),
			$data,
			array(
				'id'      => (int) $word_id,
				'user_id' => (int) $user_id,
			),
			$format,
			array( '%d', '%d' )
		);
	}

	/**
	 * @param int $user_id Owner.
	 * @param int $word_id Word ID.
	 */
	public static function delete_word( $user_id, $word_id ) {
		global $wpdb;
		$wpdb->delete( self::words_table(), array( 'user_id' => (int) $user_id, 'id' => (int) $word_id ), array( '%d', '%d' ) );
	}
}
