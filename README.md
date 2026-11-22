# Skyrocket redirects

A lightweight WordPress plugin that manages exact-path `301` (Moved Permanently) and `410` (Gone) redirects from a validated CSV file. It hooks into `template_redirect` at priority `1` to evaluate matching paths before theme templates render, while providing a basic management screen under **Tools > Site Redirects**.

---

## Features

* **Strict CSV validation on upload:** Checks file size (2 MB cap), `.csv` extension, UTF-8 BOM stripping, header order (`old_url,new_url,status`), status codes (`301` or `410`), same-domain path normalisation, duplicate `old_url` detection, loop prevention on `301` rules, and blank `new_url` enforcement on `410` rules.
* **Atomic rule replacement:** Invalid CSV uploads are rejected before overwriting the active `redirects.csv` file.
* **Path tester:** Lets administrators check whether a path matches an active rule without triggering a redirect.
* **Optional rotating log:** Records matched `301` and `410` events in a tab-separated log file (`redirects.log`) with automatic single-file rotation (`redirects.log.old`) once it exceeds roughly 1 MB.
* **Context safety:** Skips admin screens, Ajax requests, WP-Cron, and REST API requests.

---

## Requirements

* **WordPress:** Tested with standard WordPress hooks (`admin_menu`, `admin_post_*`, `template_redirect`, `wp_upload_dir`, `wp_safe_redirect`, `status_header`, `nocache_headers`).
* **PHP:** PHP 8.0+ recommended (`str_starts_with`, typed properties and return types).
* **Permissions:** `manage_options` capability (typically **Administrator**) to view the management page, upload CSVs, test paths, and toggle logging.

---

## Installation

1. Save the plugin file as `wp-content/plugins/site-redirects-csv/site-redirects-csv.php`.
2. Log in to the WordPress admin dashboard and go to **Plugins > Installed Plugins**.
3. Activate **Site Redirects (CSV)**.
4. Navigate to **Tools > Site Redirects** to upload your first CSV file.

> **Note:** On first upload, the plugin creates `wp-content/uploads/site-redirects/` along with an `index.php` file and a basic `.htaccess` rule to block direct HTTP access to `.csv` and `.log` files on Apache servers. If you run Nginx or another web server, configure your server block to deny access to `wp-content/uploads/site-redirects/` directly.

---

## CSV format and rules

The CSV must use UTF-8 encoding (with or without a BOM) and begin with the exact header row:

```csv
old_url,new_url,status

```

### Column specifications

| Column | Required | Rules |
| --- | --- | --- |
| `old_url` | Yes | Must be a non-empty relative path (e.g. `/old-page/`) or a same-domain absolute URL (e.g. `[https://example.com/old-page/](https://example.com/old-page/)`). Must not contain a query string (`?`) or fragment (`#`). Duplicates are rejected. |
| `new_url` | Conditional | Required for `301` rules; must resolve to a valid same-domain path and differ from the normalised `old_url`. Must be **blank** for `410` rules. |
| `status` | Yes | Must be `301` or `410`. |

### Example CSV

```csv
old_url,new_url,status
/legacy-news/,/news/,301
https://example.com/about-us/company/,/about/,301
/discontinued-product/,,410
/old-campaign/,/campaigns/current/,301

```

### Path normalisation and query strings

* **Trailing slashes:** Paths are normalised so `/old-page/` and `/old-page` resolve to `/old-page` (while `/` remains `/`). Matching ignores trailing slashes.
* **Query strings:** Incoming query strings on `301` requests are appended to the destination URL if the destination does not already contain `?` (for example, requesting `/legacy-news/?page=2` redirects to `/news/?page=2`). Because `old_url` rules match on path only, you cannot define separate rules for different query parameters on the same path.
* **410 responses:** Sends a `410 Gone` status header and `nocache_headers()`, then renders the theme's `404` template if one exists (falling back to `wp_die()` with HTTP `410`).

---

## Admin interface

Found under **Tools > Site Redirects** (`tools.php?page=site-redirects-csv`):

1. **Upload or replace CSV:** Validates the uploaded `.csv` file (up to 2 MB). If any row fails validation, none of the rules are installed and the existing CSV remains untouched.
2. **Test a path:** Accepts a path or same-domain URL and reports whether it matches a `301` destination, a `410` rule, or no rule at all.
3. **Logging:** Toggles the `site_redirects_csv_logging` option (`0` by default). When enabled, each matched request appends a tab-separated line to `wp-content/uploads/site-redirects/redirects.log`:

```text
2026-11-22T22:13:00+00:00	301	/legacy-news	https://example.com/news
2026-11-22T22:14:12+00:00	410	/discontinued-product	

```

---

## Architecture and internals

The entire plugin is encapsulated in the final `Site_Redirects_CSV` class:

* **Hooks registered in `__construct()`:**
* `admin_menu` -> `add_admin_page()` registers the management page under **Tools**.
* `admin_post_site_redirects_upload` -> `handle_upload()` processes file uploads with nonce and capability checks.
* `admin_post_site_redirects_test` -> `handle_test()` evaluates a test path against the stored CSV.
* `admin_post_site_redirects_settings` -> `handle_settings()` saves the logging preference.
* `template_redirect` (priority `1`) -> `maybe_redirect()` evaluates incoming front-end requests.


* **In-memory caching:** Parsed rules are stored in `self::$rules` for the duration of a single request. Note that if `parse_csv()` encounters any error on the stored file, `get_rules()` returns an empty array so partial or invalid rule sets are never applied.
* **Storage locations:**
* Active rules: `wp-content/uploads/site-redirects/redirects.csv`
* Redirect log: `wp-content/uploads/site-redirects/redirects.log` (and `redirects.log.old` after rotation)
* Admin notices: Short-lived per-user transients (`site_redirects_notice_{user_id}`, 60-second TTL) cleared on render.



---

## Limitations and operational notes

* **Exact-path matching only:** Wildcards, regex patterns, and prefix/directory catch-alls are not supported. Every redirected path must be listed explicitly.
* **No UI row editor:** Adding, editing, or removing individual redirects requires editing the CSV locally and re-uploading it.
* **Large CSVs:** Because the CSV is parsed with `fgetcsv()` in PHP on requests that need rules, keep files compact and well under the 2 MB upload limit; for tens of thousands of redirects on high-traffic sites, server-level redirects (Nginx/Apache) are typically faster.
* **Uninstall cleanup:** The plugin does not currently include an `uninstall.php` routine, so `redirects.csv`, `redirects.log`, and the `site_redirects_csv_logging` option remain in place if the plugin is deleted unless removed manually.

## Building a release

The plugin uses [Skyrocket Plugin Updater](https://github.com/lavamitts/skyrocket-plugin-updater) as a Composer dependency.

The updater dependency also provides the distribution build script, which prepares the plugin files for release.

After installing the Composer dependencies, run the following command from the plugin root directory:

```bash
./vendor/lavamitts/skyrocket-plugin-updater/tools/build-distribution.sh
```

This builds the release candidate, preparing a distributable version of the plugin without requiring the development dependency structure to be included in the release.

The resulting distribution package is intended for installation through the standard WordPress plugin installation process.
