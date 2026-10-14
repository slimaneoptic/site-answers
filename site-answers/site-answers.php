<?php
/**
 * Plugin Name:       Site Answers
 * Plugin URI:        https://slimanechanchoul.com/wp-chatbot/
 * Description:       A chat assistant that answers visitors from your own pages, posts and products, with numbered sources. Runs inside WordPress with your own AI key and keeps no visitor questions.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            S'limane Chanchoul
 * Author URI:        https://slimanechanchoul.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-answers
 * Domain Path:       /languages
 * Update URI:        https://slimanechanchoul.com/wp-chatbot/
 *
 * @package Site_Answers
 */

defined( 'ABSPATH' ) || exit;

define( 'SITE_ANSWERS_VERSION', '1.0.0' );
define( 'SITE_ANSWERS_FILE', __FILE__ );
define( 'SITE_ANSWERS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITE_ANSWERS_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'SITE_ANSWERS_DEMO_ENDPOINT' ) ) {
	/**
	 * Hosted AI used only when "Demo mode" is on (the public online demo). It accepts requests from the
	 * online demo's origin only; on any other site the widget explains that an AI key is needed.
	 */
	define( 'SITE_ANSWERS_DEMO_ENDPOINT', 'https://site-chatbot-omega.vercel.app/api/answer' );
}

require_once SITE_ANSWERS_DIR . 'includes/class-site-answers-plugin.php';
require_once SITE_ANSWERS_DIR . 'includes/class-site-answers-text.php';
require_once SITE_ANSWERS_DIR . 'includes/class-site-answers-index.php';
require_once SITE_ANSWERS_DIR . 'includes/class-site-answers-search.php';
require_once SITE_ANSWERS_DIR . 'includes/class-site-answers-ai.php';
require_once SITE_ANSWERS_DIR . 'includes/class-site-answers-limits.php';
require_once SITE_ANSWERS_DIR . 'includes/class-site-answers-answer.php';
require_once SITE_ANSWERS_DIR . 'includes/class-site-answers-rest.php';
require_once SITE_ANSWERS_DIR . 'includes/class-site-answers-frontend.php';
require_once SITE_ANSWERS_DIR . 'includes/class-site-answers-admin.php';

register_activation_hook( __FILE__, array( 'Site_Answers_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Site_Answers_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Site_Answers_Plugin', 'init' ) );
