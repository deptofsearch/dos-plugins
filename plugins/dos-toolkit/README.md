# DoS Toolkit

The standard Department of Search plugin. One upload per site, one update path,
one menu. Everything the toolkit does lives in a module, and every module ships
disabled — activating the plugin on a live site changes nothing until you tick a
box.

## Menu

Installing adds a top-level **DoS Tools** menu:

- **Modules** — the on/off switches and what each module does.
- *(one entry per active module)*
- **Activity Log** — every change any module made, including dry runs.
- **Settings** — dry-run default, log retention, update repository.

## Modules

| Key | Name | Scope |
|---|---|---|
| `seo` | SEO | Meta descriptions, Open Graph and Twitter Cards, canonicals, Organization/WebSite/WebPage schema. Stands down if Yoast, Rank Math or SEOPress is active. |
| `ai` | AI Search | Per-bot crawler policy separating training crawlers from the ones that cite you, opt-in FAQ schema per page, optional `llms.txt`. |
| `images` | Images & Media | Responsive `srcset`/`sizes` on bare theme images, alt-text audit and bulk fill, oversized-file reports, media usage scan and cleanup. |
| `utilities` | Utilities | Plugin ZIP download, tag support for Pages, permalink and cache flush, settings export/import. |

A module is *available* when its file exists under `modules/`, and *active* when
it is both available and enabled. The shell can therefore be deployed before
every module is written: missing ones show as **Not installed** and cannot be
switched on.

## Writing a module

Create `modules/<key>/class-dos-<key>.php` with a class that extends
`DOS_Module`:

```php
final class DOS_Module_Images extends DOS_Module {

    const KEY = 'images';

    public static function init() {
        add_filter( 'the_content', array( __CLASS__, 'rewrite' ) );
    }

    public static function pages() {
        return array(
            array(
                'slug'     => 'dos-images',
                'title'    => 'Images & Media',
                'callback' => array( __CLASS__, 'render_page' ),
            ),
        );
    }

    public static function jobs() {
        return array(
            'images_alt_audit' => array(
                'label'       => 'Audit alt text',
                'description' => 'Reports attachments with an empty alt attribute.',
                'batch_size'  => 100,
                'count'       => array( __CLASS__, 'count_attachments' ),
                'step'        => array( __CLASS__, 'step_alt_audit' ),
            ),
        );
    }
}
```

Register the class and file in `DOS_Toolkit::$modules`.

### Batch jobs

Do not hand-roll offset loops. Register a job and call
`DOS_Batch::render_runner( 'job_key' )` from the module page. The runner gives
you a progress bar, resumable state, a dry-run toggle, and an audit-log entry at
start and finish for free.

A `step` callback receives `( int $offset, int $size, bool $dry_run )` and
returns:

```php
return array(
    'processed' => 100,               // items examined this pass; 0 ends the run
    'changed'   => 12,                // items actually modified
    'notes'     => array( 'Post 45: alt text added.' ),
);
```

Honour `$dry_run`. A job that writes to the database when `$dry_run` is true is
a bug.

Set `'destructive' => true` on any job that deletes data. The runner then makes
the operator type `RUN` before a live pass.

## Updates

Set **Settings → Update repository** to `owner/dos-toolkit`. Tag a GitHub
release whose version is higher than the plugin header, and the update appears
on every site's Plugins screen.

For a private repository, either paste a token into Settings or — better — put
it in `wp-config.php`, which keeps it out of the database and out of backups:

```php
define( 'DOS_TOOLKIT_GITHUB_TOKEN', 'ghp_…' );
```

The updater fails quietly. If GitHub is unreachable the Plugins screen behaves
exactly as it would without this plugin.

## WordPress integration notes

Constraints learned from running this on a real site. Each of these looks
like a detail and is actually load-bearing; changing one silently breaks
something that will not show up in the tests.

**The updater must write to both update lists.** WordPress decides a plugin
supports updates by finding it in the `update_plugins` transient's `response`
list *or* its `no_update` list. Write only to `response` and the plugin
vanishes from both lists whenever the site is current — at which point
WordPress hides the auto-update toggle and reports that auto-updates are
unavailable.

**Failed update checks must cache separately from successful ones.** GitHub
allows 60 unauthenticated API requests an hour per IP, which shared hosting
reaches. If a failure is cached for as long as a success, one rate-limited
request means the site believes there are no updates for hours.

**`Update URI` is deliberate.** It stops WordPress asking wordpress.org about
this plugin, which matters because a public plugin sharing the slug
`dos-toolkit` would otherwise be offered as an update and installed over this
one. The cost is that nothing else populates the update lists on our behalf,
which is why the point above matters.

**The ZIP's top-level folder must be the plugin slug.** WordPress installs
whatever folder the archive contains. GitHub's own source archives unpack to
`owner-repo-sha`, which would install as a second, differently-named plugin;
`upgrader_source_selection` renames it back.

**robots.txt filters only apply when WordPress generates the file.** A real
`robots.txt` in the web root is served by the web server and never reaches
PHP, so the AI module checks for one and says the rules are not live rather
than showing settings that do nothing.

**The batch runner's deletion job pages from the front.** Deleting removes
rows from the set being paged through, so an advancing offset skips records. A
dry run changes nothing and therefore advances normally.

## Conventions

- Prefix everything `dos_` / `DOS_`. No legacy `breanm_`, `ptt_`, `saab_`.
- All settings live in the single `dos_toolkit_settings` option row.
- Any write to site data calls `DOS_Log::add()`.
- Capability checks go through `DOS_Settings::capability()`, filterable via
  `dos_toolkit_capability`.
- The plugin never rewrites an image file. Compression and format conversion
  stay with the host or a dedicated optimizer.

## Porting roadmap

The existing one-off plugins fold in as follows:

| Existing plugin | Destination |
|---|---|
| `saab-toolkit` `class-saab-seo.php` | `modules/seo/` |
| `saab-toolkit` `class-saab-images.php` | `modules/images/` (responsive markup) |
| `media-usage-manager` | `modules/images/` (usage scan + cleanup; destructive job) |
| `breanm-clear-image-titles` | `modules/images/` (batch job) |
| `breanm-plugin-downloader` | `modules/utilities/` |
| `page-tags-tools` | `modules/utilities/` |

`ai` was written from scratch; it has no legacy counterpart.
