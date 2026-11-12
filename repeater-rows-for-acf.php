<?php
/**
 * Plugin Name:       Repeater Rows for ACF
 * Description:       Adds a free Repeater Rows field type to Advanced Custom Fields, with a collapsible card layout on the edit screen.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            tharindunishal
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       repeater-rows-for-acf
 * Domain Path:       /languages
 *
 * @package RepeaterRowsForACF
 */

defined( 'ABSPATH' ) || exit;

define( 'RRFA_VERSION', '0.1.0' );
define( 'RRFA_PATH', plugin_dir_path( __FILE__ ) );
define( 'RRFA_URL', plugin_dir_url( __FILE__ ) );

require_once RRFA_PATH . 'includes/class-rrfa-plugin.php';

add_action( 'after_setup_theme', array( 'RRFA_Plugin', 'boot' ), 20 );
