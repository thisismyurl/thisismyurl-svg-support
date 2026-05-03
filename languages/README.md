# Translations

Translation files (`.pot`, `.po`, `.mo`) for SVG Support by thisismyurl.com.

The text domain is `thisismyurl-svg-support` (matches the plugin slug, so
WordPress 4.6+ auto-loads translations from this directory without a
`load_plugin_textdomain()` call).

## Generating the POT

```bash
wp i18n make-pot . languages/thisismyurl-svg-support.pot \
  --exclude=vendor,tests,node_modules
```

## Contributing translations

PRs adding `languages/thisismyurl-svg-support-<locale>.po` files (and the
generated `.mo`) are welcome.
