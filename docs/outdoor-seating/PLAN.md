# Outdoor Seating Near Me — redesign plan

Started 2026-10-01. Goal: replace TablePress with searchable card layouts, give every
restaurant its own page (Shot and a Beer–style), keep city pages as SEO landing pages.

## Decisions (Ryan, 2026-10-01)
- Own page per restaurant **and** city landing pages with cards. City pages keep their URLs.
- Google Places API (New) for outdoor seating / food / drinks / amenities enrichment.
- Pilot one city (Phoenix, AZ), then a state, then everything. TablePress stays installed until proven.
- Keep the Themify Landing theme for now.

## Current site (audited 2026-10-01)
- 1,187 published + 157 draft pages, flat. 6 state pages, city pages `/<city>-<st>/`.
- City pages are plain post_content containing `[table id=N filter="City, ST" /]`.
- Tables: 440 CA (3,975) · 442 OR (1,174) · 444 WA (1,630) · 445 ID (960) · 447 NV (97) ·
  448 AZ (403) · 446 Seattle neighborhoods (477, different columns). 8,716 rows total →
  `data/tablepress_export.csv` (name, phone, address, website, category).
- ~178 city pages currently filter to zero rows.

## Architecture
1. **Plugin `dos-outdoor-seating`** (data prefix `osn_`, REST `osn/v1`)
   - CPT `osn_venue` (permalink `/restaurants/<name>-<city>-<st>/`), taxonomies
     `osn_city` ("City, ST"), `osn_amenity`, `osn_category`.
   - `POST /osn/v1/venues/upsert` — batch upsert, dedupe on source+source_id, hash-skip.
   - Venue page: header (city, category, rating, price, open-now), photos, outdoor seating,
     food & drinks, amenities, hours, map, contact, more patios nearby. Restaurant JSON-LD.
   - City cards: `[osn_venues city="Phoenix, AZ"]` — search box + amenity filter chips,
     all cards in the HTML for crawlers. ItemList JSON-LD.
   - Takes over `[table id=N filter="City, ST"]` for cities switched on in settings
     (pilot allow-list → "all"); falls back to TablePress otherwise.
   - City search box (port of dos-ohi-city-search) for homepage/state pages.
2. **n8n**
   - Seed: CSV rows → n8n Data Table → upsert basic venues.
   - Enrich: Places Text Search per venue → map fields → upsert. Paced, dryRun gate.
   - Refresh on a schedule (Places ToS caching limits).
3. **Rollout:** Phoenix → Arizona → all states; then retire TablePress.
