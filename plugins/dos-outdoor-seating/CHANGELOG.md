# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-outdoor-seating-v<version>`. Releases
up to 0.6.3 are described in `readme.txt`, which was this plugin's changelog
before it moved here.

## 0.6.20

Neighborhood and district landing pages with no text of their own get an opening paragraph written from their own
listings. Until now the H1 ("Outdoor Seating in Edgewater, Chicago") went straight into the filter bar and the card
grid. The paragraph is the same kind of summary the city pages got in 0.6.18: how many places and how many confirm
outdoor seating (or, with three or fewer, their names), the most common categories, the most common amenities, and
the line that each place has its own page with the address and directions. A district page also names its busiest
neighborhoods ("By neighborhood, ..."); a neighborhood page has no area sentence. It is added to `City_Intro`, which
now also takes the hood path, and `sentences()` is shared by both.

Why only pages with no text. About 379 neighborhood pages across seven cities are empty above the grid. A page with
any text of its own (shortcodes, tags and whitespace do not count) is left exactly as stored, so a hand-written
intro always wins. There is no closing or state link line: hood views already end with a "Nearby neighborhoods"
strip and a link back to the city. Landmark pages are still out of scope. The name in the text is the one the page's
H1 prints.

Placement. The paragraph goes under the H1, not above it: Page_Title (priority 5) has already put the H1 at the top
of the content when this filter runs at 7, so a plain prepend would print the intro above the page's heading. The
first build did exactly that; its tests fed the stored content straight to the filter. The test now runs Page_Title
first, as WordPress does.

One place. A page with a single listing now ends "It has its own page with the address and directions." instead of
"Each place has its own page ...", on city pages too.

