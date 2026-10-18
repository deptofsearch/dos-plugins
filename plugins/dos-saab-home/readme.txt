=== DoS Shot and a Beer Home ===
Contributors: departmentofsearch
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The shotandabeer.com homepage: restaurant, cuisine and dish search, category cards, latest reviews and city links.

== Description ==

While switched on (Settings → SAAB Home), the front page shows:

* a search box with Everything / Restaurants / Cuisines / Dishes / Cities buttons, suggesting matches as you type from the review taxonomies (saab_restaurant, saab_cuisine, saab_dish, saab_city);
* a card for each category, with a photo;
* the latest reviews;
* links to the cities with the most reviews.

The front page itself is never edited. Switch the setting off and it is back as it was. Administrators can preview with /?saab_home_preview=1 while it is off.

Colours and fonts follow the DoS SAAB Child theme's --saab-* CSS variables when it is active.

REST: GET /wp-json/saab/v1/search?q=birria&scope= (scope r, c, d, x or empty).

== Changelog ==

= 0.1.2 =
* "Pick a category" folds: the first 8 cards show, the rest open with a "Show all categories" button. The number is a setting (0 = no fold).

= 0.1.1 =
* The homepage takeover now swaps the front-page template, so it works whether Settings → Reading shows a static page or your latest posts, and whatever Themify Builder holds for the page.

= 0.1.0 =
* Initial release.
