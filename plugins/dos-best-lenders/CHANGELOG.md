# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-best-lenders-v<version>`.

## 0.6.0

State hub redesign: `[blnm_state_index state="XX"]` is now a filterable grid of city cards with a county map
tile each, instead of a flat list of links. Why: a 90-city state page as a bare list gave visitors no way to
find their town or to see which cities have lenders, and offered search engines no more than anchor text.

- Cards show a small map (the state in white on the card's own shade, a spruce dot for the city; no county highlight), the city, its
  county, how many lenders are listed and up to three top lender names. One link per card (image and name
  share it) so there are no nested anchors.
- Filters: city name, county (with counts), "Has lenders", sort by population or A to Z. The server filters
  and sorts from the query string (`?q=&county=&has=1&sort=az`), so it works without JavaScript and the count
  is right on load; `blnm.js` then filters in place and keeps the URL in step with `history.replaceState`.
  Every card is in the HTML (non-matches are `hidden`) so crawlers still see every link.
- Tile style is `Maps::STYLE`: `A` keeps faint interior county lines, `B` draws the state outline only (one-line switch; the tile version is 2, so changing style means rebuilding tiles with the build route).
- Maps are static SVG files in `uploads/blnm-maps/v2/<st>/<slug>.svg`, written on first use, with an inline
  `data:` fallback if uploads is not writable. They are not drawn in the browser and make no third-party
  request at view time. County outlines come from Census TIGERweb, fetched once per state and stored in the
  option `blnm_geo_<ST>`. Tile colours are hex copies of the brand tokens (an SVG used as an image cannot read
  CSS variables); `tests/test-blnm-maps.php` fails if they drift from `blnm-brand.css`.
- New admin-only routes (manage_options): `POST blnm/v1/states/XX/geometry` (fetch and store the outlines),
  `POST blnm/v1/maps/build?state=XX` (write every tile for the state's published and draft cities),
  `DELETE blnm/v1/maps?state=XX` (remove the state's tiles).
- New city fields `lat`, `lng`, `population` on `POST blnm/v1/cities/upsert` and as registered meta
  (`blnm_lat`, `blnm_lng`, `blnm_population`). Each is written only when sent, so `{slug, lat, lng, population}`
  alone leaves every other field, lenders included, untouched. A city without coordinates gets a tile with
  its county tinted and no dot.
- New `Rest::hub_index( $state )`: one row per published city, cached in transient `blnm_hub_<ST>` (one query
  for the IDs, one meta-cache priming). Cleared with the city index and when a city's lenders, county or data
  year change. `GET blnm/v1/cities` is unchanged.
- Pages that contain `[blnm_state_index` now answer Themify's `hide_post_image` with yes, because the hub
  prints the page's featured image itself as a 21:9 hero. Other pages are not affected.
- `Render::join_names()` is public so the hub reuses the "A, B and C" wording.
- Why the hub transient is separate from the index: lender writes arrive in bulk from n8n, and clearing the hub
  rows on each is cheap, whereas bumping the city-search version (which busts browser and CDN caches) on each
  would not be.

## 0.5.1

The first release from this repository. It merges two lines of work that had
both been called 0.4.9, plus the 0.5.0 manual build, so nothing from either
side is lost.

Why the numbers jump: 0.5.0 was a manual build from the project repo
(`bestlendersnearme/plugin/`), uploaded by hand to bestlendersnearme.com. It
has the admin and search work but no updater and not the reviews-closed
change. This repository's own 0.4.9 had the reviews-closed change and the
updater but none of the search work. 0.5.1 has everything, and being higher
than the 0.5.0 already installed, it is what the site's updater will offer.

Install 0.5.1 by hand once: the installed 0.5.0 has no updater to fetch it.
After that the site updates from the Plugins screen. The updater looks for
tags `dos-best-lenders-v<version>` and the asset `dos-best-lenders.zip`.

From this repository's 0.4.9:
- "What reviewers mention" starts closed on every screen (it used to start
  open on desktop and close only on phones). The text stays in the HTML.
- Updates from GitHub releases through the shared updater
  (`includes/class-dos-github-updater.php`, kept byte-identical to `shared/`).

From the project repo's 0.4.9:
- Cities with no qualifying lenders no longer show "0 lenders made 0 home
  loans"; singular forms read "1 lender made 1 home loan".
- Homepage city search works by name ("Seattle", "seattle wa", "Seattle,
  Washington", "Saint/St. Helens", partial prefixes), typeahead from 2
  characters with keyboard navigation. A name in several states lists each
  state; no match shows a friendly message. New `includes/class-search.php`.
- No-JS fallback (`?s=&post_type=blnm_city`) redirects to the city page when
  exactly one published city matches.
- `GET blnm/v1/cities` payload is `{ n: city, s: state, u: url }`; the cache
  invalidates on city publish, unpublish, update, delete and meta changes.

From the project repo's 0.5.0:
- wp-admin City list: sortable "Lenders" column, "No lenders (N)" view, State
  filter and a county / nearby-towns column (new `includes/class-admin.php`).
  Uses the stored `blnm_lender_count`, backfilled when missing.
- City search index URL carries `?v=<blnm_city_index_ver>`, bumped on every
  index flush, so browsers never keep a stale empty index.
- `[blnm_state_index]` with no state lists state names linking to their state
  pages (only states with a published city and a published state page);
  `state="WA"` is unchanged. Cached, flushed with the city index.

## 0.5.0 (project repo, manual build)

See 0.5.1; this build is superseded and never came from this repository.

## 0.4.9 (this repository)

Reviews start closed; updates from GitHub releases. See 0.5.1.

## 0.4.8 and earlier

History is in `readme.txt`.
