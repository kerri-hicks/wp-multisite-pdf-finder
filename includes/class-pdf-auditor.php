<?php
/**
 * Main PDF Auditor class
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDF_Auditor {

	/**
	 * Single instance of the class
	 *
	 * @var PDF_Auditor
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'network_admin_menu', array( $this, 'register_network_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_pdf_auditor_get_site_pdfs', array( $this, 'ajax_get_site_pdfs' ) );
		add_action( 'wp_ajax_pdf_auditor_download_csv', array( $this, 'ajax_download_csv' ) );
	}

	/**
	 * Register the network admin page
	 */
	public function register_network_admin_page() {
		add_menu_page(
			__( 'PDF Auditor', 'pdf-auditor' ),
			__( 'PDF Auditor', 'pdf-auditor' ),
			'manage_network',
			'pdf-auditor',
			array( $this, 'render_admin_page' ),
			'dashicons-pdf',
			25
		);
	}

	/**
	 * Enqueue CSS and JS
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_pdf-auditor' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'pdf-auditor-css',
			PDF_AUDITOR_PLUGIN_URL . 'assets/css/pdf-auditor.css?v=123',
			array(),
			PDF_AUDITOR_VERSION
		);

		wp_enqueue_script(
			'pdf-auditor-js',
			PDF_AUDITOR_PLUGIN_URL . 'assets/js/pdf-auditor.js',
			array( 'jquery' ),
			PDF_AUDITOR_VERSION,
			true
		);

		wp_localize_script(
			'pdf-auditor-js',
			'pdfAuditorData',
			array(
				'nonce'             => wp_create_nonce( 'pdf_auditor_nonce' ),
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'strings'           => array(
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
	 * Render the admin page
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'pdf-auditor' ) );
		}

		$sites = get_sites(
			array(
				'number' => 999,
				'fields' => 'ids',
			)
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PDF Auditor', 'pdf-auditor' ); ?></h1>
			<p><?php esc_html_e( 'Scan your multisite for PDFs. Click to expand a site and view its PDF inventory.', 'pdf-auditor' ); ?></p>

			<div id="pdf-auditor-accordion" class="pdf-auditor-accordion">
				<?php foreach ( $sites as $site_id ) : ?>
					<?php $site = get_site( $site_id ); ?>
					<div class="pdf-auditor-site" data-site-id="<?php echo absint( $site_id ); ?>">
						<button class="pdf-auditor-site-toggle" type="button">
							<span class="toggle-icon">▶</span>
							<span class="site-name"><?php echo esc_html( $site->blogname ); ?></span>
							<span class="site-url"><?php echo esc_html( $site->domain . $site->path ); ?></span>
						</button>
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
	 * AJAX: Get PDFs for a specific site
	 */
	public function ajax_get_site_pdfs() {
		check_ajax_referer( 'pdf_auditor_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_network' ) ) {
			wp_send_json_error( __( 'Permission denied', 'pdf-auditor' ), 403 );
		}

		if ( ! isset( $_POST['site_id'] ) ) {
			wp_send_json_error( __( 'Site ID not provided', 'pdf-auditor' ), 400 );
		}

		$site_id = absint( $_POST['site_id'] );
		$pdfs    = array();

		try {
			// Switch to the site
			switch_to_blog( $site_id );

			global $wpdb;

			// Query PDFs directly from database
			$attachments = $wpdb->get_results(
				"SELECT ID, post_title, post_mime_type, post_date FROM {$wpdb->posts} 
				 WHERE post_type = 'attachment' 
				 AND post_mime_type = 'application/pdf'
				 ORDER BY post_date DESC"
			);

			if ( ! empty( $attachments ) ) {
				foreach ( $attachments as $attachment ) {
					$file_url  = wp_get_attachment_url( $attachment->ID );
					$file_path = get_attached_file( $attachment->ID );
					$file_size = filesize( $file_path );

					$pdfs[] = array(
						'id'           => $attachment->ID,
						'filename'     => wp_basename( $file_path ),
						'url'          => $file_url,
						'upload_date'  => $attachment->post_date,
						'file_size'    => $this->format_bytes( $file_size ),
						'file_size_raw' => $file_size,
					);
				}
			}
		} finally {
			// Always restore the blog context
			restore_current_blog();
		}

		wp_send_json_success(
			array(
				'site_id' => $site_id,
				'pdfs'    => $pdfs,
				'count'   => count( $pdfs ),
			)
		);
	}

	/**
	 * AJAX: Download CSV for a site
	 */
	public function ajax_download_csv() {
		check_ajax_referer( 'pdf_auditor_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_network' ) ) {
			wp_send_json_error( __( 'Permission denied', 'pdf-auditor' ), 403 );
		}

		if ( ! isset( $_POST['site_id'] ) ) {
			wp_send_json_error( __( 'Site ID not provided', 'pdf-auditor' ), 400 );
		}

		$site_id = absint( $_POST['site_id'] );
		$site    = get_site( $site_id );
		$pdfs    = array();

		try {
			// Switch to the site
			switch_to_blog( $site_id );

			global $wpdb;

			// Query PDFs directly from database
			$attachments = $wpdb->get_results(
				"SELECT ID, post_title, post_mime_type, post_date FROM {$wpdb->posts} 
				 WHERE post_type = 'attachment' 
				 AND post_mime_type = 'application/pdf'
				 ORDER BY post_date DESC"
			);

			if ( ! empty( $attachments ) ) {
				foreach ( $attachments as $attachment ) {
					$file_url  = wp_get_attachment_url( $attachment->ID );
					$file_path = get_attached_file( $attachment->ID );
					$file_size = filesize( $file_path );

					$pdfs[] = array(
						'filename'    => wp_basename( $file_path ),
						'url'         => $file_url,
						'upload_date' => $attachment->post_date,
						'file_size'   => $this->format_bytes( $file_size ),
					);
				}
			}
		} finally {
			// Always restore the blog context
			restore_current_blog();
		}

		// Generate CSV
		$csv_content = $this->generate_csv( $pdfs );
		$filename    = sanitize_file_name( $site->blogname . ' - WordPress PDF listing - ' . gmdate( 'Y-m-d' ) . '.csv' );

		// Return CSV as downloadable
		wp_send_json_success(
			array(
				'csv_content' => $csv_content,
				'filename'    => $filename,
			)
		);
	}

	/**
	 * Generate CSV content
	 */
	private function generate_csv( $pdfs ) {
		$output = fopen( 'php://memory', 'r+' );

		// Header row
		fputcsv( $output, array( 'Filename', 'Direct Link', 'Upload Date', 'File Size' ) );

		// Data rows
		foreach ( $pdfs as $pdf ) {
			fputcsv( $output, array(
				$pdf['filename'],
				$pdf['url'],
				$pdf['upload_date'],
				$pdf['file_size'],
			) );
		}

		rewind( $output );
		return stream_get_contents( $output );
	}

	/**
	 * Format bytes to human-readable
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
