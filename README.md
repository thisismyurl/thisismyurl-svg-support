# SVG Support

[![CI](https://github.com/thisismyurl/thisismyurl-svg-support/actions/workflows/ci.yml/badge.svg)](https://github.com/thisismyurl/thisismyurl-svg-support/actions/workflows/ci.yml) [![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org/) [![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

SVG files can carry executable code, so this plugin sanitizes every SVG on upload and stores the cleaned version in your media library.

## What it does

- Sanitizes each SVG on upload, stripping scripts, event handlers, and unsafe attributes before the file reaches the media library
- Parses the file as XML with DOMDocument and removes unsafe nodes surgically, with no regex guesswork
- Validates the real MIME type instead of trusting the file extension
- Restricts SVG upload capability to administrators unless you widen it
- Lets you allowlist trusted upload sources when you need to bypass the default restriction

## Requirements

- WordPress 6.0+
- PHP 7.4+
- PHP DOM extension (standard on most hosts)

## Installation

1. Upload the plugin to `/wp-content/plugins/thisismyurl-svg-support/`.
2. Activate it through the Plugins screen.
3. SVG uploads work immediately for administrators.

## Security model

An SVG is an XML document, and XML documents can hold JavaScript and other executable content. This plugin sanitizes on upload, not on render. The version that gets stored and served is the cleaned one, so nothing downstream has to trust the original file.

Sanitization removes script elements, event handler attributes (`onclick`, `onload`, and the rest), `javascript:` URIs, `use` elements that reference external resources, and `foreignObject` elements.

Here is what it does not do, because you should know the edges. It does not sandbox SVGs at render time. It will not catch every possible XSS vector in every browser context. And it does nothing for SVGs that were already in your media library before you activated the plugin — those were stored unsanitized.

## Versioning

Versions follow `X.Yjjj.hhmm` — year, Julian day, 24-hour time of the build.

## About

SVG Support is built and maintained by [Christopher Ross](https://thisismyurl.com/). I build focused WordPress tools for problems that keep showing up across real sites. No tracking, no ads, no upsells.

**WordPress.org:** [profiles.wordpress.org/thisismyurl](https://profiles.wordpress.org/thisismyurl/) · **GitHub:** [github.com/thisismyurl](https://github.com/thisismyurl) · **LinkedIn:** [linkedin.com/in/thisismyurl](https://linkedin.com/in/thisismyurl)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
