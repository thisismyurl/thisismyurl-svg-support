# SVG Support by This Is My URL

[![CI](https://github.com/thisismyurl/thisismyurl-svg-support/actions/workflows/ci.yml/badge.svg)](https://github.com/thisismyurl/thisismyurl-svg-support/actions/workflows/ci.yml) [![WordPress Tested](https://img.shields.io/badge/WordPress-6.9%2B-blue)](https://wordpress.org/) [![License](https://img.shields.io/badge/License-GPL--2.0-blue)](LICENSE)


Adds safe SVG upload support to WordPress with server-side sanitization, role-based upload controls, and full Media Library compatibility.

WordPress blocks SVG uploads by default because SVGs can contain executable code. This plugin enables SVG support while applying sanitization to remove unsafe elements before the file is stored.

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

## Security Notes

SVG files are sanitized on upload using an allowlist approach (`enshrined/svg-sanitize`): only known-safe elements and attributes are preserved. Scripts, event handlers, and external references are stripped before the file is stored.

Upload capability is gated by the new `upload_svg_files` capability. Roles you check on the settings screen receive that capability; roles you uncheck have it revoked. By default only Administrators are on the allowlist.

For the full threat model and reporting process, see [SECURITY.md](SECURITY.md).

## Versioning

This plugin uses the format `x.Yddd`:
- `x` = release class (`0` = pre-release, `1` = full)
- `Y` = last digit of the year
- `ddd` = Julian day number

## Standards

- Direct access protection with ABSPATH checks.
- Nonce and capability checks for all admin actions.
- Sanitization aligned with WordPress coding standards.

---

## Support and Contribute

### Ways to Support

I'm building these tools because WordPress developers and site owners deserve straightforward, practical solutions. There's no tracking, no ads, and you don't need to pay to use these plugins.

If you find them helpful, here are some genuine ways to support the work:

- **Sponsor if it fits your budget:** You can sponsor the project through [GitHub Sponsors](https://github.com/sponsors/thisismyurl). Sponsorship helps, but it's always optional.
- **Contribute code or ideas:** Opening a pull request, reporting an issue, or testing edge cases is just as valuable as sponsorship. Helping me improve these plugins is a great way to contribute.
- **Share your experience:** A follow on [WordPress.org](https://profiles.wordpress.org/thisismyurl/), [GitHub](https://github.com/thisismyurl), or [LinkedIn](https://linkedin.com/in/thisismyurl) helps others find this work.

### Report Issues and Questions

Found a bug? Want to suggest a feature? Just curious how something works?

- **File an issue:** Use the [Issues](../../issues) tab. Include your WordPress and PHP version, and steps to reproduce.
- **Start a discussion:** Use the [Discussions](../../discussions) tab for questions, ideas, or general conversation about the plugin.

### Contributing Code

Code contributions are welcome and genuinely valuable. Here's the workflow:

1. **Fork this repository** and clone it locally.
2. **Create a feature branch** with a clear name (e.g., `feature/improve-safety-check`).
3. **Make your changes** and test thoroughly on edge cases.
4. **Follow WordPress coding standards** — run `composer run lint:phpcs` before opening a PR.
5. **Open a pull request** with a clear description of what changed and why.

I review PRs thoughtfully and appreciate well-tested contributions. Contributing is never required, but it's genuinely helpful.

---


## About This Is My URL

This plugin supports the work I do at [This Is My URL](https://thisismyurl.com/wordpress-website-development/), where I help WordPress teams build secure, performant, and maintainable sites.

This plugin is built and maintained by [This Is My URL](https://thisismyurl.com/), a WordPress development and technical SEO practice. I'm Christopher Ross, a WordPress developer and technical SEO specialist with 30 years of experience on the open web (since 1996) and 19 years on WordPress (since 2007).

### My Background

- **30 years on the open web** (since 1996), with 19 of those years on WordPress (since 2007)
- **WordPress contributor since 2007** with a strong track record helping organizations build practical, maintainable web systems
- **Technical SEO practitioner** helping sites improve performance, security, and search visibility
- **Training specialist** focused on practical outcomes and helping teams adopt technology with confidence

I believe in straightforward solutions that work. No hype. No unnecessary complexity.

### Ways to Connect

- **WordPress.org profile:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/)
- **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl)
- **Website:** [thisismyurl.com](https://thisismyurl.com/)
- **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)


## Contributors

- **Christopher Ross** ([@thisismyurl](https://github.com/thisismyurl)) — author and maintainer
- **Contributors:** Thanks to everyone who's reported issues, tested edge cases, and contributed code

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) or [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).

## Documentation

- [readme.txt](readme.txt)
- [CONTRIBUTING.md](CONTRIBUTING.md)
- [SECURITY.md](SECURITY.md)
- [SUPPORT.md](SUPPORT.md)
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)


---
*This project follows the [10 Core Pillars](PILLARS.md). Support quality work [here](https://github.com/sponsors/thisismyurl).*

