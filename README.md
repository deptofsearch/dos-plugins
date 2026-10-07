# DoS Plugins

[Department of Search](https://departmentofsearch.com) WordPress plugins. One repository, one release workflow,
one update path for every site we run.

```
plugins/
  dos-toolkit/        The standard toolkit: SEO, AI Search, Images, Utilities
  dos-city-search/    City search box (Real Estate Values Near Me, Open Houses In)
  dos-market-images/  Illustrated market images (Real Estate Values Near Me)
  dos-outdoor-seating/ Venue pages and city cards (Outdoor Seating Near Me)
  dos-works/          Works post type (departmentofsearch.com)
themes/               Site themes, kept here for source control (not released)
shared/               Code every plugin vendors a copy of (the GitHub updater)
tools/                Scripts that support a plugin and never ship in it
legacy/               Plugins kept as reference or as shipped
tests/                Stubbed-WordPress tests, run on every release
.github/workflows/    Tag -> lint -> test -> build ZIP -> publish release
```

| Plugin | Folder | Tag | What it is |
|---|---|---|---|
| DoS Toolkit | `plugins/dos-toolkit` | `dos-toolkit-v<version>` | The standard toolkit, as modules that ship disabled. |
| DoS - City Search | `plugins/dos-city-search` | `dos-city-search-v<version>` | City search box for Real Estate Values Near Me and Open Houses In: lists from city-named URLs or a City taxonomy. |
| DoS - Market Images | `plugins/dos-market-images` | `dos-market-images-v<version>` | City carousel, state buttons and topic grids for Real Estate Values Near Me. Its generating and uploading scripts are in `tools/market-images/`. |
| DoS Outdoor Seating | `plugins/dos-outdoor-seating` | `dos-outdoor-seating-v<version>` | Outdoor Seating Near Me: venue post type, `osn/v1` REST upsert, venue pages, city cards that take over TablePress. Plan and REST contract in `docs/outdoor-seating/`. |
| DoS Works | `plugins/dos-works` | `dos-works-v<version>` | departmentofsearch.com: the Works (portfolio) post type and its meta box. |
| `themes/` | `themes/*` | not released | departmentofsearch.com themes: `dos-department` (block theme) and `dos-magazine-child`. |
| `legacy/` | `legacy/*` | not released | Reference only: the plugins the Toolkit absorbed, and `dos-ohi-city-search`, the Open Houses In city search as shipped. City Search 2.0.0 replaces it. |

Each plugin has its own `CHANGELOG.md` and its own version, and is released on
its own tag.

DoS Outdoor Seating and DoS Works moved here from the project repo on
2026-10-07. Outdoor Seating's history up to 0.6.3 is in its `readme.txt`;
`CHANGELOG.md` carries it from 0.6.4.

## Tests

```bash
docker run --rm -v "$PWD":/app -w /app php:8.2-cli \
  bash -c 'for t in tests/test-*.php; do php "$t"; done'
```

They stub the WordPress functions each unit touches, so they run anywhere PHP
runs, with no database and no WordPress install. `test-batch-guard.php` is the
one that matters most: it asserts that a destructive job cannot be made to run
live by a forged request, only by a deliberate one that follows a recent dry
run.

## Installing on a site

Download the `dos-toolkit.zip` asset from the latest
[release](https://github.com/deptofsearch/dos-plugins/releases) and upload it
under **Plugins → Add New → Upload Plugin**. Take the named asset, not the
"Source code" links — those unpack to a folder named after the tag, which
WordPress would install as a differently-named plugin.

That first install has to be manual. The updater ships inside the plugin, so
it cannot install itself. Afterwards the site updates itself: the plugin
checks this repository's releases and offers new versions on the normal
Plugins screen.

The repository is public, so no token is needed to read it — but set one
anyway. GitHub allows **60 unauthenticated API requests an hour per IP**, and
on shared hosting that IP belongs to every site on the server, so update
checks fail with HTTP 403 for reasons that have nothing to do with this site.
An authenticated request gets 5,000 an hour.

A token with **no permissions at all** is enough; it only identifies the
request. Create one under Settings → Developer settings → Fine-grained tokens
with read-only access to public repositories and no permissions added, then
put it in `wp-config.php` rather than the settings field, so it stays out of
database backups:

```php
define( 'DOS_GITHUB_TOKEN', 'github_pat_...' );
```

Either constant works. `DOS_GITHUB_TOKEN` applies to every DoS plugin on the
site, so it is the one to set. `DOS_TOOLKIT_GITHUB_TOKEN`, the name sites
already have, still works; if both are set `DOS_GITHUB_TOKEN` wins. Plugins
that keep a token elsewhere can supply it through the `dos_github_updater_token`
filter. Several DoS plugins on one site share one cached list of the
repository's releases, so adding plugins does not add API requests.

The City Search, Market Images, Outdoor Seating and Works ZIPs are installed
the same way, from the asset named for the plugin, and need that first manual
upload for the same reason.

GitHub's "Latest" badge on the releases page points at whichever plugin was
released last. That is harmless: the updaters never use `/releases/latest`,
they list releases and filter by their own tag prefix.

## Rollout

Releases reach every site running this plugin, so a bad one reaches every site
too. The order below exists to make sure something breaks somewhere cheap
first.

**One canary site.** Pick the lowest-stakes site and keep it a version ahead
of the rest. Turn auto-updates **on** there and leave them off everywhere
else. A release that is going to cause trouble causes it on the canary, days
before it reaches a client.

**Enable modules one at a time.** Every module ships disabled and nothing
changes until a box is ticked, which is only useful if you actually use it
that way. Turn one on, look at the site, then turn on the next. The two worth
the most care:

- **SEO** writes to `wp_head`. View source on a post, a page, an archive and
  the homepage, and confirm there is exactly one canonical, one meta
  description and one JSON-LD block. If Yoast, All in One SEO, Rank Math,
  SEOPress or The SEO Framework is active, the module stands down and says so
  on its own screen — that is expected, not a failure. To replace Yoast or
  AIOSEO, use the import jobs on that screen first (dry run, live run,
  spot-check), then deactivate the old plugin.
- **Images** buffers the whole page to rewrite markup. It has two switches for
  a reason: turn on inspection first, load a few pages, read the report on the
  module screen, and only then turn on rewriting.

**Dry run before any destructive job.** The delete and clear-titles jobs
refuse to run live until a dry run of the same job has finished within the
last 24 hours, and they ask for a typed confirmation. Read the dry-run report
before confirming — the report is the only record of what is about to happen.

**Run the usage scan before deleting media.** The delete job acts on what the
last scan recorded. Deleting against a stale scan is how an image that is in
use gets removed.

**Expect the replaced plugins to switch themselves off.** On a site still
running one of the one-off plugins this toolkit absorbed, enabling the module
that replaces it deactivates that plugin and says so in an admin notice.
Nothing is deleted, so it can be reactivated from the Plugins screen if
something turns out to be missing. It happens on enabling the module rather
than on activating the toolkit, so a site is never left with neither.

Third-party plugins are never deactivated. If a site runs Yoast, All in One
SEO, Rank Math, SEOPress or The SEO Framework, the SEO module stands down
instead and reports that it has, on the Modules screen and on its own.

**Carry settings rather than retyping them.** Configure one site, then use
**Utilities → Export settings** and import the file elsewhere. Access tokens
are never included, so an exported file is safe to move around.

### When a release misbehaves

Every site can be put back by hand: download the previous release's ZIP and
upload it over the current one. WordPress replaces the plugin directory and
settings survive, because they live in the database rather than in the plugin.

If a module rather than the plugin is the problem, untick it on the Modules
screen. That removes its hooks and its menu entry and leaves its stored data
alone, which is usually faster than a rollback and always less disruptive.

### If updates stop appearing

**DoS Tools → Settings → Updates** reports the installed version, the latest
release found, and the reason if a check failed. The **Check for updates now**
button clears the cached result and asks GitHub again.

Use that button rather than WordPress's own force-check. WordPress's clears
its cache but not the plugin's, so a stale result can survive it for up to six
hours.

A failed check is normal and self-correcting: GitHub allows 60 unauthenticated
requests an hour per IP, and shared hosting reaches that. The plugin retries
within fifteen minutes. **An HTTP 403 means the rate limit** — set a token as
described under [Installing on a site](#installing-on-a-site) and it stops
happening.

A check that keeps failing with a *transport* error rather than a 403 usually
means the host blocks outbound requests to `api.github.com`, which is a
hosting setting rather than a plugin problem.

## Cutting a release

1. Bump `Version:` in the plugin header **and** the matching
   `..._VERSION` constant (`DOS_TOOLKIT_VERSION` for the Toolkit). They must agree or the workflow refuses the
   tag.
2. Add a `CHANGELOG.md` entry saying **why**, not only what.
3. Commit.
4. Tag and push:

   ```bash
   git tag dos-toolkit-v0.1.0
   git push origin dos-toolkit-v0.1.0
   ```

Pushing the tag is what publishes. Pushing to `main` alone releases nothing,
which is why documentation-only changes need no version bump.

Tags are `<slug>-v<version>`. The prefix is what lets several plugins live in
one repository without their updaters confusing each other — each plugin only
considers releases carrying its own prefix and its own named asset.

### What the workflow does

`.github/workflows/release.yml`, triggered by any tag matching `*-v*`:

1. Reads the slug and version out of the tag, and fails if
   `plugins/<slug>/<slug>.php` does not exist.
2. Refuses the tag if the plugin header's version disagrees with it. This is
   the check that stops a release whose sites would never see it, since the
   updater compares the header against the tag.
3. Refuses it if the plugin's `..._VERSION` constant (when it has one)
   disagrees too.
4. Lints every PHP file in that plugin.
5. Runs every `tests/test-*.php`.
6. Builds a ZIP whose **top-level folder is the plugin slug**, because
   WordPress installs whatever folder the archive contains.
7. Publishes the release with generated notes and the ZIP attached. The notes
   start at that plugin's previous tag, so they do not list the other
   plugins' commits.

It authenticates with the automatic `github.token` and needs
`permissions: contents: write`. There are no repository secrets to configure,
and nothing breaks if the repository is transferred or cloned.

### If a release goes out wrong

**Never reuse a version number.** Sites cache the release lookup for six hours
and WordPress caches its own update list for twelve, so a replaced ZIP under
an existing tag reaches some sites and not others, and you cannot tell which.
Ship a higher version instead, even for a one-character fix.

Deleting the release and tag on GitHub is fine and sometimes tidy, but it is
not a rollback: sites that already installed it stay installed. Rolling a site
back is a manual upload of the previous ZIP, covered under
[When a release misbehaves](#when-a-release-misbehaves).

## Adding another plugin to this repository

The tag convention exists for this, but nothing else is automatic.

1. Create `plugins/<slug>/<slug>.php`. The folder name, the main file name and
   the tag prefix must all be the same slug.
2. Copy `shared/class-dos-github-updater.php` into the plugin's `includes/`,
   require it, and boot an instance with the plugin's slug, basename, version,
   name and description (see the comment at the top of the class). Boot it on
   load, not behind `is_admin()`. The slug is the whole of what keeps one
   plugin's releases from being offered to another.
   `tests/test-shared-copies.php` fails if any copy differs from `shared/`, so
   change the shared file first and copy it out to every plugin.
3. Set `Plugin URI` and `Update URI` in its header to this repository.
4. Add a `CHANGELOG.md` in the plugin's folder, headed "Versions are the
   plugin's, tagged `<slug>-v<version>`".
5. Add tests as `tests/test-<something>.php`. The workflow globs
   `tests/test-*.php`, so a file named anything else is never run and its
   absence is silent.
6. Tag `<slug>-v0.1.0`. The workflow resolves everything else from the tag.

## Working in this repository

- Work happens on `main`. There is no release branch; the tag is the release.
- Commit messages explain the reasoning, not the diff. The diff is already in
  the commit; what cannot be recovered later is why a thing was done that way.
- Tests live at the repository root, not inside the plugin, so they are never
  shipped to a site.
- `legacy/` is reference only. Nothing there is built, released or installed.

## Legacy

`legacy/` holds the six plugins this toolkit replaced, and
`dos-ohi-city-search`, which is the Open Houses In version of City Search kept
exactly as shipped. The six have been absorbed; the source is kept as reference for the ports and for anything that
turns out to have been missed. None of it is built, released or installed.

A site still running one of them has it deactivated automatically once the
module that replaces it is enabled, and the Modules screen lists what it
found. Nothing is deleted, so a plugin can be reactivated if something turns
out to be missing.

| Legacy plugin | Ports into |
|---|---|
| `saab-toolkit/includes/class-saab-seo.php` | `seo` module |
| `saab-toolkit/includes/class-saab-images.php` | `images` module |
| `media-usage-manager` | `images` module |
| `breanm-clear-image-titles` | `images` module |
| `breanm-plugin-downloader` | `utilities` module |
| `last-updated-column` | `utilities` module |
| `page-tags-tools` | `utilities` module |
| `dos-ohi-city-search` | `dos-city-search` 2.0.0 (settings migrate and the old plugin is deactivated on the first admin page load; kept here as shipped, delete it from the site after checking the homepage) |
