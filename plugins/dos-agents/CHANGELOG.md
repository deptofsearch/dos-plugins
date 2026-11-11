# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-agents-v<version>`.

## 0.3.8

The re-audit's copy fixes: no "trusted agents" claim on the state pages, a next step on each, and a heading on the blog index.

### Changed
- **The hubs' stock paragraph "At Best Real Estate Agents Near Me, we help connect you with trusted real estate agents in {State}..." is left out** (`includes/hub-copy.php`, from the hub's `the_content` filter). Why: it contradicts the source line added in 0.3.7 ("Information only, not a ranking or endorsement"), and 50 of the 51 hubs carry it (Oregon twice). Ryan chose to drop only that paragraph (2026-11-11). The "Finding the best real estate agent in {State}" and "Some of the top cities in {State}" paragraphs stay, since they carry the wording people search for.
- **A second "Some of the top cities in {State}" paragraph is left out.** Oregon has its whole closing block twice; it goes from 6 closing paragraphs to 3.
- Both work on `<p>` paragraphs and on plain blank-line paragraphs (the form wpautop turns into `<p>` later). A dry run on all 51 live hubs removed exactly those 52 paragraphs and nothing else. The pages are not edited.

### Added
- **A next step under each hub's facts:** "Pick a city below to compare its agents, or find yours among all 470 cities in Washington." The second half links to the hub's city table, which gets `id="all-cities"`. Why: the re-audit judged 49 state pages to lack a clear next step (all flagged to verify). States with no agent list get none.
- **An H1 and a description on the blog index:** "Articles on choosing a real estate agent", then one sentence on what the articles cover. Themify Magazine hides that page's title, so the page had no H1 (re-audit JEV-008). Added to the finished HTML after `<div class="page-content entry-content">` inside `<main id="content">`, by a buffer on that page only; the page is returned untouched if that markup is missing or its main content already has an H1.
- Filters: `dos_agents_hub_copy` (false turns off the hub changes), `dos_agents_blog_head` (false turns off the blog heading).
- **Fixed before release (independent review):** the plain-paragraph removal stopped only at a blank line, so a stored stock paragraph followed on the next line by a `<p>`, heading or the city table would have taken them too. It now also stops at any block tag, and a match over 2,000 characters is left alone. The table anchor no longer mistakes `data-id=` for an id, and the blog heading is only added when the buffer holds the whole page.

## 0.3.7

State hubs say what their agent lists hold and who compiles them. "Agent cards" is gone from visitor-facing text.

### Added
- **A facts paragraph under each hub's heading** (`includes/hub-facts.php`). Why: the SEO audit of 2026-11-10 (P5) judged the hubs thin, a list of cities and a stock paragraph with nothing original. Every number is one the site holds: the city pages the hub lists (read from its own "All Cities in {State} (N)" heading, so the two agree), the cities with an agent list, the distinct agents across them, the three cities with the most, how many have a review from the last 12 months (the same cutoff as the cards' filter), how many are teams, and when the state's lists were most recently updated (the newest `generated_at`, as a site-local date; "most recently" because the lists are refreshed one city at a time). The top three use the same counts as the city boxes below them. Example: "Washington has 470 city pages on this site. 14 of them have agent lists so far, with {N} different agents who have recorded sales in those cities. ..."
  - **Agents are counted once per state, by profile ID.** The same agent is listed in several cities (Meridian, Boise and Kuna list 215, 169 and 130, with heavy overlap), so adding up the city counts would claim agents the state does not have.
  - Singular and zero cases have their own wording ("with 1 agent who has", "1 of the 9 has", "All 5 have", "None of the 2 has", "1 of the 5 is a team", no team sentence when there are none). A state with no lists gets no paragraph, because the hub block already says so.
  - Cached 12 hours per state (`breanm_agents_hub_facts_v1_<st>`, an empty state cached as zero) and cleared by `dos_agents_index_touch()` after the index is written (so a hub view cannot cache facts from the old index), so every push and page save the sweep makes in that state refreshes it. `dos_agents_index_rebuild()` clears every state.
- **A "How this list works." line under the city boxes**: "Compiled by {site name} from public agent profiles and reviews. Information only, not a ranking or endorsement, and we are not a brokerage. Each city page explains how its list is built." It does not name the profile site, as Ryan asked (2026-11-11); each city page's disclosure does. Filter `dos_agents_hub_source_text`.
- `dos_agents_hub_facts` (false) turns both off. Neither is written into the pages. Styles are in `breanm-brand.css`, scoped under `body.breanm-brand` like the hub heading.

### Changed
- **"Agent cards" is now "agent list(s)" in everything a visitor reads**, at Ryan's request (2026-11-11): the hub heading over the city boxes ("14 cities in Washington with agent lists"), the hub's no-cities message, the "Browse by state" note on the homepage and `/by-state/`, the homepage search's no-match message, and the hub and `/by-state/` meta descriptions (singular when there is one city). Code, comments and CSS class names keep "cards".

