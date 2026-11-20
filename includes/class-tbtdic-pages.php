<?php
/**
 * The [tbt_dictionary] shortcode and its assets.
 *
 * No hero: the Divi page supplies its header. The server prints the login
 * card for visitors who are logged out; everything else is drawn by
 * assets/js/dictionary.js from the REST routes.
 *
 * The design tokens are TBT Hub's, under the shared handle 'tbt-tokens'.
 * When Hub is inactive, the vendored copy in assets/css/tbt-tokens.css is
 * registered under that same handle (the TBT Points pattern).
 */

defined( 'ABSPATH' ) || exit;

class TBTDIC_Pages {

	const SHORTCODE = 'tbt_dictionary';
	const APP_ID    = 'tbtdic-app';

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		// After Hub registers 'tbt-tokens' at priority 5.
		add_action( 'wp_enqueue_scripts', array( $this, 'early_styles' ), 20 );
		add_action( 'template_redirect', array( $this, 'maybe_nocache' ) );
		add_action( 'wp_ajax_tbtdic_nonce', array( $this, 'fresh_nonce' ) );
	}

	/* ---------- Assets ---------- */

	private static function register() {
		if ( ! wp_style_is( 'tbt-tokens', 'registered' ) ) {
			wp_register_style( 'tbt-tokens', TBTDIC_URL . 'assets/css/tbt-tokens.css', array(), self::file_version( 'assets/css/tbt-tokens.css' ) );
		}
		if ( ! wp_style_is( 'tbtdic-fonts', 'registered' ) ) {
			wp_register_style( 'tbtdic-fonts', TBTDIC_URL . 'assets/css/fonts.css', array(), TBTDIC_VERSION );
		}
		if ( ! wp_style_is( 'tbtdic-dictionary', 'registered' ) ) {
			wp_register_style( 'tbtdic-dictionary', TBTDIC_URL . 'assets/css/dictionary.css', array( 'tbt-tokens', 'tbtdic-fonts' ), TBTDIC_VERSION );
		}
	}

	private static function enqueue_styles() {
		self::register();
		wp_enqueue_style( 'tbtdic-dictionary' );
	}

	/**
	 * Styles in the head on a page holding the shortcode, so nothing flashes
	 * unstyled. The script is added by the shortcode itself.
	 */
	public function early_styles() {
		if ( self::page_has() ) {
			self::enqueue_styles();
		}
	}

	/**
	 * A per-visitor page: keep it out of every cache.
	 */
	public function maybe_nocache() {
		if ( ! self::page_has() || headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * The script and its boot data. The OpenAI and ElevenLabs keys are only
	 * tested for presence; neither ever reaches the page.
	 */
	private static function enqueue_app() {
		static $done = false;
		self::enqueue_styles();
		if ( $done ) {
			return;
		}
		$done = true;
		wp_enqueue_script( 'tbtdic-dictionary', TBTDIC_URL . 'assets/js/dictionary.js', array(), TBTDIC_VERSION, true );
		wp_localize_script(
			'tbtdic-dictionary',
			'TBTDIC_CFG',
			array(
				'rest'   => esc_url_raw( rest_url( TBTDIC_Rest::NS . '/' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'ajax'   => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'learnt' => TBTDIC_Settings::learning()['learnt'],
				'audio'  => self::audio(),
			)
		);
	}

	/**
	 * Audio, when TBT Flashcards is active and has an ElevenLabs key: the
	 * dictionary borrows Flashcards' proxy (Translation Drill's audio()
	 * check), so all three share one key, one voice and one MP3 cache.
	 *
	 * @return array|null Null when audio is off.
	 */
	private static function audio() {
		if ( function_exists( 'tbt_fc_tts_proxy' ) && '' !== (string) get_option( 'tbt_fc_elevenlabs_api_key', '' ) ) {
			return array(
				'url'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'action' => 'tbt_fc_tts',
				'nonce'  => wp_create_nonce( 'tbt_fc_tts_nonce' ),
			);
		}
		return null;
	}

	/**
	 * A fresh REST nonce for a page left open long enough for its nonce to
	 * expire. Logged-in users only (wp_ajax_, not wp_ajax_nopriv_).
	 */
	public function fresh_nonce() {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * @return bool
	 */
	private static function page_has() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/**
	 * Cache buster for the vendored token file: its modification time.
	 *
	 * @param string $relative Path relative to the plugin folder.
	 * @return string
	 */
	private static function file_version( $relative ) {
		$mtime = @filemtime( TBTDIC_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $mtime ? TBTDIC_VERSION . '.' . $mtime : TBTDIC_VERSION;
	}

	/* ---------- Shortcode ---------- */

	/**
	 * [tbt_dictionary]
	 *
	 * @return string
	 */
	public function render() {
		self::enqueue_styles();

		if ( ! is_user_logged_in() ) {
			return self::wrap(
				self::login_card(
					__( 'Log in to see your dictionary', 'tbt-dictionary' ),
					__( 'Your words, in groups you name yourself. Log in and it opens straight away.', 'tbt-dictionary' )
				)
			);
		}

		self::enqueue_app();
		return sprintf(
			'<div id="%1$s" class="tbtdic" data-tbtdic-app><p class="tbtdic-loading" role="status">%2$s</p><noscript><p class="tbtdic-muted">%3$s</p></noscript></div>',
			esc_attr( self::APP_ID ),
			esc_html__( 'Loading your dictionary…', 'tbt-dictionary' ),
			esc_html__( 'The dictionary needs JavaScript. Turn it on and reload the page.', 'tbt-dictionary' )
		);
	}

	/* ---------- Markup ---------- */

	/**
	 * The wrapper for the login card. It carries the same ID as the app, so
	 * its heading is pinned by the same rules.
	 *
	 * @param string $inner Markup.
	 * @return string
	 */
	private static function wrap( $inner ) {
		return '<div id="' . esc_attr( self::APP_ID ) . '" class="tbtdic">' . $inner . '</div>';
	}

	/**
	 * The login card, as TBT Points shows it: heading, one line, Log in.
	 *
	 * @param string $title Heading.
	 * @param string $text  One line.
	 * @return string
	 */
	private static function login_card( $title, $text ) {
		$here = get_permalink();
		return sprintf(
			'<section class="tbtdic-stage tbtdic-message"><h2 class="tbtdic-stage__title">%1$s</h2><p class="tbtdic-muted">%2$s</p><p><a class="tbtdic-btn" href="%3$s">%4$s</a></p></section>',
			esc_html( $title ),
			esc_html( $text ),
			esc_url( wp_login_url( $here ? $here : '' ) ),
			esc_html__( 'Log in', 'tbt-dictionary' )
		);
	}
}
