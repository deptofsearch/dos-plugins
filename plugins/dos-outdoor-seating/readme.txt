=== DoS Outdoor Seating ===
Contributors: departmentofsearch
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.6.3
License: GPL-2.0-or-later

Venue pages, searchable patio card grids, a REST upsert for the n8n enrichment pipeline, venue photos, a city search box, redesigned state pages, an opt-in homepage takeover, an opt-in TablePress takeover, and SEO titles/descriptions and sitemap rules for DoS Toolkit.

== Description ==

* Custom post type `osn_venue` (permalinks `/restaurants/<name>-<city>-<st>/`) with admin-only taxonomies `osn_city`, `osn_amenity`, `osn_category`.
* `POST /wp-json/osn/v1/venues/upsert` (needs `edit_posts`; max 50 per call; `dry_run` supported). Dedupes on `source` + `source_id`, then `google_place_id`, then `maps_cid`. Unchanged records are skipped by a per-pass hash. Merge semantics: fields left out are kept, `null` clears a field. Full field list: docs/UPSERT-CONTRACT.md in the project repo.
* `GET /wp-json/osn/v1/cities[?state=AZ]` public cached city index.
* Single venue page via `the_content`: rating, summary intro, outdoor seating callout, chip sections (Food & drinks, Highlights, Good to know) from Google Maps listing details, Monday-first hours with an open-now badge, map, contact, more nearby, source line, Restaurant JSON-LD. Thin pages (no summary, no confirmed patio, no hours) are noindex,follow.
* `[osn_venues city="Phoenix, AZ" limit="0" filters="1" search="1"]` card grid with ItemList JSON-LD. Permanently closed venues are left out.
* `[osn_city_search state="AZ"]` typeahead built from cities with a linked landing page; lists over 300 cities load from the REST endpoint on first focus.
* Tools > Outdoor Seating: TablePress takeover list (`City, ST` per line, or `*`), accent/text colors, venue counts. The takeover hooks `pre_do_shortcode_tag` for `[table filter="City, ST"]`; if the city is not enabled or has no visible venues, TablePress renders as normal. If TablePress is not installed, a fallback `table` shortcode renders the cards.
* `POST /wp-json/osn/v1/venues/image` (needs `upload_files` + `edit_posts`; max 5 per call; `dry_run`): downloads an image URL found on the restaurant's own website, validates it (jpeg/png/webp, at least 600x300, 1.2:1 to 2.4:1, 8 MB, no logo/icon/favicon/sprite/placeholder names), sideloads it and sets the featured image without changing the venue's modified date. Credit link stored in `osn_photo_credit_url` / `osn_photo_credit_text`.
* Cards use the featured image; venues without one get a short tinted band with a category icon. Venue pages show a 21:9 hero with a credit link.
* `[osn_state_cities state="AZ"]` state page UI: city search over every city the state page links to, "Popular cities" photo cards, A-Z city tiles, ItemList JSON-LD. Tools > Outdoor Seating > "State page takeover" swaps the HTML city tables on the checked states' pages for it without editing the pages.
* Action `osn_city_venues_changed( $term_id, $landing_page_id )` fires after upserts and takeover changes so page caches can purge.

* Homepage takeover (Tools > Outdoor Seating > Homepage, setting `osn_home_takeover`, default off): replaces the front page content with H1, intro, a city search over every city on the six state pages, SEO copy, a "Popular cities" scroll-snap carousel and "Browse by state" tiles. `GET /osn/v1/cities?scope=all` serves the full city list; `POST /osn/v1/home` configures the intro, SEO HTML, carousel and state images (see docs/UPSERT-CONTRACT.md).

