<?php
/**
 * REST routes, namespace tbt-dictionary/v1.
 *
 *   GET    /state                 the user's groups (with counts) and words
 *   POST   /groups                create a group {name}
 *   PATCH  /groups/{id}           rename {name}
 *   DELETE /groups/{id}           delete with its words
 *   POST   /groups/{id}/reset     progress 0 on its words
 *   POST   /words                 create {group_id | group_name, term, translation}
 *   POST   /words/{id}/fill       run the AI fill-in (pending words added in
 *                                 the last 10 minutes; older ones are the
 *                                 background job's, TBTDIC_Fill)
 *   PATCH  /words/{id}            edit or move
 *   DELETE /words/{id}            delete
 *   POST   /words/{id}/reset      progress 0
 *   POST   /words/{id}/answer     a game answer {correct}: progress ± (0.2.0)
 *   POST   /games/finish          a finished round {type, group_ids, score,
 *                                 total}: one Notes activity row (0.2.0)
 *   POST   /lookup                read-only {terms}: the user's groups and
 *                                 which terms they already have (0.3.0,
 *                                 for the Flashcards glossary)
 *
 * Every route: logged in only; the user is always the session user and no
 * route takes a user_id. Another user's row is 404, never 403. Cookie
 * requests are nonce-checked by WordPress core (X-WP-Nonce, action wp_rest).
 * Every response carries no-cache headers.
 */

defined( 'ABSPATH' ) || exit;

class TBTDIC_Rest {

	const NS = 'tbt-dictionary/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		$id  = '(?P<id>\d+)';
		$can = array( $this, 'can' );

