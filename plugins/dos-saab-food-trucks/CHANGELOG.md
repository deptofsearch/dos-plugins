# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-saab-food-trucks-v<version>`.

## 0.1.0

First release, built from the "Editorial guide" design (option C) with the
city filter from option B, which Ryan picked on 2026-11-19.

- Server-rendered: the table and every park section are in the HTML, so the
  page reads and ranks without the script. The filter is hidden until the
  script runs, because without it the buttons would do nothing.
- Map: Leaflet 1.9.4 from unpkg with OpenStreetMap tiles shown in greyscale to
  match the white/black/grey theme. No API key.
- Coordinates: geocoding runs only in WP-Cron, never in a visitor's page load,
  and respects Nominatim's one-request-a-second limit. Typed `lat`/`lng` in the
  data file always override the lookup, for any address Nominatim gets wrong.
- Empty `hours`, `pick` and `image` fields render nothing, so no bracketed
  placeholders ever reach the live page.
- schema.org ItemList of the places, for search.
