(function($) {
	'use strict';

	var PDFAuditor = {
		sortState: {},

		init: function() {
			this.cacheSelectors();
			this.bindEvents();
		},

		cacheSelectors: function() {
			this.$accordion = $('#pdf-auditor-accordion');
		},

		bindEvents: function() {
			var self = this;
			
			// Toggle site accordion
			this.$accordion.on('click', '.pdf-auditor-site-toggle', function(e) {
				e.preventDefault();
				self.toggleSite.call(this);
			});

			// CSV download button (delegated)
			this.$accordion.on('click', '.pdf-auditor-download-csv', function(e) {
				e.preventDefault();
				self.downloadCSV.call(this);
			});

			// Table header sorting
			this.$accordion.on('click', '.pdf-auditor-table th[data-sort]', function(e) {
				e.preventDefault();
				self.handleSort.call(this);
			});
		},

		toggleSite: function() {
			var $button = $(this);
			var $site = $button.closest('.pdf-auditor-site');
			var $content = $site.find('.pdf-auditor-site-content');
			var siteId = $site.data('site-id');
			var isOpen = $content.is(':visible');
			var strings = pdfAuditorData.strings;

			// Toggle the content
			if (isOpen) {
				$content.slideUp(200);
				$button.find('.toggle-icon').text('▶');
				$button.attr('aria-expanded', false);
			} else {
				$button.find('.toggle-icon').text('▼');
				$button.attr('aria-expanded', true);
				
				// Check if we need to load the data
				if ($content.find('.pdf-auditor-loading').length) {
					// Prevent duplicate loads
					if ( $site.data('loading') ) {
						$content.slideDown(200);
						return;
					}

					$site.data('loading', true);
					// Update loading message to localized string
					$content.find('.pdf-auditor-loading p').text(strings.loadingPDFs);
					PDFAuditor.fetchPDFs(siteId, $content, $site);
				}
				
				$content.slideDown(200);
			}
		},

		fetchPDFs: function(siteId, $container, $site) {
			var self = this;
			var strings = pdfAuditorData.strings;

			$.ajax({
				url: pdfAuditorData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'pdf_auditor_get_site_pdfs',
					nonce: pdfAuditorData.nonce,
					site_id: siteId
				},
				success: function(response) {
					if (response.success && response.data) {
						self.renderPDFTable(response.data, $container);
					} else if (response.data && response.data.message) {
						$container.html('<p class="error">' + response.data.message + '</p>');
					} else {
						$container.html('<p class="error">' + strings.invalidResponse + '</p>');
					}
				},
				error: function(jqXHR, textStatus, errorThrown) {
					if (textStatus === 'error' && jqXHR.status === 403) {
						$container.html('<p class="error">' + strings.permissionDenied + '</p>');
					} else if (textStatus === 'error') {
						$container.html('<p class="error">' + strings.networkError + '</p>');
					} else {
						$container.html('<p class="error">' + strings.errorLoading + '</p>');
					}
				},
				complete: function() {
					// Clear loading flag after AJAX completes
					if ( $site ) {
						$site.data('loading', false);
					}
				}
			});
		},

		renderPDFTable: function(data, $container) {
			var html = '';
			var strings = pdfAuditorData.strings;

			if (data.count === 0) {
				html = '<p class="no-pdfs">' + strings.noPDFs + '</p>';
				$container.html(html);
				return;
			}

			// Table header
			html += '<div class="pdf-auditor-controls">';
			html += '<button type="button" class="pdf-auditor-download-csv" data-site-id="' + data.site_id + '">';
			html += strings.downloadCSV;
			html += '</button>';
			html += '</div>';

			html += '<table class="pdf-auditor-table">';
			html += '<thead><tr>';
			html += '<th data-sort="filename">Filename <span class="sort-indicator"></span></th>';
			html += '<th>Direct Link</th>';
			html += '<th data-sort="upload_date">Upload Date <span class="sort-indicator">▼</span></th>';
			html += '<th data-sort="file_size_raw">File Size <span class="sort-indicator"></span></th>';
			html += '</tr></thead>';
			html += '<tbody>';

			// Table rows
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

			$container.html(html);
		},

		handleSort: function() {
			var $header = $(this);
			var $table = $header.closest('.pdf-auditor-table');
			var $container = $table.closest('.pdf-auditor-site-content');
			var sortKey = $header.data('sort');
			var $siteElement = $container.closest('.pdf-auditor-site');
			var siteId = $siteElement.data('site-id');
			var strings = pdfAuditorData.strings;

			// Initialize sort state for this site if needed
			if (!PDFAuditor.sortState[siteId]) {
				PDFAuditor.sortState[siteId] = {};
			}

			// Determine sort direction
			var currentSort = PDFAuditor.sortState[siteId];
			var direction = 'asc';

			if (currentSort.key === sortKey && currentSort.direction === 'asc') {
				direction = 'desc';
			}

			PDFAuditor.sortState[siteId] = { key: sortKey, direction: direction };

			// Get the PDFs data from the table
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

			// Sort the array
			pdfs.sort(function(a, b) {
				var aVal, bVal;

				switch(sortKey) {
					case 'filename':
						aVal = a.filename.toLowerCase();
						bVal = b.filename.toLowerCase();
						break;
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

				if (direction === 'asc') {
					return aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
				} else {
					return aVal > bVal ? -1 : aVal < bVal ? 1 : 0;
				}
			});

			// Update sort indicators
			$table.find('th[data-sort] .sort-indicator').text('');
			var $activateHeader = $table.find('th[data-sort="' + sortKey + '"]');
			$activateHeader.find('.sort-indicator').text(direction === 'asc' ? ' ▲' : ' ▼');

			// Re-render the table rows
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

		downloadCSV: function() {
			var $button = $(this);
			var siteId = $button.data('site-id');
			var strings = pdfAuditorData.strings;

			$button.prop('disabled', true).text(strings.downloading);

			$.ajax({
				url: pdfAuditorData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'pdf_auditor_download_csv',
					nonce: pdfAuditorData.nonce,
					site_id: siteId
				},
				success: function(response) {
					if (response.success && response.data && response.data.csv_content) {
						PDFAuditor.triggerDownload(response.data.csv_content, response.data.filename);
						$button.prop('disabled', false).text(strings.downloadCSV);
					} else {
						alert(strings.invalidResponse);
						$button.prop('disabled', false).text(strings.downloadCSV);
					}
				},
				error: function(jqXHR, textStatus, errorThrown) {
					if (jqXHR.status === 403) {
						alert(strings.permissionDenied);
					} else {
						alert(strings.errorGenerating);
					}
					$button.prop('disabled', false).text(strings.downloadCSV);
				}
			});
		},

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

	$(document).ready(function() {
		PDFAuditor.init();
	});

})(jQuery);
