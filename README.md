# This Is My URL - SVG Support

[![CI](https://github.com/thisismyurl/thisismyurl-svg-support/actions/workflows/ci.yml/badge.svg)](https://github.com/thisismyurl/thisismyurl-svg-support/actions/workflows/ci.yml) [![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org/) [![License](https://img.shields.io/badge/License-GPL--2.0-blue)](LICENSE)

Adds safe SVG upload support to WordPress with server-side sanitization, role-based upload controls, and full Media Library compatibility.

WordPress blocks SVG uploads by default because SVGs can contain executable code. This plugin enables SVG support while applying sanitization to remove unsafe elements before the file is stored.

> **Part of the This Is My URL image toolkit:** [Image Support](https://github.com/thisismyurl/thisismyurl-image-support) for library-wide filename cleanup, content-reference syncing, photo credits, and alt text; [WebP Support](https://github.com/thisismyurl/thisismyurl-webp-support) and [HEIC Support](https://github.com/thisismyurl/thisismyurl-heic-support) for focused format conversion; and [SVG Support](https://github.com/thisismyurl/thisismyurl-svg-support) for safe SVG uploads. Reach for a focused plugin if you only need that format; use Image Support for library-wide work.

## Features

- **Safe SVG uploads:** Enables `.svg` and `.svgz` file uploads with allowlist-based server-side sanitization via [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer).
- **Per-role upload allowlist:** Configure which roles can upload SVGs from `Settings > SVG Support`. Backed by a real `upload_svg_files` capability, granted only to roles you check.
- **MIME validation:** Server-side `finfo_file()` check rejects disguised payloads before sanitization runs.
- **Sandboxed admin preview:** Media Library previews render in `<iframe sandbox="">`, so script execution and cookie exfil from a pre-sanitization or edge-case payload are denied at the iframe boundary.
- **Sanitization-failure log:** Last 50 rejection events recorded with filename, reason, user, and IP for incident response.
- **Bundled sanitizer:** `enshrined/svg-sanitize` is shipped in `vendor/` — no Composer step required to install.

## Requirements

- WordPress 6.0+
- PHP 8.1+

## Installation

1. Upload the plugin to `/wp-content/plugins/thisismyurl-svg-support/`.
2. Activate through the WordPress Plugins screen.
3. Go to **Settings > SVG Support** to configure upload permissions.

## Security notes

SVG files are sanitized on upload using an allowlist approach (`enshrined/svg-sanitize`): only known-safe elements and attributes are preserved. Scripts, event handlers, and external references are stripped before the file is stored.

Upload capability is gated by the new `upload_svg_files` capability. Roles you check on the settings screen receive that capability; roles you uncheck have it revoked. By default only Administrators are on the allowlist.

For the full threat model and reporting process, see [SECURITY.md](SECURITY.md).

## Versioning

This plugin uses the format `x.Yddd`:

- `x` = release class (`0` = pre-release, `1` = full)
- `Y` = last digit of the year
- `ddd` = Julian day number

## Standards

- Direct-access protection with `ABSPATH` checks.
- Nonce and capability checks for all admin actions.
- Sanitisation aligned with WordPress coding standards.

## Changelog

See [releases](../../releases) or [readme.txt](readme.txt).

## Documentation

- [readme.txt](readme.txt)
- [CONTRIBUTING.md](CONTRIBUTING.md)
- [SECURITY.md](SECURITY.md)
- [SUPPORT.md](SUPPORT.md)
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)

---

## Support and donations

I build these tools because WordPress sites in the wild keep hitting the same problems, and a small, focused plugin is usually the right fix. They're free to use, with no tracking and no ads.

If one of them saves you time, here are the genuine ways to help:

- **Sponsor the work.** [GitHub Sponsors](https://github.com/sponsors/thisismyurl) is the simplest way, and the Sponsor button at the top of this repo lists it alongside Bitcoin, Dogecoin, PayPal, and Interac e-transfer. Any amount helps, and none of it is expected.
- **Contribute code or ideas.** A pull request, a bug report, or a tested edge case is worth as much as a donation. See [CONTRIBUTING.md](CONTRIBUTING.md) to get started.
- **Share it.** A note on [WordPress.org](https://profiles.wordpress.org/thisismyurl/), [GitHub](https://github.com/thisismyurl), or [LinkedIn](https://linkedin.com/in/thisismyurl) helps other people find work that might save them the same afternoon.

### Report issues and questions

- **Found a bug or want a feature?** Open an issue on the [Issues](../../issues) tab. Include your WordPress and PHP versions and the steps to reproduce it.
- **Have a question?** Start a thread on the [Discussions](../../discussions) tab.

### Contributing code

Code contributions are welcome. The short version:

1. Fork the repository and clone your fork.
2. Create a branch with a clear name, like `feature/short-descriptive-name`.
3. Make your change and test it against the edge cases.
4. Run the coding-standards check before you open the pull request.
5. Open a pull request that explains what changed and why.

The full workflow and standards live in [CONTRIBUTING.md](CONTRIBUTING.md). Contributing is never required, but it is always appreciated.

## About This Is My URL

This plugin is built and maintained by [This Is My URL](https://thisismyurl.com/), the WordPress development and technical SEO practice of Christopher Ross. I help teams build WordPress sites that stay secure, fast, and maintainable, and I write small, focused plugins like this one for the problems those sites keep running into.

### My background

- On the web since 1996, and in WordPress since 2007
- WordPress.org plugin developer with 19 plugins published since 2009
- Technical SEO practitioner focused on performance, security, and search visibility
- Lead instructor and curriculum architect at the M.L. Campbell Training Center, the Sherwin-Williams® international training facility for its industrial wood division

### Ways to connect

- **Website:** [thisismyurl.com](https://thisismyurl.com/)
- **WordPress.org:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/)
- **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl)
- **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)

## Contributors

- **Christopher Ross** ([@thisismyurl](https://github.com/thisismyurl)) — author and maintainer
- Thanks to everyone who has reported issues, tested edge cases, and contributed code

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) or [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).

---
*This project follows the [10 Core Pillars](PILLARS.md). Support quality work [here](https://github.com/sponsors/thisismyurl).*
