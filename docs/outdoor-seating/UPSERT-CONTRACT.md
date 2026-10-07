# Upsert payload contract (plugin 0.3.6)

`POST https://<site>/wp-json/osn/v1/venues/upsert` (application password, user with `edit_posts`; `publish_posts` needed to publish).

```json
{ "dry_run": false, "pass": "mapsdata", "venues": [ { ... } ] }
```

- `venues`: array, max 50. Each item is validated alone; one bad item does not fail the batch.
- `dry_run` (bool, default false): report created/updated/skipped without writing.
- `pass` (string, optional): default pass for items that lack their own.

Response: `{ "dry_run": bool, "counts": {created,updated,skipped,error}, "results": [ { source_id, action: created|updated|skipped|error, id, link, message? } ] }`.

## Identity and merge rules

- `source` + `source_id` are **required** on every item (no fallback). Example `source: "mapsdata"`, `source_id: <google place_id>`.
- Matching order: (1) `source` + `source_id`, (2) `google_place_id`, (3) `maps_cid`. When matched by 2 or 3 the stored `source`/`source_id` are kept.
- Create needs `title` and `city`. Updates may omit them.
- Fields left out of an item are untouched. `null` (or empty string/array) clears the field. Invalid values are ignored with a warning in `message`.
- Field names may carry the `osn_` prefix; it is stripped.
- Trashed venues are never recreated (`skipped`).

## Hash and passes

The hash of everything sent in an item is stored as `osn_hash_{pass}`. `pass` defaults to the item's `source` (or the request-level `pass`). The same item sent again under the same pass is `skipped`. Another pass has its own hash, so it never invalidates this one. Allowed pass chars: `a-z 0-9 _ -`, max 40. Note a pass whose data was later overwritten by another pass will still skip if its own payload is unchanged.

## Core fields

| Field | Type | Notes / example |
|---|---|---|
| `title` | string | `"Adams Table"` (post title) |
| `city` | string | `"Phoenix, AZ"` strictly `City, ST`; creates the city term |
| `category` | string | `"American restaurant"` (`osn_category` term; `""` clears) |
| `amenities` | string[] | slugs, e.g. `["outdoor-seating","beer"]`; unknown slugs create terms; replaces the set; used for city grid filters |
| `status` | `publish`/`draft`/`pending`/`private` | default `publish` on create. Without `publish_posts`, create and explicit `publish` become `pending`. Omit on update to keep current status |
| `content` | string (HTML) | optional extra body, shown above the sections |
| `excerpt` | string | optional |
| `pass` | string | see above |
| `landing_page_id` | int | explicit landing page for the city term (any existing page) |
| `landing_slug` | string | alternative to the id; if neither given, a page with slug `phoenix-az` is auto-guessed when the city has no link |

## Meta fields (stored as `osn_<name>`)

| Field | Type | Notes / example |
|---|---|---|
| `summary` | string | short original write-up, rendered as the intro. `"A downtown bar with a covered patio."` |
| `patio_notes` | string | shown in the Outdoor seating callout |
| `outdoor_seating` | `yes`/`no`/`unknown` | drives callout, badge, sort order, JSON-LD amenityFeature |
| `business_status` | `open`/`temporarily_closed`/`permanently_closed` | permanently closed: hidden from grids and "more nearby"; temporarily closed: badge |
| `rating` | number 0-5 | `4.0` |
| `rating_count` | int | `112` |
| `price_level` | int 0-4, `"$"`..`"$$$$"`, or `PRICE_LEVEL_*` | `2` |
| `price_range` | string | Google's range text, e.g. `"$10–60"`; shown when `price_level` is empty (0.2.2+) |
| `phone` | string | `"+16023884888"` |
| `address` | string | full address, `"150 W Adams St, Phoenix, AZ 85003"` (a leading venue name is tolerated) |
| `street`, `zip` | string | for JSON-LD and cards |
| `city_name`, `state` | string | default from `city` |
| `website` | url | |
| `google_place_id` | string | `"ChIJBQEL..."` |
| `maps_cid` | string (digits) | `"7144607331345457616"` |
| `google_maps_uri` | url | API `place_link`; used in JSON-LD sameAs |
| `lat`, `lng` | number | map embed and geo |
| `timezone` | IANA string | `"America/Phoenix"`; open-now check. Falls back to a state default |
| `hours_json` | object | `{"Monday":"11 AM–10 PM","Tuesday":"Closed","Wednesday":"Open 24 hours","Thursday":"11 AM–2 PM, 5–9 PM","Friday":"5 PM–2 AM", ...}`. Keys are full day names; en dash U+2013; narrow no-break spaces tolerated. Unparseable days still display but are skipped in open-now and JSON-LD |
| `details_json` | object | the raw Maps Data `details` map: `{"Highlights":["Live music"],"Offerings":["Beer"], ...}`. Sanitized to `{string:[string]}`, values deduped. Rendered: Food & drinks = Offerings + Dining options + Popular for; Highlights = Highlights + Atmosphere + Crowd; Good to know = Service options + Planning + Children + Amenities + Accessibility. `Payments` is stored but never shown. `Planning` containing "Accepts reservations" sets JSON-LD `acceptsReservations` |
| `types_json` | string[] | Maps `types`, e.g. `["Restaurant","Bar"]`; JSON-LD `servesCuisine` (generic types dropped; falls back to the category) |
| `data_sources` | string[] | `["tablepress","mapsdata","website"]`; `website` adds the website part of the source line |
| `enriched_at` | `YYYY-MM-DD` | "last checked" date in the source line |
| `photos_json` | array | `[{"url","width","height","attribution_html"}]`; expected empty. Placeholder uses the venue name initial |
| `featured_media_url` | url | stored, not sideloaded |

