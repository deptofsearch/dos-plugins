# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-works-v<version>`.

## 0.1.1

Works now updates itself from this repository's releases, like the other DoS
plugins. Nothing else changes.

The plugin carries the shared updater (`includes/class-dos-github-updater.php`,
one copy kept in `shared/` and checked byte-for-byte by a test) and the headers
WordPress needs to route updates to it: `Plugin URI` (now this repository) and
`Update URI`. It looks for tags `dos-works-v<version>` and the asset
`dos-works.zip`. It starts on load rather than only in the admin, because
WP-Cron runs the update check too.

The updater has to be installed by hand once, as 0.1.0 does not contain it.
After that the site updates from the Plugins screen.

## 0.1.0

The Works (`dos_work`) post type, its case-file meta fields and meta box for
the departmentofsearch.com portfolio. Moved here from the project repo on
2026-10-07.