### Unchanged
- The hubs' stored text, including the closing marketing paragraphs, which Ryan chose to leave for now.

## 0.3.6

State hubs link the city pages their city table was missing.

### Added
- **A hub's "All Cities in {State}" table also links the state's published city pages it does not list** (`includes/hub-cities.php`, from the hub's `the_content` filter). Why: each table was written into the hub when the hub was made, so a city page published or renamed later is in none. The 343 pages renamed from `__trashed` on 2026-11-10 were linked from no hub, so only the sitemap and the 0.3.3 redirects reached them. A sitemap-against-hub check of all 51 hubs that day found exactly those pages missing (297 AL, 26 MA, 14 NH, 5 RI) and nothing else.
- **Nothing is written into the hub.** The table is rebuilt at render time only when something is missing, and a hub that already links every city page is printed exactly as stored. The rebuilt table reuses the stored `<table>`, `<td>` and `<a>` tags (copied from the first cell), keeps every link it had, and keeps the stored order, alphabetical down three columns. The "All Cities in {State} (N)" count follows the new total.
- **A city page counts** when it is a published, top-level Page whose slug is `in-<city>-<st>` and whose title is "in {City} {ST}" (the title gives the link text). Empty city pages (0.3.4, noindex) are not added. The links a hub already has to them are kept.
- **One scan per state**, `post_name LIKE 'in-%-<st>'`, cached 12 hours in the transient `breanm_agents_hub_cities_v1_<st>`. It is cleared when a page of that state is saved, renamed (`post_updated`, old and new slug) or deleted. A failed scan adds nothing and is not cached. Filters: `dos_agents_hub_add_missing` (false turns it off), `dos_agents_pre_hub_city_pages` (short-circuits the scan).
- **Cheap when nothing is missing**, which is 47 of the 51 hubs: the empty-page scan (0.3.4) runs only when the hub lacks a city page, so a hub view never pays for it otherwise. If the count in the heading cannot be updated, the merged table is still printed and the hub is never blanked.
- The table is found with `strpos`, not a regex over the page: the largest hubs are 500 to 600 KB, and the class name also appears in each hub's own `<style>` block. Merging one city into Texas or Pennsylvania takes about 4 ms.

## 0.3.5

Hub and city pages are titled by their headings.

### Changed
- **The `<title>` of a state hub or city page is its heading** (`dos_agents_head_document_title_parts()` in `includes/head-meta.php`, on `document_title_parts`). Why: the pages are named after their slugs, so WordPress titled them "in Abilene TX – Best Real Estate Agents Near Me" and "in Alabama – …" (one hub, "In Washington"), while the headings say "Real Estate Agents in Abilene, TX". Google may rewrite a title it finds unhelpful, usually from the main heading, and the SEO audit of 2026-11-10 flagged this (P4). The text is the share title the pages have had since 0.3.0, so the two cannot drift apart.
  - City pages with cards and state hubs: "Real Estate Agents in {City}, {ST}" / "Real Estate Agents in {State}".
  - Older brokerage-summary pages, including the 343 renamed `__trashed` pages: "Real Estate Brokerages in {City}, {ST}".
  - Empty city pages: "Real Estate Agents in {City}, {ST}", read off their own heading.
- **Only the title part changes.** WordPress still adds the separator, the site name and any page number. The whole title is never replaced (`pre_get_document_title` is not used). The homepage, `/by-state/`, the blog, posts and other pages keep their titles.
- **It stands down for Yoast, Rank Math, All in One SEO, SEOPress, The SEO Framework and DoS Toolkit's SEO module**, which own the title. It does not stand down for Jetpack, which prints share tags and no title. The filter `dos_agents_document_title` (false) turns it off.

### Added
- **A page kind `empty`** for the empty city pages from 0.3.4, recognized by the empty-page sentence plus a heading "Real Estate Agents in {City}, {ST}". Their `og:title` was "in Vesta GA" and is now the heading. They still get no description. A page with the sentence but no such heading stays an ordinary page.

## 0.3.4

City pages with no agents: noindex, follow, and out of the sitemap.

### Added
- **An empty city page is `noindex, follow`** (`includes/empty-cities.php`, on `wp_robots` at priority 99). Why: 876 published city pages (a full REST search on 2026-11-10) hold only "Real Estate Agents in {City}, {ST}" and "There are no agents we have found that service this area", with no cards and no brokerage summary. Google reports an empty page that returns 200 as a soft 404, and pages like these dilute every crawl. The other robots directives core prints (`max-image-preview:large`) are kept.
- **"Empty" is read live from the page's stored content:** a published Page that holds that sentence and no `[breanm_agents]` shortcode. No list decides it, so a stale cache can never noindex a page that has cards. When the sweep gives a city cards, it rewrites the page with the shortcode, and the page is indexable again at its next view.
- **The same pages are left out of the core Pages sitemap** (`wp_sitemaps_posts_query_args`, `post__not_in`), which also keeps the sitemap's page count right. The IDs come from one LIKE scan over published pages, cached for 12 hours in the transient `breanm_agents_empty_ids_v1`. The cache is cleared only when a saved or deleted page crosses between empty and not empty, so the sweep's hourly page edits do not force a rescan. A failed scan leaves every page in the sitemap rather than caching the failure. Filter `dos_agents_pre_empty_city_ids` (return an array of IDs) short-circuits the scan.

### Unchanged
- The page's own text, so a visitor who lands on one still sees what they did before. The pages still return 200 and are not removed from the state hubs. No description is added: there is nothing on the page to describe.

## 0.3.3

301s for the 343 city pages that were published as `__trashed-NN`.

### Added
- **A 301 from each old `/__trashed-NN/` URL to the page's proper `/in-<city>-<st>/` URL** (`includes/old-slugs.php`, map in `includes/old-slugs-map.php`). Why: 343 published city pages (298 AL, 26 MA, 14 NH, 5 RI) lost their slugs in a bulk trash and restore in September 2026 and are being renamed to the `in-<city>-<st>` pattern. Core keeps old-slug redirects for posts only (`wp_check_for_changed_slugs` skips hierarchical types), and the site has no redirect plugin, so without this every old URL would 404.
- **It acts only on a 404**, at `template_redirect` priority 1, before `redirect_canonical` makes its own guess. So it does nothing while a page still holds its old slug, and this release can go live before the renames, with no gap between the two.
- **The target is read from the page ID, not stored as a URL**, so a later slug change still lands. No redirect when the page is missing, not published, or still holds the old slug (which would loop). Only a single-segment path that starts `__trashed` and is in the map is considered; the map is not read on any other request.

## 0.3.2

Meta descriptions for the older city pages, which are most of the site.

### Added
- **A description and og:title for the older brokerage-summary city pages** (new page kind `brokerages`). Why: a live sample of 200 random sitemap pages found 191 are older city pages that do not hold `[breanm_agents]`, so they were kind `page` and got no description at all (about 27,000 of 28,718 pages). Each holds a brokerage summary headed "Top-Rated Real Estate Brokerages in {City}, {ST}: ...", with each brokerage's sales, rating, review count and a client summary.
- **The description says only what the page shows.** Two or more entries: "Compare 5 real estate brokerages in Bancroft, WI by sales, rating and what clients say about them." One entry: "A real estate brokerage serving Tunnelton, WV: its sales, rating and what clients say about it." A heading with no entries: "Real estate brokerages in {City}, {ST}: sales, ratings and what clients say." The count is the number of entries showing "(Sales:" in the stored content. A very long city name falls back to a shorter sentence instead of being cut mid-sentence. `og:title` is "Real Estate Brokerages in {City}, {ST}"; `og:url` is the page's own canonical.
- **Detection uses the heading in the stored content, not the slug.** 343 of these pages are published under broken slugs like `__trashed-83`, so a slug rule would miss them. The heading is matched tolerantly (wpautop output or raw content, entity forms such as `&#8217;` and `&amp;`, extra whitespace, tags inside the h3), in one regex pass over `post_content` with no queries. A page that also holds `[breanm_agents]` stays a city page, and a page without the heading still gets no description.
- `$kind` in the `dos_agents_meta_description` and `dos_agents_og_title` filters can now also be `brokerages`.

### Unchanged
- **The `<title>` is not touched.** These pages still have the WordPress default titles ("in Alexander NY - Best Real Estate Agents Near Me"); fixing them is a separate change.

## 0.3.1

### Fixed
- **/blog/ printed no description.** On the live site it is an ordinary page holding a posts module, not WordPress's posts page, so `is_home()` never matched it and it fell through to "any other page", which gets a description only from its own excerpt (it had none). A top-level page with the slug `blog` is now treated as the blog index: it gets "Articles from {site} about working with a real estate agent." unless it has its own excerpt, which wins. Its og:title and og:url are the page's own. A child page that happens to be called "blog" is still an ordinary page. Found by the live check of 0.3.0.

## 0.3.0

Meta descriptions and social sharing tags, which the site had almost none of.

### Added
- **A meta description, Open Graph and Twitter Card tags** (`includes/head-meta.php`, hooked on `wp_head` at priority 1). Why: a live audit found no `<meta name="description">` and no Open Graph on 59 of 60 pages, and no SEO plugin printing them, so every share was a bare link and every result snippet was whatever the search engine guessed. One description per page, plain text, 155 characters at most, cut on a word boundary. The tags are `og:type`, `og:site_name`, `og:title`, `og:description`, `og:url`, `og:locale`, `og:image` (with width, height and alt), `twitter:card`, `twitter:title`, `twitter:description` and `twitter:image`, each printed once.
- **Every description says only what the page shows.** A city page: "Compare 12 real estate agents in Abilene, TX: ratings, local sales, what reviewers say about each one, and how recent the reviews are", with the stored agent count from the index (one option read, no query per agent); a city with nothing stored gets the same sentence with no number. A state hub: "Real estate agents in Texas: 12 cities with agent cards, including Houston, Dallas and Austin.", the three being the cities with the most agents. It mirrors the hub's own heading because the hub page also lists every city in the state, so a bare "12 Texas cities" would contradict it; with long city names the call to action is shortened, then dropped, rather than cut mid-sentence. The homepage and `/by-state/`: the live city and state counts, each pluralized on its own ("1 city across 1 state"). A blog post: its manual excerpt, else the first sentences of its content with shortcodes and tags stripped (a password-protected post gets none). The blog index: a fixed sentence. Any other page: its manual excerpt, or no description at all, because a weak one is worse than letting the search engine write its own. The cards do not link out to Zillow, so no description mentions a profile link.
- **`og:title` is a clean title, not the WordPress document title:** "Real Estate Agents in Abilene, TX" on a city page, "Real Estate Agents in Texas" on a hub, the site name on the homepage, the post or page title elsewhere. Titles come back from WordPress with `&#8211;` and `&#038;` in them, so they are decoded once and escaped once.
- **`og:image`: the post's featured image, else the WordPress Site Icon at 512px, else none.** Never the brand SVG favicon: Open Graph readers do not render SVG, and a filter result that points at one is dropped. `twitter:card` is `summary_large_image` only for an image at least 600px wide; the square Site Icon gets `summary`, because stretched into the large card it is cropped into a blur.
- **Filters:** `dos_agents_head_meta` (default true, turns all of it off), `dos_agents_meta_description` (`$text`, `$post`, `$kind`), `dos_agents_og_title` (`$title`, `$post`, `$kind`), `dos_agents_og_image` (an array of `url`, `width`, `height`, `alt`, or empty) and `dos_agents_meta_description_max` (155). `$kind` is one of `home`, `by-state`, `hub`, `city`, `blog`, `post`, `page`.

### Stands down
- It prints nothing when another layer already prints these tags: Yoast (`WPSEO_VERSION`), Rank Math (`RANK_MATH_VERSION`), All in One SEO (`AIOSEO_VERSION`, or the `aioseo()` function, which some builds define late), Jetpack's Open Graph output (`JETPACK__VERSION` defined and the `jetpack_enable_open_graph` filter true, which defaults to on when Jetpack's Publicize or Sharing module is active), SEOPress (`SEOPRESS_VERSION`), The SEO Framework (`THE_SEO_FRAMEWORK_VERSION`), or DoS Toolkit's SEO module when it is enabled (`DOS_Toolkit::is_active( 'seo' )`, not merely loaded). Two descriptions or two `og:title` tags are worse than none.
- The page being described is the queried object, not whatever the global post happens to be when `wp_head` runs. A protected or private post's `og:title` drops the "Protected: " and "Private: " prefix, which belongs in the page heading and not a share card.
- It prints only on singular views, the front page and the posts page. Nothing on 404, search, archives, feeds, attachments or in the admin.

