# Table Upload Manager — Developer Reference

## Overview
WordPress plugin that lets **admin-approved users** upload CSV / TSV / XLSX files and display beautifully formatted, filterable, sortable tables on any front-end page — without requiring back-end access.

---

## Plugin Metadata
| Key | Value |
|-----|-------|
| Slug | `table-upload-manager` |
| Version | `1.0.0` |
| Requires WP | 5.8+ |
| Requires PHP | 7.4+ |
| Text Domain | `table-upload-manager` |
| Shortcode | `[table_manager]` |

---

## File Structure
```
table-upload-manager/
├── table-upload-manager.php          # Main plugin bootstrap
├── uninstall.php                     # Clean uninstall (drops all tables)
├── .gitignore
├── code.md                           # This file
│
├── includes/
│   ├── class-tum-activator.php       # activate / deactivate hooks
│   ├── class-tum-database.php        # All SQL via $wpdb->prepare()
│   ├── class-tum-security.php        # Nonces, caps, sanitization
│   ├── class-tum-file-parser.php     # CSV / TSV / XLSX → array
│   ├── class-tum-user-manager.php    # Approval + user search helpers
│   ├── class-tum-table-manager.php   # Business logic layer
│   └── class-tum-ajax-handler.php    # wp_ajax_* endpoints
│
├── admin/
│   ├── class-tum-admin.php           # Admin menu + asset enqueue
│   ├── views/
│   │   ├── dashboard.php             # Stats + shortcode helper
│   │   ├── users.php                 # Approve / revoke users
│   │   └── tables.php               # View / delete all tables
│   ├── css/admin.css
│   └── js/admin.js
│
└── public/
    ├── class-tum-public.php          # Shortcode registration + assets
    ├── views/main.php                # HTML shell (JS fills content)
    ├── css/public.css                # CSS-variable–driven theming
    └── js/public.js                  # Full SPA-style frontend (TUM namespace)
```

---

## Database Schema

### `wp_tum_approved_users`
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | |
| user_id | BIGINT UNIQUE | WP user ID |
| approved_by | BIGINT | Admin who approved |
| approved_at | DATETIME | UTC |

### `wp_tum_tables`
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | |
| title | VARCHAR(255) | |
| description | TEXT | |
| owner_id | BIGINT | |
| status | VARCHAR(20) | `active` \| `deleted` |
| sort_order | INT | Tab ordering |
| created_at / updated_at | DATETIME | |

### `wp_tum_table_data`
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | |
| table_id | BIGINT FK | |
| headers | LONGTEXT | JSON array |
| rows | LONGTEXT | JSON 2-D array |
| row_count / column_count | INT | Cached counts |
| uploaded_at | DATETIME | |
| uploaded_by | BIGINT | |

### `wp_tum_formatting`
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | |
| table_id | BIGINT UNIQUE FK | |
| formatting_json | LONGTEXT | See formatting schema below |
| updated_at / updated_by | | |

### `wp_tum_permissions`
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | |
| table_id | BIGINT | |
| user_id | BIGINT | |
| granted_by / granted_at | | Owner who granted |
| UNIQUE | (table_id, user_id) | |

---

## Formatting JSON Schema
```json
{
  "colorScheme": "ocean-blue",
  "colors": {
    "headerBg":   "#1e3a5f",
    "headerText": "#ffffff",
    "rowBg":      "#ffffff",
    "altRowBg":   "#f0f4f8",
    "borderColor":"#c7d7e8",
    "hoverBg":    "#e0eaf5",
    "accentColor":"#2563eb"
  },
  "typography": {
    "fontSize": 14,
    "headerFontWeight": "600",
    "textAlign": "left"
  },
  "layout": {
    "stripedRows":  true,
    "hoverEffect":  true,
    "borderStyle":  "full",
    "compactMode":  false,
    "frozenHeader": true,
    "pagination":   true,
    "rowsPerPage":  25
  },
  "columnVisibility": { "2": false },
  "sortable": [0, 1, 2],
  "filters": {
    "enabled": true,
    "columns": {
      "1": { "enabled": true, "type": "text",   "placeholder": "Search…" },
      "2": { "enabled": true, "type": "select",  "placeholder": "" },
      "3": { "enabled": true, "type": "number-range", "placeholder": "" }
    }
  }
}
```

**Allowed values**
- `colorScheme`: `ocean-blue | forest-green | royal-purple | sunset-orange | midnight-dark | clean-white | custom`
- `layout.borderStyle`: `full | horizontal | none`
- `filters.columns[n].type`: `none | text | select | number-range | date-range`

---

## AJAX Actions (all require nonce `tum_nonce`)

| Action | Auth required | Description |
|--------|--------------|-------------|
| `tum_get_tables` | logged-in | List metadata for all tables |
| `tum_get_table` | logged-in | Full payload inc. rows |
| `tum_create_table` | approved | Create new table record |
| `tum_upload_file` | table editor | Parse & store file data |
| `tum_save_formatting` | table editor | Update formatting JSON |
| `tum_delete_table` | table owner | Hard delete table + data |
| `tum_grant_permission` | table owner | Add editor to table |
| `tum_revoke_permission` | table owner | Remove editor from table |
| `tum_get_permissions` | table owner | List editors |
| `tum_search_users` | logged-in | User search for permission picker |
| `tum_admin_approve_user` | admin | Approve global user |
| `tum_admin_unapprove_user` | admin | Revoke global approval |
| `tum_admin_get_users` | admin | All users with status |
| `tum_admin_delete_table` | admin | Force-delete any table |

