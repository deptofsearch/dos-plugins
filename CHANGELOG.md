# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-toolkit-v<version>`.

## 0.7.0

Conflict detection. Plugins this toolkit absorbed are deactivated
automatically, logged, and reported; third-party plugins are never touched.

Deactivation happens when the module that replaces a plugin is switched on,
not when the toolkit is activated — doing it at activation would leave a site
with neither the old plugin nor the new module, turning a conflict into a loss
of function. Superseded plugins are matched by name as well as by path,
because a plugin folder often gets renamed on the way onto a site.

## 0.6.1 – 0.6.3

Three silent failures in the updater, all found on the first real install and
none reachable by the tests, because all three were about how WordPress reads
what the plugin hands it.

- **0.6.1** A failed lookup was cached exactly like a successful one, for six
  hours, so a rate limit or timeout read as "no updates exist". Failures now
  cache for fifteen minutes and carry their reason, which the Settings screen
  shows.
- **0.6.2** Caches were not cleared after a self-update, so the Plugins screen
  could offer an update to the version already running.
- **0.6.3** The updater only wrote to the update transient's `response` list.
  WordPress decides a plugin supports updates by finding it in `response` *or*
  `no_update`, so on a current site it appeared in neither and the
  auto-update toggle was hidden. Both lists are now written.

## 0.6.0

AI Search module, written from scratch and deliberately narrower than first
sketched. A per-bot crawler policy separating training crawlers from the ones
that cite you, opt-in FAQ schema, and an `llms.txt` file labelled speculative
in the UI because no major vendor has committed to reading it.

Blanket-blocking "AI" costs the citations along with the training, which is
why the policy is a table rather than a switch.

## 0.5.0 – 0.5.2

- **0.5.0** Utilities module, the last of the five absorbed plugins. Adds
  settings export and import, which is what makes this a toolkit rather than a
  plugin: configure one site, carry the configuration to the rest. Imports are
  filtered to keys this plugin recognises, and access tokens are excluded in
  both directions.
- **0.5.1** A shared media picker replaced the numeric attachment ID fields.
  Stored format is unchanged, so nothing needed migrating.
- **0.5.2** Attribution corrected. `Plugin URI` had pointed at a repository
  that never existed. Adds `Update URI`, which stops wordpress.org being asked
  about this plugin — without it, a public plugin sharing the slug
  `dos-toolkit` would be offered as an update and installed over this one.

## 0.4.0

Images & Media module: responsive markup rewriting, usage scanning, alt-text
audit, title clearing and deletion of unused images.

Two things the original plugins did not do. A protected list, because the site
logo, icon, header image and share image appear in no post content and a scan
therefore concludes they are unused — which is exactly how a cleanup deletes a
site's logo. And correct paging on delete: a live pass always takes the first
page, because deleting removes rows from the set being paged through and an
advancing offset would skip half of them.

## 0.3.0

The destructive-job guard moved to the server. The typed `RUN` confirmation
had existed only in JavaScript, so it stopped a misclick and nothing else —
any request reaching `admin-ajax.php` with a valid nonce could start a live
destructive run by omitting `dry_run`.

A destructive job now runs live only with the exact confirmation phrase and a
dry run of the same job completed within 24 hours. The receipt is consumed by
the run it authorises, and the mode is fixed by the opening request, so a
forged continuation cannot flip a dry run into a live one.

Also adds `tests/`, run before every release.

## 0.2.0

SEO module, ported from SAAB Toolkit. Behaviour carried over intact;
site-specific copy genericised; the author-archive noindex became a setting,
because it is right for a single-author site and wrong for one with several
bylines and this plugin has to suit both. Per-post overrides fall back to the
old `_saab_seo_*` meta keys so the site it came from keeps its descriptions.

## 0.1.0

The shell: module registry with per-module toggles, the DoS Tools menu, a
shared resumable batch runner, an audit log, and the GitHub release updater.

No modules. Everything ships disabled, because on these sites the live site is
usually the only test environment.
