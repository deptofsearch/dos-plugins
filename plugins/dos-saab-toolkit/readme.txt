=== DoS SAAB Toolkit ===
Contributors: departmentofsearch
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.9.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SEO tags, responsive image markup and restaurant pages for shotandabeer.com.

== Description ==

Modules, each off until ticked under Settings → SAAB Toolkit:

* SEO tags
* Responsive image markup
* Restaurant pages: the saab_restaurant post type filled by n8n, with its dish board, menu, photos and visit history

Formerly "SAAB Toolkit" (saab-toolkit). Settings and data carry over: deactivate the old plugin, then activate this one.

== Changelog ==

= 0.9.6 =
* Fix: /wp-sitemap.xml, the sitemap robots.txt points to, showed the homepage instead of the sitemap. It lists the site's sitemaps again, and /sitemap.xml redirects to it.
* The sitemap no longer lists the author archive, which is marked noindex.

= 0.9.5 =
* On phones, a page's only photo also gets a frame shaped like the image (at most 200px tall), so wide logos aren't trimmed.

= 0.9.4 =
* On desktop, a page's only photo is centered, with its credit centered under it.

= 0.9.3 =
* A page's only photo gets a frame shaped like the image, at most 400px tall, so wide images and logos aren't cropped on desktop.
* A lone photo from the restaurant's website is no longer a link to a lightbox that only repeated it.

= 0.9.2 =
* On phones, a page with a single photo shows it full width in a short, wide frame instead of a tall cropped card.

= 0.9.1 =
* Restaurant pages show the featured image once, in the photo strip, instead of also above the page.
* A website photo shared by a chain's locations is described with each page's own city.

= 0.9.0 =
* New REST route POST /saab/v1/restaurants/image: sets a restaurant page's featured image from a photo on the restaurant's own website (downloaded, checked, re-encoded), credited to that site. An n8n workflow fills it for directory pages.
* A page whose lead photo came from the restaurant's website shows "Photo: <name>'s website" under the photo strip.

= 0.8.2 =
* A page Ryan hasn't eaten at no longer says "I haven't eaten here yet" twice: when The Hit List shows, the empty Ryan's Take box above it is left out.

= 0.8.1 =
* Fix: a visit whose title leads with the place ("Breakfast at Hill's Restaurant — Pulled Pork Benedict - March 8, 2026") wasn't counted, so Hill's showed Pulled Pork Benedict 4× with December 2025 as the last time instead of 5× and March 2026.
* Dishes from Google reviews that are one generic word ("Eggs", "Bacon", "Coffee") or a kind of food ("Indian Food") no longer show on the dish board or the hit list.

= 0.8.0 =
* Restaurant pages for places Ryan hasn't eaten at show "The Hit List": up to five numbered dishes Google reviewers mention most, in place of the full dish board.

= 0.7.3 =
* Fix: with the old SAAB Toolkit still active, the site went down with "Cannot declare class SAAB_Toolkit" on every page after the click on Activate. This plugin now stands down whenever the old one is active, whichever loads first.

= 0.7.2 =
* Fix: the hub tile size setting had no visible effect at most screen widths (tiles stretched to fill the row). Tiles are now a fixed size: 19% smaller than the 0.7.0 tiles on desktop.

= 0.7.1 =
* Best in Tri-Cities hub tiles another 10% smaller (--saab-hub-scale: 0.81).

= 0.7.0 =
* Best in Tri-Cities hub drawn by the plugin: an H1, an intro and a linked tile for every Best-Of page, with tile-size images. Switch it on in the "Best in Tri-Cities Hub" box on that page; off restores the Builder page. Tiles ship 10% smaller (--saab-hub-scale: 0.9 in bestof.css).

= 0.6.1 =
* Fix: 0.6.0 never started (its "old plugin still active" check was always true), so restaurant pages were 404s. It now stands down only while the old SAAB Toolkit is actually loaded.

= 0.6.0 =
* Moved into deptofsearch/dos-plugins as DoS SAAB Toolkit, with updates from its releases.
* Dish board: one card per dish when titles name it differently ("Pulled Pork Eggs Benedict" = "Pulled Pork Benedict"), visits counted from linked review titles, and a photo from a single-dish visit when the sync found none.
