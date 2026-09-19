# DoS Plugins

[Department of Search](https://departmentofsearch.com) WordPress plugins. One repository, one release workflow,
one update path for every site we run.

```
plugins/
  dos-toolkit/      The standard toolkit: SEO, AI Search, Images, Utilities
legacy/             The one-off plugins being folded into dos-toolkit
tests/              Stubbed-WordPress tests, run on every release
.github/workflows/  Tag -> lint -> test -> build ZIP -> publish release
```

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
Plugins screen. The repository is public, so no access token is needed.

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
  description and one JSON-LD block. If Yoast, Rank Math or SEOPress is
  active, the module stands down and says so on its own screen — that is
  expected, not a failure.
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

Third-party plugins are never deactivated. If a site runs Yoast, Rank Math or
SEOPress, the SEO module stands down instead and reports that it has, on the
Modules screen and on its own.

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
within fifteen minutes. A check that keeps failing with a transport error
usually means the host blocks outbound requests to `api.github.com`, which is
a hosting setting rather than a plugin problem.

## Cutting a release

1. Bump `Version:` in the plugin header **and** the matching `DOS_TOOLKIT_VERSION`
   constant.
2. Commit.
3. Tag and push:

   ```bash
   git tag dos-toolkit-v0.1.0
   git push origin dos-toolkit-v0.1.0
   ```

The workflow lints the PHP, refuses the tag if the header version disagrees
with it, builds a ZIP whose top-level folder is the plugin slug, and publishes
the release with generated notes.

Tags are `<slug>-v<version>`. The prefix is what lets several plugins live in
one repository without their updaters confusing each other — each plugin only
considers releases carrying its own prefix and its own named asset.

## Legacy

`legacy/` holds the plugins this toolkit replaces, kept as reference while
their features are ported. They are not built or released, and should not be
installed alongside `dos-toolkit` once the matching module exists.

| Legacy plugin | Ports into |
|---|---|
| `saab-toolkit/includes/class-saab-seo.php` | `seo` module |
| `saab-toolkit/includes/class-saab-images.php` | `images` module |
| `media-usage-manager` | `images` module |
| `breanm-clear-image-titles` | `images` module |
| `breanm-plugin-downloader` | `utilities` module |
| `page-tags-tools` | `utilities` module |
