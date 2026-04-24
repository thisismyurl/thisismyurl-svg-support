# SVG Support by This Is My URL

Adds safe SVG upload support to WordPress with server-side sanitization, role-based upload controls, and full Media Library compatibility.

WordPress blocks SVG uploads by default because SVGs can contain executable code. This plugin enables SVG support while applying sanitization to remove unsafe elements before the file is stored.

## Features

- **Safe SVG uploads:** Enables `.svg` file uploads with server-side sanitization.
- **Role-based controls:** Restrict SVG uploads to trusted user roles only (administrators, editors).
- **SVG sanitization:** Strips potentially harmful scripts and attributes before saving.
- **Media Library compatibility:** SVGs appear correctly in the WordPress Media Library with preview support.
- **Inline rendering support:** Option to render SVGs inline for CSS/animation control.
- **No external dependencies:** All sanitization runs locally.

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Upload the plugin to `/wp-content/plugins/thisismyurl-svg-support/`.
2. Activate through the WordPress Plugins screen.
3. Go to **Settings > SVG Support** to configure upload permissions.

## Security Notes

SVG files are sanitized on upload using an allowlist approach: only known-safe elements and attributes are preserved. Scripts, event handlers, and external references are stripped before the file is stored.

Upload capability is restricted to the roles you configure — by default, only administrators.

## Versioning

This plugin uses the format `1.Yddd`:
- `Y` = last digit of the year
- `ddd` = Julian day number

## Standards

- Direct access protection with ABSPATH checks.
- Nonce and capability checks for all admin actions.
- Sanitization aligned with WordPress coding standards.

---

## About This Is My URL

This plugin is built and maintained by [This Is My URL](https://thisismyurl.com/), a WordPress development and technical SEO practice with more than 25 years of experience helping organizations build practical, maintainable web systems.

Christopher Ross ([@thisismyurl](https://profiles.wordpress.org/thisismyurl/)) is a WordCamp speaker, plugin developer, and WordPress practitioner based in Fort Erie, Ontario, Canada. Member of the WordPress community since 2007.

### More Resources

- **Plugin page:** [https://thisismyurl.com/thisismyurl-svg-support/](https://thisismyurl.com/thisismyurl-svg-support/)
- **WordPress.org profile:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/)
- **Other plugins:** [github.com/thisismyurl](https://github.com/thisismyurl)
- **Website:** [thisismyurl.com](https://thisismyurl.com/)

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) or [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
