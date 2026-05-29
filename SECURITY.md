# Security Policy

## Reporting a Security Vulnerability

If you discover a security vulnerability in this plugin, please email
**security@thisismyurl.com** instead of opening a public issue. You can also
use [GitHub's private security advisory flow](https://github.com/thisismyurl/thisismyurl-svg-support/security/advisories/new).

Please include:

- A description of the vulnerability
- Steps to reproduce or proof of concept
- Affected versions
- Any known workarounds

I aim to acknowledge reports within 72 hours and ship a fix or mitigation
within 7 days for HIGH/CRITICAL severity findings.

## Threat Model

This plugin's security boundary is the **upload pipeline**: any file that
reaches `wp-content/uploads/` must be safe to serve from the same origin as
authenticated WordPress sessions.

### Trust boundaries

- **Untrusted:** the raw file payload, its filename, its claimed MIME type,
  and any URL the SVG references (`xlink:href`, `href`, `<image href>`).
- **Trusted:** the configured per-role allowlist, the bundled
  `enshrined/svg-sanitize` library at `vendor/enshrined/svg-sanitize/`, and
  the `WP_Filesystem` direct transport during the prefilter pass.

### Asset protected

- Authenticated session cookies on the same origin as `wp-content/uploads/`.
- The integrity of the Media Library (no script execution from previews).
- The host filesystem (no oversize/decompression-bomb writes).

### Threats addressed (and how)

| Threat | Mitigation |
|---|---|
| **XSS via `<script>`, event handlers, `javascript:` URIs in SVG** | Allowlist sanitization via `enshrined/svg-sanitize` (only known-safe elements/attributes survive). |
| **Disguised payload (HTML/JS named `.svg`)** | `finfo_file()` MIME check before sanitization rejects content that does not match an SVG MIME family. |
| **Decompression bomb / billion-laughs in `.svgz`** | 5 MB hard byte ceiling on both compressed and decompressed payloads; `LIBXML_NONET` + entity stripping in the underlying sanitizer. |
| **Privilege escalation via untrusted role uploading SVG** | New `upload_svg_files` capability on a per-role allowlist; default grants only `administrator`. |
| **Silent compromise** | Sanitization-failure log records filename, reason, user, and IP for the most recent 50 events. |

### Threats explicitly NOT addressed

- **Compromised vendor supply chain.** If `enshrined/svg-sanitize` itself is
  compromised, this plugin inherits that compromise. Mitigation:
  Dependabot-pinned versions, manual review of every vendor update.
- **Server-side XSS via cached HTML output rendering an SVG inline.** This
  plugin only governs uploads. Themes that inline-include SVG payloads at
  render time should run the file through `TIMU_SVG_Sanitizer::sanitize_string()`
  on the way out, not just on the way in.
- **CSP on the front end.** A site-wide `Content-Security-Policy` header is
  the right complement to upload sanitization but is out of scope for this
  plugin (tracked as a future feature).

## Security Practices

This plugin follows WordPress security best practices:

- **Input validation:** all option input passes through `sanitize_callback`;
  upload payloads pass through MIME detection, byte-ceiling checks, and
  allowlist sanitization.
- **Escaping:** output is escaped for context (`esc_html`, `esc_attr`,
  `esc_url`) and translatable strings use the `_e` / `__` family with
  escape-aware variants where the value reaches the page.
- **Capability checks:** every admin-screen render checks `manage_options`;
  upload acceptance checks the new `upload_svg_files` capability.
- **Nonce verification:** the settings form uses the WP Settings API, which
  enforces the `_wpnonce` round-trip.
- **No external phone-homes:** sanitization runs locally; the only network
  call in the codebase is the GitHub-updater check against the public
  Releases API on the configured GitHub repo.

## Supported Versions

Security updates are provided for the current version and the most recent
prior minor release.

| Version | Support |
|---------|---------|
| 0.6123 (current) | Security and feature updates |
| 1.6365 and earlier | **No longer supported — please upgrade** |

## Changelog and Updates

See [CHANGELOG.md](CHANGELOG.md) and the
[GitHub Releases](https://github.com/thisismyurl/thisismyurl-svg-support/releases)
page for security-related updates and fixes.

## Questions

For non-vulnerability security questions, open a discussion on GitHub or
contact me through [thisismyurl.com](https://thisismyurl.com/).
