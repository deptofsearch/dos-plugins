=== DoS Tax Votes ===
Contributors: departmentofsearch
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Elected or Appointed: how each Washington legislator and the governor voted on bills that raise or cut taxes.

== Description ==

Adds the `[eoa_tax_votes]` shortcode. For one biennium it shows:

* the governor's record: bills that raise taxes and bills that cut taxes, signed of each
* every legislator's headline counts: yes votes on bills that raise taxes and on bills that cut taxes, switchable between all, business and individual taxes, with each counted vote listed
* the bills counted, with revenue estimates, floor tallies and links to the bill and its fiscal note
* how the counting works

Attributes: `biennium="2025-26"` (default: the newest data file shipped) and `show="all|legislators|bills|method"`.

The page is rendered on the server; the small script only filters, sorts and switches categories. Styling inherits the theme's fonts and text color. A theme can set `--eoa-tv-up` and `--eoa-tv-down` on `.eoa-tv` to color the two meters.

Data lives in `data/<biennium>.json`, written by `electedorappointed/tax-votes/export_public.py` in the deptofsearch/Claude repository from reviewed bill classifications.

== Changelog ==

= 0.1.1 =
* Color: orange marks bills that raise taxes, teal marks bills that cut them.

= 0.1.0 =
* Initial release: 2025–26 data.
