# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-outdoor-seating-v<version>`. Releases
up to 0.6.3 are described in `readme.txt`, which was this plugin's changelog
before it moved here.

## 0.6.4

Outdoor Seating now updates itself from this repository's releases, like the
other DoS plugins. Nothing else changes.

The plugin carries the shared updater (`includes/class-dos-github-updater.php`,
one copy kept in `shared/` and checked byte-for-byte by a test) and the headers
WordPress needs to route updates to it: `Plugin URI` and `Update URI`. It looks
for tags `dos-outdoor-seating-v<version>` and the asset
`dos-outdoor-seating.zip`. It starts on load rather than only in the admin,
because WP-Cron runs the update check too. The class is global, so the plugin's
`OSN` namespace refers to it as `\DOS_GitHub_Updater`.

The updater has to be installed by hand once, as 0.6.3 does not contain it.
After that the site updates from the Plugins screen.
