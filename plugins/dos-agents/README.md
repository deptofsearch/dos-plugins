# DoS - Agents

Best Real Estate Agents Near Me, end to end: the agent cards on a city page, the
homepage city search and "Browse by state", the blocks at the top of each state
hub, and the site's brand layer.

Agent data is pushed to a REST endpoint and stored per city; `[breanm_agents]`
prints the cards, with search, filter chips, sort and "Show more agents".

## Shortcode

```
[breanm_agents city="boise-id" initial="12"]
```

`city` is the city's slug (`sanitize_title("<city>-<state>")`). `initial` is how
many cards show before "Show more agents" (12 more per click). All cards are in
the HTML; the filter controls appear once the script runs. If nothing is stored
for the city, a short neutral note is printed instead. The CSS and JS load only
on pages that hold the shortcode.

## Getting data in

`POST /wp-json/breanm/v1/agents/upsert` (needs `manage_options`, so an
application password works) with the city payload:

```json
{ "city": "Boise", "state": "ID", "generated_at": "...", "agents": [ { "zuid": "...", "name": "...", "profile_url": "https://www.zillow.com/profile/...", "tier": "primary", "tags": [ { "slug": "...", "label": "...", "count": 3 } ], "...": "..." } ] }
```

It answers `{ "ok": true, "slug": "boise-id", "count": 161, "skipped": 0 }`. Only
the schema is kept, a profile link must be `https://www.zillow.com/profile/...`,
and a city is capped at 500 agents. The stored copy is read back from
`GET /wp-json/breanm/v1/agents/boise-id` (also needs `manage_options`). Field-by-field detail is in
`includes/store.php`; a real payload is `tests/fixtures/boise-payload.json`.

### Review summary and the old-reviews label

An agent carries `summary` (one paragraph, up to 1500 characters) and may also carry
`summary_parts`:

```json
"summary_parts": { "buyers": "...", "sellers": "...", "working_style": "..." }
```

Each part is a plain string of up to 320 characters; tags are stripped, empty or
non-string parts are dropped, and the object is dropped when no part is left. With
at least one part the card prints a closed `<details>` ("What reviewers say") holding
one labelled paragraph per part, in the order Buyers, Sellers, Working style, with the
tag chips below it. Without parts, the card shows the `summary` paragraph in full, so
older city payloads render as they did. `summary_parts` is stored and returned by the
authenticated GET unchanged.

When `last_review` is more than 3 years before the site's local date, the card also
shows "Reviews on file are from {year} or earlier" and carries `data-old="1"`. The
`dos_agents_old_reviews_years` filter changes the 3.

### Review window

An agent may also carry `reviews_window_years`, a whole number from 1 to 20: how many
years back the reviews behind its tags and summary were taken from. Anything else is
dropped (not clamped), and an agent without it stores the same keys as before. It is
stored right after `reviews_analyzed` and returned by the authenticated GET unchanged.

With it set (and `reviews_analyzed` above 0) the card's freshness line reads "Last
review {Mon YYYY} · tags based on {N} reviews from the last {Y} years"; without it, "tags
based on the {N} most recent reviews". If it is set and the agent has neither
`summary_parts` nor `summary`, the card prints "Not enough reviews from the last {Y} years
to write a summary." where the summary would be.

## The homepage

```
[breanm_city_search]              the city box
[breanm_state_index]              "Browse by state"
[breanm_state_index state="WA"]   the cities in one state, for the top of its hub page
```

All three read one index of the cities that have agent cards, so what the box
can find, what the state counts claim and what the hubs list are the same set by
construction.

A city is in that index only when **all three** hold: agents are stored for it,
its page is published, and that page's content holds `[breanm_agents]`. The
sweep pushes agents before it edits the page, and a city can be held for review
or rolled back, so "agents are stored" on its own would offer visitors cities
whose live pages show nothing.

The index is kept current by the upsert and by `save_post`. It starts empty, so
**a fresh install needs one rebuild**:

```bash
curl -u 'user:application password' -X POST \
  https://bestrealestateagentsnearme.com/wp-json/breanm/v1/cities/rebuild
```

That answers `{"ok":true,"cities":N,"version":N}`. Run it again any time a page
was changed outside WordPress — a restore from backup, a bulk edit — since the
per-city hooks never hear about those.

### Searching

The matching rules are in `includes/search.php` and mirrored in
`assets/breanm-home.js`; change one and change the other. "Boise", "boise id",
"Boise, ID", "Boise, Idaho", "Saint Helens" and "St. Helens" all work, as does
any prefix. A name in several states lists them rather than guessing. Without
JavaScript the form posts to the site root with `breanm_city=1`, and a query
that means exactly one city is redirected to it.

Up to 400 cities ride along in the page markup (filter `dos_agents_inline_max`);
beyond that the script fetches `GET /wp-json/breanm/v1/cities`, which is public
and carries `?v=<index version>` so it can be cached hard.

## The brand layer

`assets/breanm-brand.css` applies Libre Franklin, the `--breanm-` tokens and a
wordmark header over Themify Magazine, site-wide, under `body.breanm-brand`.
The palette and type are the ones the agent cards already used. Light theme
only. The fonts are bundled, not fetched from Google.

City pages are given the page layout rather than the blog-post one, so they stop
printing a "Recent Posts" sidebar beside a list of agents. Themify's per-post
settings are answered through `get_post_metadata`, so nothing is written to the
28,000 city pages.

Three filters turn it off without a release: `dos_agents_brand` (the whole
layer), `dos_agents_brand_logo` (the header wordmark alone) and
`dos_agents_page_layout` (the city-page layout).

## Settings

- A payload may carry `"disclosure": { "title": "...", "paragraphs": ["..."] }` (title up to 80 characters, up to 6 paragraphs of up to 900). It is printed under the list and wins over the option below.
- Option `breanm_agents_disclosure`: the paragraph under the list when the city has no disclosure of its own. Defaults to a
  neutral "information only, not a ranking or endorsement" note. Output is escaped
  plain text.
- Filters `breanm_agents_chip_min` (0.05) and `breanm_agents_chip_max` (0.70): a
  filter chip is offered only when its share of the city's agents is within these
  bounds. `breanm_agents_now`: the render time, mainly for tests.
  `dos_agents_old_reviews_years` (3): how old the newest review must be for the
  "Reviews on file are from {year} or earlier" label.

## Previewing locally

```bash
php plugins/dos-agents/tests/render-preview.php
php plugins/dos-agents/tests/home-preview.php [cities.json]
```

write `tests/preview/index.html` (a city page) and `tests/preview/home.html`
(the homepage), which link the real CSS and JS by relative path. `home-preview.php`
takes an optional JSON array of `{"c": city, "s": ST, "n": agents}` — an export of
the live index — so the counts and the typeahead are the site's own. Browsers block some features on `file://`, so serving the plugin folder
(`python3 -m http.server` from `plugins/dos-agents`, then open
`/tests/preview/index.html`) is the reliable way to try it.

## Updates

Updates come from this repository's releases (tag `dos-agents-v<version>`, asset
`dos-agents.zip`) through the shared updater. See the root README for the
`DOS_GITHUB_TOKEN` constant. `tools/dos-agents/build-zip.sh` builds the same ZIP
locally.
