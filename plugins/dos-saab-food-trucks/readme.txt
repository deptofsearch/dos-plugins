=== DoS SAAB Food Trucks ===
Contributors: departmentofsearch
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shot and a Beer: Tri-Cities food truck parks with a city filter, a map, an at-a-glance table and a section per park.

== Description ==

Adds the `[saab_food_truck_parks]` shortcode. It renders, in order:

* a city filter (All, Kennewick, Pasco, Richland area) that narrows the map, table and sections
* a map (Leaflet with OpenStreetMap tiles) with a numbered pin per park
* an "At a glance" table
* a section per park: area, name, address, write-up, optional "Our pick" with a review link, hours and directions

Attribute: `show="filter,map,table,parks"` (any subset). The post supplies the title, intro and anything else around it.

Everything is in the server HTML; the script only adds the filter and the map. Styling reads the DoS SAAB Child theme's `--saab-*` variables.

Data lives in `data/parks.json`. Empty `hours`, `pick`, `review_url` and `image` fields add nothing to the page. A park's `lat`/`lng` always win; when they are empty, WP-Cron looks the address up once (then the park's `geocode` text, if it has one) on OpenStreetMap Nominatim (one request a second) and caches it in the `dos_saab_ft_geo` option. A failed lookup waits a day before it is tried again. Until a park has coordinates it is listed without a pin.

== Changelog ==

= 0.1.1 =
* A park can carry a fallback search (`geocode`) for when its address isn't found; Columbia Gardens and Flat Top Park get one.
* Pins that share a spot fan out so each is visible.
* Coordinates print with six decimals.

= 0.1.0 =
* First release: the six Tri-Cities food truck parks, Wednesdays in West and Richland's park vending.
