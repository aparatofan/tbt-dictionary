<?php
/**
 * Settings page under the TBT Hub menu (own menu when Hub is absent):
 * OpenAI key, model, AI fill-ins per student per day, the Learning points
 * (0.2.0), and (when AYS
 * Personal Dictionary's tables exist) the copy of the old dictionary.
 * Administrators only. The key is stored server-side and never sent to a
 * page.
 */

defined( 'ABSPATH' ) || exit;

class TBTDIC_Settings {

	const GROUP = 'tbtdic_settings';
	const SLUG  = 'tbt-dictionary-settings';

	/** admin-post action and nonce of the AYS copy. */
	const COPY_ACTION = 'tbtdic_ays_copy';

	/**
	 * The Learning settings in force: learnt, right, wrong, each in its
	 * range (TBTDIC_Words::learning()). Every runtime use of the learnt
	 * points reads this; TBTDIC_LEARNT_DEFAULT is only the default.
	 *
	 * @return array{learnt:int,right:float,wrong:float}
	 */
	public static function learning() {
		return TBTDIC_Words::learning(
			get_option( 'tbtdic_learnt', TBTDIC_LEARNT_DEFAULT ),
			get_option( 'tbtdic_right', TBTDIC_Words::RIGHT_DEFAULT ),
			get_option( 'tbtdic_wrong', TBTDIC_Words::WRONG_DEFAULT )
		);
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::COPY_ACTION, array( $this, 'handle_copy' ) );
	}

	/**
	 * The Copy to TBT Dictionary button (POST to admin-post.php).
	 */
	public function handle_copy() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'tbt-dictionary' ), 403 );
		}
		check_admin_referer( self::COPY_ACTION );
		$result = TBTDIC_AYS::copy();
		$url    = add_query_arg(
			array(
				'page'        => self::SLUG,
				'tbtdic_copy' => null === $result ? 'busy' : 'done',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url . '#tbtdic-ays' );
		exit;
	}

	public function register_menu() {
		if ( defined( 'TBT_HUB_SLUG' ) ) {
			add_submenu_page(
				TBT_HUB_SLUG,
				__( 'TBT Dictionary Settings', 'tbt-dictionary' ),
				__( 'Dictionary Settings', 'tbt-dictionary' ),
				'manage_options',
				self::SLUG,
				array( $this, 'render' )
			);
			return;
		}
		add_menu_page(
			__( 'TBT Dictionary Settings', 'tbt-dictionary' ),
			__( 'TBT Dictionary', 'tbt-dictionary' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-book-alt'
		);
	}

	public function register_settings() {
		register_setting( self::GROUP, 'tbtdic_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_api_key' ),
			'default'           => '',
		) );
		register_setting( self::GROUP, 'tbtdic_model', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( self::GROUP, 'tbtdic_ai_per_day', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_per_day' ),
			'default'           => TBTDIC_AI::DEFAULT_PER_DAY,
		) );
		register_setting( self::GROUP, 'tbtdic_learnt', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_learnt' ),
			'default'           => TBTDIC_LEARNT_DEFAULT,
		) );
		register_setting( self::GROUP, 'tbtdic_right', array(
			'type'              => 'number',
			'sanitize_callback' => array( $this, 'sanitize_right' ),
			'default'           => TBTDIC_Words::RIGHT_DEFAULT,
		) );
		register_setting( self::GROUP, 'tbtdic_wrong', array(
			'type'              => 'number',
			'sanitize_callback' => array( $this, 'sanitize_wrong' ),
			'default'           => TBTDIC_Words::WRONG_DEFAULT,
		) );
	}

	/**
	 * 1–100, whole points; not a number falls back to the default.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public function sanitize_learnt( $value ) {
		return TBTDIC_Words::learning( $value, null, null )['learnt'];
	}

	/**
	 * 0–100 in steps of 0.25; not a number falls back to the default.
	 *
	 * @param mixed $value Submitted value.
	 * @return float
	 */
	public function sanitize_right( $value ) {
		return TBTDIC_Words::quarter( $value, TBTDIC_Words::RIGHT_DEFAULT );
	}

	/**
	 * 0–100 in steps of 0.25; not a number falls back to the default.
	 *
	 * @param mixed $value Submitted value.
	 * @return float
	 */
	public function sanitize_wrong( $value ) {
		return TBTDIC_Words::quarter( $value, TBTDIC_Words::WRONG_DEFAULT );
	}

	/**
	 * Keep the stored key when the field is left blank, so submitting the
	 * masked form never wipes it. A single dash clears it on purpose, which
	 * is how the site goes back to TBT Swipe's key.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_api_key( $value ) {
		$value = trim( (string) $value );
		if ( '-' === $value ) {
			return '';
		}
		if ( '' === $value ) {
			return (string) get_option( 'tbtdic_api_key', '' );
		}
		return sanitize_text_field( $value );
	}

	/**
	 * 0–1000; anything else falls back to the default.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public function sanitize_per_day( $value ) {
		if ( ! is_numeric( $value ) ) {
			return TBTDIC_AI::DEFAULT_PER_DAY;
		}
		return max( 0, min( TBTDIC_AI::MAX_PER_DAY, (int) $value ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$source = TBTDIC_AI::key_source();
		$model  = (string) get_option( 'tbtdic_model', '' );
		$learn  = self::learning();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'TBT Dictionary Settings', 'tbt-dictionary' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'AI fill-in', 'tbt-dictionary' ); ?></h2>
				<p class="description"><?php esc_html_e( 'When a student adds a word, AI adds the phonetics, an example sentence and, if the student left it empty, the translation.', 'tbt-dictionary' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tbtdic_api_key"><?php esc_html_e( 'OpenAI API key', 'tbt-dictionary' ); ?></label></th>
						<td>
							<input type="password" name="tbtdic_api_key" id="tbtdic_api_key" class="regular-text" autocomplete="off" value=""
								placeholder="<?php echo 'own' === $source ? esc_attr__( 'Saved — leave blank to keep', 'tbt-dictionary' ) : 'sk-…'; ?>">
							<p class="description">
								<?php
								if ( 'swipe' === $source ) {
									esc_html_e( 'Currently using the key saved in TBT Swipe. Enter a key here only if the dictionary should use a different one.', 'tbt-dictionary' );
								} elseif ( 'own' === $source ) {
									esc_html_e( 'Currently using the key saved here. Stored server-side and never sent to the browser. Leave blank to keep it; enter a single dash (-) to remove it and fall back to TBT Swipe\'s key.', 'tbt-dictionary' );
								} else {
									esc_html_e( 'No key is set here or in TBT Swipe, so AI fill-in is off: words save as typed. Stored server-side and never sent to the browser.', 'tbt-dictionary' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbtdic_model"><?php esc_html_e( 'Model', 'tbt-dictionary' ); ?></label></th>
						<td>
							<input type="text" name="tbtdic_model" id="tbtdic_model" class="regular-text" value="<?php echo esc_attr( $model ); ?>"
								placeholder="<?php echo esc_attr( TBTDIC_AI::model() ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: model in use */
									esc_html__( 'OpenAI model identifier. In use now: %s. Leave blank to use TBT Swipe\'s model, or the default if Swipe has none.', 'tbt-dictionary' ),
									'<code>' . esc_html( TBTDIC_AI::model() ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbtdic_ai_per_day"><?php esc_html_e( 'AI fill-ins per student per day', 'tbt-dictionary' ); ?></label></th>
						<td>
							<input type="number" min="0" max="<?php echo esc_attr( TBTDIC_AI::MAX_PER_DAY ); ?>" step="1"
								name="tbtdic_ai_per_day" id="tbtdic_ai_per_day" class="small-text"
								value="<?php echo esc_attr( TBTDIC_AI::per_day() ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %d: default */
									esc_html__( 'Default %d. 0 means no limit. Past the limit, words still save, without AI. Failed calls do not count. To switch AI off, clear the key.', 'tbt-dictionary' ),
									(int) TBTDIC_AI::DEFAULT_PER_DAY
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Learning', 'tbt-dictionary' ); ?></h2>
				<p class="description"><?php esc_html_e( 'How each answer in the games moves a word toward "learned". Learned words leave the games.', 'tbt-dictionary' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tbtdic_learnt"><?php esc_html_e( 'Points to learn a word', 'tbt-dictionary' ); ?></label></th>
						<td>
							<input type="number" min="1" max="100" step="1" name="tbtdic_learnt" id="tbtdic_learnt" class="small-text"
								value="<?php echo esc_attr( $learn['learnt'] ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %d: default */
									esc_html__( 'Default %d. Progress is stored in points, so changing Points to learn a word changes every word\'s percentage at once.', 'tbt-dictionary' ),
									(int) TBTDIC_LEARNT_DEFAULT
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbtdic_right"><?php esc_html_e( 'Points for a right answer', 'tbt-dictionary' ); ?></label></th>
						<td>
							<input type="number" min="0" max="100" step="0.25" name="tbtdic_right" id="tbtdic_right" class="small-text"
								value="<?php echo esc_attr( $learn['right'] ); ?>">
							<p class="description"><?php echo esc_html( sprintf( /* translators: %s: default */ __( 'Default %s.', 'tbt-dictionary' ), TBTDIC_Words::RIGHT_DEFAULT ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbtdic_wrong"><?php esc_html_e( 'Points taken for a wrong answer', 'tbt-dictionary' ); ?></label></th>
						<td>
							<input type="number" min="0" max="100" step="0.25" name="tbtdic_wrong" id="tbtdic_wrong" class="small-text"
								value="<?php echo esc_attr( $learn['wrong'] ); ?>">
							<p class="description"><?php echo esc_html( sprintf( /* translators: %s: default */ __( 'Default %s.', 'tbt-dictionary' ), TBTDIC_Words::WRONG_DEFAULT ) ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php $this->render_ays(); ?>
		</div>
		<?php
	}

	/**
	 * The "Old dictionary" section; nothing when AYS's tables are missing.
	 */
	private function render_ays() {
		if ( ! TBTDIC_AYS::available() ) {
			return;
		}
		$sum     = TBTDIC_AYS::summary();
		$last    = TBTDIC_AYS::last_copy();
		$pending = TBTDIC_DB::tables_exist() ? TBTDIC_DB::count_pending() : 0;
		$flag    = isset( $_GET['tbtdic_copy'] ) ? sanitize_key( wp_unslash( $_GET['tbtdic_copy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<hr>
		<h2 class="title" id="tbtdic-ays"><?php esc_html_e( 'Old dictionary (AYS Personal Dictionary)', 'tbt-dictionary' ); ?></h2>
		<?php if ( 'busy' === $flag ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'The copy did not run: another copy is still running, or the dictionary tables are not ready. Try again in a few minutes.', 'tbt-dictionary' ); ?></p></div>
		<?php endif; ?>
		<p>
			<?php
			printf(
				/* translators: 1: words, 2: students, 3: words already copied */
				esc_html__( 'Found %1$s words from %2$s students. %3$s of them are already in TBT Dictionary.', 'tbt-dictionary' ),
				esc_html( number_format_i18n( $sum['words'] ) ),
				esc_html( number_format_i18n( $sum['students'] ) ),
				esc_html( number_format_i18n( $sum['already'] ) )
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent=<?php echo esc_attr( wp_json_encode( __( 'Copying…', 'tbt-dictionary' ) ) ); ?>;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::COPY_ACTION ); ?>">
			<?php wp_nonce_field( self::COPY_ACTION ); ?>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Copy to TBT Dictionary', 'tbt-dictionary' ); ?></button></p>
		</form>
		<?php if ( $last ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: words, 2: groups, 3: students, 4: words skipped */
					esc_html__( 'Copied %1$s words into %2$s groups for %3$s students. Skipped %4$s (already there, empty, or no account).', 'tbt-dictionary' ),
					esc_html( number_format_i18n( (int) $last['words_copied'] ) ),
					esc_html( number_format_i18n( (int) $last['groups_created'] ) ),
					esc_html( number_format_i18n( (int) $last['students'] ) ),
					esc_html( number_format_i18n( (int) $last['words_skipped'] ) )
				);
				if ( (int) $last['users_missing'] > 0 ) {
					echo ' ';
					printf(
						/* translators: %s: number of students */
						esc_html( _n( '%s student no longer has an account.', '%s students no longer have an account.', (int) $last['users_missing'], 'tbt-dictionary' ) ),
						esc_html( number_format_i18n( (int) $last['users_missing'] ) )
					);
				}
				?>
				<br><span class="description">
				<?php
				printf(
					/* translators: %s: date and time */
					esc_html__( 'Last run: %s', 'tbt-dictionary' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] ) )
				);
				?>
				</span>
			</p>
		<?php endif; ?>
		<?php if ( $pending > 0 ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: number of words */
					esc_html( _n( 'Adding phonetics and examples in the background: %s word to go.', 'Adding phonetics and examples in the background: %s words to go.', $pending, 'tbt-dictionary' ) ),
					esc_html( number_format_i18n( $pending ) )
				);
				?>
			</p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'AYS is only read, never changed. Running the copy again only adds words that are new in AYS. Copied words earn no points. Phonetics and examples are added about 10 words every 2 minutes while the site has visitors (WordPress runs background jobs on page visits); these do not count against the students\' daily AI limit.', 'tbt-dictionary' ); ?></p>
		<?php
	}
}