		register_rest_route( self::NS, '/state', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'state' ),
			'permission_callback' => $can,
		) );
		register_rest_route( self::NS, '/groups', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'create_group' ),
			'permission_callback' => $can,
		) );
		register_rest_route( self::NS, '/groups/' . $id, array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'rename_group' ),
				'permission_callback' => $can,
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_group' ),
				'permission_callback' => $can,
			),
		) );
		register_rest_route( self::NS, '/groups/' . $id . '/reset', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'reset_group' ),
			'permission_callback' => $can,
		) );
		register_rest_route( self::NS, '/words', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'create_word' ),
			'permission_callback' => $can,
		) );
		register_rest_route( self::NS, '/words/' . $id . '/fill', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'fill_word' ),
			'permission_callback' => $can,
		) );
		register_rest_route( self::NS, '/words/' . $id, array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'edit_word' ),
				'permission_callback' => $can,
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_word' ),
				'permission_callback' => $can,
			),
		) );
		register_rest_route( self::NS, '/words/' . $id . '/reset', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'reset_word' ),
			'permission_callback' => $can,
		) );
		register_rest_route( self::NS, '/words/' . $id . '/answer', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'answer_word' ),
			'permission_callback' => $can,
		) );
		register_rest_route( self::NS, '/games/finish', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'finish_game' ),
			'permission_callback' => $can,
		) );
		// POST only because the list of terms can be long; it changes nothing.
		register_rest_route( self::NS, '/lookup', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'lookup' ),
			'permission_callback' => $can,
		) );
	}

	/* ---------- Permission ---------- */

	/**
	 * Logged in, tables present. No-cache headers are sent from here so even
	 * a refusal is never cached (the Points approach).
	 *
	 * @return true|WP_Error
	 */
	public function can() {
		self::nocache();
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'tbtdic_logged_out', __( 'Log in to use your dictionary.', 'tbt-dictionary' ), array( 'status' => 401 ) );
		}
		if ( ! TBTDIC_DB::tables_exist() ) {
			return new WP_Error( 'tbtdic_not_ready', __( 'The dictionary is not ready yet. Try again in a minute.', 'tbt-dictionary' ), array( 'status' => 503 ) );
		}
		return true;
	}

	/* ---------- State ---------- */

	public function state() {
		$user   = get_current_user_id();
		$words  = TBTDIC_DB::words( $user );
		$by_grp = array();
		foreach ( $words as $w ) {
			$by_grp[ (int) $w['group_id'] ][] = (float) $w['progress'];
		}
		$groups = array();
		foreach ( TBTDIC_DB::groups( $user ) as $g ) {
			$groups[] = self::group_out( $g, $by_grp[ (int) $g['id'] ] ?? array() );
		}
		return self::respond(
			array(
				'groups' => $groups,
				'words'    => array_map( array( __CLASS__, 'word_out' ), $words ),
				'learnt'   => TBTDIC_Settings::learning()['learnt'],
				'settings' => TBTDIC_Settings::learning(),
			)
		);
	}

	/* ---------- Lookup (0.3.0) ---------- */

	/**
	 * For the Flashcards glossary: the user's groups (A–Z, with word counts)
	 * and, for each sent term already in the dictionary, its group's name.
	 * Two queries; read-only.
	 */
	public function lookup( WP_REST_Request $req ) {
		$user  = get_current_user_id();
		$terms = $req->get_param( 'terms' );
		if ( ! is_array( $terms ) ) {
			return self::error( 'tbtdic_no_terms', __( 'Send a list of terms.', 'tbt-dictionary' ), 400 );
		}
		if ( count( $terms ) > TBTDIC_Words::LOOKUP_MAX ) {
			return self::error( 'tbtdic_too_many_terms', sprintf( __( 'Send at most %d terms at a time.', 'tbt-dictionary' ), TBTDIC_Words::LOOKUP_MAX ), 400 );
		}
		$terms = array_values( $terms );

		$groups = array();
		$names  = array();
		foreach ( TBTDIC_Words::sort_by_name( TBTDIC_DB::groups_with_counts( $user ) ) as $g ) {
			$names[ (int) $g['id'] ] = (string) $g['name'];
			$groups[]                = array(
				'id'         => (int) $g['id'],
				'name'       => (string) $g['name'],
				'word_count' => (int) $g['word_count'],
			);
		}

		$prep  = 'sanitize_text_field'; // As create_word(), so the keys match.
		$found = array();
		foreach ( TBTDIC_DB::group_ids_by_keys( $user, TBTDIC_Words::lookup_keys( $terms, $prep ) ) as $key => $group_id ) {
			if ( isset( $names[ $group_id ] ) ) {
				$found[ (string) $key ] = $names[ $group_id ];
			}
		}

		return self::respond(
			array(
				'groups' => $groups,
				// An object even when empty or when a term looks like a number.
				'have'   => (object) TBTDIC_Words::have_map( $terms, $found, $prep ),
			)
		);
	}

	/* ---------- Groups ---------- */

	public function create_group( WP_REST_Request $req ) {
		$user = get_current_user_id();
		$name = self::name_param( $req );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		$existing = TBTDIC_DB::group_by_name( $user, $name );
		if ( $existing ) {
			return self::group_exists( $user, $existing );
		}
		if ( TBTDIC_DB::count_groups( $user ) >= TBTDIC_Words::MAX_GROUPS ) {
			return self::error( 'tbtdic_too_many_groups', sprintf( __( 'You can have up to %d groups. Delete one you no longer use first.', 'tbt-dictionary' ), TBTDIC_Words::MAX_GROUPS ), 400 );
		}
		$group = self::make_group( $user, $name );
		if ( is_wp_error( $group ) ) {
			return $group;
		}
		return self::respond( array( 'group' => self::group_out( $group, array() ) ) );
	}

	public function rename_group( WP_REST_Request $req ) {
		$user  = get_current_user_id();
		$group = TBTDIC_DB::group( $user, (int) $req['id'] );
		if ( ! $group ) {
			return self::not_found();
		}
		$name = self::name_param( $req );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		$existing = TBTDIC_DB::group_by_name( $user, $name, (int) $group['id'] );
		if ( $existing ) {
			return self::group_exists( $user, $existing );
		}
		if ( ! TBTDIC_DB::rename_group( $user, (int) $group['id'], $name ) ) {
			return self::error( 'tbtdic_write_failed', __( 'Could not save that. Try again.', 'tbt-dictionary' ), 500 );
		}
		return self::respond( array( 'group' => self::group_full( $user, (int) $group['id'] ) ) );
	}

	public function delete_group( WP_REST_Request $req ) {
		$user  = get_current_user_id();
		$group = TBTDIC_DB::group( $user, (int) $req['id'] );
		if ( ! $group ) {
			return self::not_found();
		}
		TBTDIC_DB::delete_group( $user, (int) $group['id'] );
		return self::respond( array( 'deleted' => (int) $group['id'] ) );
	}

	public function reset_group( WP_REST_Request $req ) {
		$user  = get_current_user_id();
		$group = TBTDIC_DB::group( $user, (int) $req['id'] );
		if ( ! $group ) {
			return self::not_found();
		}
		TBTDIC_DB::reset_group( $user, (int) $group['id'] );
		return self::respond( array( 'group' => self::group_full( $user, (int) $group['id'] ) ) );
	}

	/* ---------- Words ---------- */

	public function create_word( WP_REST_Request $req ) {
		$user = get_current_user_id();

		$term = TBTDIC_Words::clean_term( sanitize_text_field( (string) $req->get_param( 'term' ) ) );
		if ( '' === $term ) {
			return self::error( 'tbtdic_empty_term', __( 'Type a word or phrase first.', 'tbt-dictionary' ), 400 );
		}
		$translation = TBTDIC_Words::clean( sanitize_text_field( (string) $req->get_param( 'translation' ) ), TBTDIC_Words::TRANSLATION_MAX );
		$key         = TBTDIC_Words::key( $term );

		// The group: an ID of the user's own, or a name (used or created).
		$group    = null;
		$new_name = '';
		$group_id = (int) $req->get_param( 'group_id' );
		if ( $group_id > 0 ) {
			$group = TBTDIC_DB::group( $user, $group_id );
			if ( ! $group ) {
				return self::not_found();
			}
		} else {
			$name = TBTDIC_Words::clean_name( sanitize_text_field( (string) $req->get_param( 'group_name' ) ) );
			if ( '' === $name ) {
				return self::error( 'tbtdic_no_group', __( 'Type a group name or pick one from the list.', 'tbt-dictionary' ), 400 );
			}
			$group = TBTDIC_DB::group_by_name( $user, $name );
			if ( ! $group ) {
				$new_name = $name;
			}
		}

		// Checked before a new group is made, so a refused word leaves none behind.
		$dup = TBTDIC_DB::word_by_key( $user, $key );
		if ( $dup ) {
			return self::duplicate( $dup );
		}
		if ( TBTDIC_DB::count_words( $user ) >= TBTDIC_Words::MAX_WORDS ) {
			return self::error( 'tbtdic_too_many_words', sprintf( __( 'Your dictionary is full (%s words). Delete words you no longer need first.', 'tbt-dictionary' ), number_format_i18n( TBTDIC_Words::MAX_WORDS ) ), 400 );
		}
		if ( '' !== $new_name ) {
			if ( TBTDIC_DB::count_groups( $user ) >= TBTDIC_Words::MAX_GROUPS ) {
				return self::error( 'tbtdic_too_many_groups', sprintf( __( 'You can have up to %d groups. Pick one you already have.', 'tbt-dictionary' ), TBTDIC_Words::MAX_GROUPS ), 400 );
			}
			$group = self::make_group( $user, $new_name );
			if ( is_wp_error( $group ) ) {
				return $group;
			}
		}

		$word_id = TBTDIC_DB::insert_word(
			$user,
			array(
				'group_id'    => (int) $group['id'],
				'term'        => $term,
				'term_key'    => $key,
				'translation' => $translation,
			)
		);
		if ( ! $word_id ) {
			return self::error( 'tbtdic_write_failed', __( 'Could not save that word. Try again.', 'tbt-dictionary' ), 500 );
		}

		$points = TBTDIC_Points::award_new_word( $user, $key, $term );

		return self::respond(
			array(
				'word'   => self::word_out( TBTDIC_DB::word( $user, $word_id ) ),
				'group'  => self::group_full( $user, (int) $group['id'] ),
				'points' => $points,
			)
		);
	}

	public function fill_word( WP_REST_Request $req ) {
		$user = get_current_user_id();
		$word = TBTDIC_DB::word( $user, (int) $req['id'] );
		if ( ! $word ) {
			return self::not_found();
		}
		// The page fills only a word added in the last few minutes (its own
		// fill-in, maybe interrupted). Older pending words, e.g. those copied
		// from AYS, are left to the background job, so they never use up the
		// student's daily limit.
		if ( 'pending' === $word['ai_status'] && TBTDIC_Fill::page_may_fill( (string) $word['created_at'] ) ) {
			TBTDIC_AI::fill_and_save( $user, $word );
			$word = TBTDIC_DB::word( $user, (int) $word['id'] );
		}
		return self::respond( array( 'word' => self::word_out( $word ) ) );
	}

	public function edit_word( WP_REST_Request $req ) {
		$user = get_current_user_id();
		$word = TBTDIC_DB::word( $user, (int) $req['id'] );
		if ( ! $word ) {
			return self::not_found();
		}
		$params = $req->get_params();
		$fields = array();

		if ( array_key_exists( 'term', $params ) ) {
			$term = TBTDIC_Words::clean_term( sanitize_text_field( (string) $params['term'] ) );
			if ( '' === $term ) {
				return self::error( 'tbtdic_empty_term', __( 'The word cannot be empty.', 'tbt-dictionary' ), 400 );
			}
			$key = TBTDIC_Words::key( $term );
			$dup = TBTDIC_DB::word_by_key( $user, $key, (int) $word['id'] );
			if ( $dup ) {
				return self::duplicate( $dup );
			}
			$fields['term']     = $term;
			$fields['term_key'] = $key;
		}
		$limits = array(
			'translation' => TBTDIC_Words::TRANSLATION_MAX,
			'phonetic'    => TBTDIC_Words::PHONETIC_MAX,
			'example'     => TBTDIC_Words::EXAMPLE_MAX,
		);
		foreach ( $limits as $field => $max ) {
			if ( array_key_exists( $field, $params ) ) {
				$fields[ $field ] = TBTDIC_Words::clean( sanitize_text_field( (string) $params[ $field ] ), $max );
			}
		}
		if ( array_key_exists( 'group_id', $params ) ) {
			$group = TBTDIC_DB::group( $user, (int) $params['group_id'] );
			if ( ! $group ) {
				return self::not_found();
			}
			$fields['group_id'] = (int) $group['id'];
		}

		if ( ! TBTDIC_DB::update_word( $user, (int) $word['id'], $fields ) ) {
			return self::error( 'tbtdic_write_failed', __( 'Could not save that. Try again.', 'tbt-dictionary' ), 500 );
		}
		return self::respond( array( 'word' => self::word_out( TBTDIC_DB::word( $user, (int) $word['id'] ) ) ) );
	}

	public function delete_word( WP_REST_Request $req ) {
		$user = get_current_user_id();
		$word = TBTDIC_DB::word( $user, (int) $req['id'] );
		if ( ! $word ) {
			return self::not_found();
		}
		TBTDIC_DB::delete_word( $user, (int) $word['id'] );
		return self::respond( array( 'deleted' => (int) $word['id'] ) );
	}

	public function reset_word( WP_REST_Request $req ) {
		$user = get_current_user_id();
		$word = TBTDIC_DB::word( $user, (int) $req['id'] );
		if ( ! $word ) {
			return self::not_found();
		}
		TBTDIC_DB::update_word( $user, (int) $word['id'], array( 'progress' => 0 ) );
		return self::respond( array( 'word' => self::word_out( TBTDIC_DB::word( $user, (int) $word['id'] ) ) ) );
	}

	/* ---------- Games (0.2.0) ---------- */

	/**
	 * One game answer: progress + right or − wrong, within 0 … learnt.
	 * Called after every answer, so progress stays even when the round is
	 * abandoned.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function answer_word( WP_REST_Request $req ) {
		$user = get_current_user_id();
		$word = TBTDIC_DB::word( $user, (int) $req['id'] );
		if ( ! $word ) {
			return self::not_found();
		}
		$correct = $req->get_param( 'correct' );
		if ( null === $correct || ! in_array( $correct, array( true, false, 1, 0, '1', '0', 'true', 'false' ), true ) ) {
			return self::error( 'tbtdic_bad_answer', __( 'Say whether the answer was right.', 'tbt-dictionary' ), 400 );
		}
		$set      = TBTDIC_Settings::learning();
		$progress = TBTDIC_Words::answer( $word['progress'], rest_sanitize_boolean( $correct ), $set['right'], $set['wrong'], $set['learnt'] );
		TBTDIC_DB::update_word( $user, (int) $word['id'], array( 'progress' => $progress ) );
		return self::respond( array( 'word' => self::word_out( TBTDIC_DB::word( $user, (int) $word['id'] ) ) ) );
	}

	/**
	 * A finished round: one row in the Notes activity log (TBT Results, the
	 * Class Progress panel and, through Notes, TBT Points). The score comes
	 * from the browser, as in Swipe and Drill; Points' repeat rule and daily
	 * cap limit what a replay earns. Never fails because Notes or Points is
	 * missing.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function finish_game( WP_REST_Request $req ) {
		$user = get_current_user_id();
		$type = (string) $req->get_param( 'type' );
		if ( ! isset( TBTDIC_Words::GAMES[ $type ] ) ) {
			return self::error( 'tbtdic_bad_game', __( 'Unknown game.', 'tbt-dictionary' ), 400 );
		}

		$raw = $req->get_param( 'group_ids' );
		if ( ! is_array( $raw ) || ! $raw || count( $raw ) > TBTDIC_Words::MAX_GROUPS ) {
			return self::error( 'tbtdic_bad_groups', __( 'Choose at least one group.', 'tbt-dictionary' ), 400 );
		}
		$ids = array();
		foreach ( $raw as $gid ) {
			if ( ! is_numeric( $gid ) || (int) $gid <= 0 ) {
				return self::not_found();
			}
			$ids[ (int) $gid ] = true;
		}
		// Every group must be the user's; the names in the order the page shows them.
		$names = array();
		foreach ( TBTDIC_DB::groups( $user ) as $g ) {
			if ( isset( $ids[ (int) $g['id'] ] ) ) {
				$names[] = (string) $g['name'];
				unset( $ids[ (int) $g['id'] ] );
			}
		}
		if ( $ids || ! $names ) {
			return self::not_found();
		}
		$group_ids = array_map( 'intval', array_unique( array_map( 'intval', $raw ) ) );

		$score = $req->get_param( 'score' );
		$total = $req->get_param( 'total' );
		if ( ! self::is_int_like( $score ) || ! self::is_int_like( $total ) ) {
			return self::error( 'tbtdic_bad_score', __( 'That score is not valid.', 'tbt-dictionary' ), 400 );
		}
		$score = (int) $score;
		$total = (int) $total;
		if ( $total < 1 || $total > TBTDIC_Words::MAX_WORDS || $score < 0 || $score > $total ) {
			return self::error( 'tbtdic_bad_score', __( 'That score is not valid.', 'tbt-dictionary' ), 400 );
		}

		$row_id = 0;
		if ( function_exists( 'tbt_notes_record_activity' ) ) {
			try {
				$id = tbt_notes_record_activity(
					array(
						'user_id'      => $user,
						'tool'         => 'dictionary',
						'object_ref'   => TBTDIC_Words::game_ref( $type, $group_ids ),
						'object_title' => TBTDIC_Words::game_title( $type, $names ),
						'score'        => $score,
						'score_max'    => $total,
					)
				);
				// A WP_Error (e.g. a teacher in no class) is not recorded.
				if ( is_int( $id ) && $id > 0 ) {
					$row_id = $id;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Notes failing never fails the game.
			}
		}

		$points = 0;
		if ( $row_id > 0 && function_exists( 'tbtp_points_for_activity' ) ) {
			try {
				$points = max( 0, (int) tbtp_points_for_activity( $row_id ) );
			} catch ( \Throwable $e ) {
				$points = 0;
			}
		}

		return self::respond(
			array(
				'recorded' => $row_id > 0,
				'points'   => $points,
			)
		);
	}

	/**
	 * An int, or a string of digits (JSON or form body).
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	private static function is_int_like( $v ) {
		return is_int( $v ) || ( is_string( $v ) && preg_match( '/^\d{1,9}$/', $v ) );
	}

	/* ---------- Helpers ---------- */

	/**
	 * The `name` parameter, cleaned; 400 when empty.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return string|WP_Error
	 */
	private static function name_param( WP_REST_Request $req ) {
		$name = TBTDIC_Words::clean_name( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
		if ( '' === $name ) {
			return self::error( 'tbtdic_empty_name', __( 'Type a name for the group.', 'tbt-dictionary' ), 400 );
		}
		return $name;
	}

	/**
	 * Insert a group. A race with the unique key returns the group that won.
	 *
	 * @param int    $user_id Owner.
	 * @param string $name    Cleaned name.
	 * @return array|WP_Error Group row.
	 */
	private static function make_group( $user_id, $name ) {
		$group = TBTDIC_DB::find_or_create_group( $user_id, $name );
		if ( $group ) {
			return $group;
		}
		return self::error( 'tbtdic_write_failed', __( 'Could not save that group. Try again.', 'tbt-dictionary' ), 500 );
	}

	/**
	 * 409: "You already have a group called …", with the existing group.
	 *
	 * @param int   $user_id  Owner.
	 * @param array $existing Group row.
	 * @return WP_Error
	 */
	private static function group_exists( $user_id, array $existing ) {
		return new WP_Error(
			'tbtdic_group_exists',
			sprintf( __( 'You already have a group called “%s”.', 'tbt-dictionary' ), $existing['name'] ),
			array(
				'status' => 409,
				'group'  => self::group_full( $user_id, (int) $existing['id'] ),
			)
		);
	}

	/**
	 * 409: "Already in your dictionary (Group name)".
	 *
	 * @param array $dup Row from word_by_key().
	 * @return WP_Error
	 */
	private static function duplicate( array $dup ) {
		return new WP_Error(
			'tbtdic_duplicate',
			sprintf( __( 'Already in your dictionary (%s)', 'tbt-dictionary' ), (string) $dup['group_name'] ),
			array(
				'status'   => 409,
				'word_id'  => (int) $dup['id'],
				'group_id' => (int) $dup['group_id'],
			)
		);
	}

	/**
	 * A group with its counts, read fresh.
	 *
	 * @param int $user_id  Owner.
	 * @param int $group_id Group ID.
	 * @return array|null
	 */
	private static function group_full( $user_id, $group_id ) {
		$group = TBTDIC_DB::group( $user_id, $group_id );
		return $group ? self::group_out( $group, TBTDIC_DB::group_progresses( $user_id, $group_id ) ) : null;
	}

	/**
	 * @param array   $g          Group row.
	 * @param float[] $progresses Its words' progress.
	 * @return array
	 */
	private static function group_out( array $g, array $progresses ) {
		$learnt  = TBTDIC_Settings::learning()['learnt'];
		$learned = 0;
		foreach ( $progresses as $p ) {
			if ( TBTDIC_Words::is_learned( $p, $learnt ) ) {
				++$learned;
			}
		}
		return array(
			'id'         => (int) $g['id'],
			'name'       => (string) $g['name'],
			'word_count' => count( $progresses ),
			'learned'    => $learned,
			'percent'    => TBTDIC_Words::group_percent( $progresses, $learnt ),
			'created_at' => (string) $g['created_at'],
		);
	}

	/**
	 * @param array|null $w Word row.
	 * @return array|null
	 */
	public static function word_out( $w ) {
		if ( ! $w ) {
			return null;
		}
		return array(
			'id'          => (int) $w['id'],
			'group_id'    => (int) $w['group_id'],
			'term'        => (string) $w['term'],
			'speak'       => TBTDIC_Words::speak_text( (string) $w['term'] ),
			'translation' => (string) $w['translation'],
			'phonetic'    => (string) $w['phonetic'],
			'example'     => (string) $w['example'],
			'progress'    => (float) $w['progress'],
			'percent'     => TBTDIC_Words::percent( $w['progress'], TBTDIC_Settings::learning()['learnt'] ),
			'ai_status'   => (string) $w['ai_status'],
			'created_at'  => (string) $w['created_at'],
		);
	}

	private static function not_found() {
		return self::error( 'tbtdic_not_found', __( 'Not found.', 'tbt-dictionary' ), 404 );
	}

	private static function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => (int) $status ) );
	}

	/**
	 * No-cache headers for this request.
	 */
	private static function nocache() {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

	/**
	 * A response that no cache keeps.
	 *
	 * @param array $data Body.
	 * @return WP_REST_Response
	 */
	private static function respond( array $data ) {
		$response = new WP_REST_Response( $data, 200 );
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( $value ) {
				$response->header( $name, $value );
			}
		}
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		return $response;
	}
}
