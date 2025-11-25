(function($) {
	'use strict';

	// The PDFAuditor object collects all the behaviors for this tool
	// so the code stays organized and easy to maintain.
	var PDFAuditor = {

		// This keeps track of how each site’s table is currently sorted
		// (for example: by filename, by date, ascending or descending).
		sortState: {},

		// This runs when the page first loads.
        // It prepares the tool by finding important elements
        // and setting up what should happen when the user clicks things.
		init: function() {
			this.cacheSelectors();
			this.bindEvents();
		},

		// This remembers key parts of the page (like the accordion container)
        // so the script doesn't have to look them up repeatedly.
		cacheSelectors: function() {
			this.$accordion = $('#pdf-auditor-accordion');
		},

		// This sets up all the user interactions:
        // - opening and closing sites
        // - downloading CSV files
        // - sorting tables of PDFs
		bindEvents: function() {
			var self = this;

			// When a site’s header is clicked, open or close that section.
			this.$accordion.on('click', '.pdf-auditor-site-toggle', function(e) {
				e.preventDefault();
				self.toggleSite.call(this);
			});

			// When the CSV download button is clicked,
            // request the CSV for that site from the server.
			this.$accordion.on('click', '.pdf-auditor-download-csv', function(e) {
				e.preventDefault();
				self.downloadCSV.call(this);
			});

			// When the user clicks a column header,
            // the tool sorts the table by that column.
			this.$accordion.on('click', '.pdf-auditor-table th[data-sort]', function(e) {
				e.preventDefault();
				self.handleSort.call(this);
			});
		},

		// This opens or closes a site's section,
        // and loads PDF data the first time the site is opened.
		toggleSite: function() {
			var $button = $(this);
			var $site = $button.closest('.pdf-auditor-site');
			var $content = $site.find('.pdf-auditor-site-content');
			var siteId = $site.data('site-id');
			var isOpen = $content.is(':visible');
			var strings = pdfAuditorData.strings;

			// If the site is already open, close it.
			if (isOpen) {
				$content.slideUp(200);
				$button.attr('aria-expanded', false);

			} else {

				// If opening, indicate expanded status for accessibility.
				$button.attr('aria-expanded', true);
				
				// Only load PDFs the first time a site is opened.
				if ($content.find('.pdf-auditor-loading').length) {

					// Prevent the tool from loading the same data twice
                    // if the user double-clicks quickly.
					if ( $site.data('loading') ) {
						$content.slideDown(200);
						return;
					}

					$site.data('loading', true);

					// Show the "loading" message while we fetch the PDFs.
					$content.find('.pdf-auditor-loading p').text(strings.loadingPDFs);

					// Ask the server for PDFs belonging to this site.
					PDFAuditor.fetchPDFs(siteId, $content, $site);
				}
				
				// Open the site section visually.
				$content.slideDown(200);
			}
		},

		// This sends a background request to the server to get the list of PDFs
        // for the selected site. When the data comes back, it displays the table.
		fetchPDFs: function(siteId, $container, $site) {
			var self = this;
			var strings = pdfAuditorData.strings;

			$.ajax({
				url: pdfAuditorData.ajaxUrl, // WordPress AJAX endpoint
				type: 'POST',
				data: {
					action: 'pdf_auditor_get_site_pdfs',
					nonce: pdfAuditorData.nonce,
					site_id: siteId
				},

				// Runs if the server responds successfully.
				success: function(response) {
					if (response.success && response.data) {
						// Create the table of PDFs.
						self.renderPDFTable(response.data, $container);
					} else if (response.data && response.data.message) {
						// The server responded, but with an error message.
						$container.html('<p class="error">' + response.data.message + '</p>');
					} else {
						// Unexpected or malformed response from the server.
						$container.html('<p class="error">' + strings.invalidResponse + '</p>');
					}
				},

				// Runs if the request fails (for example, network issues).
				error: function(jqXHR, textStatus) {
					if (textStatus === 'error' && jqXHR.status === 403) {
						$container.html('<p class="error">' + strings.permissionDenied + '</p>');
					} else if (textStatus === 'error') {
						$container.html('<p class="error">' + strings.networkError + '</p>');
					} else {
						$container.html('<p class="error">' + strings.errorLoading + '</p>');
					}
				},

				// Always runs after success or error.
                // Used to remove the “loading” lock on the site.
				complete: function() {
					if ( $site ) {
						$site.data('loading', false);
					}
				}
			});
		},

		// This builds the HTML table that shows all PDFs for a site.
        // It creates the CSV button and the sortable table structure.
		renderPDFTable: function(data, $container) {
			var html = '';
			var strings = pdfAuditorData.strings;

			// If the site has no PDFs, show a friendly message.
			if (data.count === 0) {
				html = '<p class="no-pdfs">' + strings.noPDFs + '</p>';
				$container.html(html);
				return;
			}

			// Add the CSV download button.
			html += '<div class="pdf-auditor-controls">';
			html += '<button type="button" class="pdf-auditor-download-csv" data-site-id="' + data.site_id + '">';
			html += strings.downloadCSV;
			html += '</button>';
			html += '</div>';

			// Build the table header.
			html += '<table class="pdf-auditor-table">';
			html += '<thead><tr>';
			html += '<th data-sort="filename">Filename <span class="sort-indicator"></span></th>';
			html += '<th>Direct Link</th>';
			html += '<th data-sort="upload_date">Upload Date <span class="sort-indicator">▼</span></th>';
			html += '<th data-sort="file_size_raw" class="file-size">File Size <span class="sort-indicator"></span></th>';
			html += '</tr></thead>';
			html += '<tbody>';

			// Add a row for each PDF.
            // Each value is escaped before being added for safety.
			$.each(data.pdfs, function(index, pdf) {
				html += '<tr>';
				html += '<td class="filename">' + $('<div>').text(pdf.filename).html() + '</td>';
				html += '<td class="url"><a href="' + $('<div>').text(pdf.url).html() + '" target="_blank">' + strings.viewPDF + '</a></td>';
				html += '<td class="upload-date">' + $('<div>').text(pdf.upload_date).html() + '</td>';
				html += '<td class="file-size" data-size-raw="' + pdf.file_size_raw + '">' + $('<div>').text(pdf.file_size).html() + '</td>';
				html += '</tr>';
			});

			html += '</tbody>';
			html += '</table>';

			// Insert the completed table into the page.
			$container.html(html);
		},

		// This handles sorting when the user clicks one of the sortable column headers.
        // It reads the table as it appears, sorts the rows, and redraws them.
		handleSort: function() {
			var $header = $(this);
			var $table = $header.closest('.pdf-auditor-table');
			var $container = $table.closest('.pdf-auditor-site-content');
			var sortKey = $header.data('sort');
			var $siteElement = $container.closest('.pdf-auditor-site');
			var siteId = $siteElement.data('site-id');
			var strings = pdfAuditorData.strings;

			// Make sure this site has a sortState entry.
			if (!PDFAuditor.sortState[siteId]) {
				PDFAuditor.sortState[siteId] = {};
			}

			// Determine whether we're sorting ascending or descending.
			var currentSort = PDFAuditor.sortState[siteId];
			var direction = 'asc';

			if (currentSort.key === sortKey && currentSort.direction === 'asc') {
				direction = 'desc';
			}

			PDFAuditor.sortState[siteId] = { key: sortKey, direction: direction };

			// Collect the current table data into an array.
			var pdfs = [];
			$table.find('tbody tr').each(function() {
				var $row = $(this);
				pdfs.push({
					filename: $row.find('.filename').text(),
					url: $row.find('.url a').attr('href'),
					upload_date: $row.find('.upload-date').text(),
					file_size: $row.find('.file-size').text(),
					file_size_raw: parseInt( $row.find('.file-size').data('size-raw'), 10 ) || 0
				});
			});

			// Perform the sorting based on the selected column.
			pdfs.sort(function(a, b) {
				var aVal, bVal;

				switch(sortKey) {
					case 'filename':
						// Use localeCompare with numeric option for natural sorting
						// (file1, file2, file10 instead of file1, file10, file2)
						aVal = a.filename.localeCompare(b.filename, undefined, { numeric: true });
						return direction === 'asc' ? aVal : -aVal;
					case 'upload_date':
						aVal = new Date(a.upload_date).getTime();
						bVal = new Date(b.upload_date).getTime();
						break;
					case 'file_size_raw':
						aVal = a.file_size_raw;
						bVal = b.file_size_raw;
						break;
					default:
						return 0;
				}

				// For non-filename sorts, use standard comparison
				if (direction === 'asc') {
					return aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
				} else {
					return aVal > bVal ? -1 : aVal < bVal ? 1 : 0;
				}
			});

			// Update the little arrow icons to show sort direction.
			$table.find('th[data-sort] .sort-indicator').text('');
			var $activateHeader = $table.find('th[data-sort="' + sortKey + '"]');
			$activateHeader.find('.sort-indicator').text(direction === 'asc' ? ' ▲' : ' ▼');

			// Rebuild the table body using the newly sorted data.
			var html = '';
			$.each(pdfs, function(index, pdf) {
				html += '<tr>';
				html += '<td class="filename">' + $('<div>').text(pdf.filename).html() + '</td>';
				html += '<td class="url"><a href="' + $('<div>').text(pdf.url).html() + '" target="_blank">' + strings.viewPDF + '</a></td>';
				html += '<td class="upload-date">' + $('<div>').text(pdf.upload_date).html() + '</td>';
				html += '<td class="file-size" data-size-raw="' + pdf.file_size_raw + '">' + $('<div>').text(pdf.file_size).html() + '</td>';
				html += '</tr>';
			});

			$table.find('tbody').html(html);
		},

		// When the user clicks “Download CSV,” this sends a request
        // to the server asking for the CSV file for that site.
		downloadCSV: function() {
			var $button = $(this);
			var siteId = $button.data('site-id');
			var strings = pdfAuditorData.strings;

			// Disable the button and show a “working” message
            // so the user knows something is happening.
			$button.prop('disabled', true).text(strings.downloading);

			$.ajax({
				url: pdfAuditorData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'pdf_auditor_download_csv',
					nonce: pdfAuditorData.nonce,
					site_id: siteId
				},

				// If the server returns the CSV successfully,
                // start the download.
				success: function(response) {
					if (response.success && response.data && response.data.csv_content) {
						PDFAuditor.triggerDownload(response.data.csv_content, response.data.filename);
						$button.prop('disabled', false).text(strings.downloadCSV);
					} else {
						alert(strings.invalidResponse);
						$button.prop('disabled', false).text(strings.downloadCSV);
					}
				},

				// If there’s an error, alert the user.
				error: function(jqXHR) {
					if (jqXHR.status === 403) {
						alert(strings.permissionDenied);
					} else {
						alert(strings.errorGenerating);
					}
					$button.prop('disabled', false).text(strings.downloadCSV);
				}
			});
		},

		// This creates a temporary invisible link on the page
        // and clicks it automatically so the CSV downloads cleanly.
		triggerDownload: function(csvContent, filename) {
			var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
			var link = document.createElement('a');
			var url = URL.createObjectURL(blob);

			link.setAttribute('href', url);
			link.setAttribute('download', filename);
			link.style.visibility = 'hidden';

			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);
		}
	};

	// When the page is ready, start the PDFAuditor tool.
	$(document).ready(function() {
		PDFAuditor.init();
	});

})(jQuery);
