# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-outdoor-seating-v<version>`. Releases
up to 0.6.3 are described in `readme.txt`, which was this plugin's changelog
before it moved here.

## 0.6.6

A REST route that updates this plugin: `GET /osn/v1/self-update` reports the
installed and latest versions, `POST /osn/v1/self-update` installs the latest
release when it is newer.

Core's REST API can install and activate plugins but not update them, so a
release sat waiting until someone opened Dashboard -> Updates. The n8n side
already holds an administrator's application password for this site, so with
this route a release can go live straight after it is published.

It needs `update_plugins` and `manage_options`, and only ever updates this
plugin from the package the shared GitHub updater already offers WordPress, so
it adds no new source of code. POST always asks GitHub afresh (the updater's
cached release list is dropped first), so a release published minutes earlier
is found. The response reads the new version from the file on disk, because
the request that ran the update is still executing the old code. This version
itself has to be installed the usual way, once.

## 0.6.5

The homepage's default SEO copy no longer ends with a "Where We Cover" list of
state links.

The "Browse by state" tiles further down the same page already link every
state, so the paragraph repeated them in a sentence, and as a hand-kept list it
fell behind whenever a state was added (the default still named six states
when the site covered fourteen). The live copy, stored in `osn_home_seo_html`,
had the section removed on 2026-10-18; this keeps it from coming back if that
setting is ever cleared and the default shows again.

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