### Unchanged
- **The `<title>` is not touched in this release.** Today's titles are the WordPress defaults ("in Texas - Best Real Estate Agents Near Me"); fixing them is a separate change.

### Changed
- American spelling in the changelog, README and code comments ("normalized", "recognized", "color", "labeled", "sanitizing"). The function `dos_agents_normalise_agent()` keeps its name: renaming it is a code change, not a wording one.

## 0.2.4

### Added
- **A filter on the by-state grid.** 51 cards is more than anyone wants to read through to find one state, so `[breanm_state_index layout="grid"]` now carries a box above it. It matches the start of the state's name, the start of any later word in it, or the postal code — so "new", "mexico", "nm" and "NM" all land on New Mexico, and "carolina" finds both. A live count reads "Showing 4 of 51 states", and an empty result says so rather than leaving a blank page.
- The box ships `hidden` and `breanm-home.js` reveals it: a filter that cannot filter should not be on the page, and without the script every state is visible anyway. Cards are hidden rather than removed, so clearing the box is instant and nothing is re-rendered. The count wording is handed over in `data-label-*` attributes so it stays translatable.
- Every state card now carries `data-name` (normalized the same way the city search normalizes) and `data-code`. Harmless on the carousel, which gets no filter.

## 0.2.3

### Fixed
- **State hubs loaded the brand stylesheet but not the homepage one**, so the cities-with-cards block came out as a bulleted list with the city name and the agent count run together ("Bellevue18 agents"). A hub never holds the shortcode — `hub.php` renders the block straight into the content — so nothing ever asked for `breanm-home.css`. `dos_agents_maybe_enqueue_home()` now recognizes a hub page and enqueues it in `<head>`, and the content filter asks again as a fallback. Found on the live site right after 0.2.2 went up; `test-agents-home.php` now asserts that rendering a hub enqueues the stylesheet, which is the check that would have caught it.