## Removed in 0.2.0

`editorial_summary` (now `summary`), `review_summary`, `attributes_json`, Places-style hours (`periods`/`weekdayDescriptions`), the `google-places` source fallback, single `osn_hash`.

## Side effects

After a non-dry-run batch with created/updated rows, each saved venue's `_osn_thin` sitemap flag is refreshed (see SEO below), then for each touched city: sorted-venue cache purged, `do_action('osn_city_venues_changed', $term_id, $landing_page_id)`, `clean_post_cache($landing_page_id)`. The city index flushes only when a venue is created or its status/city changes.


## Venue photos: `POST /wp-json/osn/v1/venues/image` (plugin 0.2.6+)

n8n finds an image URL on the restaurant's own website (og:image, twitter:image, a hero `<img>`); the plugin downloads, validates and sideloads it and sets it as the venue's featured image. Needs a user with `upload_files` **and** `edit_posts` (an application password for an editor/admin works).

```json
{ "dry_run": false, "items": [ { "id": 123, "image_url": "https://example.com/hero.jpg", "credit_url": "https://example.com/", "credit_text": "Photo: Example's website", "force": false } ] }
```

- `items`: array, **max 5** (400 above that; each image is a download of up to 20 s). Each item is handled alone.
- `dry_run` (bool): runs the lookup, URL checks, download and size/ratio validation, saves nothing (temp file deleted). A passing item reports `action: "set"` with reason `dry_run: ...`.
- Time budget: after `osn_image_time_budget` seconds (filter, default 60) the remaining items come back as `error` with reason `Deferred: request time budget reached; resend this item.`; resend them.
- Item fields:
  - `id` (int) **or** `source` + `source_id`: finds the venue (trashed venues are errors).
  - `image_url` (required, public http/https URL).
  - `credit_url`: link for the credit line; default is the venue `website`. `credit_text`: default `Photo: {Venue}'s website`.
  - `force` (bool): replace an existing featured image; otherwise a venue that already has one is `skipped`.
- Checks, in order: venue exists, not already photographed (unless `force`), URL path/filename containing `logo`, `icon`, `favicon`, `sprite` or `placeholder` is rejected, then an attachment already sideloaded from the exact same URL is reused (chains share images), else download (20 s timeout, 8 MB cap), then `wp_getimagesize` must say jpeg/png/webp, width >= 600, height >= 300, aspect ratio between 1.0:1 and 3.2:1 (filters osn_image_min_ratio / osn_image_max_ratio, 0.2.7+). Originals wider than 1600 px are resized to 1600 wide before saving.
- 0.3.6+: the downloaded file is rotated per EXIF, shrunk to at most 1600 px wide and **always re-encoded** as WebP at quality 82 (JPEG q82 where the server cannot write WebP), whatever the source format, so the attachment, its filename (`.webp`) and every sub-size are WebP. Transparency is not preserved (these are photos). If the image editor fails the original file is kept as is.
- 0.3.9+: every re-encoded file is **verified** before use (file exists, more than 1024 bytes, `wp_getimagesize` width >= 300 and the expected mime). A WebP that fails is deleted and JPEG q82 is tried; if that fails too the downloaded original is used (it must pass the same check). An empty file is never sideloaded; the row is an `error` and nothing is saved. A sideloaded attachment that still reads as broken is flagged `_osn_broken` = `1` and not linked.
- 0.3.9+: the same-URL reuse lookup skips attachments whose file is missing, 1 KB or smaller, or whose metadata width is under 300 (or that carry `_osn_broken`), flags them `_osn_broken` = `1` (not on `dry_run`) and sideloads a fresh image instead.
- Saved as a Media Library attachment attached to the venue (title `{Venue} — photo`, alt `{Venue} in {City}`, meta `_osn_source_image_url`). The featured image is set with `update_post_meta('_thumbnail_id')`, so the venue's `post_modified` does not change. Venue meta written: `hide_post_image = yes` (stops Themify printing its own featured image above the plugin hero), `osn_photo_credit_url`, `osn_photo_credit_text`, `osn_photo_source_url`.
- Side effects: same cache purge as the upsert (sorted list, `osn_city_venues_changed`, landing page cache, state page data).

