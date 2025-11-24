<?php
/**
 * Main PDF Auditor class
 *
 * This class powers the entire PDF Auditor tool.
 * It creates the admin page, loads scripts, talks to the database,
 * switches between subsites, and generates CSV reports.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Safety check: makes sure the file isn't accessed directly.
}

class PDF_Auditor {

	/**
	 * Holds the one allowed instance of this class.
	 * The plugin uses the “singleton” pattern so WordPress
	 * doesn’t accidentally create multiple copies.
	 *
	 * @var PDF_Auditor
	 */
	private static $instance = null;

	/**
	 * Returns the single instance of this class.
	 * If it doesn’t exist yet, it creates it.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * This runs immediately when the class is created.
	 * It attaches the plugin’s features to WordPress.
	 */
	public function __construct() {

		// Disable WordPress emoji scripts. These can interfere with
		// Unicode characters and are unnecessary in the admin tool.
		remove_action( 'wp_head', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );

		// Adds the PDF Auditor page to the Network Admin menu.
		add_action( 'network_admin_menu', array( $this, 'register_network_admin_page' ) );

		// Ensures CSS and JavaScript load only on the PDF Auditor screen.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX endpoint for loading PDFs for a specific site.
		add_action( 'wp_ajax_pdf_auditor_get_site_pdfs', array( $this, 'ajax_get_site_pdfs' ) );

		// AJAX endpoint for generating and downloading the CSV.
		add_action( 'wp_ajax_pdf_auditor_download_csv', array( $this, 'ajax_download_csv' ) );
	}

	/**
	 * Registers the PDF Auditor page in the Network Admin menu.
	 *
	 * This page is where the multisite admin opens the scanner tool.
	 */
	public function register_network_admin_page() {
		add_menu_page(
			__( 'PDF Auditor', 'pdf-auditor' ), // Page title
			__( 'PDF Auditor', 'pdf-auditor' ), // Menu label
			'manage_network',                    // Capability required
			'pdf-auditor',                       // URL slug
			array( $this, 'render_admin_page' ), // Callback to draw the page
			'dashicons-pdf',                     // Icon
			25                                   // Menu position
		);
	}

	/**
	 * Loads the plugin’s CSS and JavaScript.
	 * Only runs on the PDF Auditor screen.
	 */
	public function enqueue_assets( $hook ) {
		// Prevents loading assets on unrelated admin pages.
		if ( 'toplevel_page_pdf-auditor' !== $hook ) {
			return;
		}

		// Load CSS layout for the PDF Auditor interface.
		wp_enqueue_style(
			'pdf-auditor-css',
			PDF_AUDITOR_PLUGIN_URL . 'assets/css/pdf-auditor.css',
			array(),
			PDF_AUDITOR_VERSION
		);

		// Load the interactive script (sorting, AJAX, CSV output).
		wp_enqueue_script(
			'pdf-auditor-js',
			PDF_AUDITOR_PLUGIN_URL . 'assets/js/pdf-auditor.js',
			array( 'jquery' ),
			PDF_AUDITOR_VERSION,
			true
		);

		// Passes PHP data into the JavaScript file
		// including AJAX URL, text labels, and a security token (nonce).
		wp_localize_script(
			'pdf-auditor-js',
			'pdfAuditorData',
			array(
				'nonce'   => wp_create_nonce( 'pdf_auditor_nonce' ),
				'ajaxUrl' => network_site_url( 'wp-admin/admin-ajax.php' ),

				// All user-facing text is stored here so it’s easy to translate.
				'strings' => array(
					'loadingPDFs'       => __( 'Loading PDFs...', 'pdf-auditor' ),
					'noPDFs'            => __( 'No PDFs found in this site.', 'pdf-auditor' ),
					'downloadCSV'       => __( 'Download CSV for this site', 'pdf-auditor' ),
					'downloading'       => __( 'Generating...', 'pdf-auditor' ),
					'viewPDF'           => __( 'View PDF', 'pdf-auditor' ),
					'errorLoading'      => __( 'Error loading PDFs', 'pdf-auditor' ),
					'errorGenerating'   => __( 'Error generating CSV', 'pdf-auditor' ),
					'networkError'      => __( 'Network error. Please check your connection and try again.', 'pdf-auditor' ),
					'invalidResponse'   => __( 'Invalid response from server. Please try again.', 'pdf-auditor' ),
					'permissionDenied'  => __( 'You do not have permission to perform this action.', 'pdf-auditor' ),
				),
			)
		);
	}

	/**
	 * Draws the main PDF Auditor screen.
	 *
	 * This screen lists all subsites and gives each one
	 * a collapsible section that loads its PDF inventory.
	 */
	public function render_admin_page() {

		// Extra safety: only network admins can use this tool.
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'pdf-auditor' ) );
		}

		// Fetches up to 999 subsites, returning only their IDs.
		$sites = get_sites(
			array(
				'number' => 999,
				'fields' => 'ids',
			)
		);

		// Everything below is HTML output for the interface.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PDF Auditor', 'pdf-auditor' ); ?></h1>
			<p><?php esc_html_e( 'Scan your multisite for PDFs. Click to expand a site and view its PDF inventory.', 'pdf-auditor' ); ?></p>

			<div id="pdf-auditor-accordion" class="pdf-auditor-accordion">
				<?php foreach ( $sites as $site_id ) : ?>
					<?php $site = get_site( $site_id ); ?>

					<!-- Each site becomes a collapsible section -->
					<div class="pdf-auditor-site" data-site-id="<?php echo absint( $site_id ); ?>">

						<!-- The button that opens the site’s PDF list -->
						<button class="pdf-auditor-site-toggle" type="button" aria-expanded="false">
							<span class="toggle-icon"></span>
							<span class="site-name"><?php echo esc_html( $site->blogname ); ?></span>
							<span class="site-url"><?php echo esc_html( $site->domain . $site->path ); ?></span>
						</button>

						<!-- The container where PDFs will be loaded -->
						<div class="pdf-auditor-site-content" style="display: none;">
							<div class="pdf-auditor-loading">
								<p><?php esc_html_e( 'Loading PDFs...', 'pdf-auditor' ); ?></p>
							</div>
						</div>

					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Fetches PDFs for an individual site.
	 *
	 * This runs when the user expands a subsite for the first time.
	 * It switches into that subsite, grabs its PDF attachments,
	 * includes file sizes and dates, and sends the info back as JSON.
	 */
	public function ajax_get_site_pdfs() {

		// Security check: ensures the request is valid and came from an authorized session.
		check_ajax_referer( 'pdf_auditor_nonce', 'nonce' );

		// Only network admins can perform this action.
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_send_json_error( __( 'Permission denied', 'pdf-auditor' ), 403 );
		}

		// Make sure the request included a site ID.
		if ( ! isset( $_POST['site_id'] ) ) {
			wp_send_json_error( __( 'Site ID not provided', 'pdf-auditor' ), 400 );
		}

		$site_id = absint( $_POST['site_id'] );

		// Validate the site ID.
		if ( $site_id <= 0 ) {
			wp_send_json_error( __( 'Invalid site ID', 'pdf-auditor' ), 400 );
		}

		$site = get_site( $site_id );

		// Ensure the site actually exists in the multisite network.
		if ( ! $site ) {
			wp_send_json_error( __( 'Invalid site.', 'pdf-auditor' ), 404 );
		}

		$pdfs = array();

		try {
			// Temporarily switch WordPress's global context to the target subsite.
			switch_to_blog( $site_id );

			global $wpdb;

			// Direct SQL query to fetch PDF attachments.
			// (Faster than get_posts() for large media libraries.)
			$attachments = $wpdb->get_results(
				"SELECT ID, post_title, post_mime_type, post_date FROM {$wpdb->posts} 
				 WHERE post_type = 'attachment' 
				 AND LOWER(post_mime_type) = 'application/pdf'
				 ORDER BY post_date DESC"
			);

			// Build PDF entry list for the JSON response.
			if ( ! empty( $attachments ) ) {
				foreach ( $attachments as $attachment ) {

					$file_url  = wp_get_attachment_url( $attachment->ID );
					$file_path = get_attached_file( $attachment->ID );

					// Handle missing files (orphaned media entries).
					if ( ! $file_path || ! file_exists( $file_path ) ) {
						$pdfs[] = array(
							'id'            => $attachment->ID,
							'filename'      => wp_basename( $file_url ),
							'url'           => $file_url,
							'upload_date'   => $attachment->post_date,
							'file_size'     => __( 'Missing file', 'pdf-auditor' ),
							'file_size_raw' => 0,
						);
						continue;
					}

					// @ suppresses warnings in case of permission issues.
					$file_size = @filesize( $file_path );
					$file_size = $file_size ? $file_size : 0;

					$pdfs[] = array(
						'id'            => $attachment->ID,
						'filename'      => wp_basename( $file_path ),
						'url'           => $file_url,
						'upload_date'   => $attachment->post_date,
						'file_size'     => $this->format_bytes( $file_size ),
						'file_size_raw' => $file_size,
					);
				}
			}

		} finally {
			// Always return to the original site, no matter what happens above.
			restore_current_blog();
		}

		// Send the list back to JavaScript.
		wp_send_json_success(
			array(
				'site_id' => $site_id,
				'pdfs'    => $pdfs,
				'count'   => count( $pdfs ),
			)
		);
	}

	/**
	 * AJAX: Generates and returns a CSV of a site's PDFs.
	 *
	 * The browser receives CSV content and triggers a download.
	 */
	public function ajax_download_csv() {

		// Security nonce validation.
		check_ajax_referer( 'pdf_auditor_nonce', 'nonce' );

		// Permission check.
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_send_json_error( __( 'Permission denied', 'pdf-auditor' ), 403 );
		}

		// Input validation.
		if ( ! isset( $_POST['site_id'] ) ) {
			wp_send_json_error( __( 'Site ID not provided', 'pdf-auditor' ), 400 );
		}

		$site_id = absint( $_POST['site_id'] );

		if ( $site_id <= 0 ) {
			wp_send_json_error( __( 'Invalid site ID', 'pdf-auditor' ), 400 );
		}

		$site = get_site( $site_id );

		if ( ! $site ) {
			wp_send_json_error( __( 'Invalid site.', 'pdf-auditor' ), 404 );
		}

		$pdfs = array();

		try {
			// As before: temporary switch to the requested subsite.
			switch_to_blog( $site_id );

			global $wpdb;

			// Fetch PDF attachments.
			$attachments = $wpdb->get_results(
				"SELECT ID, post_title, post_mime_type, post_date FROM {$wpdb->posts} 
				 WHERE post_type = 'attachment' 
				 AND LOWER(post_mime_type) = 'application/pdf'
				 ORDER BY post_date DESC"
			);

			// Build CSV entries.
			if ( ! empty( $attachments ) ) {
				foreach ( $attachments as $attachment ) {

					$file_url  = wp_get_attachment_url( $attachment->ID );
					$file_path = get_attached_file( $attachment->ID );

					// Handle missing files so the CSV still downloads cleanly.
					if ( ! $file_path || ! file_exists( $file_path ) ) {
						$pdfs[] = array(
							'filename'    => wp_basename( $file_url ),
							'url'         => $file_url,
							'upload_date' => $attachment->post_date,
							'file_size'   => __( 'Missing file', 'pdf-auditor' ),
						);
						continue;
					}

					$file_size = @filesize( $file_path );
					$file_size = $file_size ? $file_size : 0;

					$pdfs[] = array(
						'filename'    => wp_basename( $file_path ),
						'url'         => $file_url,
						'upload_date' => $attachment->post_date,
						'file_size'   => $this->format_bytes( $file_size ),
					);
				}
			}

		} finally {
			restore_current_blog();
		}

		// Generate the CSV file content in memory.
		$csv_content = $this->generate_csv( $pdfs );

		// Sanitize filename for safety.
		$filename = sanitize_file_name(
			$site->blogname . ' - WordPress PDF listing - ' . gmdate( 'Y-m-d' ) . '.csv'
		);

		// Send CSV output back to JavaScript for download.
		wp_send_json_success(
			array(
				'csv_content' => $csv_content,
				'filename'    => $filename,
			)
		);
	}

	/**
	 * Generates CSV output for the browser.
	 *
	 * The CSV is built in memory and then returned as a string.
	 */
	private function generate_csv( $pdfs ) {
		$output = fopen( 'php://memory', 'r+' );

		// Adds a special marker that makes Excel properly read UTF-8 characters.
		fwrite( $output, "\xEF\xBB\xBF" );

		// CSV header row.
		fputcsv( $output, array( 'Filename', 'Direct Link', 'Upload Date', 'File Size' ) );

		// Write each row of PDF data.
		foreach ( $pdfs as $pdf ) {
			fputcsv( $output, array(
				$pdf['filename'],
				$pdf['url'],
				$pdf['upload_date'],
				$pdf['file_size'],
			) );
		}

		// Rewind and return all CSV contents as a string.
		rewind( $output );
		return stream_get_contents( $output );
	}

	/**
	 * Converts a raw byte number into a human-readable format
	 * such as “123 KB” or “2.4 MB”.
	 */
	private function format_bytes( $bytes, $precision = 2 ) {
		$units = array( 'B', 'KB', 'MB', 'GB' );

		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );
		$bytes /= ( 1 << ( 10 * $pow ) );

		return round( $bytes, $precision ) . ' ' . $units[ $pow ];
	}
}