## 0.2.2

The 49 state hub pages, and a grid for the by-state page.

### Added
- **State hubs get the heading and the cities-with-cards block** (`includes/hub.php`), through `the_content` at priority 9 (before `wpautop`, which would otherwise wrap the block's markup in stray paragraphs). Why a filter and not an edit: there are 49 of these pages and each holds a table of every city in the state — Washington's content is 159,000 characters — so rewriting them would mean pushing 7MB of other people's markup back and forth to add two blocks. The filter also covers a hub created tomorrow, and `dos_agents_hub_block` turns it off and puts every page back as it was. Added once per page: `the_content` runs again for excerpts and feeds, and the block would otherwise appear twice.
- A hub is recognized by its slug — "in-" plus the state's name, which is how all 49 are named. City pages are "in-&lt;city&gt;-&lt;st&gt;" and end in a two-letter code, so the two cannot collide; `in-house-financing` is not New Hampshire.
- **`[breanm_state_index layout="grid"]`**: the same state cards laid out as a grid instead of a scrolling row, for the by-state page, where browsing every state *is* the page and hiding 46 of 51 behind a horizontal scroll would be perverse. The grid carries no carousel hook and no arrows. Default is unchanged.

### Changed
- **Hubs render as Pages, not posts.** They carried Themify's `sidebar1`, so each one printed a "Recent Posts" column beside a table of 470 cities. They now take the same no-sidebar treatment city pages do, and get a `breanm-hub-page` body class.
- The old hub heading carries an inline `font-family: Verdana` and color. Inline styles beat a stylesheet, so the brand rule for it is the one place `!important` is the only tool that works; it is scoped to `body.breanm-hub-page` so nothing else is touched.

## 0.2.1

The homepage lead row: intro copy on the left, the city box on the right.

- `.breanm-home__lead` in `breanm-brand.css` is a two-column grid, half the width each, vertically centered. The box is moved right with `order: 2` rather than by markup order, so the single-column phone layout can put it FIRST and the copy under it — on a phone the point is to search, not to read. Why this shape: it is the one Outdoor Seating Near Me already uses (`.osn-home__lead`), so the two sites behave the same way, and Ryan asked for this page to match it.
- The dek loses its top margin and width cap inside the row, where it sets the row height and the 62ch cap would fight the column.
- The page content wraps the dek in `.breanm-home__lead-copy` and the two of them in `.breanm-home__lead`; without the wrapper the page just stacks as before, so content and plugin can be updated in either order.

## 0.2.0

The plugin stops being only a city-page component and becomes the site's front door: the homepage
search and "Browse by state", the state hub blocks, and the brand layer that makes the rest of the
site look like the cards.

### Added
- **A city index** (`includes/index.php`), option `breanm_agents_index`. A city is in it only when agents are stored for it, its page is published, **and** that page's content actually holds `[breanm_agents]`. Why: the sweep pushes agents before the page is edited, and a city can be held for review or rolled back, so "agents are stored" on its own would have offered visitors cities whose live pages show nothing. Kept current by the upsert, by `save_post` and by `deleted_post` (a REST `DELETE ?force=true` fires no save, and the index would go on linking to a 404). A rebuild drops each city's agents from the object cache as it goes, because WordPress keeps every option it reads for the rest of the request and 1,800 cities' worth of agents will not fit in one. `breanm_agents_index_ver` is bumped on every change and ridden on the cities route as `?v=`, so a browser never offers cities whose cards have since gone. Neither option is autoloaded.
- **`[breanm_city_search]`**: the homepage city box. Matching rules in `includes/search.php`, mirrored in `assets/breanm-home.js`, so the box behaves the same with or without JavaScript. "Boise", "boise id", "Boise, ID", "Boise, Idaho", "Saint Helens" and "St. Helens" all work, and a name in several states lists them instead of guessing. The rules are the ones DoS - Best Lenders arrived at, so someone who uses both sites types the same things. Without JavaScript the form posts to the site root with `breanm_city=1` and `template_redirect` sends a single match straight to its page.
- **`[breanm_state_index]`**: with no attribute, "Browse by state" — one card per state with its city count, richest state first, over the state's Census outline. With `state="WA"`, the cities in that state that have cards, for the top of the hub page. Why both from one index: a card that says "8 cities" has to lead somewhere that says the same, and the hub's own list of every city in the state does not.
- **The brand layer** (`includes/brand.php`, `assets/breanm-brand.css`): Libre Franklin, the `--breanm-` tokens and a wordmark header in place of the clip-art banner, site-wide over Themify Magazine. Why: the palette and type were already settled in `breanm-agents.css` and used by nothing else, so the cards were an island of their own design on a page that looked like something else. Light theme only. Fonts are bundled rather than fetched from Google. `dos_agents_brand` and `dos_agents_brand_logo` turn it off without a release.
- **`GET /wp-json/breanm/v1/cities`** (public): the index as `{c, s, u}`, for the typeahead once there are more cities than ride along in the page markup (400, filter `dos_agents_inline_max`). **`POST /cities/rebuild`** (`manage_options`) rebuilds the index from every stored city — needed once after this version is installed, because the index starts empty, and any time a page was changed outside WordPress.

### Changed
- **City pages render as Pages, not posts** (`includes/layout.php`). Why: they carried Themify's `sidebar1`, so every city page printed a "Recent Posts" column beside a list of agents, unrelated to it, and on a phone that landed under the cards as a tail of blog links. The site's other Pages are already sidebar-none. Themify's per-post settings are answered through `get_post_metadata` rather than written to 28,000 pages, so nothing is stored and a page that stops holding the shortcode goes straight back to the theme's own behavior. Filter `dos_agents_page_layout` turns it off.

### Notes
- The state outlines in `assets/state-outlines.json` are the US Census Bureau's generalized state geometry (public domain), shared with DoS - Best Lenders. They are drawn inline rather than written out as files: there are 51 on one page, they are decorative, and they recolor on hover, which an `<img>` cannot do. No uploads directory to be unwritable, no stale tiles to version.
- `tests/home-preview.php` writes `tests/preview/home.html` from the real shortcodes and the real CSS, optionally over an export of the live index, so the homepage can be looked at without a WordPress install.

## 0.1.8

### Added
- **An optional review window.** A payload agent may now carry `reviews_window_years`, a whole number from 1 to 20: how many years back the reviews behind its tags and summary were taken from. It is stored right after `reviews_analyzed`, re-sanitized on every read and returned unchanged by the authenticated GET. Anything else (0, a negative, over 20, a fraction, a word, a list) is dropped, not clamped, and an agent that does not send it stores exactly the keys it did before. Why: the pipeline now limits tagging to recent reviews, and "the 20 most recent reviews" no longer said what was actually used. Dropping rather than clamping because a wrong window would print a false claim on the card.
- **A note when a window leaves too little to summarize.** With `reviews_window_years` set and neither `summary_parts` nor `summary`, the card prints a muted "Not enough reviews from the last {Y} years to write a summary." where the review summary would be. Why: those cards otherwise looked like the summary was missing by mistake. The note only appears when a window was given, so older payloads render as before.

### Changed
- **The "Last review" line says the window when there is one.** With a window it reads "Last review {Mon YYYY} · tags based on {N} reviews from the last {Y} years" (singular "review" and "year" at 1). Without one it keeps "tags based on the N most recent reviews". With no review count it still stops after the date, as before. Why: the reader should see how recent the reviews behind the tags are, not just how many.
- **"the 1 most recent review", not "reviews".** The old wording said "1 most recent reviews". Why: a plain grammar slip that stood out on agents with a single review.
- **The summary labels (Buyers, Sellers, Working style) are small uppercase labels on their own line** above each paragraph, styled like the filter labels (`.breanm-agents__flabel`). Why: the label was run in inline, so a paragraph read "Buyers Buyers describe ...", the same word twice. CSS only; the markup is unchanged. The theme reset on buttons, inputs and selects does not touch the label, so it keeps its uppercase.

## 0.1.7

### Added
- **A labeled, collapsible review summary.** A payload agent may now carry `summary_parts`, `{ "buyers": string, "sellers": string, "working_style": string }` (each up to 320 characters). The card prints a closed `<details>` titled "What reviewers say" with one paragraph per part that has text, labeled Buyers, Sellers and Working style, in that order. The tag chips stay visible below it. Why: one run-together paragraph cut off after four lines hid most of what reviewers said, and ran what buyers said together with what sellers said. A native `<details>` needs no JavaScript, so it works for crawlers and visitors without JS, and the text stays in the HTML.
- Sanitizing follows the other text fields: strings only (a number or list in a slot is ignored), tags stripped, whitespace collapsed, empty parts dropped, unknown keys ignored, and the whole object dropped if no part is left, so a city that sends none stores exactly what it did before. It is re-sanitized on every read and the authenticated GET returns it unchanged. The existing `summary` string is untouched: an agent with no `summary_parts` still shows its single paragraph, and an agent with both shows the parts only.
- **An "old reviews" label.** When the newest review (`last_review`) is more than 3 years before the site's local date, the card shows "Reviews on file are from {year} or earlier" under the "Last review" line and carries `data-old="1"`. Why: the line above only gave a month and year, which is easy to read past, and a reader can mistake a years-old review history for a current one. The threshold uses the same local-date "now" and year length as the 12-month filter, so the two agree; it defaults to 3 years and the `dos_agents_old_reviews_years` filter changes it.

### Changed
- **No more 4-line cut-off.** The old single `summary` paragraph is shown in full instead of being clamped to four lines. Why: a summary that ends mid-sentence reads as broken, and with nothing to click to expand it, the rest was unreachable.
- The card grid aligns cards to the top (`align-items: start`) so opening one card's summary does not stretch the card beside it.

### Removed
- The unused `.breanm-agents__link` CSS rules. Why: the profile link left the card in 0.1.3, so nothing prints that class, and its `margin-top: auto` no longer has anything to push.

## 0.1.6

### Changed
- **Filter buttons use the card shade.** Unselected filter chips and "More filters" are filled with the same light gray (`--ba-tint`) as the cards, instead of white, so the controls and the cards look like one set. Hover still adds the pale blue tint and a selected chip is still solid blue with white text.

## 0.1.5

### Changed
- **Cards have a light gray fill.** Each card is shaded with the existing `--ba-tint` (nudged from `#f5f6f8` to `#f3f5f8` so it reads on a white page) instead of white, so one card is easy to tell from the next and from the page. The tag chips inside a card are now white rather than transparent, so their gray outline still shows against the shaded card. Why: on a white page the cards were separated only by a thin border.

## 0.1.4

### Changed
- **Filter buttons carry the page's blue.** Unselected filter chips, "More filters" and "Clear filters" now use the royal blue text and border already used by the "Office in {City}" badge and the "Show more" button; hover and focus add a pale blue tint, and a selected chip stays solid blue with white text. Why: the chips were plain gray and black, so the page's only accent appeared on cards and not on the controls people use first. The blue is the existing `--ba-blue-ink` (about 7:1 on white), so contrast is unchanged, and one new token (`--ba-blue-tint`) is added for the hover tint.

## 0.1.3

### Removed
- **No link from a card to the agent's Zillow profile.** The agent's name is plain text and the "View Zillow profile" link at the foot of the card is gone. Why: the list is built from public profiles but is not meant to send readers to a third-party site, and the page copy no longer names Zillow. The stored `profile_url` is still required and validated (it identifies the agent and keeps the payload schema unchanged); it is just never printed.
- The built-in default disclosure now says "online or public agent profiles" instead of naming Zillow, matching the per-city disclosure.

## 0.1.2

### Added
- **The disclosure travels with the city data.** The payload may carry an optional top-level `disclosure` object, `{ "title": string (80 characters), "paragraphs": up to 6 strings (900 characters each) }`. It is stored with the city, re-sanitized on every read like the rest of the payload, and returned unchanged by the authenticated GET. The page prints it in the existing disclosure box: the title in `<strong>`, then each paragraph in its own `<p>`, all escaped.
- Why: the wording is part of what a city's data is published with, and the pipeline that produces the data is the thing that knows the right wording per city. A city without one still shows the `breanm_agents_disclosure` option, and then the built-in default, exactly as before, so nothing changes for cities already stored.
- Sanitizing is strict: strings only, tags stripped, whitespace inside a paragraph collapsed, empty paragraphs dropped, anything else inside the object ignored. An object with no paragraph left is dropped whole (a lone title is not a disclosure), which means the fallback applies.

## 0.1.1

### Fixed
- Themes such as Themify restyle buttons, inputs and selects (uppercase text, wide letter spacing, their own font, capitalized placeholders). The filter chips, "More filters", "Clear filters", the search box and the sort menu now keep the page font in sentence case. Found on the first draft page on bestrealestateagentsnearme.com.

## 0.1.0

First release: filterable agent cards for a city page on Best Real Estate
Agents Near Me.

**Data is pushed, not fetched.** An n8n workflow reads each city's agents from
public Zillow profiles, summarizes their recent reviews and tags them, then
POSTs the result to `breanm/v1/agents/upsert`. The plugin never calls Zillow
or any other service; a page load reads one option.

**One option per city, never autoloaded.** `breanm_agents_<slug>` (slug =
`sanitize_title("<city>-<state>")`, e.g. `breanm_agents_boise-id`). A city holds
up to 500 agents and only its own page needs them, so autoloading would put
every city's agents into memory on every request of the site.

**The endpoint treats its input as untrusted anyway.** Every field is coerced to
its type and trimmed to a length, and anything not in the schema is dropped, so
the stored copy and `GET breanm/v1/agents/<slug>` can only ever hold the schema. A profile link must be `https://www.zillow.com/profile/...` on that
exact host; an agent without one is dropped, since the name and the bottom link
on every card point there. More than 500 agents are cut at 500. The response is
`{ok, slug, count}` plus `skipped`, the number of agents dropped, so a push that
lost agents is visible to the workflow. Tags nobody mentioned (count 0) are
dropped: a chip for one would filter to no one.

**Server-rendered, filters on top.** `[breanm_agents city="boise-id"
initial="12"]` prints every card, so crawlers and visitors without JS see all of
them. The search, chips and sort stay hidden until the script runs, as in the
Outdoor Seating plugin. The script only hides, reorders and reveals.

**Local first.** Cards come in the order tier (`primary`, `served_with_sales`,
`served`, `served_via_url`, `sales_only`, `none`), then sales in the city. Nobody
is numbered and nothing in the copy says "best": the page orders by how local an
agent is, which is a fact on the profile, not a verdict. The disclosure under
the list (option `breanm_agents_disclosure`) says the same in words.

**Chips are offered only where they sort agents.** A review tag on more than 70%
of a city's agents filters nothing (in Boise, "Responsive" is on 90%), and one on
fewer than 5% filters down to a lone agent. Both bounds are inclusive and
filterable (`breanm_agents_chip_min` 0.05, `breanm_agents_chip_max` 0.70). Tags
over the bound still show on cards, like any other tag. The profile chips
("Office in {City}", Team, reviewed in the last 12 months, 10+ years) follow the
same bounds. 10+ years in the industry sits at 71% in Boise, so it is not
offered there.

**The 12-month chip uses one cutoff on both sides.** The server counts agents
whose last review is on or after (render time minus a year, as the site-local
date) and passes that
date to the browser as `data-recent-cutoff`; the script applies the same date to
each card's `data-last`. A cached page keeps matching its own chip count, even
though the page itself ages.

**A sales stats line is left out where the badge already says it.** For
`served_with_sales` ("Serves Boise · 12 sales here") and `sales_only` ("12 sales
in Boise") the badge carries the number, so the "N sales in {City}" line would
repeat it.

**"Also serves" leaves out the city's own neighborhoods.** Zillow lists areas
such as "East End, Boise, ID"; on a Boise page that is Boise, not another area.

**Light look only, scoped under `.breanm-agents`.** The colors are custom
properties defined once on that class, the font is the theme's, and there are
no external fonts or images. No card has a photo or a logo.

**Asset URLs carry the plugin version.** The host's CDN caches `?ver=` URLs for
years, so `DOS_AGENTS_VERSION` must be bumped whenever the CSS or JS changes.

Not in this release: ItemList JSON-LD for the listed agents (a TODO in
`includes/render.php`).

Release ZIPs leave out `tests/` and `dist/` (the fixture is 161 agents of real
data the site does not need).

### Fixed (review, before release)

An independent review found these; all are in 0.1.0 because it has not shipped.

- **The agent list is no longer public.** `GET breanm/v1/agents/<slug>` now needs
  `manage_options`, like the upsert. It stays so the pushing workflow can verify
  what it stored; the page already shows visitors what they may see, and a
  public dump of 500 profiles is a scraping gift. It also no longer sends a
  public Cache-Control header.
- **Assets load in `<head>` where they can.** A `wp_enqueue_scripts` hook
  enqueues the CSS and JS on a singular page whose content has the shortcode, so
  the stylesheet is there before the cards paint on classic and Themify themes.
  The shortcode still enqueues them as a fallback for content a page builder
  stores outside the post body (those print in the footer). Whether they are
  already enqueued is asked of WordPress, so they are never added twice.
- **Stored data is re-checked on every read.** The shortcode and the GET run the
  stored option back through the sanitizer, and the renderer fills in any missing
  key and skips an agent with no valid Zillow link, so a hand-edited option or an
  older shape cannot cause a PHP warning, an empty `href` or a field outside the
  schema on the page.
- **"Office in {City}" follows the 5%-70% bounds** like every other chip. The
  earlier exemption showed a chip for one agent in a hundred; the bounds exist to
  keep exactly that out.
- **Rating sort.** Rating is compared to one decimal (4.96 and 5.0 both read
  "5.0"), then by number of reviews, and an agent with fewer than 5 reviews goes
  below everyone else: a lone 5-star review should not outrank a 5.0 from sixty.
  It is done in the script only; PHP does not sort by rating. Equal agents keep
  their Local first order.
- **Accessibility.** After the last "Show more agents" click, focus moves to the
  first card it revealed instead of vanishing with the button. Each tag chip
  carries "Mentioned in N of M recent reviews" as visually hidden text as well as
  the tooltip, which touch and screen-reader users never see. The count inside a
  selected chip is no longer faded, keeping white-on-blue contrast. Each "View
  Zillow profile" link has an `aria-label` naming the agent. "More filters"
  points `aria-controls` at a wrapper that holds only the extra chips.
- **No English in the script.** The count line ("Showing X of Y agents"), "More
  filters (N)" and "Fewer filters" come from PHP, already translated, as
  `data-text-*` attributes on the root element.
- **The 12-month cutoff is the site-local date.** It is `wp_date()` of render
  time minus a year, not UTC: after 5 pm Pacific it is already tomorrow in UTC,
  which moved the cutoff a day early. The PHP chip count and the script still use
  the one cutoff string embedded in the page.
- **Office city is tidied for display.** Cut at the first comma, trimmed, and
  capitalised ("Boise, ID 83716" gives "Boise"; "meridian" gives "Meridian,
  ID"). The state is added only when the city had no comma, so a city that
  already carried its state and zip never reads "Meridian, ID 83642, ID". Stored
  data is untouched.
- **Tests no longer pin fixture numbers.** Expectations come from the fixture
  itself, or from small synthetic cities, so refreshing the fixture does not
  break the suite.