---

## Permission Hierarchy
```
Administrator  →  full access (implied approval)
       ↓
Globally Approved User  →  can create tables, manage their own tables
       ↓
Table-level Editor  →  can upload data + change formatting for one table
       ↓
Regular Logged-in User  →  read-only view of all tables
```

---

## Security Measures
- **Nonce verification** on every AJAX call (`wp_verify_nonce`)
- **Capability checks** before each privileged action
- **All SQL** via `$wpdb->prepare()` — zero raw interpolation
- **File uploads** validated by extension + finfo MIME check; parsed into DB and temp file discarded
- **All output** escaped with `esc_html()`, `esc_attr()`, `wp_json_encode()`
- **Formatting JSON** sanitized through a strict whitelist function (`TUM_Security::sanitize_formatting()`)
- **Cell data** stripped of HTML tags, limited to 1000 chars per cell
- **Headers** stripped of HTML tags, limited to 255 chars
- CSS scoped to `#tum-app` / `.tum-modal-overlay` — no global style leakage

---

## Shortcode Usage
```
[table_manager]
```
Place on any page or post. Logged-in users see all active tables in a tabbed interface. Approved users see a "+ New Table" button.

---

## Supported File Formats
| Format | Extension | Parser |
|--------|-----------|--------|
| CSV | `.csv` | PHP `fgetcsv()` |
| Tab-separated | `.tsv` | PHP `fgetcsv()` with `\t` delimiter |
| Excel 2007+ | `.xlsx` | PHP `ZipArchive` + `SimpleXMLElement` |

**Limits:** 5 MB file size, 10,000 rows max.

---

## Color Schemes
| Key | Label |
|-----|-------|
| `ocean-blue` | Ocean Blue (default) |
| `forest-green` | Forest Green |
| `royal-purple` | Royal Purple |
| `sunset-orange` | Sunset Orange |
| `midnight-dark` | Midnight Dark |
| `clean-white` | Clean White |
| `custom` | Custom (color pickers) |

---

## Installation & Activation
1. Upload plugin folder to `/wp-content/plugins/`
2. Activate in WP Admin → Plugins
3. Go to **Table Manager → Approved Users** to approve users
4. Add `[table_manager]` shortcode to any page
5. Approved users can create and upload tables from the front end

---

## Future Improvements
- [ ] Export table as CSV / XLSX from frontend
- [ ] Chart / graph view toggle
- [ ] Conditional row highlighting (rules-based)
- [ ] Column data type detection (number, date, currency)
- [ ] REST API endpoints (in addition to AJAX)
- [ ] Bulk import via WP CLI
- [ ] Table versioning / rollback
- [ ] Public (non-login) view mode per table
- [ ] Column freeze (sticky left columns)
- [ ] Date-range filter UI component
- [ ] Email notification when a table is updated

---

## Changelog

### 1.0.2 — Upload Save Fix
- **Fix:** Auto-create / upgrade database tables on `plugins_loaded` when `tum_db_version` option is missing or stale — handles FTP deployments where `register_activation_hook` never fires.
- **Fix:** `save_table_data()` now guards `wp_json_encode()` return values; a `false` return (encoding failure) is caught and logged before attempting the INSERT.
- **Fix:** `sanitize_cell_value()` now strips 4-byte UTF-8 characters (emoji, rare CJK extensions) that MySQL's legacy `utf8` charset cannot store, preventing silent INSERT failures on shared hosting.
- **Fix:** Added `error_log()` calls with full MySQL error and JSON error details so server administrators can diagnose issues in the PHP error log.
- **Improvement:** Upload failure message is now descriptive and actionable rather than a generic string.

### 1.0.1 — Panel Visibility Fix
- **Fix:** Removed `<div class="tum-table-panel">` wrapper from inside the `<template>` element — CSS was hiding all panel content because the cloned inner div never received the `.active` class.
- **Fix:** Scoped panel CSS selectors with child combinator (`#tum-panels-container > .tum-table-panel`) to prevent unintended hiding of nested elements.
- **Fix:** Upload zone now auto-opens when a table has no data and the current user can edit it.
- Version bump to bust browser/CDN caches.

### 1.0.0 — Initial Release
- CSV, TSV, XLSX upload and parsing
- Tabbed table navigation on frontend
- 6 color scheme presets + custom colors
- Column show/hide, sort, filter (text, select, number-range)
- Pagination (10/25/50/100 rows per page)
- Compact mode, striped rows, hover highlight, border styles
- Formatting persists across data re-uploads
- Global user approval (admin)
- Per-table editor permissions (owner managed)
- Live formatting preview
- Full AJAX security (nonce + capability + prepared statements)
- Clean uninstall

---

## GitHub
```
git init
git add .
git commit -m "feat: initial release v1.0.0"
git remote add origin https://github.com/YOUR_USERNAME/table-upload-manager.git
git push -u origin main
```
