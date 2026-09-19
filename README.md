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
under **Plugins → Add New → Upload Plugin**.

After that the site updates itself: the plugin checks this repository's
releases and offers new versions on the normal Plugins screen. The repository
is public, so no access token is needed.

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
