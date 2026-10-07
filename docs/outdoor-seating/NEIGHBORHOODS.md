# Neighborhoods (plugin 0.5.0)

## Data model
- Taxonomy `osn_hood` on `osn_venue`, hierarchical, not public (no archives). Top-level terms = districts (Seattle L_HOOD), children = neighborhoods (S_HOOD).
- Term slug = city slug + short slug: `seattle-wa-ballard`. URLs and `data-*` attributes use the short slug (`?hood=ballard`). A district whose slug collides with a neighborhood gets `-district` (`ballard-district`).
- A venue carries ONE term: its neighborhood. The district is derived via the parent. (A venue assigned a top-level term has a district but no neighborhood.)
- Term meta: `osn_city` ("Seattle, WA"), `osn_page_id` (legacy landing page, 0 = none), `osn_neighbors` (array of full slugs), `osn_redirect` (URL).
- Page post meta `_osn_redirect`: 301 target, honored only when the page's city is enabled in the takeover setting; page is dropped from the core sitemap and noindexed.
- Visible venue = published and not permanently closed (same rule as the city grid).

## REST (namespace `osn/v1`, all `manage_options`)
| Route | Body / params | Notes |
|---|---|---|
| `POST /hoods/import` | `{city, dry_run, districts:[{name, slug, page_id?, neighborhoods:[{name, slug, page_id?, neighbors?:[slug]}]}]}` | Upserts terms + meta. Returns created/updated/unchanged counts, warnings (unknown neighbors), `unmanaged_terms` (existing terms not in payload; never deleted). |
| `GET /hoods/venues?city=Seattle, WA` | | Every venue, any status: id, title, status, lat, lng, hood_slug, link. |
| `POST /hoods/assign` | `{dry_run, assignments:[{venue_id, hood_slug\|null}]}` | Sets the single term (full or short slug; must belong to the venue's city). null clears. Max 1000/request. |
| `POST /hoods/pages` | `{dry_run, city?, pages:[{page_id, hood_slug?\|null, redirect?}]}` | `hood_slug` links the page (sets `osn_page_id`), `null` unlinks; `redirect` URL sets `_osn_redirect` (and term `osn_redirect` when linked), empty string clears. |
| `GET /hoods?city=Seattle, WA` | | Tree with visible/all venue counts, page id/link/status, redirect, neighbors; unassigned count. |

## Front end
- City grid: district chips, neighborhood chips for the active district, AND-combined with amenity chips and search. `history.replaceState` keeps `?district=` / `?hood=`; read on load. All cards stay in the HTML. "Browse by neighborhood" (`#hoods`) lists neighborhoods with venues, linking to the landing page when it exists and is not redirected, else `?hood=<slug>#osn-results-1`.
- Hood landing pages: Takeover matches a `[table filter=...]` that is not a city when the page is linked via `osn_page_id`, or the filter's "Hood, City" matches a hood of the parent page's city. Requires the city to be enabled and the hood to have a visible venue.

## Seattle workflow (data/seattle/hoods.py)
1. `python3 hoods.py build` -> `import.json`, `page_mapping.json` (read-only against the live site).
2. Install plugin 0.5.0, then `python3 hoods.py push --live --only import` (review the dry run first: omit `--live`).
3. `python3 hoods.py assign` -> `assignments.json` (review the outside list), then `push --live --only assign`.
4. Resolve `unmapped` rows in `page_mapping.json` (fill `decision`), then `push --live --only pages`.

## Multi-city tool (data/hoods/hoods.py)
`python3 data/hoods/hoods.py <seattle|portland> <build|assign|candidates|push>`; per-city config is the CITIES dict. `data/seattle/hoods.py` is a shim for Seattle. Outputs go to `data/<city>/`.
- Portland: districts are the 6 official Administrative Sextants (portlandmaps layer 233, "South" shown as "South Portland"); neighborhoods are the official Neighborhood Boundaries (layer 3). Unclaimed areas are skipped; shared strips go to the participant with the nearest own polygon; each neighborhood's district is the sextant with the largest area share (ambiguous ones are listed by `build`). `areas.json` has a bbox per neighborhood.
- `candidates --min 5` lists neighborhoods with that many visible venues (landing page candidates).
