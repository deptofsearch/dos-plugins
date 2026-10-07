=== DoS Works ===
Contributors: departmentofsearch
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Works (dos_work) post type and case-file fields for the Department of Search portfolio.

== Description ==

Registers the `dos_work` custom post type (labelled "Works") with the slug `works`, plus these REST-enabled post meta fields and a simple classic meta box to edit them:

* dos_case_no, case number (e.g. 001)
* dos_client
* dos_year
* dos_stack, comma-separated tools
* dos_status, e.g. Live or Underway
* dos_result, one-line result
* dos_featured, boolean

There is no post type archive: a regular page with the slug `works` hosts the archive using the DoS Department theme's "Works Archive" template.

== Changelog ==

= 0.1.0 =
* Initial release.
