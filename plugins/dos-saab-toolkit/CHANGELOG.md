# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-saab-toolkit-v<version>`.

## 0.8.0

**A hit list on pages Ryan hasn't eaten at.** The restaurant directory is
growing from places Ryan has reviewed to every non-chain restaurant, bar,
brewery and taproom in Kennewick, Richland, Pasco, West Richland, Prosser,
Benton City and Walla Walla. Most of those pages will have no visits, so Ryan
asked for "a short hit list of menu items to eat based on other people's
reviews on Google".

The sync already stores what reviewers order (`saab_popular_dishes`: dishes
named in two or more of ~100 Google reviews, plus Google's photo tags), and the
board showed all of it as "The Dishes". On a page with no dishes of Ryan's it is
now **The Hit List**: at most five dishes, numbered, review dishes first by
mentions, then photo tags only to fill the list
(`SAAB_Dishes::hit_list()`, pinned in `tests/test-saab-toolkit-dishes.php`). A
tag that is the same dish as one already listed is dropped. Once Ryan eats
there, the page goes back to the full board with his dishes shaded, so nothing
else changes for reviewed places.

## 0.7.3

**No more site-wide crash while the old SAAB Toolkit is still active.** On
2026-10-29 shotandabeer.com went down after a round of plugin updates, and came
back when the old `saab-toolkit` folder was renamed. Both plugins declare
`SAAB_Toolkit`. The 0.6.1 check stood this one down only if the old copy had
*already* declared the class, and that is true only during the click on
Activate, because WordPress loads the plugin being activated last. On every
request after that, plugins load in alphabetical order: `dos-saab-toolkit`
first, which declared the class, then `saab-toolkit`, which died with "Cannot
declare class SAAB_Toolkit". That fatal takes down the whole site, front end
and wp-admin alike.

The main file now also stands down when the old copy is listed in
`active_plugins` (or network-active) and its file is there, so whichever order
the two load in, only one declares the class. It matches on the file name
`saab-toolkit.php`, not the folder, since folders get renamed on the way onto
a site. A listed copy whose file is gone does not count, because WordPress
skips it too: renaming the folder is the quick way back up, and it must not
leave this plugin stood down with the restaurant pages offline (0.6.0's
mistake).

The class moved from the main file to `includes/class-saab-toolkit.php`. A
class written in the main file is declared when the file is compiled, before
any check in it runs, so there it could never be held back. Settings, option
names and data are unchanged.

## 0.7.2

**The size setting now actually changes the tile size.** Ryan saw no
reduction after 0.7.0. The grid was `repeat(auto-fill, minmax(<tile>, 1fr))`:
`--saab-hub-scale` only lowered the *minimum* tile width, and the `1fr` then
stretched the tiles back out to fill the row. The scale changed how many
tiles fit per row, and only at some screen widths. Measured on a 1160 px
content area, tiles were 278 px wide at scale 1 and 279 px at 0.9: no change.
0.81 happened to fit a fifth column (222 px), so 0.7.1 would have shown a
jump of about 20% at that one width, and still nothing at others.

The tile width is now a fixed share of the row that does not stretch:
`--saab-hub-cols` tiles across at full size (4 on desktop, 3 under 1000 px,
2 under 760 px), times `--saab-hub-scale`. At scale 1 that is the size the
0.7.0 tiles had on a full desktop row (278 px at 1160 px), so the scale is
measured against what Ryan saw first. At the shipped `0.81`, desktop tiles
are 225 px: the two 10% steps he asked for. The room a smaller scale frees up
stays at the end of each row, not spread back into the tiles. Phones (one
tile per row, under 480 px) stay full width with the scale not applied.

## 0.7.1

**Hub tiles another 10% smaller.** Ryan saw 0.7.0 live and asked for the tiles
to come down another 10%. `--saab-hub-scale` goes from `0.9` to `0.81`, 10%
under the 0.7.0 size, not 20% under the base: each round is relative to what is
on the page. Tile minimum width is now about 211 px (was 234 px), label
about 1.09rem.

## 0.7.0

**The Best in Tri-Cities hub moves into the plugin.** `/best-in-tri-cities/`
is the most-linked page on the site (it is in the main menu), but as a Themify
Builder page it was a grid of 20 hand-placed 1920×1080 PNG tiles: only 2 of
them linked anywhere, the page weighed 10.3 MB and had no H1 (SEO audit,
September 2026). Ryan also wants the tiles smaller, and Builder styling can't be
tuned from code.

- New `SAAB_Bestof_Hub` (`includes/class-saab-bestof-hub.php`, template
  `templates/bestof-hub.php`). On the hub page it renders the page title as the
  H1, an intro, and **one linked tile per Best-Of page**.
- The tiles build themselves: every published page whose slug is
  `best-…-tri-cities`, or that has a `saab_bestof` config. A new Best-Of page
  appears on the hub with no edit to the hub. Labels drop "Best" and "in
  Tri-Cities" (the H1 says it); the image is the Best-Of hero, else the
  featured image, served at `medium_large` with a srcset, so the tiles are tens
  of KB, not 500 KB each. The first four load eagerly, the rest lazily.
- Opt-in and reversible, like the Best-Of pages: a "Best in Tri-Cities Hub" box
  on that page only, with an on/off switch, the intro (prefilled from the
  page's current text on first open), and a tick list to leave a page off.
  Off, or never saved, and the Builder page shows as before; its content is
  never changed. Stored as `saab_bestof_hub` page meta (JSON: `on`, `intro`,
  `hide`).
- Tile size is one CSS variable, `--saab-hub-scale` in `assets/bestof.css`,
  which scales the tile width, gaps, padding and label together. It ships at
  `0.9`: 10% smaller than the base size (260 px minimum tile width, 1.35rem
  label). The next size change is that one number.

New `tests/test-saab-bestof-hub.php`: which pages get a tile, labels, hero over
featured image, hidden pages left off, one H1 and one link per tile.

## 0.6.1

**0.6.0 took the restaurant pages offline.** Installed on shotandabeer.com with
the old saab-toolkit deactivated, every restaurant page was a 404 and
`/wp-json/wp/v2/types/saab_restaurant` said "Invalid post type": no module ran.

The guard added in 0.6.0 to stand down while the old plugin is active was
`class_exists( 'SAAB_Toolkit', false )`. PHP declares a file's top-level class
before the file's first line runs (early binding), so that check is always true
in the file that declares the class: the plugin showed its "waiting" notice and
returned on every request, with or without the old copy. It is the same trap
0.6.0's own changelog describes for `class-saab-restaurants.php`, missed here.

The guard now stands down only when `SAAB_Toolkit` exists **and was declared
by a different file** (`ReflectionClass::getFileName() !== __FILE__`), which is
true only while the old plugin is loaded.

New `tests/test-saab-toolkit-boot.php` loads the real main file in separate PHP
processes: on its own it must hook `SAAB_Toolkit::boot` and define its version
with no notice; with an old `SAAB_Toolkit` already declared it must stand down
with the notice and no "cannot declare class" fatal. Run against 0.6.0's main
file, the first case fails exactly as the site did (notice only, no boot).

## 0.6.0

The SAAB Toolkit (`saab-toolkit/saab-toolkit.php` 0.5.6) moves into this
repository as **DoS SAAB Toolkit**, and the restaurant pages' dish board stops
showing one dish twice and stops leaving dishes without their photo.

### Moving in

- Imported unchanged from the live site first (its own commit), so the diff of
  this release is only what changed.
- Named for the `dos-` rule: folder and main file `dos-saab-toolkit`, display
  name "DoS SAAB Toolkit", text domain `dos-saab-toolkit`. Classes
  (`SAAB_Toolkit`, `SAAB_Restaurants`, …), option names
  (`saab_toolkit_enable_*`) and meta keys are unchanged, so module settings
  and restaurant data carry straight over.
- Updates come from this repository's releases through the shared updater.
- Both copies declare `SAAB_Toolkit`. While the old plugin is active this one
  stands down with a notice ("deactivate the old SAAB Toolkit") rather than a
  redeclare fatal. Deactivate the old one, then activate this one.
- The DoS Toolkit's conflict list still names the old `saab-toolkit` (for its
  SEO and Images modules); it does not match this plugin's file or name.

### The dish board

On Hill's Restaurant & Lounge (2026-10-19) "Pulled Pork Benedict" and
"Pulled Pork Eggs Benedict" were two cards, both without a photo, and the
March 2026 visit ("Breakfast at Hill's Restaurant — Pulled Pork Benedict") was
on neither, although all five visits have a featured image of the dish.

`saab_ryan_dishes` is written by the n8n syncs (Restaurant Page Sync, New
Review Post Sync). They match dishes by exact name, and a Claude photo check
sets `photo_id: 0` when it is not sure an image "clearly shows" the dish. The
board now reads the dish list through `SAAB_Restaurants::get_ryan_dishes()`:

- **Same dish, one card** (`SAAB_Dishes::same()`): same words in any order,
  "&" or "and", plural or not; or one extra word when the shorter name has
  three words or more, or the extra word is filler ("eggs", "classic", …).
  "Pulled Pork Benedict" = "Pulled Pork Eggs Benedict"; "Eggs Benedict" ≠
  "Pulled Pork Eggs Benedict"; "Pho" ≠ "Chicken Pho". The card takes the
  most-eaten name.
- **Visits from titles**: a linked post whose title names the dish
  (`SAAB_Dishes::from_title()`, which also reads "Breakfast at X — Dish") is a
  visit. The card shows the larger of the synced count and the visits found,
  and "Last time" is the newest visit.
- **Photo**: the first photo the sync verified for any of the merged names;
  when there is none, the featured image of a visit whose title names *only*
  this dish. The sync's "no photo of this dish" still holds for a two-dish post
  ("Spring Rolls and Beef Pho" lends nothing to "Spring Rolls").
- A Google-review dish that is the same dish as one of Ryan's joins his card.

The lightbox's photo list uses the same resolved cards, so every card photo
opens in it. Nothing is written back: the stored meta is as the syncs left it.

Found on the way: `class-saab-restaurants.php` guards with
`class_exists( 'SAAB_Restaurants' )`, which is always true by its first line
(PHP declares the file's class up front), so code after the guard never runs.
The new `require_once` sits above it.

New `tests/test-saab-toolkit-dishes.php` (26 checks) runs Hill's live data
through the board.
