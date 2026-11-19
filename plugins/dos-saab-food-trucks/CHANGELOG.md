# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-saab-food-trucks-v<version>`.

## 0.1.1

After the first live run (2026-11-19) placed only 5 of 7 pins:

- `geocode`: an optional fallback search per park, tried right after the
  address fails. Columbia Gardens (whose plaza address Nominatim doesn't know)
  falls back to its street, and Flat Top Park to the street address its
  listings give, 4705 W Van Giesen St. The fallback is part of the cache key,
  so adding or changing one retries straight away instead of waiting out a
  day-long failure. The key format changed, so the five pins already found are
  looked up once more (about ten requests, in WP-Cron).
- Pins that share a spot fan out around it in screen pixels. Summer's Hub of
  Kennewick and 1derful Food Park are next door to each other on W. Skagit
  Ave. and got the same street-level point, so one hid the other.
- Coordinates are written as 6-decimal strings. The site's PHP printed floats
  with ~40 digits.

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