Response: `{ "dry_run": bool, "counts": {set,skipped,rejected,error}, "results": [ { id, action: set|skipped|rejected|error, reason, attachment_id, width, height } ] }`.

Where it shows: venue cards (16:10, `loading=lazy`, srcset), a 21:9 hero with a credit link on the venue page, the state page "Popular cities" cards, and a `photo` sort tiebreaker (outdoor seating, rating count, **has photo**, has summary, title). Venues without a photo show a short tinted category-icon band on cards and no hero on the page.


## Homepage: `POST /wp-json/osn/v1/home` (plugin 0.3.0+)

Configures the homepage takeover content. Needs `manage_options`. It does not switch the takeover on: that is the checkbox in Tools > Outdoor Seating > Homepage (option `osn_home_takeover`, default off). Preview with it off: `/?osn_home_preview=1` as a logged-in admin.

```json
{
  "h1": "Find Restaurants with Outdoor Seating",
  "search_heading": "Search by city",
  "intro": "Plain text under the H1.",
  "seo_html": "<h2>Outdoor Dining, City by City</h2><p>...</p>",
  "carousel": [ { "label": "Seattle, WA", "url": "https://outdoorseatingnearme.com/seattle-wa/", "attachment_id": 123 } ],
  "state_images": { "WA": 456, "OR": "https://outdoorseatingnearme.com/wp-content/uploads/2025/08/IMG_5149.png" }
}
```

- Every field is optional; fields left out are kept. Sending an empty value (`""`, `[]`) resets that field to its default.
- `h1` (0.3.9+, option `osn_home_h1`): the homepage H1, plain text. Default "Find Restaurants with Outdoor Seating" (was "Outdoor Seating Near Me"). The `osn_front_page_h1_text` filter is still applied on top. The page has exactly one H1.
- `search_heading` (0.3.9+, option `osn_home_search_heading`): heading of the city search panel. Default "Search by city" (was "Find outdoor seating in your city"). The one-line search intro is unchanged.
- Both are also editable in Tools > Outdoor Seating > Homepage, and returned by `GET /osn/v1/home`.
- `intro`: plain text. Default is the "Find restaurants, bars, breweries, and cafés ..." paragraph.
- `seo_html`: passed through `wp_kses_post`. Default is the "Outdoor Dining, City by City" block.
- `carousel`: array (or a JSON string of one), at most 24 items. Each needs `label` ("City, ST") and `url` (http/https, or a path starting with `/`); `attachment_id` is optional (0 or missing shows the icon band). The "{n} patios" line is computed from published venues for the label's state. Invalid input returns HTTP 400 (`osn_carousel_json`, `osn_carousel_shape`, `osn_carousel_size`, `osn_carousel_row`) and nothing is saved.
- `state_images`: map of WA/OR/CA/ID/AZ/NV to an attachment ID or image URL. States left out use the image already linked to that state's page on the current front page, else the icon band.
- Response: `{ updated: [option names], takeover, h1, search_heading, intro, seo_html, carousel, state_images, preview_url }`. `GET /osn/v1/home` returns the same config without writing.

Related: `GET /wp-json/osn/v1/cities?scope=all[&state=AZ]` (public, cached 5 minutes by `Cache-Control`) lists every city linked from the six state pages (rows `{ n, u, s, p }`), which feeds the homepage search. It is built from the state pages' city tables and cached 12 hours in a transient, cleared when a state page or the front page is saved.


## Takeover: `GET|POST /wp-json/osn/v1/takeover` (plugin 0.4.0+)

Switches the city and state card takeovers on or off remotely instead of pasting lists into Tools > Outdoor Seating. Needs `manage_options`. Stored as option `osn_takeover_cities` (newline-separated "City, ST" lines) and `osn_state_takeover` (array of two-letter state codes).

