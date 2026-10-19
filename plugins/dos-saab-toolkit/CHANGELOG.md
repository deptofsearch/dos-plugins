# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-saab-toolkit-v<version>`.

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
