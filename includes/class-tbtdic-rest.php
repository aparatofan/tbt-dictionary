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
 *   POST   /words/{id}/fill       run the AI fill-in (pending words only)
 *   PATCH  /words/{id}            edit or move
 *   DELETE /words/{id}            delete
 *   POST   /words/{id}/reset      progress 0
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
				'words'  => array_map( array( __CLASS__, 'word_out' ), $words ),
				'learnt' => TBTDIC_LEARNT_DEFAULT,
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
		if ( 'pending' === $word['ai_status'] ) {
			$fields = TBTDIC_AI::fill( $user, $word );
			// The student may have typed a translation while the AI worked.
			$now = TBTDIC_DB::word( $user, (int) $word['id'] );
			if ( $now && '' !== (string) $now['translation'] ) {
				unset( $fields['translation'] );
			}
			TBTDIC_DB::update_word( $user, (int) $word['id'], $fields );
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
		$id = TBTDIC_DB::insert_group( $user_id, $name );
		if ( $id ) {
			return TBTDIC_DB::group( $user_id, $id );
		}
		$existing = TBTDIC_DB::group_by_name( $user_id, $name );
		if ( $existing ) {
			return $existing;
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
		$learned = 0;
		foreach ( $progresses as $p ) {
			if ( TBTDIC_Words::is_learned( $p ) ) {
				++$learned;
			}
		}
		return array(
			'id'         => (int) $g['id'],
			'name'       => (string) $g['name'],
			'word_count' => count( $progresses ),
			'learned'    => $learned,
			'percent'    => TBTDIC_Words::group_percent( $progresses ),
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
			'percent'     => TBTDIC_Words::percent( $w['progress'] ),
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
