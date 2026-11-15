# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-tax-votes-v<version>`.

## 0.2.0

Three things Ryan asked for after seeing 0.1.0 on the site.

**A party chart near the top.** Two small diverging bar charts, one for bills
that raise taxes and one for bills that cut them: Democrats above the line,
Republicans below, counted by the share of the bills put to them that each
lawmaker voted yes on (none, under 25%, 25-49%, 50-74%, 75-99%, all). Shares,
not raw counts, because members who served part of the biennium voted on
fewer bills. Both chambers and all taxes; the switcher below does not change
it. A text version sits alongside for screen readers.

**A district map.** The 49 districts from the Census Bureau's 2024
cartographic boundary file (`cb_2024_53_sldu_500k`, which includes the 2024
court-ordered redraw), simplified to about 65 KB and shipped as
`data/wa-districts.json`; built by `electedorappointed/tax-votes/build_map.py`
in the Claude repo. Districts are shaded by delegation: all Democrat, all
Republican, or split. Hover, focus or tap shows the senator and both
representatives with their counts (following the all / business / individual
switch); selecting a district filters the list to them. A Puget Sound zoom
helps with the small Seattle-area districts. Party colors are muted blue,
red and purple, used only in the chart and map, apart from the orange and
teal that mark increases and cuts.

**Links on each lawmaker.** Their page on leg.wa.gov and their district's
map in the Legislature's District Finder. The member-page slugs were checked
for all 148 members.

### Color (first planned as 0.1.1, never released on its own)

Color, so the two kinds of bills are easy to tell apart (Ryan asked for it
after seeing 0.1.0 on the site). Orange marks bills that raise taxes and teal
marks bills that cut them: the meters, a swatch beside each count's label,
tags on every vote and in the bill table, and the governor's box.

- Not red and blue. Those read as Republican and Democrat, and the page has
  to stay neutral about party.
- Both are mid-tones picked to hold up on a light or a dark theme. They color
  fills, borders and light tints only; text keeps the theme's own color, so
  contrast is never worse than the theme's.
- The cuts meter stays hatched, so the two still differ for readers who
  can't tell orange from teal.
- A theme can still set `--eoa-tv-up` and `--eoa-tv-down` on `.eoa-tv`.

## 0.1.0

First version, for electedorappointed.com: the `[eoa_tax_votes]` shortcode
with 2025–26 data.

- The headline for each person is two separate counts, yes votes on bills that
  raise taxes and yes votes on bills that cut taxes, not one blended
  percentage. Near-unanimous small tax cuts pulled every blended score toward
  50%, which hid real differences (Ryan's call, 2026-11-15).
- Property tax exemptions are not counted. Under Washington's levy limits they
  mostly shift tax onto other owners, so a no vote on one is not a vote to
  raise taxes.
- Everything is rendered on the server so the full record is in the page for
  readers without JavaScript and for search engines. The script only filters,
  sorts and switches between all, business and individual taxes.
- Styling inherits the site theme (fonts and currentColor) rather than
  carrying a palette, so the block looks like part of the site. The cuts meter
  is hatched so the two meters differ without relying on color.
- Data ships as a JSON file per biennium instead of a REST endpoint for now.
  A later version can take pushes from n8n when the yearly update is
  automated.
