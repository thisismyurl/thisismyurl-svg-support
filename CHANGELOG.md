# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
the `x.Yddd` versioning scheme (`x` = release class, `Y` = year last digit,
`ddd` = Julian day).

## 1.6190.1670 — 2026-07-09

### Changed
- Suite core refactor — Vortops client and settings UI moved to shared `class-timu-suite-core.php`. Single canonical file synced across all thisismyurl plugins.
- Vortops postbox now rendered by `TIMU_Suite_Settings::render_vortops_postbox()`.

## 1.6190.1630 — 2026-07-09

### Added
- **Vortops cloud SVG sanitization** — if the local `enshrined/svg-sanitize` library is unavailable, connecting a Vortops API key enables cloud SVG sanitization as a fallback. Local sanitization is always preferred; Vortops is the fallback, not the default.
- Vortops Settings postbox with API key field, test-connection button, and honest capability messaging (different text when local sanitizer is vs. isn't available).
- `TIMU_Vortops_Client` shared client class (`includes/class-timu-vortops-client.php`).

### Fixed
- `TIMU_SVG_VERSION` constant was out of sync with the plugin header (was `1.6165.0822`); corrected to `1.6190.1630`.

## [1.6190.1530] — 2026-07-09

### Fixed
- Resolved fatal "Cannot redeclare TIMU_SVG_Support::enqueue_admin_assets()" from a merge collision; dead `register_hooks()` instance method removed, surviving static path wired correctly.
- `ajax_scan_existing` wired into `init()` so the Scan Existing SVGs AJAX handler is reachable.
- Removed `GitHub Plugin URI` and `Primary Branch` headers for WordPress.org directory compatibility.
- Version corrected from 0.x back to 1.x (major version had regressed in a prior merge).

## [0.6174.1641] — 2026-06-23

### Added

- **WP-CLI command `wp timu-svg scan-existing`** — retroactively sanitizes all
  SVG attachments in the Media Library. Queries `image/svg+xml` attachments
  via `WP_Query`, reads each file via `WP_Filesystem`, passes content through
  `TIMU_SVG_Sanitizer::sanitize_file()`, and outputs a per-file status line
  (`OK` / `SKIP` / `FAIL`). Supports `--dry-run` (report without writing) and
  `--batch-size=<n>` (default 25). Registered via `WP_CLI::add_command` as
  `TIMU_SVG_CLI`, loaded from `class-svg-cli.php` only when `WP_CLI` is true.
- **"Sanitize Existing SVGs" admin button** on the Settings > SVG Support
  screen. Triggers `wp_ajax_timu_svg_scan_existing`; processes 25 files per
  request with offset pagination until the server reports `has_more: false`.
  Requires `manage_options` capability and a `timu_svg_scan_existing` nonce
  (verified server-side with `wp_verify_nonce()`). Returns JSON with
  `processed`, `total`, `next_offset`, `has_more`, and `errors` fields.
  Per-file errors are displayed inline below the button.
- `js/timu-svg-scan.js` — vanilla jQuery AJAX driver for the scan button;
  enqueued via `wp_enqueue_script` on `admin_enqueue_scripts` only on the SVG
  Support settings page. Nonce and i18n strings injected via
  `wp_localize_script`.

### Security note

This release closes the documented gap in `SECURITY.md` under "Threats
explicitly NOT addressed": SVGs uploaded before the allowlist sanitizer was
active were never retroactively cleaned. Both the CLI command and the admin
button now provide an auditable path to close that gap.

## [0.6123] — 2026-05-03

### Security

- **Replaced the self-rolled denylist SVG sanitizer with the allowlist-based
  [`enshrined/svg-sanitize`](https://github.com/darylldoyle/svg-sanitize)
  library** (bundled in `vendor/`). Addresses **GHSA-wmc2-4458-vm72** (HIGH):
  the prior denylist had multiple known bypass classes that could land an
  XSS payload via SVG upload. Allowlist semantics close that surface.
- Added `finfo_file()` server-side MIME validation before sanitization runs.
  Disguised payloads (HTML/JS named `.svg`) are rejected early.
- Sandboxed the Media Library SVG preview by replacing inline `<img src>`
  rendering with `<iframe sandbox="" referrerpolicy="no-referrer">`. Cookie
  and JS exfil from a pre-sanitization or edge-case payload is contained.

### Added

- Per-role upload allowlist with a new `upload_svg_files` capability granted
  to roles selected on the settings screen. Default: `administrator` only.
- Sanitization-failure log (`timu_svg_failure_log` option, capped at 50
  most-recent entries) recording filename, reason, user ID, and IP for
  incident response.
- `CHANGELOG.md`, `CODEOWNERS`, `.github/dependabot.yml`, `tests/`, and
  `languages/` scaffolding.

### Changed

- Settings page moved from `Tools > SVG Support` to `Settings > SVG Support`,
  matching the README claim. Plugin row "Settings" link updated.
- Bumped `Requires PHP` from `7.4` to `8.1` (matches `composer.json`).
- Replaced `file_get_contents()` / `file_put_contents()` calls with
  `WP_Filesystem` (direct transport pinned during the upload prefilter).
- Dropped `@gzdecode()` error suppression; explicit error-path branches.
- Strict comparison on the `enabled` option (`1 === (int) $value`).

### Fixed

- `register_activation_hook()` is now registered at file scope, not from
  inside `__construct()`. The prior registration timing meant defaults
  could silently fail to seed on first activation.
- `Stable tag` in `readme.txt` is aligned with the plugin header version.

## [1.6365] — 2026-12-30

- Documentation and profile alignment update (no functional changes).
