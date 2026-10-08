# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-best-lenders-v<version>`.

## 0.5.1

The first release from this repository. It merges two lines of work that had
both been called 0.4.9, plus the 0.5.0 manual build, so nothing from either
side is lost.

Why the numbers jump: 0.5.0 was a manual build from the project repo
(`bestlendersnearme/plugin/`), uploaded by hand to bestlendersnearme.com. It
has the admin and search work but no updater and not the reviews-closed
change. This repository's own 0.4.9 had the reviews-closed change and the
updater but none of the search work. 0.5.1 has everything, and being higher
than the 0.5.0 already installed, it is what the site's updater will offer.

Install 0.5.1 by hand once: the installed 0.5.0 has no updater to fetch it.
After that the site updates from the Plugins screen. The updater looks for
tags `dos-best-lenders-v<version>` and the asset `dos-best-lenders.zip`.

From this repository's 0.4.9:
- "What reviewers mention" starts closed on every screen (it used to start
  open on desktop and close only on phones). The text stays in the HTML.
- Updates from GitHub releases through the shared updater
  (`includes/class-dos-github-updater.php`, kept byte-identical to `shared/`).

From the project repo's 0.4.9:
- Cities with no qualifying lenders no longer show "0 lenders made 0 home
  loans"; singular forms read "1 lender made 1 home loan".
- Homepage city search works by name ("Seattle", "seattle wa", "Seattle,
  Washington", "Saint/St. Helens", partial prefixes), typeahead from 2
  characters with keyboard navigation. A name in several states lists each
  state; no match shows a friendly message. New `includes/class-search.php`.
- No-JS fallback (`?s=&post_type=blnm_city`) redirects to the city page when
  exactly one published city matches.
- `GET blnm/v1/cities` payload is `{ n: city, s: state, u: url }`; the cache
  invalidates on city publish, unpublish, update, delete and meta changes.

From the project repo's 0.5.0:
- wp-admin City list: sortable "Lenders" column, "No lenders (N)" view, State
  filter and a county / nearby-towns column (new `includes/class-admin.php`).
  Uses the stored `blnm_lender_count`, backfilled when missing.
- City search index URL carries `?v=<blnm_city_index_ver>`, bumped on every
  index flush, so browsers never keep a stale empty index.
- `[blnm_state_index]` with no state lists state names linking to their state
  pages (only states with a published city and a published state page);
  `state="WA"` is unchanged. Cached, flushed with the city index.

## 0.5.0 (project repo, manual build)

See 0.5.1; this build is superseded and never came from this repository.

## 0.4.9 (this repository)

Reviews start closed; updates from GitHub releases. See 0.5.1.

## 0.4.8 and earlier

History is in `readme.txt`.
