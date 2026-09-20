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
| `seo` | SEO | Meta descriptions, Open Graph and Twitter Cards, canonicals on every view, and a schema graph that can be reduced to Organization alone where a theme emits its own. Stands down if Yoast, Rank Math or SEOPress is active. |
| `ai` | AI Search | Per-bot crawler policy separating training crawlers from the ones that cite you, opt-in FAQ schema per page, optional `llms.txt`. |
| `images` | Images & Media | Responsive `srcset`/`sizes` on bare theme images, an alt-text audit that reports and never invents, clearing of filename-derived titles, media usage scanning and deletion of unreferenced images. |
| `links` | Internal Links | Keyword phrases linked to chosen pages, written into the content behind a dry run. Capped at ten links per page by default, with the allowance given to the least-used phrases first. Never links inside headings, bold, lists, tables, existing links, code or shortcodes, never links a page to itself, and throttles a phrase by percentage so one does not carry the whole profile. |
| `redirects` | Redirects & 404s | Logs requests that hit nothing and redirects the ones worth keeping, with one-click creation from a logged 404. Renaming a published page redirects its old URL automatically. Exact paths only. |
| `utilities` | Utilities | Plugin ZIP download, tag support for Pages, a sortable Last Updated column and front-end updated dates, permalink flush, settings export/import. |

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

**Settings → Update repository** defaults to `deptofsearch/dos-plugins`. Tag a
release there whose version is higher than the plugin header and the update
appears on the site's Plugins screen.

Set an access token even though the repository is public. Reading it needs no
token, but GitHub caps unauthenticated requests at 60 an hour per IP address,
and on shared hosting that address belongs to every site on the server — so
checks fail with HTTP 403 for reasons unrelated to this site. An authenticated
request gets 5,000. A token with **no permissions at all** is enough; it only
identifies the request. Put it in `wp-config.php` rather than the settings
field, so it stays out of database backups:

```php
define( 'DOS_TOOLKIT_GITHUB_TOKEN', 'github_pat_…' );
```

The updater never breaks the Plugins screen. A failed check is cached for
fifteen minutes with the reason recorded, and **Settings → Updates** shows the
installed version, the latest release and that reason, with a button to clear
the cache and ask again. Use that button rather than WordPress's own
force-check, which clears WordPress's cache but not this plugin's.

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

**Titles come out of WordPress as HTML, not as data.** `wp_get_document_title()`
and `get_the_title()` return text containing HTML entities, which is correct
for markup and wrong for JSON-LD — a consumer of structured data reads the
literal characters, so an en dash arrives as `&#8211;`. Titles used in schema
go through `plain()`; descriptions go through `clean()`, which also truncates.

**A theme can emit its own schema.** Conflict detection only knows about
plugins, so a theme outputting its own WebSite and WebPage nodes produces two
sets on a page and nothing warns about it. The SEO module has an Organization
only mode for this. Check any new site by searching its source for
`application/ld+json` — more than one block is the sign.

**Page builders keep their layouts in post meta, not post content.** Themify,
Elementor, Beaver Builder, WPBakery and ACF all store what a page contains
outside `post_content`. Anything that asks "is this image used" by reading
`post_content` alone concludes that almost every image on a builder site is
unused. The usage scan reads post meta as well, and skips its own bookkeeping
keys so a second pass does not mark everything used.

It still does not read widgets, menus, theme options or stylesheets. An image
used only in one of those reads as unused, which is why the delete job says so
in its own description and why its dry run exists.

**The runner advances by a fixed stride, not by what a step processed.** A job
that divides one offset space into phases must align each boundary to a whole
number of batches, or the offset steps straight over it: the next phase starts
partway in and everything before that point is never visited. The usage scan
pads its first phase for exactly this reason, and the arithmetic uses the
constant the job is registered with rather than whatever size is passed in.

**A redirect target that starts with a slash is not necessarily internal.**
`//evil.example/x` is protocol-relative: it begins with a slash, so any check
of the form "starts with `/`" accepts it, and the browser then loads a
different origin. A redirect table that accepts one hands out an open redirect
under the site's own name. Targets are either absolute `http`/`https` or a
path, and everything else — protocol-relative, `javascript:`, `data:`,
`mailto:` — is refused rather than repaired into something that looks safe.

**`get_the_date()` is a display function and can be filtered; `get_post_time()`
cannot.** Anything machine-readable — a schema date, an Open Graph timestamp,
a feed — must use the latter. The Last Updated feature prepends "Updated:" to
dates through the `get_the_date` filter, and before the SEO module was moved
off that function it would have published `<span>Updated: …</span> | 2026-…`
inside `article:published_time` on every post. The feature also refuses to
touch a format that looks machine-readable, so both ends are covered.

**Content that will be saved is edited as a string, not through DOMDocument.**
A DOM parse and re-serialise rewrites entities, closes tags the author left
open and reorders attributes. That is invisible when rendering a page and
unacceptable when the result is written back over somebody's post. The
internal-links engine walks the markup once to find which byte ranges are
ordinary text, then splices into those ranges from the end backwards so
earlier offsets stay valid.

**An automated content pass must not touch `post_modified`.** `wp_update_post()`
stamps a post as modified. The SEO module publishes `dateModified` and the
Utilities module can show a Last Updated column, so a pass that adds links
across a site would announce that every page had just been revised. Adding a
link is not a revision, so the links module writes `post_content` directly and
cleans the cache.

**Redirects run before WordPress guesses.** Core will redirect a near-miss URL
to whatever it thinks was meant. The router hooks `template_redirect` at
priority 1 so a configured rule wins, and never touches admin, login, cron,
REST, or anything that is not a GET or HEAD — a redirected POST loses its body
and the sender never learns why.

**A job that changes nothing has no useful dry run.** The usage scan only
records state for other jobs to read, so offering it a dry run meant offering
an option that did no work while looking like it had — and left the delete job
with no data. Such jobs set `always_live` and the runner hides the choice.

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

## What replaced what

All five one-off plugins have been absorbed. `legacy/` keeps their source as
reference; none of it is built or shipped.

| Absorbed plugin | Now lives in |
|---|---|
| `saab-toolkit` `class-saab-seo.php` | `modules/seo/` |
| `saab-toolkit` `class-saab-images.php` | `modules/images/` (responsive markup) |
| `media-usage-manager` | `modules/images/` (usage scan and deletion) |
| `breanm-clear-image-titles` | `modules/images/` (batch job) |
| `breanm-plugin-downloader` | `modules/utilities/` |
| `last-updated-column` | `modules/utilities/` (admin column and front-end date) |
| `page-tags-tools` | `modules/utilities/` |

`ai` was written from scratch and has no legacy counterpart.

A site still running one of these has it deactivated automatically once the
module that replaces it is switched on — see `DOS_Conflicts`. Nothing is
deleted, and third-party plugins are never touched.

Per-post SEO overrides written by SAAB Toolkit are still read: the SEO module
falls back to the `_saab_seo_*` meta keys, so the site it came from keeps its
hand-written descriptions.
