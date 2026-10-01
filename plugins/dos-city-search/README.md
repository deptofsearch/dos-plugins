# DoS - City Search

A city search box for a market site. It autocompletes from the site's
published city pages and jumps straight to the one chosen. It is inserted on
the homepage automatically, and is available anywhere as `[dos_city_search]`
(`[revnm_city_search]` and `[ohi_city_search]` still work). Settings and index
status are under **Tools → City Search**.

One plugin serves both Real Estate Values Near Me and Open Houses In. Where it
differs between them is a setting:

- **City list.** Pages whose URL ends in a state code (`kennewick-wa`), or pages
  tagged with a City taxonomy (`ohi_city`). Automatic uses the taxonomy when the
  site has it.
- **Placement.** After the opening section, before the first H2, or after the
  first paragraph; or directly after the section with a given class.
- **Styling.** Self-contained CSS under `.dos-city-search`, with a background and
  button colour. A site that styles its own box adds its classes under Extra
  classes (REVNM: `rv-sec rv-city-search`, `rv-search`, `rv-btn`) and the
  plugin leaves the button's colours to it.

The index is cached in a transient (`dos_city_search_index_v2`) for 12 hours and
rebuilt when a page is published, saved or unpublished, and, on the taxonomy
source, when a city tag changes. Up to 200 cities ride along in the markup;
more are fetched from `/wp-json/dos-city-search/v1/cities`.
`/wp-json/revnm/v1/cities` still answers with the old `"City, ST|slug"` strings
for pages cached before 2.0.0.

## Moving from the older plugins

2.0.0 migrates settings on its own: REVNM's 1.x settings keep the box exactly
as it was, and the Open Houses In plugin's settings are carried over and that
plugin is deactivated on the first admin page load. Its options are kept, so
reactivating it is a rollback. See `CHANGELOG.md` for the cutover steps.

## Updates

Updates come from this repository's releases (tag
`dos-city-search-v<version>`, asset `dos-city-search.zip`) through the shared
updater. See the root README for the `DOS_GITHUB_TOKEN` constant.

`legacy/dos-ohi-city-search` is the Open Houses In plugin this one replaced,
kept there as shipped.
