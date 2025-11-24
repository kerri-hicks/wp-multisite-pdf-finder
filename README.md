# PDF Auditor

A WordPress multisite plugin to scan and catalog all PDFs across your network, export metadata as CSV, and identify PDFs for accessibility remediation.

## Features

- **Network-wide PDF Scanning**: View all PDFs across your WordPress multisite in one place
- **Expandable Site List**: Accordion interface to view PDFs for each site individually
- **PDF Metadata**: Track filename, direct URL, upload date, and file size for each PDF
- **CSV Export**: Download PDF metadata for a site as a CSV file for further analysis
- **Accessibility Audit**: Identify inaccessible PDFs that need remediation
- **No PDF Downloads**: This tool catalogs metadata only; actual PDF downloads are a separate process

## Installation

1. Download or clone this plugin into your `/wp-content/plugins/` directory
2. Activate it from the **Network Admin → Plugins** menu (must network-activate)
3. Navigate to **Network Admin → PDF Auditor** to begin scanning

## Requirements

- WordPress Multisite enabled
- WordPress 5.0 or higher
- PHP 7.4 or higher
- Network admin access to use the tool

## Usage

### Accessing the Tool

1. Go to **Network Admin Dashboard**
2. Click on **PDF Auditor** in the sidebar
3. You'll see an accordion list of all sites in your network

### Scanning a Site for PDFs

1. Click on a site name to expand it
2. The plugin scans that site's media library for PDFs
3. A table appears showing:
   - **Filename**: The name of the PDF file
   - **Direct Link**: A link to view/download the PDF
   - **Upload Date**: When the PDF was added to the media library
   - **File Size**: Size of the PDF file

### Exporting PDF Data

1. With a site expanded, click the **"Download CSV for this site"** button
2. The CSV file will download with the filename format: `[Site Name] - WordPress PDF listing - [Date].csv`
3. Open the CSV in Excel or Google Sheets to analyze, sort, and track remediation

## CSV Export Format

The exported CSV contains four columns:

| Column | Description |
|--------|-------------|
| Filename | The PDF filename |
| Direct Link | URL to the PDF file |
| Upload Date | Date and time the PDF was uploaded |
| File Size | Size of the file (e.g., 2.5 MB) |

## Accessibility Remediation Workflow

Suggested workflow for improving PDF accessibility:

1. **Audit Phase** (This Tool)
   - Use PDF Auditor to catalog all PDFs
   - Export CSV files for each site
   - Review with your team to identify priority PDFs

2. **Analysis Phase** (External)
   - Test PDFs for accessibility issues
   - Mark PDFs as "to be kept," "to be deleted," or "to be remediated"
   - Track in your CSV files

3. **Remediation Phase** (Future Enhancement)
   - Re-create PDFs with accessible formatting
   - Upload new versions to replace old ones
   - Or delete if no longer needed


## Technical Details

### Database Queries
- The plugin queries only the `wp_posts` table with `post_type = 'attachment'` and `post_mime_type = 'application/pdf'`
- It uses WordPress's native `WP_Query` class for proper data handling

### Performance
- Each site scan loads only that site's PDFs
- Uses AJAX to prevent page timeout on large PDF collections
- No pagination limit (loads all PDFs for a site at once)

### Multisite Handling
- Properly uses `switch_to_blog()` and `restore_current_blog()` for multisite queries
- Respects user capabilities (requires network admin)
- Generates nonces for all AJAX requests

### CSV Generation
- Uses PHP's built-in `fputcsv()` for proper CSV encoding
- Handles special characters and quotes correctly
- Sanitizes filenames for safe downloads

## Troubleshooting

### "PDF Auditor requires WordPress Multisite to be enabled"
This plugin only works on multisite installations. It cannot be activated on single-site WordPress.

### No PDFs showing for a site
- The site may not have any PDFs in the media library
- Check that PDFs are actually uploaded (not just links to external PDFs)
- Ensure they're stored as `application/pdf` MIME type

### Large number of PDFs (100+)
- The table will load all PDFs for a site at once (no pagination)
- This is intentional for accessibility audit purposes
- Use your browser's Find function (Ctrl+F) to search within the table

### CSV doesn't download
- Check that JavaScript is enabled in your browser
- Verify you have network admin permissions
- Check browser console for errors (F12)

## File Structure

```
pdf-auditor/
├── pdf-auditor.php           # Main plugin file
├── includes/
│   └── class-pdf-auditor.php # Main plugin class
├── assets/
│   ├── css/
│   │   └── pdf-auditor.css   # Plugin styles
│   └── js/
│       └── pdf-auditor.js    # Plugin JavaScript
├── languages/                 # For future i18n
└── README.md                  # This file
```

## Security

- All AJAX requests include nonce verification
- Capability checks ensure only network admins can access the tool
- Data is properly escaped for safe output in HTML
- Uses `wp_get_attachment_url()` and `get_attached_file()` for safe file path handling

## License

PolyForm Noncommercial License 1.0.0

## Support & Contributing

For issues, questions, or contributions, please reach out to the plugin author.

## Changelog

### 1.0.0
- Initial release
- Network admin page with accordion site list
- Per-site PDF scanning and display
- CSV export functionality
- Responsive design