```json
{ "cities": ["Phoenix, AZ", "Tempe, AZ"], "states": ["AZ", "Washington"], "mode": "add", "dry_run": true }
```

- `GET` returns `{ cities: [...], states: ["AZ", ...] }` as stored.
- `mode`: `add` (default; union, case-insensitive de-dupe, existing order kept, new ones appended), `remove` (drops the listed ones), `replace` (overwrites). A field left out (`cities` or `states`) is not touched.
- Cities must match `^.+, [A-Z]{2}$` after trim. States may be codes or names (WA OR CA ID AZ NV / Washington ...), case-insensitive, stored as codes. Anything else goes into `rejected`; the rest of the request still applies.
- Response: `{ mode, dry_run, added, removed, rejected, cities_total, states }`. `added`/`removed` list cities and state codes together. `dry_run` computes the same response without saving.
- Saving uses `update_option`, so the existing option hooks purge the affected city landing pages and state pages.

## Photo optimizer: `POST /wp-json/osn/v1/venues/images/optimize` (plugin 0.3.6+)

Converts photos sideloaded before 0.3.6 in place. Needs `manage_options`.

```json
{ "offset": 0, "limit": 20, "dry_run": false }
```

- Targets attachments carrying `_osn_source_image_url` whose mime is not WebP (`limit` default 20, max 100).
- Per attachment: re-encodes the attached file (rotate, <= 1600 wide, WebP q82), points `_wp_attached_file` and `post_mime_type` at it, regenerates metadata and sub-sizes, then deletes the old file, old sub-sizes and edit backups. The **attachment ID is unchanged**, so every venue's `_thumbnail_id` stays valid; the cities of venues using it get the usual cache purge.
- Response: `{ dry_run, pending, counts: {converted, would_convert, skipped, error}, next_offset, results: [ { id, action: converted|would_convert|skipped|error, reason, before_bytes, after_bytes, before_total, after_total } ] }`. `*_bytes` is the original file, `*_total` includes sub-sizes.
- Converted rows leave the pending set, so `next_offset` only counts rows that stayed (dry run, skipped, error): keep calling with the returned `next_offset` until it is `null`.
- 0.3.9+: the new file is verified (exists, > 1024 bytes, readable, width >= 300, mime matches) **before** the attachment, its metadata or any old file is touched. If WebP fails verification, JPEG q82 is tried (same check; the attachment then becomes `.jpg`; a file that is already JPEG is just `skipped`). If both fail, only the NEW file is deleted, the original and metadata stay, and the row is `action: "failed_verification"` with the reason. `counts` gains `failed_verification`. Old files are removed only after the metadata is rebuilt and checked (width >= 300).


## Photo repair: `POST /wp-json/osn/v1/venues/images/repair` (plugin 0.3.9+)

Fixes attachments broken by the 0.3.6 optimizer (0-byte `.webp`, metadata width 0 or 1). Needs `manage_options`.

```json
{ "offset": 0, "limit": 10, "dry_run": true }
```

- `limit` default 10, max 50. Run with `dry_run: true` first, then without.
- Targets attachments with `_osn_source_image_url` whose file is missing or 1 KB or smaller, whose metadata width is under 300, or that are flagged `_osn_broken`.
- Per attachment: re-downloads the source URL (public http(s) host, no IPv6 literal, 8 MB cap, `wp_getimagesize` must say jpeg/png/webp and >= 300 wide), resizes to <= 1600 and writes a verified WebP (JPEG fallback, then the verified download) into the current uploads month folder, **into the same attachment ID** (`_wp_attached_file`, `post_mime_type` and metadata/sub-sizes rebuilt, `_osn_broken` cleared, stale old files of that attachment removed). Attachments are never deleted.
- Relinking (`update_post_meta` on `_thumbnail_id`, so `post_modified` is untouched): every venue that has no featured image (thumbnail 0 or dangling) and either has `osn_photo_source_url` equal to the attachment's source URL or is the attachment's `post_parent`. City caches of affected venues are purged.
- If the download or the re-encode fails the attachment stays flagged `_osn_broken` and the row is `unrecoverable` with the reason.
- Response: `{ dry_run, broken, counts: {repaired, would_repair, ok, unrecoverable}, next_offset, results: [ { id, action: repaired|would_repair|ok|unrecoverable, reason, bytes, width, venues_relinked: [venue ids] } ] }`. `ok` = flagged but the file is actually healthy (flag cleared, venues relinked). Repaired rows leave the broken set, so `next_offset` only counts rows that stayed; repeat with it until it is `null`.


## SEO (plugin 0.3.6+)

