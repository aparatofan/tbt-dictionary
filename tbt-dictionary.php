<?php
/**
 * Plugin Name:       TBT Dictionary
 * Description:       Each student's own dictionary: words in groups they name, with AI translation, phonetics, an example and audio. New words earn TBT Points.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            The Blue Tree
 * Text Domain:       tbt-dictionary
 */

defined( 'ABSPATH' ) || exit;

define( 'TBTDIC_VERSION', '0.1.0' );
define( 'TBTDIC_DB_VERSION', '1' );
define( 'TBTDIC_FILE', __FILE__ );
define( 'TBTDIC_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBTDIC_URL', plugin_dir_url( __FILE__ ) );

require_once TBTDIC_DIR . 'includes/class-tbtdic-words.php';
require_once TBTDIC_DIR . 'includes/class-tbtdic-db.php';
require_once TBTDIC_DIR . 'includes/class-tbtdic-ai.php';
require_once TBTDIC_DIR . 'includes/class-tbtdic-points.php';
require_once TBTDIC_DIR . 'includes/class-tbtdic-rest.php';
require_once TBTDIC_DIR . 'includes/class-tbtdic-pages.php';
require_once TBTDIC_DIR . 'includes/class-tbtdic-settings.php';

/**
 * Activation: the two tables.
 */
function tbtdic_activate() {
	TBTDIC_DB::install();
}
register_activation_hook( __FILE__, 'tbtdic_activate' );

/**
 * Register this plugin on the TBT Hub Overview page. A filter on Hub's own
 * hook: when Hub is absent nothing applies it.
 *
 * @param array $items Existing hub items.
 * @return array
 */
function tbtdic_register_hub_item( $items ) {
	$items   = is_array( $items ) ? $items : array();
	$items[] = array(
		'slug'        => TBTDIC_Settings::SLUG,
		'title'       => 'TBT Dictionary',
		'description' => 'Each student keeps their own dictionary, with AI translation, phonetics, examples and audio.',
		'capability'  => 'manage_options',
	);
	return $items;
}
add_filter( 'tbt_hub_items', 'tbtdic_register_hub_item' );

// Points, Swipe and Flashcards are optional: each integration checks for its
// function or option when used, never here.
add_action(
	'plugins_loaded',
	static function () {
		// FTP deploys never fire activation: create or update the tables here.
		TBTDIC_DB::maybe_upgrade();
		new TBTDIC_Rest();
		new TBTDIC_Pages();
		if ( is_admin() ) {
			new TBTDIC_Settings();
		}
	}
);
