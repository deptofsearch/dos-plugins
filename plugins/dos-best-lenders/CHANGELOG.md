# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-best-lenders-v<version>`.

## 0.6.3

State hub featured image no longer renders twice.

- Layout answers Themify's `hide_image` ('yes') as well as `hide_post_image` for hub pages. Why: Themify Magazine
  pages read `hide_image` (default/yes/no); 0.6.2 only answered the post-style key, so Themify still printed its
  `figure.post-image` above the shortcode's own hero. Same scoping as before: a Page whose content has
  `[blnm_state_index ... state=]`, front end only.
- Hub pages get body class `blnm-hub-page`, and blnm.css hides `.page-content > figure.post-image` and
  `article > figure.post-image` under it. Why: belt and braces if a theme update reads the meta another way. The
  selectors are direct-child only, so our `.blnm-hero-fig` (inside the shortcode output) is untouched.
- The hub hero uses the `full` size (WP serves the -scaled file with srcset) with
  `sizes="(min-width: 1200px) 1140px, 100vw"`, `decoding=async`, `fetchpriority=high`. Why: `large` is 1024px wide,
  soft on desktop.

## 0.6.2

State hub controls trimmed and lender names made readable.

- The County and Sort dropdowns are removed (markup, `county`/`sort` query handling, JS, CSS). Why: with every
  state's cities already listed by population, two extra selects added a row of controls and nothing a visitor
  needed that the name box does not do faster. Cities always sort by population, ties by name, on the server;
  the JS no longer reorders. Old `?county=` and `?sort=` URLs are ignored, and Reset still strips them.
- Remaining controls: city name box, Has lenders checkbox, live count, Reset. Name box takes the width with the
  checkbox beside it from 640px; on phones they stack.
- `Render::display_name( $legal, $google = '' )`: the "Including ..." line on hub cards. HMDA legal names are
  often ALL CAPS ("CMG MORTGAGE, INC."). Hub cards pass the legal name only (stored `branch_name` values are too inconsistent); the optional
  `$google` argument still prefers a name and drops a " - place" suffix. It title-cases all-caps names while keeping acronyms (LLC, USA, NMLS, CMG, FSB, ...) and strips trailing
  Inc./LLC/N.A./Corp./Company/National Association (only after another word). City page cards are unchanged. `hub_index` rows gain `topd` (display names); a cached row
  without it falls back to the legal names, cleaned.
- The stats line (cities across counties) is unchanged.

## 0.6.1

Geometry route fix. On a live host `POST /states/WA/geometry` answered 502 "TIGERweb returned no counties" while the
same URL worked from a laptop, and the code threw away the reason.

- `fetch_geometry` records each layer attempt (WP_Error message, HTTP code, first 200 characters of a reply that is
  not JSON with features) and returns them as `attempts` in the error data, so the REST response says why. It also
  sends a plain browser-style user-agent. `sslverify` stays at the WordPress default. The query string was checked
  against what a browser sends: `where=STATE%3D%2753%27`, identical.
- The route accepts an optional JSON body `{"geojson": <FeatureCollection>}` (same `manage_options` permission).
  When present the fetch is skipped. Why: lets a machine that can reach TIGERweb supply the outlines when the web
  host cannot. Validation (400 on failure): FeatureCollection, 1 to 300 features, `properties.GEOID` a 5-digit string
  starting with the state's FIPS, `properties.BASENAME` a string of at most 80 characters, geometry Polygon or
  MultiPolygon with numeric [lon, lat] inside -180..180 / -90..90, at most 200,000 coordinate pairs in total (this
  is the size cap). The response now includes `source`: `body` or `tigerweb`.

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
- Maps are static SVG files in `uploads/blnm-maps/v2/<st>/<slug>-<hash>.svg`, written on first use (atomically) and named with a hash of
  everything the drawing depends on, so a changed coordinate or county gets a new file and URL. If uploads is
  not writable the card renders without a map and one line is logged. They are not drawn in the browser and make no third-party
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
  for the IDs, one meta-cache priming). Cleared with the city index and when a city's lenders, county, data
  year, lat, lng or population change (the last three are not in the `/cities` payload, so they do not bump the search version). `GET blnm/v1/cities` is unchanged.
- Pages that contain `[blnm_state_index` now answer Themify's `hide_post_image` with yes, because the hub
  prints the page's featured image itself as a 21:9 hero. Other pages are not affected.
- `create: false` on the city and lender upsert returns 404 `blnm_not_found` instead of creating, so a partial
  update (for example only lat/lng) to a mistyped slug cannot make a stray post. A create with no `status` now
  gets `publish` (it used to read an undefined key and could create a post with no status).
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
