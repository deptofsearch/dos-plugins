=== DoS Best Lenders ===
Contributors: departmentofsearch
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.6.2
License: GPLv2 or later

City pages of mortgage lenders built from public HMDA data, with filterable lender cards and a "BLNM Score".

== Description ==

* Post types: `blnm_city` (/mortgage-lenders/<city>-<st>/) and `blnm_lender` (/lenders/<lei>/).
* City data is stored as post meta, all exposed in REST: `blnm_city_name`, `blnm_state`, `blnm_county_fips`, `blnm_data_year`, `blnm_updated_at`, and `blnm_lenders_json` (JSON array of lender rows). `blnm_lender_count` and `blnm_loan_total` are derived on write.
* City page: header, filter bar (loan type, lender type, minimum loans, sort), responsive card grid, HMDA disclosure. Filtering is client-side. The grid is appended to the page content through `the_content`, so the theme's own layout stays in charge.
* Shortcodes: `[blnm_city_search]`, `[blnm_state_index state="WA"]`.
* REST (namespace `blnm/v1`): `GET /cities` (public city list, optional `?state=WA`), `POST /cities/upsert`, `POST /lenders/upsert` (both need a user who can publish posts, via application password). Standard `wp/v2/blnm-cities` and `wp/v2/blnm-lenders` also work.
* Reviews (0.2.0): each lender row may carry a Google rating, count, Maps link, as-of date, an original review summary, pros/cons, branch name/address and a builder-lender flag. Shown as visible text only; no schema.org Review/AggregateRating markup is output for Google data.
* Settings -> DoS Best Lenders: GA4 Measurement ID (gtag in wp_head, skipped for admins) and a contact email used by `[blnm_contact]` (entity-obfuscated mailto link).
* City meta `blnm_county_name` (without the word County) drives the header "N lenders in <County> County, <ST> · 2025 home-purchase loans".
* Partial updates: `POST /cities/upsert` on an existing slug changes only the keys sent. Omit `lenders` to keep blnm_lenders_json; send `"lenders": []` to clear it.

= Lender row (blnm_lenders_json) =
`{lei, name, type: bank|credit_union|mortgage_company, loans_2025, approval_rate (0-100), median_rate (percent), city_median_rate (percent), loan_types: [conventional,fha,va,usda,jumbo], score (0-100), nmls_url, place_id?}`

Optional review fields (whitelisted and clamped): `google_rating` (0-5, 1 decimal), `google_review_count` (int), `google_maps_url` (https only), `rating_as_of` (YYYY-MM-DD), `review_summary` (plain text, 900 chars max), `review_pros` (4 max), `review_cons` (3 max), `is_builder_lender` (bool), `branch_name`, `branch_address`.

The same review fields plus `summary` are accepted by `POST /lenders/upsert` (stored as `blnm_review_json` and `blnm_summary`) and shown on the lender profile.

== Changelog ==

= 0.6.2 =
* State hub: the County and Sort dropdowns are gone. Cities always list by population (ties by name); old ?county= and ?sort= links are ignored. What remains is the city name box, a Has lenders checkbox, the live count and Reset, laid out side by side on desktop and stacked on phones.
* Hub cards show cleaned lender names: the branch/Google name when stored, otherwise ALL CAPS legal names in title case without the trailing Inc./LLC/N.A.

= 0.6.1 =
* Geometry route: a failed TIGERweb fetch now reports why (per-layer error, HTTP code, start of the reply) in the 502, and sends a plain user-agent.
* POST /states/XX/geometry also accepts a JSON body {"geojson": FeatureCollection} (admin only, validated) for hosts that cannot reach TIGERweb.

= 0.6.0 =
* State pages ([blnm_state_index state="XX"]) become a filterable grid of city cards with a county map tile, lender counts and top lenders. Filters work without JavaScript.
* New admin routes to fetch county outlines (Census TIGERweb) and build or clear map tiles per state.
* City upsert accepts lat, lng and population; sending only those leaves everything else untouched.
* State pages no longer print the featured image twice (the hub shows it as a hero).

= 0.5.1 =
* First release from the shared dos-plugins repository: merges the two 0.4.9 lines and the 0.5.0 manual build. Nothing is lost from either. See CHANGELOG.md.
* Updates itself from GitHub releases (shared DoS updater, tag `dos-best-lenders-v<version>`, asset `dos-best-lenders.zip`).

= 0.5.0 =
* Homepage "Browse by state": `[blnm_state_index]` with no state now lists state names (e.g. Washington, "91 cities") linking to each state page (page slug = slugified state name), instead of every city under a bare code. Only states with a published city and a published state page appear. `[blnm_state_index state="WA"]` is unchanged. List is cached and flushed with the city index and on page publish/update/delete.
* wp-admin only: the City list gets a sortable "Lenders" column, a "No lenders (N)" view, a State filter, and a county / nearby-towns column. Count uses the stored `blnm_lender_count` meta (backfilled when missing). 
* City search index URL now carries `?v=<blnm_city_index_ver>`, bumped whenever the index is flushed (publish/unpublish/update/delete), so browsers never keep a stale empty index.

= 0.4.9 =
* "What reviewers mention" starts closed on every screen; clicking it opens the summary, highlights and pros. It used to start open on desktop and close only on phones. The text is still in the page HTML. Version bump so the changed blnm.js loads past caches.
* Cities with no qualifying lenders no longer show the "0 lenders made 0 home loans" summary line. Singular forms checked ("1 lender made 1 home loan").
* Homepage city search works by city name: accepts "Seattle", "seattle wa", "Seattle, Washington", "Saint/St. Helens", extra spaces and partial prefixes; typeahead from 2 characters with keyboard navigation. A name in several states lists each state and asks the visitor to pick; no match shows a friendly message instead of a 404 or search page.
* No-JS search fallback (?s=&post_type=blnm_city) redirects to the city page when exactly one published city matches, otherwise falls through.
* `GET blnm/v1/cities` payload is now `{ n: city, s: state, u: url }` (city name no longer includes ", ST"); cache invalidates on city publish, unpublish, update, delete and meta changes (including first-time meta on create).

