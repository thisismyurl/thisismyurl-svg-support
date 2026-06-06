=== - SVG Support by Christopher Ross ===
Contributors: thisismyurl
Donate link: https://github.com/sponsors/thisismyurl
Tags: svg, media library, sanitization, uploads, security
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.6149.0734
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Plugin URI: https://thisismyurl.com/thisismyurl-svg-support/
Author: Christopher Ross
Author URI: https://thisismyurl.com/

Safe SVG uploads for WordPress with allowlist sanitization (enshrined/svg-sanitize), MIME validation, and per-role permissions.

== Description ==

SVG Support enables secure SVG upload workflows in WordPress with hardened defaults.

= Key features =

* Enables `.svg` and `.svgz` uploads for trusted roles only
* Allowlist sanitization via the well-vetted [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer) library
* Server-side MIME validation with `finfo_file()` before sanitization runs
* Per-role upload allowlist configurable from Settings > SVG Support
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
3. Go to `Settings > SVG Support`.
4. Choose which roles can upload SVG files.

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

= 1.6149 =
* **Security/honesty:** Removed the "sandboxed iframe preview" claim from the readme and settings page, and deleted the dead `sandbox_svg_preview()` code path that set an `svg_sandbox_html` key nothing consumed. The preview was never wired (the plugin ships no Media-view JS to render it), so SVGs still rendered as raw `<img>`. The real defense — allowlist sanitization on upload plus `finfo_file()` MIME validation — is unchanged and remains the control. Shipped state now matches the documentation.

= 1.6148 =
* **Feature:** WordPress 7.0 Abilities API support. Registers the readonly `thisismyurl-svg-support/sanitize-svg` ability, which sanitizes SVG markup (or an existing SVG attachment, read-only) in memory and returns the cleaned result plus a report of what was stripped — without modifying any stored file.

= 1.6147 =
* Unified plugin versioning to the x.Yddd calendar-version scheme.
* Confirmed compatibility with WordPress 7.0.


= 1.6143 =
* First full release (class 1). The 0.6xxx line was pre-release on the `x.Yddd` scheme.
* Standardized the donation link to GitHub Sponsors.

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

= 1.6365 =
* Documentation and profile alignment update.

== Upgrade Notice ==

= 0.6123 =
Critical security release. Replaces the self-rolled SVG sanitizer with the well-audited enshrined/svg-sanitize allowlist library. Addresses GHSA-wmc2-4458-vm72. Update immediately.
