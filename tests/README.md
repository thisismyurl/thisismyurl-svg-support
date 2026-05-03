# Tests

Test scaffolding for SVG Support by thisismyurl.com.

## Layout

```
tests/
├── README.md          (this file)
├── fixtures/          (raw SVG payloads — safe + malicious)
└── unit/              (PHPUnit unit tests for TIMU_SVG_Sanitizer)
```

## Running

PHPUnit isn't wired into the bundled `composer.json` runtime profile to keep
the distribution slim. To run tests locally:

```bash
composer require --dev phpunit/phpunit ^10
vendor/bin/phpunit tests/unit
```

## Adding fixtures

When a sanitizer regression is reported, drop the offending SVG into
`tests/fixtures/` and add a unit test that asserts the post-sanitization
markup no longer contains the dangerous element. This is also the right home
for any future GHSA-derived test cases.
