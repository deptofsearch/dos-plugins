# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-market-images-v<version>`.

## 1.6.0

Market Images now updates itself from this repository's releases, like DoS
Toolkit. Behaviour on the front end is unchanged apart from one bug fix.

**The update path.** The plugin carries the shared updater
(`includes/class-dos-github-updater.php`, one copy kept in `shared/` and
checked byte-for-byte by a test) and the headers WordPress needs to route
updates to it: `Plugin URI` and `Update URI`. It looks for tags
`dos-market-images-v<version>` and the asset `dos-market-images.zip`, so a
Toolkit or City Search release is never offered to this plugin. It starts on
load rather than only in the admin, because WP-Cron runs the update check too.

The updater has to be installed by hand once, as 1.5.2 does not contain it.
After that the site updates from the Plugins screen.

**Names.** The plugin, its Tools page and the page heading now read "DoS -
Market Images", matching the other DoS plugins in the Plugins list. The menu
entry stays "Market Images": it sits under Tools, where "DoS" says nothing.

**The shortcode bug.** `[revnm_state_buttons]` was registered as the function
itself, so WordPress passed the shortcode's attributes (an empty string for a
bare tag) into the function's `$counts` parameter. That parameter is the
by-state page's city counts. It worked only because nothing indexed into an
empty string; any attribute whose name matched a state slug would have
printed a city count the author never wrote. Both shortcodes are now wrapped
in closures that call the functions with no arguments. The by-state page still
passes its real counts when it calls the function directly.

**Tests.** `tests/test-market-images.php` covers the markup surgery that
rewrites page content (`dos_mi_div_end` on nested and unbalanced markup, the
state hero moving the filter into the card and leaving other pages alone),
the topics and buttons map parsing, and the shortcode wrapper. The wrapper
test was confirmed to fail with the old registration.

## 1.5.2 and earlier

Shipped before this repository, as a ZIP uploaded by hand. There is no history
here for those versions.