Rollback. The same setting as the city intros (Tools > Outdoor Seating, "Write city and neighborhood page intros from
listing data", option `osn_city_intro`) switches both off. The stored content is never changed, only the page output,
so unchecking restores every page exactly. City page output is identical to 0.6.19 except the one-place closing.

Also: British spellings in comments and docs changed to American ("behavior", "canonicalize").

## 0.6.19

The dead "there are plenty of options." ending is replaced by the state link on the pages 0.6.18 missed.

Why. Checked on the live site after 0.6.18: the new intros were in place, but 29 of 140 sampled city pages still
ended with the dead sentence and had no state link. Their stored content ends with that paragraph and no `</p>`
(wpautop adds it later, after this filter runs), and the 0.6.18 pattern required one. The test fixtures had been
built from rendered pages, where the tag is always closed. The pattern now accepts the paragraph closed, followed
by another tag, or at the end of the content.

## 0.6.18

City pages with listings show an intro written from their own data. The stock intro blocks pasted into city pages are
replaced, in the page output only, by a short summary built from the city's cards: how many places and how many
confirm outdoor seating, the most common categories, the most common amenities, the busiest areas (the top level of the city's neighborhood list, which is a borough in New York), and a
line saying each place has its own page with the address and directions. The dead "plenty of options" ending
is replaced by a link to the state page ("More places nearby: every city in Texas with outdoor seating."). New class
`City_Intro`, on `the_content` at priority 7 (after the H1 at 5 and the state takeover at 6, before wpautop).
`Cards::view()` is now computed once per request, so the intro and the grid share one pass over the city. That
also helps numbered pages (`/city/page/2/`), where the paging and title code already built the view before the grid
did: two full builds become one.

Why. In a sample of 140 random indexed city pages, 70 had the same one-paragraph intro and 65 the same
three-block intro (a "List of Outdoor & Patio Seating in ..." heading, a sunshine paragraph, a "here is your list"
line), identical but for the city name; a few Utah and Colorado pages have a hand-written first sentence followed by
the same stock sentence. That is the same text on about 2,400 indexed pages. It is also partly wrong: the stock
sentence promises "ratings", which the site stopped showing in 0.6.11. 29 of the 65 pages with the three-block intro end with a
heading and one sentence ("there are plenty of options") that links nowhere. Google's spam policies describe doorway
pages as pages targeted at specific cities that funnel users without unique value, and a set of city pages that
differ only by the name in a template reads that way. Google gives no word-count requirement, so the replacement is
facts a visitor can use, not padding: every number comes from the listings on the page.

What each template becomes. The one-paragraph intro becomes one generated paragraph. In the three-block intro the
heading and the "here is your list" line are removed and the sunshine paragraph becomes the generated one. On a page
with a hand-written first sentence, that sentence is kept and only the stock sentence after it is replaced. Matching
is tolerant (apostrophes straight or curly, `&`, `&amp;` or `&#038;`, extra whitespace, a missing style attribute,
any city name); anything that does not match a known stock block exactly is left as it is. A city with 1 to 3
places names them as well as counting them. Counts are written as digits and phrases agree with them ("1 has a happy
hour", "4 have a happy hour").

What it deliberately does not do. The stored page content is never edited. Neighborhood and landmark pages are not
touched. A page with no intro (Los Angeles) does not get one; it only gets the state link line if it has no closing
section. The closing sections that already link to nearby towns are kept as they are. The homepage's own copy is not
touched. A city with no cards is not touched. Nothing is rewritten outside the main loop of a single page.

Rollback. Tools > Outdoor Seating > "Write city page intros from listing data" (option `osn_city_intro`, on by
default). Off restores every page's own text at once. The filter `osn_city_intro_enabled` can force it either way.
Pages already in a page cache keep their old text until the cache is purged.

Notes. "Confirm outdoor seating" counts venues with the Outdoor Seating amenity, the same number as the grid's
"Outdoor Seating" filter chip, not the card badge (which reads the outdoor_seating field; the pipeline sets the
two separately). The rewrite runs only on the page body: not inside `get_the_excerpt`, and not in a nested
`the_content` call.

## 0.6.17

A city page's view records on the page (meta `_osn_empty`) whether it printed a listing, and the sitemap leaves
out pages whose last view was empty, in addition to the cached set.

Why. With 0.6.16 live, all 211 empty city pages were noindex, but 78 of them were still in the sitemap. The cached
set is built from city terms that have a landing page, and those 78 (the states that began on TablePress) have no
city term at all: a term is made when a city gets its first venue. Finding them from the page side would mean
reading the content of every page on each rebuild. The view already decides, for robots, what the page prints, so
it writes that down: set when the page is empty, removed when it no longer is, a write only on a change. A page is
flagged at its first uncached view, which for a page in the sitemap is at the latest the next crawl; until then it
is noindex and still listed, which Search Console reports as "Submitted URL marked noindex" and nothing worse.

The cached set stays for what the flag cannot give: the homepage's per-state counts, and pages nobody has viewed.

## 0.6.16

Fixes 0.6.15 on the live site. A city page is empty when its first `[table]` shortcode, rendered, prints neither a
card nor a table row. The source note reads "Compiled by Ryan R. Listings come from..." and no longer "Ryan R..".

Why. After 0.6.15 went live, the 133 empty city pages that print "No patios listed here yet." (Illinois, Texas)
were noindex and out of the sitemap, and the 79 that still print an empty TablePress table (Washington,
California, Oregon, Arizona) were not. 0.6.15 decided with two guesses about TablePress, and at least one was
wrong for those pages: it required the takeover to be enabled for the city, and it treated a page as showing
listings when the stored JSON of its TablePress table contained the filter text. Neither is what the page prints.
A city the takeover is not enabled for is left to TablePress, and an empty TablePress table is as empty as no
cards; text in the stored table does not mean a row is printed. Rendering the shortcode is the one test that is
right for all of it: takeover cards, TablePress rows (Columbia City, OR still prints one and stays indexed),
hidden rows, a missing table, the tableless note.

Cost and side effects. The render runs only on pages the takeover shows nothing on, once per request, and
TablePress caches its output. On those pages TablePress numbers the visible copy of the table "-no-2", because it
has rendered the table once already. When no `[table]` shortcode is registered in the request (TablePress
registers it on the front end only), a page is treated as not empty rather than guessed at, and a cached
set built in such a request is kept for 10 minutes instead of 12 hours. The requirement that the takeover be enabled for the city is gone from both the robots decision
and the cached set.

## 0.6.15

City pages with no venue to show leave the index. A city page that prints no cards (no published venue, or only
permanently closed ones) is now `noindex,follow`, is left out of the sitemap and is not counted in the homepage's
"N cities" for its state. State pages no longer print a tile for any city with no venue to show (a letter left
with no tiles loses its heading and jump link). New class `Empty_Cities`.

Two mechanisms, on purpose. Robots is decided live from the page itself (`Empty_Cities::page_shows_nothing()`): the
page's `[table filter="City, ST"]` must name a real state and a city the takeover is enabled for, and
`Takeover::context()` must find nothing to show. It never reads the cached set, so a stale set cannot noindex a page
that has venues. A page with no filter, a neighborhood filter ("Ballard, Seattle"), a state page, the front page
and About are never touched. Nor is a page that may print listings the first filter does not account for: one
with a second `[table]` or an `[osn_venues]`, or one whose TablePress table still holds a row for that filter
(without venues the takeover does not apply and TablePress prints its own rows; a check of the live pages found
one, Columbia City, OR). The sitemap, the state tiles and the homepage counts read a cached set (one transient,
12 hours) that can lag: in the worst case up to 12 hours (about 24 for the homepage counts, which sit in the
homepage's own 12-hour cache), normally until the next venue batch that crosses a city between empty and not
empty, or the next city change. The set is built from what pages show, not from term meta
alone: a landing ID in term meta that points at a state page, the front page or About, or at a page whose filter
names a different city, is not in it; a page whose filter names no city at all is. Rebuilding the set runs inside
the request that needs it and costs one term query, one term-meta load, one grouped closed-venues query and one
post prime.

State tiles use the state's own data instead: a city gets a tile only when that data counts a venue for it. That
also removes links to city pages that do not exist. The same check of the live pages found 53 of the 265 "View
list" links were 404s: the Oregon page's content lists 52 cities that have no page, and Idaho's links Coeur
d'Alene under a slug that is not the page's. Hiding the tiles hides those links; the page content is still wrong
and the homepage still counts those 52 for Oregon. Only when a state has no data at all (a failed build with no
copy to fall back on) does the cached set decide, so one bad build cannot empty a state page.

Invalidation. City term created, edited or deleted, landing meta changes, any save of a published page, the
register-cities route (all through `City_Index::flush()`) and a change to the takeover's city list clear the set.
Venue batches no longer discard it: `City_Index::flush( false )` skips it, and instead the
`osn_city_venues_changed` hook, a venue's city being set or changed, and a venue being published, unpublished or
trashed run `Empty_Cities::check_term()`, which clears the set only when that city crossed between empty and not
empty. Deleting a published venue outright is not caught there (its term count is still the old one when the
hook runs); the next rebuild catches it. To stay cheap on bulk purges (turning the takeover on or off purges every city), a city the
set calls non-empty with a term count above zero is not queried; one that dropped to only closed venues is caught at
the next rebuild.

Hub pages (city grids, state pages, the homepage) end with one line: "Compiled by Ryan R. Listings come from Google
Maps listing data and each venue's own website.", a date, and a "How this directory is made" link to the page with
the slug "about". On a city grid the date is "Last checked <date>": the newest check among the cards on the current
numbered page. On state pages and the homepage it is "Directory last updated <date>": the newest check across all
published venues, which is site-wide, not that state's. The note appears on state pages and the homepage only where
the plugin renders them (state takeover or shortcode, homepage takeover). The name and the link are filters:
`osn_compiled_by` (empty string leaves the name out) and `osn_about_url` (empty string leaves the link out). New
class `Source_Note`.

Why. 265 of the 2,615 cities listed on the state pages had no venue to show. 211 of them were pages that were
indexable, in the sitemap and printed an empty table (the rest: 53 dead links and the one TablePress row above), and Google reports an empty page that returns 200 as a soft 404. "Empty" is what the page shows,
not the raw term count: `Util::city_has_venues()` skips permanently closed venues, so a city whose only venues are
closed prints no cards even though its term count is above zero. `Empty_Cities` therefore subtracts the closed
venues per city with one grouped query rather than calling `city_has_venues()` for each of 2,600 terms. Venue pages
already said where their data came from and hub pages did not; a directory that does not say who is behind it
or how current it is gives a visitor, and a quality reviewer, nothing to judge it by.

Not changed. The empty page's own text ("No patios listed here yet." and the rest), so a visitor who lands on one
still sees what they did before. The city search list and its datalist still include empty cities. Neighborhood
and landmark pages are not targeted (one exception: a neighborhood page whose filter happens to end in a state
code, such as "Downtown, LA", and whose area has no venue is treated like an empty city page). A state whose
cities are all empty would print its "All cities" heading with nothing under it. A city that gets a venue
is indexable again at once (robots is live); page caches in front of the site may hold the old robots tag until
they expire.

## 0.6.14

Venue names are plain text wherever they are not HTML: the `name` in the
Restaurant and ItemList JSON-LD, the Google Maps "Get directions" link and
map embed, the `title` in `GET /osn/v1/hoods/venues`, and the excerpt the
plugin writes for a venue. New helper `Util::title()`.

Why. `get_the_title()` is a display function: it runs wptexturize, so
"Ivar's Acres of Clams" comes back as `Ivar&#8217;s Acres of Clams`. In
HTML that entity is a curly apostrophe, which is what the heading shows.
Outside HTML it is just those characters. The venue's JSON-LD name was
literally `Ivar&#8217;s Acres of Clams`. The Maps query sent the entity to
Google as text, and because the address ("Ivar's Acres of Clams, 1001
Alaskan Wy...") then no longer started with the title, the name was added
in front of it a second time. `Util::title()` reads the stored title,
strips tags and decodes entities; the map functions use it whatever title
their caller passes. Headings, card titles, alt text and the visible
sentences still use the display title.

Excerpts already written keep their text until the venue is next saved.

## 0.6.13

State pages and the homepage carousel no longer rebuild their per-city data
while a visitor waits. A venue change marks only its own state out of date,
and `State_Cities::data()` returns the copy it has while a cron job
(`osn_rebuild_state`) rebuilds that state in the background.

Why. The data behind a state page (venue count, top categories and photo per
city) costs two aggregate queries over every venue in the state. Until now
one version number covered all states: any venue saved anywhere, or any
photo attached, discarded all of them, and the next visitor to each state
page ran those queries inside the request. The homepage carousel reads
several states, so it paid for each in turn. The import pipeline saves
venues for 30 to 100 minutes every two hours, which kept the data
permanently discarded while it ran: an audit crawl that overlapped a run
timed state pages at 10 to 14 seconds and the homepage at 22 to 27, against
about 0.6 seconds when the pipeline was idle.

What changed, and what it costs:

- Each state has its own flush time, in an option of its own
  (`osn_state_flush_WA`, and `osn_state_flush_all` for every state), so
  writing one never rewrites another. `State_Cities::flush()` takes a city term ID or
  a state code and marks that state only; with no argument, or a term whose
  state cannot be told, it marks every state. One shared map was the first
  design and was dropped in review: a request reads options once at its
  start, so two overlapping batches would have written back each other's
  stale map and lost flushes. The old counter `osn_state_data_ver` is no
  longer read.
- What still marks every state: a city term created, edited or deleted, any
  published page saved or a page published or unpublished (existing
  behavior, broader than it needs to be), a city's landing page or state
  changed, and the register-cities route. A venue batch no longer does. It
  used to reach `State_Cities::flush()` a second time through
  `City_Index::flush()` whenever a venue was created or changed status or
  city, which is most of an import; `purge_cities()` has already marked the
  states it touched, so that call now passes `false`. (A venue that creates
  a brand new city still marks every state, through the term hooks.)
- The cached copy lives a week and records when its build started, not when
  it finished: a venue saved while the queries run is not in the result, so
  its flush has to count as newer. A copy is out of date when a flush is as
  new as it or newer, or it is 12 hours old (the old lifetime).
- An out-of-date copy is still served, and a rebuild is scheduled no sooner
  than 15 minutes after the last one, so a long import costs one rebuild per
  visited state per 15 minutes rather than one per page view. The price:
  counts and photos on state pages and the carousel can be 15 minutes
  behind during an import, plus however late cron runs and however long the
  page cache holds the page. City pages are not affected; they do not read
  this data.
- A request still builds in line in three cases: there is no copy at all
  (the cache was evicted, or a state is read for the first time), the job
  could not be scheduled, or the scheduled job is more than 10 minutes
  overdue, which means cron is not running. The last two happen no sooner
  than 15 minutes after the previous build, as a job would, and a two-minute
  lock keeps them to one request where there is a shared object cache.
  Without these a site with broken cron would show the same counts for a
  week.
- A build whose query fails no longer replaces a good copy with an empty
  state (which 0.6.12 then cached for 12 hours). `build()` returns null when
  either query reports an error; the copy is kept and the retry waits 15
  minutes.
- Copies cached by 0.6.12 have no build time, so they count as out of date:
  each is served until its background rebuild runs.

Not changed: the homepage's own city list (`Home::data()`) and the city
index are still discarded by a venue batch that creates a venue, and are
rebuilt in the next request. That build is page parsing and one term query,
not the per-state venue queries; if the homepage is still slow during an
import, that is where to look next.

## 0.6.12

City, neighborhood and landmark grids are cut into numbered pages of 240
cards: `/seattle-wa/`, `/seattle-wa/page/2/`, and so on. With JavaScript the
grid still searches and filters the whole view, because the other pages are
fetched on demand. This replaces the 0.6.8 decision that every card is in the
HTML of one page.

Why. Googlebot reads only the first 2 MB (uncompressed) of an HTML file and
ignores the rest. A card averages about 1,950 bytes, so New York (2,087
cards) was 4.19 MB, San Diego 2.38 MB, Chicago 2.17 MB and Portland 1.90 MB:
past the limit, the lowest-ranked venues on the biggest pages were never
read at all. Real numbered pages put every venue in server-rendered HTML on
some page, each one well under the limit (240 cards is about 0.5 MB), and
they link to each other with plain anchors so a crawler can walk them. They
canonicalize to themselves, so they are not duplicates of page 1. DoS Toolkit
builds that canonical with core's `get_pagenum_link()`, which keeps the
request's query string, so `/city/page/2/?utm_source=x` would have named
itself canonical; a `get_pagenum_link` filter here returns the clean
`/page/N/` URL on grid pages.

What a numbered page is.
- 240 cards per page (setting Tools -> Outdoor Seating -> Cards per page,
  option and filter `osn_cards_per_page`). Any value from 1 to 119 is raised
  to 120: the script reveals 120 cards at a time, so a smaller page would
  make every visit fetch the next part on load.
- 0 puts everything on one page and is the rollback switch. It has to undo
  all of this, so with 0 there is no nav, no `data-src`, the old script path,
  the old markup apart from a `data-id` on each card, `/page/N/` answers as
  it did before (page 1 again, not a 404), the canonical link filter stands
  aside, and `/osn/v1/cards` returns 404.
- The nav shows "Page N of T (places 241 to 480 of 700)", a link to every
  page, and Prev / Next.
- The filter and chip block, the count line and the chip counts cover the
  whole view on every page, not the slice. A filter that said "Dog friendly 9"
  on page 1 and "Dog friendly 4" on page 2 would be wrong, and the counts are
  what the JavaScript filters promise.
- The H1 gets " – Page N", the title gets it before the first " | " (a
  filter at priority 20, after the Toolkit's 10), and the meta description
  gets "Page N of T. " in front, all from page 2 on, so the pages read as
  different pages in a results list.
- The "Browse by neighborhood" and "Browse by landmark" lists and the ItemList
  JSON-LD are on page 1 only: they describe the whole view, and repeating
  them would add weight and duplicate markup to every page. The "nearby
  neighborhoods" and "back to the city" links stay on every page.
- A page number past the last is a real 404 (it used to return page 1 again
  with a 200, a duplicate of the canonical page). That includes `/page/2/` on
  a grid that fits on one page. Setting the 404 is not enough on its own:
  core answers a 404 by guessing a permalink from the slug and redirecting,
  so `/seattle-wa/page/99/` would have 301'd to `/seattle-wa/`, and
  `/<city>/downtown/page/9/` to whichever "downtown…" page matched first,
  possibly in another city. `do_redirect_guess_404_permalink` is switched off
  for that request. Pages that are not takeover grids are left alone. Paged
  URLs stay out of the sitemap, and there is no rel prev/next (Google ignores
  it).
- `[osn_venues city=...]` used on its own is not paged in this release.

On-demand loading. A new public route, `GET /osn/v1/cards?post=<page ID>&part=<N>&v=<hash>`,
returns the cards of numbered page N as JSON (`part`, `parts`, `total`, `per`,
`ids`, `html`) with `Cache-Control: public, max-age=300`, so the edge cache can
hold it. It resolves the page through the same resolver the page itself uses
and cuts the same slice, so part N is exactly numbered page N. `v` is a
cache-buster printed in the page (a hash of the plugin version and the ranked
ids); the server ignores it. A page that is unknown, unpublished, password
protected or not a grid is a 404 `osn_no_grid`, a part out of range a 404
`osn_no_part`. The script treats a 404 as an empty part, not a failure: it
means the view shrank after the page was cached.

`assets/osn.js` fetches only when it has to: when a search or filter becomes
active (including `?hood=`, `?district=`, `?spot=` and `?casino=` on load),
once when the search box or a chip first takes focus, as a head start (focus,
not touch: on a phone a scroll that begins on the filter block is a touch too),
and for the next part when "Show more" reaches the end of what is loaded. At most two requests
run at once, each is retried once, and a part that still fails is skipped:
filters keep working over what loaded, the count line says some places could
not be loaded, "Show more" becomes a retry button and the numbered nav comes
back. While a filter is still waiting on parts the count line says "Searching
all N places…" and "No places match" is held back until every part has been
searched. A card already on the page is dropped from a part (matched by
`data-id`), which is the guard if a venue upsert re-ranks the list between
the page and the part. On page 2 and later the view starts at that page until
the first filter, then becomes the whole view from rank 1. When the filter is
cleared, the list goes back to that page's cards unless every earlier part is
already loaded, in which case it stays the whole view from rank 1. That is
decided once, at the clear, so the list never goes blank waiting for part 1
and never jumps as parts arrive.

Things to know.
- `osn_max_cards` still caps the ranked list before it is split, and the
  `osn_sorted2_` transient is unchanged (it holds ids, not pages).
- Each numbered page and each part still loads the term cache for every id
  of the view (the hood and landmark filters read it); posts, meta and
  thumbnails are primed for the slice only. A city that has landmarks also
  primes meta for every id, because the casino lookups read it.
- A cached numbered page and a fresh part can disagree for a few minutes
  after an upsert re-ranks the list. The version in `data-src` and the
  `data-id` de-duplication are the whole guard; nothing heavier is added.
- Changing the setting does not purge page caches by itself.
- None of this ran on a real WordPress before release: there is no staging
  site. The PHP is covered by `tests/test-osn-*.php` against stubs, and the
  script was exercised in a browser against a local harness. The 404, the
  redirect guard and the canonical filter rest on a reading of core.

## 0.6.11

The star rating and review count are no longer shown anywhere: not on venue
cards in city, neighborhood and landmark grids, and not in the header of a
venue page. 0.6.10 took them out of the structured data; this takes them off
the page.

Both numbers come from each venue's Google Maps listing. Whether a site may
republish them is a question about the data provider's terms that has not
been settled, and Ryan chose to stop showing them rather than leave it open
(2026-11-09). The values are still stored and still used where a visitor
never sees them: `Util::sorted_ids()` ranks a city's venues by review count,
and the state page's top-cities query uses it too. Do not remove the fields.

The meta description templates for city and landmark pages no longer promise
"ratings". Descriptions already seeded keep the old wording until Tools ->
Outdoor Seating -> Seed SEO titles/descriptions is run again.

## 0.6.10

Venue pages no longer put the Google rating in their `Restaurant` JSON-LD.
The `aggregateRating` block (star value and review count) is gone; every other
property is unchanged, and the visible "★ 4.3 (9,568 reviews)" line on the
page stays as it was.

The rating and count come from each venue's Google Maps listing. Google's
review snippet guidelines say "Don't aggregate reviews or ratings from other
websites", and the same page names a manual action as the consequence of
breaking them. Its local business documentation recommends `aggregateRating`
only for sites that collect reviews about other businesses, which this site
does not. A site audit on 2026-11-09 found the markup on every venue page
sampled (43 of 43), so the exposure was the whole venue template.

Do not add it back from Maps data. Ratings in the markup would need to be
first-party: collected from this site's own visitors.

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
