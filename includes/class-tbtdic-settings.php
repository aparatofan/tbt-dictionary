<?php
/**
 * Settings page under the TBT Hub menu (own menu when Hub is absent):
 * OpenAI key, model, AI fill-ins per student per day. Administrators only.
 * The key is stored server-side and never sent to a page.
 */

defined( 'ABSPATH' ) || exit;

class TBTDIC_Settings {

	const GROUP = 'tbtdic_settings';
	const SLUG  = 'tbt-dictionary-settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
