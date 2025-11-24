<?php
/**
 * Plugin Name: PDF Auditor
 * Description: Scan WordPress multisite for PDFs, catalog the metadata, display in WordPress, and export as CSV
 * Version: 1.0.0
 * Author: Kerri Hicks
 * License: PolyForm Noncommercial License 1.0.0
 * Text Domain: pdf-auditor
 * Network: true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'PDF_AUDITOR_VERSION', '1.0.0' );
define( 'PDF_AUDITOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PDF_AUDITOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Only load on multisite
if ( ! is_multisite() ) {
	add_action(
		'admin_notices',
		function() {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'PDF Auditor requires WordPress Multisite to be enabled.', 'pdf-auditor' ); ?></p>
			</div>
			<?php
		}
	);
	return;
}

// Load the main plugin class
require_once PDF_AUDITOR_PLUGIN_DIR . 'includes/class-pdf-auditor.php';

// Initialize the plugin
PDF_Auditor::get_instance();
