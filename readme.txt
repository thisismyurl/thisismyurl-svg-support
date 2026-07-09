=== SVG Support by Christopher Ross ===
Contributors: thisismyurl
Donate link: https://github.com/sponsors/thisismyurl
Tags: svg, media library, sanitization, uploads, security
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.6174.1641
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Plugin URI: https://thisismyurl.com/thisismyurl-svg-support/
Author: Christopher Ross
Author URI: https://thisismyurl.com/

Safe SVG uploads for WordPress with allowlist sanitization, MIME validation, per-role permissions, and a sandboxed admin preview.

== Description ==

SVG Support enables secure SVG upload workflows in WordPress with hardened defaults, then gives you a Tools page to re-sanitize and minify the SVG files already in your Media Library.

= Key features =

* Enables `.svg` and `.svgz` uploads for trusted roles only
* Allowlist sanitization via the well-vetted [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer) library
* Server-side MIME validation with `finfo_file()` before sanitization runs
* Per-role upload allowlist configurable from Tools > SVG Support
* Tools > SVG Support page with Optimize, Settings, and Report tabs
* Bulk and single "sanitize and optimize": re-runs the allowlist sanitizer with minification on to strip unsafe content and cut file weight
* Per-file backups under `uploads/svg-backups/` with one-click restore
* Per-file report of what the sanitizer stripped — the transparency record security buyers want
* Sanitization-failure log (last 50 events) for incident response

= Practical benefits =

* Lets teams use lightweight, scalable graphics safely
* Keeps branding assets crisp across devices
* Improves workflow consistency for design and content teams

= EEAT and credibility =

Built by Christopher Ross, a WordPress development and technical SEO practice.

* WordPress.org profile: https://profiles.wordpress.org/thisismyurl/
* GitHub profile: https://github.com/thisismyurl
* Website: https://thisismyurl.com/

== Installation ==

1. Upload the plugin to `/wp-content/plugins/thisismyurl-svg-support/`.
2. Activate through the Plugins screen in WordPress.
3. Go to `Tools > SVG Support`.
4. On the Settings tab, choose which roles can upload SVG files.
5. On the Optimize tab, sanitize and minify the SVG files already in your library.

== Frequently Asked Questions ==

= Why does WordPress block SVG uploads by default? =
SVG can contain executable code (script, event handlers, foreign objects). This plugin adds allowlist sanitization, MIME validation, and per-role permission controls to mitigate that risk.

= Is sanitization automatic? =
Yes. Every uploaded SVG is sanitized via enshrined/svg-sanitize before it reaches the Media Library.

= Should all users upload SVG files? =
No. By default only Administrators are on the allowlist. Add other roles only after weighing the upload-trust risk.

= Where is the sanitization-failure log? =
It lives in the `timu_svg_failure_log` option. Inspect it with WP-CLI:
`wp option get timu_svg_failure_log --format=json`

== Changelog ==

= 0.6174.1641 =
* **Feature:** WP-CLI command `wp timu-svg scan-existing` — retroactively sanitizes all SVG attachments in the Media Library via `TIMU_SVG_Sanitizer::sanitize_file()`. Supports `--dry-run` and `--batch-size` options.
* **Feature:** "Sanitize Existing SVGs" button on the Settings > SVG Support screen. Runs AJAX batches of 25 files with nonce verification and `manage_options` capability gate; displays per-file progress and errors inline.

= 0.6123 =
* **Security:** Replaced the self-rolled denylist sanitizer with the allowlist-based `enshrined/svg-sanitize` library. Addresses GHSA-wmc2-4458-vm72.
* **Security:** Added `finfo_file()` server-side MIME validation before sanitization.
* **Security:** Sandboxed Media Library SVG preview in `<iframe sandbox="">` (never functioned; removed in 1.6149).
* **Feature:** Per-role upload allowlist with a new `upload_svg_files` capability.
* **Feature:** Sanitization-failure log (last 50 events) at `timu_svg_failure_log`.
* **Fix:** Settings page moved to `Settings > SVG Support` to match documentation.
* **Fix:** `register_activation_hook()` registered at file scope so defaults seed reliably.
* **Fix:** Strict comparison on `enabled` option.
* **Fix:** `WP_Filesystem` for in-place writes; dropped `@gzdecode` error suppression.
* **Fix:** Stable tag aligned with the plugin header version.
* **Hygiene:** Bumped Requires PHP to 8.1; added CHANGELOG.md, dependabot.yml, CODEOWNERS.

= 1.6365 (prior numbering scheme) =
* Documentation and profile alignment update.

== Upgrade Notice ==

= 0.6123 =
Critical security release. Replaces the self-rolled SVG sanitizer with the well-audited enshrined/svg-sanitize allowlist library. Addresses GHSA-wmc2-4458-vm72. Update immediately.
