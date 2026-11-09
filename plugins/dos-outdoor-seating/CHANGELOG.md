# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-outdoor-seating-v<version>`. Releases
up to 0.6.3 are described in `readme.txt`, which was this plugin's changelog
before it moved here.

## 0.6.9

The city grid's ItemList JSON-LD lists the top 100 venues in the grid's own
ranked order (filter `osn_itemlist_max`), not every card. Since 0.6.8 the New
York page renders all 2,087 cards, and listing every one of them in JSON-LD
added roughly 300 KB of markup that search engines gain nothing from: rich
results use a short list, and every venue is still linked from its card.
`numberOfItems` is the number listed. The cards themselves are unchanged.

## 0.6.8

City grids show every venue. New York had 2,087 published venues but its page
showed 1,500, and not the best 1,500: the `osn_max_cards` cap was applied to
the database query, which is ordered by title, so every venue past the
1,500th alphabetically was dropped before the ranking (patio first, then
rating count, photo, summary) ever saw it. The same list feeds the district
and neighborhood chip counts, SEO venue counts and "more patios nearby", so
all of those were short too.

A city's query now takes every venue, ranks them, and only then applies the
cap, which is raised to 5,000 (filter `osn_max_cards`); past it the
lowest-ranked go. The site-wide list (no city) still caps the query, since it
can be the whole site. The sorted-list transient key changed
(`osn_sorted2_`) so lists cached before this release are not served for
their remaining 12 hours.

Every card is still in the HTML, so crawlers and no-JS visitors see them all.
With JS the grid shows the first 120 matching cards and a "Show N more"
button; any search, chip or area change starts again at 120. This keeps a
2,000-card page from laying out every card at once (hidden cards' lazy
images never load).

## 0.6.7

A GitHub access token setting, under Tools -> Outdoor Seating -> Updates.

On outdoorseatingnearme.com every release lookup was failing with GitHub's
403 rate limit: unauthenticated API calls get 60 an hour per IP address, and
on WordPress.com that address is shared with other sites. The shared updater
already reads a `DOS_GITHUB_TOKEN` constant, but `wp-config.php` cannot be
edited on WordPress.com, so there was nowhere to put one. The token is now
an option, passed to the updater through its `dos_github_updater_token`
filter, and every DoS plugin on the site uses it. A constant still wins where
one is defined, and another filter's token is never overridden. Saving the
token drops the cached failed lookup so it takes effect at once.

`POST /osn/v1/self-update` also stops calling an unreachable GitHub "up to
date": when no release could be read it returns a 502 with the reason, which
is how the rate limit above was hiding.

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
