=== DoS SAAB Toolkit ===
Contributors: departmentofsearch
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.6.1
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

= 0.6.1 =
* Fix: 0.6.0 never started (its "old plugin still active" check was always true), so restaurant pages were 404s. It now stands down only while the old SAAB Toolkit is actually loaded.

= 0.6.0 =
* Moved into deptofsearch/dos-plugins as DoS SAAB Toolkit, with updates from its releases.
* Dish board: one card per dish when titles name it differently ("Pulled Pork Eggs Benedict" = "Pulled Pork Benedict"), visits counted from linked review titles, and a photo from a single-dish visit when the sync found none.
