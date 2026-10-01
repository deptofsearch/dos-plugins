# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-city-search-v<version>`. There is no 1.2.0:
the updater work planned for it shipped inside 2.0.0.

## 2.0.0

One City Search for both market sites. Real Estate Values Near Me and Open
Houses In each had their own copy of this plugin (this one, and
`dos-ohi-city-search` 1.0.0), forked a year apart. They differed in where the
city list came from, how the box was placed, and what it was called, and every
fix had to be made twice and was usually made once. 2.0.0 is the two merged,
so a fix lands on both sites with one release and one update.

The differences are now settings, not forks:

- **Where the list comes from.** `City list comes from`: pages whose URL ends in
  a state code (the REVNM way, `kennewick-wa`), pages tagged with a City
  taxonomy (the Open Houses In way), or detect automatically, which uses the
  taxonomy when the site has it. Both build the same index: label, address and
  slug for each city, so everything downstream no longer cares.
- **Where it goes.** Automatic (after the opening section, else before the
  first H2, else after the first paragraph) or right after the section with a
  given class (REVNM's hero). Either way it is inserted once per request, and
  not at all if the page already holds a search box from any of the three
  plugins' class names.
- **How it looks.** The box now carries this plugin's own classes
  (`dos-city-search`, `dos-cs-*`) and its own CSS, with the background and
  button colour as settings. A site that styles its own box adds its classes in
  Extra classes, and the plugin steps back from the button's colours and the
  heading spacing so the two stylesheets do not fight. The plugin's CSS never
  names a site's classes.
- **How it is fed.** The address of the feed is always in the markup. Sites with
  200 cities or fewer also get the list inline, which saves the request; larger
  ones fetch it. The cap on suggestions and whether an empty box lists every
  city are settings too.

Shortcodes: `[dos_city_search]` is the name. `[revnm_city_search]` and
`[ohi_city_search]` still work, so content written for either plugin keeps
rendering.

The form now submits to the site's search when JavaScript is off, because the
Open Houses In form already did and a search box that does nothing is worse
than one that does something imperfect.

Two fixes came along because they were the Open Houses In plugin getting right
what this one got wrong. The index is now also rebuilt when a published page is
saved, so a renamed city shows up at once instead of up to 12 hours later (the
old rule only watched for a change of status). And on the taxonomy source it is
rebuilt when a page's city tag changes or a city is renamed or deleted.

The index changed shape (objects, not `"City, ST|slug"` strings), so it is
cached under a new key, `dos_city_search_index_v2`. The old
`revnm_city_index_v1` is never read; it expires on its own. The old feed,
`/wp-json/revnm/v1/cities`, still answers with the old strings, built from the
same index, because a page cached before the update may still be running the
old script. The new feed is `/wp-json/dos-city-search/v1/cities`. Both are
public and cacheable for an hour.

### Updater and name

The plugin updates itself from this repository's releases, like DoS Toolkit. It
carries the shared updater (`includes/class-dos-github-updater.php`, one copy
kept in `shared/` and checked byte-for-byte by a test) and the headers
WordPress needs to route updates to it: `Plugin URI` and `Update URI`. It looks
for tags `dos-city-search-v<version>` and the asset `dos-city-search.zip`, so
another plugin's release is never offered to it. It starts on load rather than
only in the admin, because WP-Cron runs the update check too. 1.1.1 does not
contain the updater, so this version has to be installed by hand once; after
that the site updates from the Plugins screen.

The plugin and its Tools page now read "DoS - City Search", matching the other
DoS plugins in the Plugins list. The menu entry stays "City Search".

### Migration

It runs on every load and writes only when the stored settings have no `schema`
key, so a current site pays one option read. There is deliberately no
activation hook: "Replace current with uploaded" and updater installs do not
fire one, and the sites that most need this are updated exactly those ways.

- **REVNM (settings exist, no `schema`).** If 1.x never saved a setting there are
  none to find, so the index meta 1.x left behind (fallback counts, no `source`)
  is the evidence instead, and the site is treated as REVNM with its defaults.
  Keeps the copy and the background
  colour, and sets the list source to URLs, placement to the hero class, and
  the section, form and button classes to `rv-sec rv-city-search`, `rv-search`
  and `rv-btn`, with no browse link. The box has the same classes, in the same
  place, with the same colour as before; the site's Additional CSS is untouched
  and still does the styling it did.
- **Open Houses In (no settings here, but `dos_ohi_cs_settings` exists, or the
  old plugin is active or left an index behind).**
  Copies the heading, intro, placeholder, button label, both colours and the
  homepage switch; uses the City taxonomy `ohi_city`, automatic placement and
  the `by-city` page for "See every city". It also keeps what the old plugin
  did without a setting: no cap on suggestions, and every city listed when the
  box is clicked. The settings record `migrated_from`, shown on the Tools page.
  The old plugin's options are never deleted, so reactivating it is a complete
  rollback.
- **Fresh install.** Neither plugin left anything: the defaults, with the source on automatic.

### Cutting Open Houses In over

1. Upload this version as a ZIP and activate it (the old plugin may still be
   active; the two do not clash).
2. The first request migrates the settings, and the first admin page load
   deactivates "DoS Open Houses In City Search" and says so in a notice. Until
   then the front end keeps showing the old plugin's box, so the homepage never
   has two.
3. Look at the homepage. Then delete the old plugin.

CSS written against the old `ohi-*` class names will no longer match; put those
classes into Extra classes if a site has any.

## 1.1.1 and earlier

Shipped before this repository, as a ZIP uploaded by hand. There is no history
here for those versions.