* `POST /wp-json/osn/v1/seo/seed` (needs `manage_options`; `{scope: home|states|cities|all, dry_run, offset, limit}`) and a "Seed SEO titles/descriptions" button in Tools > Outdoor Seating write DoS Toolkit's per-page title and meta description for the homepage, state pages and city pages. Only empty values, or values the plugin wrote and nobody edited since, are written. Venue titles read "Venue – Outdoor Seating in City, ST" while the Toolkit SEO module is active.
* Core sitemaps: no users sitemap, and noindexed thin venues are left out of the venue sitemap (flag `_osn_thin`).
* `POST /wp-json/osn/v1/venues/images/optimize` (needs `manage_options`) converts earlier venue photos to WebP in place (every new file is verified first; originals are only removed after it passes). `POST /wp-json/osn/v1/venues/images/repair` (needs `manage_options`; `{offset, limit, dry_run}`) rebuilds broken (empty/missing) venue photos in place from their source URL and relinks venues.

== Third-party ==

Category icons are from Lucide (https://lucide.dev), ISC License, Copyright (c) Lucide Icons and Contributors. See LICENSES.txt.

== Changelog ==

= 0.6.3 =
* Landmark zones: new flat taxonomy `osn_landmark` (per-city terms, slugs prefixed with the city slug) with term meta osn_city, osn_order, osn_page_id, and venue post meta `_osn_casino` / `_osn_casino_slug`. Generic for any city; labels come from the term data.
* New admin REST routes (`manage_options`, `dry_run` where they write): POST /osn/v1/landmarks/import, POST /osn/v1/landmarks/assign (`replace: true` also clears venues of the city that are not listed), POST /osn/v1/landmarks/pages, GET /osn/v1/landmarks?city=.
* City cards: "Landmarks" chip row (term order, with counts), a casino chip row for the active landmark, "At <casino>" label on cards, "Browse by landmark" list. Client-side filtering (?spot=short-slug, ?casino=slug) ANDs with the neighborhood, amenity and search filters; the server HTML is identical for every query string. Cities without landmark terms render exactly as before.
* Landmark landing pages: a page linked to a landmark term (osn_page_id) renders the city's venues limited to that landmark, a casino chip row, "More landmarks" and a link back to the city. Optional term meta `osn_h1` (import field `h1`) sets the H1 and the SEO title stem; the default H1 is "Landmark: Outdoor Seating in City". A landmark with no visible venues falls back to the plain city grid and H1. The SEO seed gives landmark pages their own title and description.
* City names in the landmark routes are matched to the canonical city term (case/spacing variants work). `landmarks/import` `page_id` and `landmarks/pages` refuse pages that are not pages, are hood pages, or belong to another landmark; `hoods/pages` refuses landmark pages.

= 0.6.2 =
* Takeover: the tableless-city rule now reads TablePress's JSON `tablepress_tables` option correctly and only applies to a missing or `id=0` table, so every `[table id=N]` page keeps its exact current behaviour.
* Homepage preview/draft responses send no-cache headers; the draft is never returned in REST responses. City index changes also clear the homepage cache.
* SEO state descriptions say "1 Colorado city" and only name fallback cities the state page lists. Tools > Outdoor Seating skips state stats for states without a page. `cities/register` returns landing-page warnings.

= 0.6.1 =
* New states without a TablePress table: `[table id=0 filter="Denver, CO" /]` city pages work when the city is enabled for the takeover; with no venues yet the page says so instead of TablePress's "table not found".
* New POST /osn/v1/cities/register (`manage_options`, `dry_run`): creates the city terms and links their landing pages before any venue exists, so a new city shows on its state page, the city search and the homepage.
* State pages built around `[osn_state_cities state="CO"]` (no HTML city table) now count their cities from the city index on the homepage tiles and in SEO descriptions.
* Homepage draft preview: POST /osn/v1/home/draft stores intro, SEO copy and state images; /?osn_home_draft=1 (admin) renders them, including tiles for state pages that are still drafts, without changing the live homepage.
* SEO fallback city lists for NM, CO, UT, TX, IL, FL, NY and GA.

= 0.6.0 =
* All 50 states + DC: single state table drives state names, page slugs, timezones, state takeover and SEO seeding.

= 0.5.2 =
* "Browse by neighborhood" is an accordion: one native details/summary per district (name, count, chevron), collapsed by default, links stay in the HTML. A page opened with ?district= or ?hood= opens that district. Neighborhood links use a compact auto-fill grid; district URLs drop the internal -district suffix (?district=ballard, the old form still loads); the section heading uses the plugin's h2 size instead of the theme's.

= 0.5.1 =
* City page filters are two clearly separate sections: a "Neighborhoods" panel (district chips with the active district's neighborhoods nested underneath) and "Filter by" (amenities, top 8 by count with "More filters (N)" at every width). Search stays on top. Chip rows scroll horizontally on phones.
* Fix: district/neighborhood chips no longer keep a stale active look after another chip is clicked; active, hover and focus styles are now identical to the amenity chips.

= 0.5.0 =
* Neighborhoods: new hierarchical taxonomy `osn_hood` (districts > neighborhoods, slugs prefixed with the city slug) with term meta osn_city, osn_page_id, osn_neighbors, osn_redirect. Generic for any city; Seattle first. Docs: docs/NEIGHBORHOODS.md.
* New admin REST routes (`manage_options`): POST /osn/v1/hoods/import, GET /osn/v1/hoods/venues, POST /osn/v1/hoods/assign, POST /osn/v1/hoods/pages, GET /osn/v1/hoods (all with dry_run where they write).
* City cards: district chip row, neighborhood chip row, per-card neighborhood label, "Browse by neighborhood" section (#hoods). Client-side filtering combines with amenity chips and search; URL state ?district=slug / ?hood=slug.
* Neighborhood landing pages: a [table filter="Hood, City"] on a page linked to (or named for) a hood of an enabled city renders that neighborhood's cards, a "Nearby neighborhoods" strip and a link back to the city. H1 "Outdoor Seating in Hood, City".
* Pages with `_osn_redirect` post meta 301 (enabled cities only), are left out of the core sitemap and are noindex if rendered.
* Venue pages show "Neighborhood · City, ST" in the header, linked when a landing page exists.

= 0.4.2 =
* Homepage takeover: flipped the lead row. The SEO copy's first heading and paragraph now sit on the left, with the city search box on the right. Mobile still stacks search first.

= 0.4.1 =
* Homepage takeover: the city search box now takes the left half of the row, with the SEO copy's first heading and paragraph beside it on the right. The two stack (search first) at 900px and below.

= 0.4.0 =
* New GET/POST /osn/v1/takeover (`manage_options`): read or change the takeover cities and states remotely (add/remove/replace, dry_run).

= 0.3.9 =
* Homepage takeover: editable H1 (option osn_home_h1, default "Find Restaurants with Outdoor Seating") and city search panel heading (osn_home_search_heading, default "Search by city"), in Tools > Outdoor Seating > Homepage and via POST/GET /osn/v1/home (`h1`, `search_heading`; empty resets). The osn_front_page_h1_text filter still applies on top.
* Fix: the 0.3.6 WebP optimizer could leave 0-byte .webp files and delete the originals. Every re-encoded file is now verified (exists, over 1 KB, readable with width >= 300, matching mime) before anything is touched; a failed WebP is deleted and JPEG q82 is tried, and if both fail the original and its metadata stay as they were (optimizer action `failed_verification`). Same verification in the sideload (falls back to JPEG, then the downloaded original; an empty file is never sideloaded).
* Fix: the "reuse the attachment from the same source URL" lookup no longer reuses attachments whose file is missing, 1 KB or less, or whose metadata width is under 300; they get post meta `_osn_broken` = 1 and a fresh image is sideloaded.
* New `POST /osn/v1/venues/images/repair` (`manage_options`; `{offset, limit (default 10, max 50), dry_run}`): re-downloads the source image of each broken attachment into the same attachment ID (verified WebP, JPEG fallback), clears `_osn_broken`, and sets `_thumbnail_id` back on venues that lost it (thumbnail empty and the attachment is their `osn_photo_source_url` or their child). Never deletes attachments.

= 0.3.8 =
* Old .png/.jpg URLs of venue photos converted to WebP now 301 to the WebP of the closest size, so cached pages and image search never show broken images.

= 0.3.7 =
* State SEO descriptions name each state's best-known cities (filter osn_state_major_cities) until it has venue data, instead of the first cities alphabetically. City description shortened to fit the Toolkit's 155-character cut.

= 0.3.6 =
* SEO for DoS Toolkit: POST /osn/v1/seo/seed seeds titles and descriptions on the homepage (including the Toolkit's Homepage description setting), state pages and city/neighborhood pages, never overwriting a hand-edited value; Tools > Outdoor Seating button. Venue document title "{Venue} – Outdoor Seating in {City, ST}" while the Toolkit SEO module is active and the venue has no title of its own (og:title follows).
* No Organization/WebSite/WebPage JSON-LD from this plugin while the Toolkit SEO module is active (it never printed them; guard added). Restaurant and ItemList stay.
* Core sitemaps: users sitemap removed, plugin taxonomies excluded, thin (noindex) venues excluded via _osn_thin. The thin rule is now shared (Venue_Page::is_thin). Flag refreshed on upsert and venue save; backfill with POST /osn/v1/venues/excerpts (run once after upgrading).
* Venue photos are always saved as WebP q82 (JPEG q82 without WebP support), max 1600 px wide, so the original and all sub-sizes are small. New POST /osn/v1/venues/images/optimize converts photos sideloaded earlier in place (same attachment ID, old files deleted).

= 0.3.5 =
* Venue excerpts auto-filled from the summary (or a generated "Name, category in City, ST" line) so SEO plugins such as DoS Toolkit output real meta descriptions. Never overwrites a hand-written excerpt. Backfill: POST /osn/v1/venues/excerpts {offset, limit} (manage_options).

= 0.3.4 =
* Homepage widened: block is min(1280px, viewport - 48px); intro, search and SEO copy now span the full width, aligned with the carousel. Reverts the 0.3.3 vertical tightening and logo resize (back to 0.3.2 spacing).

= 0.3.3 =
* Tighter homepage spacing (20px between sections, slimmer search panel and SEO headings) and a smaller desktop text logo (max 52px), ~120px shorter above the fold.

= 0.3.2 =
* Tighter header site-wide: empty desktop side-menu block collapsed, logo padding trimmed (logo to content gap ~80px -> ~23px on desktop). Homepage top padding 20px -> 8px.

= 0.3.1 =
* Upserts of existing venues use wp_update_post, so updates may omit title/content as the contract says (was: "Content, title, and excerpt are empty").
* Site-wide mobile fix: Themify Landing text logo no longer runs into the menu icon at <=600px (filter osn_mobile_logo_fix).

= 0.3.0 =
* New homepage takeover module (class Home), opt in via Tools > Outdoor Seating > Homepage. Order: H1, intro (osn_home_intro), city search "Find outdoor seating in your city", SEO copy (osn_home_seo_html), "Popular cities" carousel (osn_home_carousel JSON; 4:3 cards, patio counts, ItemList JSON-LD, prev/next, swipe, keyboard), "Browse by state" tiles (osn_home_state_images, default: the images already on the front page; city counts).
* Beats Themify Builder three ways: Builder's stored layout meta is hidden from the front-page main query, a the_content filter at PHP_INT_MAX - 10 returns only the takeover markup (once, main loop only), and body.osn-home-on CSS hides any .themify_builder_content that does not contain it. Page_Title adds no second H1. Header and spacing are tightened under body.home.osn-home-on (tagline hidden). Admin preview link: /?osn_home_preview=1.
* GET /osn/v1/cities?scope=all: every city linked from the six state pages (union with the city index), cached 12 hours in a transient and cleared when a state page or the front page is saved. The home search loads it on first focus.
* POST /osn/v1/home (manage_options) sets intro, seo_html, carousel and state_images; GET returns the config. Invalid carousel JSON is rejected (admin notice / HTTP 400).
* City_Search::render takes an optional lazy source URL; State_Cities::local_key is public.

= 0.2.8 =
* Cards: photo and no-photo cards share one 16:10 media box (the category-icon band fills it, icon centered), so rows line up on city grids and on the state page's popular-city cards. Popular cities grid is 4 columns on desktop, 2 at 900px and below, 1 at 480px and below; each card is one link.
* Mobile (640px and below): the filter chips collapse to the first 6 plus a "More filters (N)" toggle (aria-expanded). Stays open while a hidden chip is active. Desktop and no-JS show every chip.
* State takeover also drops a standalone "Welcome!" paragraph, in addition to the "Here is {State} so far:" line.

= 0.2.7 =
* Venue image aspect range widened to 1.0:1-3.2:1 (wide banners and square photos crop fine to the 16:10 card / 21:9 hero). Filters: osn_image_min_ratio, osn_image_max_ratio.

= 0.2.6 =
* New REST route POST /osn/v1/venues/image: venue photos from each restaurant's own website (download, validate, sideload, set as featured image; credit meta). See docs/UPSERT-CONTRACT.md.
* Cards show the featured image (16:10, lazy, srcset); venue pages get a 21:9 hero with a credit link. New `photo` sort tiebreaker: venues with photos sort first after outdoor seating and rating count.
* No-photo cards use a short tinted category-icon band (Lucide, ISC) instead of the big letter block. Venue pages without a photo show no hero.
* State pages: [osn_state_cities] (search, popular city cards, A-Z tiles, JSON-LD) and the opt-in per-state takeover (setting osn_state_takeover) that replaces the page's HTML city tables without editing the page. Per-state data is cached in a transient and invalidated with the city caches. Hooks: osn_state_page_changed.
* Tools > Outdoor Seating: state takeover checkboxes and a State pages table (parsed city links, cities with venues).


= 0.2.5 =
* City grid filter chips and card chips use a fixed priority (outdoor seating, happy hour, dog friendly, brunch, live music ... wheelchair accessible last) instead of count order. Filter: osn_amenity_chip_order.
* Venue page chip sections drop generic values (Seating, Food, Alcohol, Dine-in, Credit cards ...). Filter: osn_hidden_detail_values.
* Placeholder initial skips a leading article ("The Vig" shows V).
* State pages (filter osn_state_pages) and the front page get a modest H1 too ("Outdoor Seating in Arizona", "Outdoor Seating Near Me"; filter osn_front_page_h1_text).

= 0.2.4 =
* City pages (any page with [table filter="City, ST"]) get an H1 "Outdoor Seating in City, ST", 24-30px, with the page's intro H3s scaled below it. Filters: osn_city_page_h1, osn_city_page_h1_text.
* Venue H1 reduced to the same 24-30px range; section h2s 18-22px.

= 0.2.3 =
* Venue pages print the venue name as an H1 (Themify Landing hides post titles; filter osn_venue_print_title).
* Venue pages use the full-width layout instead of an empty sidebar column (filter osn_venue_full_width).
* Plugin h2 headings sized down so theme display headings (72px uppercase) don't swamp the page.

= 0.2.2 =
* New `price_range` field (Google range text like "$10–60"), shown on cards, venue header and JSON-LD priceRange when price_level is empty.

= 0.2.1 =
* Drop the duplicate "Outdoor seating" chip from Good to know (the callout covers it).

= 0.2.0 =
* Data source is Google Maps listing data (Maps Data): details_json, Maps-shaped hours_json, maps_cid, data_sources, business_status.
* editorial_summary renamed summary; review_summary removed; old period-based hours and attribute handling removed (a one-time migration cleans old meta).
* Takeover now uses pre_do_shortcode_tag (the 0.1.0 wrapper never ran after TablePress registered).
* Taxonomies no longer public; per-pass hashes; explicit source + source_id; cache purge action; city index and sorted-list caching; noindex for thin pages; landing_page_id / landing_slug.
* Requires PHP 8.1.

= 0.1.0 =
* Initial release.
