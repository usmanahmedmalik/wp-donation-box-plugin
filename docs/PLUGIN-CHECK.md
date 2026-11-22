# Plugin Check verification

Donation Box 0.1.5 was checked on 22 November 2026 with WordPress 7.1.4,
PHP 8.4, and the official Plugin Check 2.2.0 release.

The default Plugin Check run against the installable package completed without
reported errors or warnings. This is not a guarantee of directory approval or
an independent security audit.

## Changes made from the initial report

- SQL table names use the WordPress `%i` identifier placeholder.
- Report filters use literal SQL templates and bound values instead of
  concatenated WHERE fragments.
- The table helper accepts only the plugin's three known table suffixes.
- URL filters reject array inputs and sanitise and unslash scalar values.
- Server request values are sanitised and unslashed before use.
- Placeholder strings have translator comments.
- The readme declares the verified WordPress release series.

## Documented exceptions

Exceptions are local to the applicable operation, rather than disabling
security checks for whole files:

- Direct database operations target plugin-owned tables. Current financial
  state is read without result caching for webhook deduplication, portal
  ownership, reporting, and CSV exports.
- Display-only URL filters and pagination do not change state. The relevant
  nonce warning is excluded for the shared input reader; settings, exports,
  checkout, and portal actions retain their separate protections.
- CSV export closes `php://output`, an HTTP response stream, rather than a
  filesystem file requiring the WordPress filesystem abstraction.
- Explicit translation loading remains necessary for the bundled Dutch
  catalogue on GitHub ZIP installations outside the WordPress.org directory.

## Recheck a release

1. Run the regression commands in the README and build the ZIP.
2. Install that ZIP on a local or staging WordPress site.
3. Run **Tools → Plugin Check** for Donation Box with the default checks, or:

   ```sh
   wp plugin check donation-box --format=json
   ```

For runtime checks through WP-CLI, follow Plugin Check's instructions for
loading its `cli.php` with `--require` before WordPress bootstraps. Keep the
checker version and WordPress version with the results, since checks evolve.