The site runs DoS Toolkit's SEO module (not All in One SEO). The plugin feeds it, and core sitemaps, as follows.

### Seed titles and descriptions: `POST /wp-json/osn/v1/seo/seed`

Needs `manage_options`.

```json
{ "scope": "all", "dry_run": true, "offset": 0, "limit": 50 }
```

- `scope`: `home` | `states` | `cities` | `all` (default). `limit` default 50, max 200. Order is front page, state pages, then city pages by ID.
- Response: `{ dry_run, scope, total, counts: {set, kept, skipped}, next_offset, results: [ { id, slug, kind: home|state|city, title, description, action: set|kept|skipped, fields } ] }`. Page with `next_offset` until it is `null`. Also available as the "Seed SEO titles/descriptions" button in Tools > Outdoor Seating (batches of 50, dry run ticked by default).
- Writes the Toolkit meta `_dos_seo_title` and `_dos_seo_description` **only** when the value is empty, or still exactly what this endpoint last wrote (`_osn_seo_auto` = 1 and a per-field md5 in `_osn_seo_auto_hash`). A value that was hand-edited, or that something else wrote, is `kept`. Re-running is safe; a re-run after the venue counts or state-page links change refreshes only untouched values.
- Front page: the Toolkit reads its description from its own **Homepage description** setting (`seo_home_description`), not the post meta, so that setting is seeded under the same rules (hash in option `osn_seo_home_hash`). `fields.home_setting` reports it.
- Text:
  - Front page: title `Outdoor Seating Near Me | Patios, Rooftops & Restaurants in the West`; description = `osn_home_intro` (or its default) cut to 160 characters at a word boundary.
  - State pages (slugs from `osn_state_pages`): title `Outdoor Seating in {State} | Restaurants with Patios`; description `Find restaurants, bars, and cafés with patios and outdoor seating in {N} {State} cities, including {A}, {B}, and {C}. Search your city, then filter for happy hour, dog-friendly patios, and more.` N = city links parsed from the page, A-C = the three cities with the most published venues (padded with the first parsed links). Over 160 characters the last sentence is dropped, which in practice is always.
  - City pages (any page with `[table ... filter="City, ST"]`): title `Outdoor Seating in {City, ST} | Patios & Restaurants`; description `Restaurants, bars, and cafés with outdoor seating in {City, ST}. See patios, hours, ratings, and filter for happy hour, dog-friendly spots, brunch, and more.` when the city has a published venue, else `Restaurants, bars, and cafés with patios and outdoor seating in {City, ST}, with addresses, phone numbers, and websites.` A filter without a 2-letter state (`Roxbury, Seattle`) is used as written.
- The Toolkit cuts descriptions longer than 155 characters (word boundary plus an ellipsis), so the 156 to 160 character texts above render shortened. Filters: `osn_seo_description_max` (default 160) and `osn_seo_text` ( `$text, $post, $kind` ).

### Venue titles

Single venues are not seeded. While the Toolkit SEO module is active and a venue has no `_dos_seo_title`, its document title is `{Venue} – Outdoor Seating in {City, ST}` via `pre_get_document_title` (priority 5, ahead of the Toolkit's 10), so the Toolkit's `og:title`, twitter title and schema name follow. A hand-written `_dos_seo_title` wins. Filter: `osn_venue_seo_title`. Nothing changes when the Toolkit SEO module is off.

### Schema

With the Toolkit SEO module active the plugin never prints Organization, WebSite or WebPage nodes (the Toolkit owns that graph; `Util::json_ld` drops them). What the plugin itself emits: `Restaurant` (venue page), `ItemList` (card grids, state "Popular cities", homepage carousel). It has never emitted WebSite/WebPage/Organization; duplicates seen on the live site come from another source (theme or another plugin).

### Sitemaps (core `wp-sitemap.xml`)

- The users sitemap is removed (`wp_sitemaps_add_provider`), and the plugin's three private taxonomies are removed from the taxonomy sitemaps.
- The `osn_venue` sitemap leaves out thin venues: those for which `Venue_Page::is_thin()` is true (no summary, no confirmed patio, no hours), the same rule that makes the page `noindex,follow`. The flag is post meta `_osn_thin` = `1` (deleted when not thin), refreshed on every upsert, every venue save, and by the backfill. A venue without the flag is treated as not thin, so **run the backfill once after upgrading**.
- Backfill: `POST /wp-json/osn/v1/venues/excerpts {offset, limit}` (`manage_options`) now also refreshes `_osn_thin`: `{ scanned, changed, flags_changed, thin, next_offset }`; page until `next_offset` is `null`.