= 0.4.8 =
* Defers meta description and Open Graph/Twitter tags to the DoS Toolkit SEO module when it is active (same as for Yoast, Rank Math, AIOSEO, SEOPress).
* Cities whose county has no qualifying lender (checked, `reviewed_at` set, empty list) show a fixed explanation and up to 3 nearest towns with listed lenders (new `nearby` field on cities/upsert: slug, city, state, county, distance_mi, lender_count, top_lenders). Links resolve from the slug and only point at published pages. Cities not yet checked keep the "still checking" line.

= 0.4.7 =
* The BLNM Score is renamed the Local Lending Score (LLS): badge label "LLS" (full name on hover), "Local Lending Score" in the sort menu and screen-reader heading, "LLS" in the state table header. Data keys stay `score`.

= 0.4.6 =
* Google rating filter only lists thresholds that narrow the page without emptying it (e.g. no "5.0 only" when no lender is rated 5.0). With no useful threshold the filter is hidden; the Google rating sort stays.

= 0.4.5 =
* Review block leads with 2-3 paraphrased client experiences ("One client said ...", "Another client described ...") from the new `review_highlights` field (max 3, 240 chars each; accepted on cities and lenders upsert), then the overall summary and pros/cons. Our own writing: no review text is quoted.
* City grid: "Google rating" filter (Any, 4.9 and up, 5.0 only) and a "Google rating" sort (rating, then review count). Both appear only once the page's lenders carry Google ratings.

= 0.4.4 =
* Score badges 60-79 ("solid") use the same dark spruce as 80-100. Fair (0-59) and unscored stay light.
* Open Graph and Twitter card tags (og:title, og:description, og:url, og:image with width, height, type and alt; twitter:card summary_large_image). Image = featured image, else the new "Default social image" setting (media ID). Skipped when Yoast, Rank Math, AIOSEO, SEOPress or The SEO Framework is active.
* Meta description on pages and lender profiles too (excerpt, else the opening text cut near 155 characters), not only city pages.

= 0.4.3 =
* Lender cards use `paper-sunk`, the same shade as the filter panel. Inside them, loan-type chips, the review block and the fair score band move to white (`paper-raised`) so they stay visible.
* The lender-type badge (Bank, Credit union, Mortgage company) is spruce with white text, matching the strong BLNM Score band.
* Brand `paper` token is white in light.

= 0.4.2 =
* Page background is white (`paper-raised`) site-wide instead of the warm `paper` tint. Cards keep their hairline border and shadow; the filter panel and footer stay `paper-sunk`.

= 0.4.1 =
* City pages: the visible H2 "Top-rated mortgage lenders in <City>" repeated the H1 and is now screen-reader only ("Lenders in <City> ranked by BLNM Score"). The lender and loan count line sits under the H1 as the dek (18px).

= 0.4.0 =
* City and lender pages use the page layout instead of the blog-post layout: no sidebar, and no date, author, category or post navigation. Themify's per-post layout settings are answered for these post types (no data written), with body classes and a CSS fallback. Filter `blnm_page_layout` (default true) turns it off.
* Dark token block now declares --blnm-on-marigold.

= 0.3.1 =
* Top bar search and RSS icons now use the muted ink color (spruce on hover) instead of Themify's white, which disappeared on the light bar.

= 0.3.0 =
* Brand layer: Google Fonts (Newsreader, Public Sans, IBM Plex Mono) and assets/blnm-brand.css with the --blnm- tokens, applied site-wide over Themify Magazine under body.blnm-brand (body, headings, links, header, footer, menus, buttons, focus ring). Light theme only; dark tokens kept for later.
* Components restyled to the brand book: LenderCard, ScoreBadge (strong / solid / fair bands, dash when unscored), RateDelta (blue below / orange above median, arrow plus words), LoanTypeChip, CitySearch, Button, builder tag, review block. Figures in IBM Plex Mono. The old REVNM-blue accent is gone.
* Settings: "Use brand logo in header" (default on) swaps the Themify header title/logo for the bundled horizontal lockup (assets/brand/). Favicon and apple-touch icon are output from bundled files only when no WordPress Site Icon is set.
* Copy: disclosure line, "What reviewers mention", "Loans in 2025", "Builder lender: new homes only", dash for missing data.
* Includes 0.2.1 (settings cache fix) which was never uploaded on its own.

= 0.2.1 =
* Fix: Settings (GA4 ID, contact email) not showing after save on hosts with a persistent object cache. The option now flushes its cache entries on save, reads fall back to the database row, the setting is registered on init and exposed at /wp/v2/settings (blnm_settings), and the page shows what is saved in the database.

= 0.2.0 =
* County name in the city header (`blnm_county_name`, `county_name` on upsert).
* `cities/upsert` is now a partial update: only keys present are changed (lenders, title, content, excerpt, status, ...).
* Lender review/branch fields, review block, builder-lender tag; removed the empty `.blnm-stars` slot.
* Empty state, "Show more" only above 24 lenders, H2 "Top-rated mortgage lenders in <City>".
* Excerpt used as the meta description when present.
* Settings page (GA4 ID, contact email) and `[blnm_contact]`.
* Lender profile pages: summary, review block, branch.

= 0.1.0 =
* First skeleton: data model, REST, city grid, search, state index.
