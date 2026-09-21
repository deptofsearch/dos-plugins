# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-toolkit-v<version>`.

## 0.19.1

"View version details" still said "Plugin not found." on a site running the
0.17.1 fix, so the fix was not the whole story.

0.17.1 made this plugin always answer `plugins_api` for its own slug. That is
necessary and it is not sufficient: `plugins_api` is a filter, every plugin on
the site can hook it, and anything running after this plugin can overwrite the
answer. An updater that answers the hook without checking which plugin it was
asked about does exactly that, and WordPress then asks wordpress.org, which
has never heard of a self-hosted plugin.

So the handler is registered twice now, at the normal priority and again last.
It only ever acts on its own slug, so answering twice changes nothing except
that the last word belongs to the plugin the question was about.

It also accepts the plugin file where the slug is expected, since not every
caller passes what the documentation says it passes.

The larger problem was that none of this could be seen. The details screen is
an iframe with room for one line, and that line cannot name whoever took the
question. The Updates panel now asks the same question the modal asks and
reports who answered, lists every handler on the hook in the order it runs —
closures included, located by file and line — and links to the details screen
outside the modal, where the page shows the real error rather than a summary
of it.

A sixth instance of the defect being correct behaviour that cannot be told
apart from a fault, and the second on this same screen. The first fix made
this plugin answer correctly. It could not make anything reveal that a
correct answer was being discarded.

## 0.19.0

The library job rescales as well as re-encodes.

Quality was always the smaller lever, and 0.18.0 only pulled the smaller
lever. The weight audit has been saying so since 0.15.0: an image larger than
anything that displays it costs more than the difference between quality 82
and 75, and no quality setting fixes it. The job that acts on the audit now
acts on its first finding rather than its third.

Both happen in one pass. A resize is itself a re-encode, so rescaling and then
compressing as separate jobs would put every photograph through two lossy
generations to reach one result. Everything happens between a single decode
and a single encode.

The limit is the one already on the screen — the same number that scales new
uploads — so there is one setting rather than two that can disagree. Nothing
is ever enlarged, nothing is cropped, and a result that is no smaller is
discarded rather than kept for the sake of having done something.

Shrinking the main file makes a generated size bigger than the image it came
from. The 2048x2048 size WordPress registers by default is wider than a 1920
limit, and WordPress will offer it in a srcset quite happily: more bytes, no
more detail, which is the opposite of the reason for rescaling. Those sizes
are removed — the metadata entry and the file together, because removing the
entry alone orphans the file forever. Attachment deletion only knows about the
sizes the metadata lists.

The generated sizes that still fit are left alone. They were produced from the
original at dimensions that are still correct, and regenerating them from a
smaller source would make them worse.

One image, one lossy generation, still. The record on each attachment now says
whether it was rescaled as well as re-encoded, and an image compressed by
0.18.0 while still oversized is allowed exactly one more pass to fix the
dimensions — the larger saving, and the one that could not be had any other
way. Anything already rescaled is never touched again.

Two things this deliberately does not do. It does not touch the unscaled
original WordPress keeps beside a `-scaled` copy: that file is never served,
so shrinking it would save disk and not load time, and it is the only copy of
the full-resolution image on the site. And re-encoding through GD drops EXIF,
which includes any embedded copyright or photographer credit — that is how
every WordPress resize has always behaved, but it is worth knowing before
running this over a library of commissioned photography.

## 0.18.0

Compression reported what it could save and never what it had saved.

The Images screen had a weight audit, which is a forecast, and a quality
sampler, which is an experiment. Neither of them answered the only question
worth asking afterwards: what did this actually do to my library? The one
record of a compression was a line in the Activity Log, which is an audit
trail rather than a report — no running total, nothing per image, and nothing
that survived being scrolled past.

There is now a "What compression has saved" section: images compressed, bytes
saved, the percentage that represents, and the twenty-five most recent with
before, after, saved, quality and where each came from. It counts work done,
not work available, and it says so, because the two numbers sitting on one
screen would otherwise be read as the same number.

Building the report exposed the larger gap behind it. Compression only ever
ran on new uploads, so on an existing site the report would have stayed near
enough empty forever while the weight audit went on listing problems nothing
could act on. A "Compress the existing library" job now walks what is already
there.

Three decisions in that job are worth keeping:

It re-encodes the main file only. The registered sizes were generated through
the quality filter already; putting them through a second lossy pass would
cost quality for almost no bytes. The original — or the `-scaled` copy
WordPress serves in its place — is the file the audit is complaining about.

Each attachment keeps a record of having been compressed, and that record is
the guard, not the receipt. A JPEG re-encode is lossy every time. Without it,
running the job twice would quietly degrade every photograph on the site while
reporting almost no further saving, and nothing on screen would have looked
wrong.

Its dry run does the full encode and throws the result away rather than
estimating. It costs the same work as the real run, which is the only way the
figure it reports can be trusted — and this job overwrites originals, so the
number in front of the confirmation has to be the real one.

The job queries every image and skips the already-compressed ones in PHP
rather than excluding them in SQL. The runner advances by a fixed stride, so a
result set that shrinks as the job progresses steps straight over images it
never looked at. That is the 0.7.3 failure, and it is a property of the runner
rather than of any one job.

## 0.17.1

"View version details" showed "Plugin not found."

The `plugins_api` handler answered only when a release lookup had succeeded,
and returned false otherwise. False means "nothing to say about this", so
WordPress passed the question on to wordpress.org, which has never heard of a
self-hosted plugin and said so. The message was accurate; the question should
never have reached it. From the outside it reads as a broken plugin.

It answers for its own slug every time now. Where the release cannot be read
it falls back to the installed version and says why the notes are missing,
instead of rendering blank. The screen opens on a description, which it
previously did not supply at all, and links to the release on GitHub.

It also accepts arguments as an array as well as an object, since not every
caller of `plugins_api` casts first.

The fifth time on this project the defect has been correct behaviour that
could not be told apart from a fault.

## 0.17.0

Bulk page tagging was built for four pages and had to survive four hundred.

The screen listed up to three hundred pages in one flat table behind a plain
title filter. Titles can now be matched by regular expression as well as plain
text, which is what makes tagging a whole section practical: `^Services` for
everything under one heading, `(roof|gutter)` for either word. An invalid
pattern is reported as invalid, rather than matching nothing and leaving the
operator to work out which of their assumptions was wrong.

Matching happens in PHP against titles fetched in a single query rather than
through MySQL's `REGEXP`. Its syntax is not PCRE, so a pattern that works in
one would quietly behave differently in the other, and a bad pattern would
arrive as a database error instead of a message.

The list scrolls with its header fixed, shows a hundred at a time and filters
by status. Select all and select none act on what is shown. Where the matches
run past one page there is an "Apply to every match" button, which re-runs the
search on the server rather than trusting a list of several thousand IDs
posted from a browser.

## 0.16.1

Layout only. The editor arrows were floated right, which left them above the
text baseline with uneven spacing between the three rows.

The position and the arrows now share one flex row, aligned to each other,
with the arrows matched in width so the pair reads as a pair. The
save-and-next button spans the Publish box rather than ending wherever its
label happened to, which is what made the block look ragged.

## 0.16.0

Adds next and previous navigation to the editor.

Working through a run of pages meant the same four clicks between each one:
update, back to the list, find your place, open the next. This removes three.

The arrows follow the list you arrived from, with its filters and sorting,
which is the whole point. Recomputing "the next page" from scratch gives the
next by date, and that is rarely the next in the set somebody is working
through. The list is captured on the list screen itself, where filters, search
and sort order have already been resolved into a query — reconstructing that
later from a URL would mean reimplementing whatever core and every other
plugin did to it.

The Publish box gains the position in the list, arrows either side, a link
back, and an "Update and open the next" button that saves and moves on in one
click. The arrows stop at the ends rather than wrapping, and save-and-next
never lands on a post the user cannot edit. Five hundred posts are remembered
from one list, which is more than anyone works through by hand in a sitting.

Classic editor only. The block editor's sidebar is built in JavaScript and
takes additions through its own plugin API, so the setting names which editor
it applies to rather than appearing to do nothing.

## 0.15.2

Restores the media library usage column, and audits the rest of the ports.

Each image now shows whether anything uses it, with links to the pages that
do — in the list, and in the attachment details panel, where somebody is
usually deciding whether a file can safely be changed or deleted.

Three states rather than the original's two. An image nobody has scanned yet
reads "Not scanned" rather than "Unused". Showing them alike would put
unexamined images on a deletion list, which is the one mistake this module
must not encourage.

Since two omissions had now been found one screenshot at a time, this also
compares every hook the six absorbed plugins registered against what the
toolkit registers. Everything else is accounted for.

Two remain unimplemented on purpose, written down here rather than left to be
discovered again. Page Tags Tools injected a tag chooser into core's
bulk-action markup with JavaScript, which breaks whenever that markup changes;
the Utilities screen does the same job. Media Usage Manager drew a usage badge
in the media grid through `wp_prepare_attachment_for_js` and bespoke
JavaScript; the attachment details panel gives the same answer without
depending on core's markup staying still.

## 0.15.1

Restores the featured-image column the port dropped.

Media Usage Manager showed whether a post or page had a featured image and let
you filter for the ones missing it. Its description said so. The port took the
usage scanning and the deletion and left that behind.

Posts and pages show Yes or No beside the title now, with a thumbnail where
there is one, and a filter for finding everything missing one. A post without
a featured image is invisible in a list of fifty until you open each one, and
nothing breaks to draw attention to it — the archive still renders, with a
hole where the thumbnail should be.

Two things done differently from the original. The column covers any public
type that supports thumbnails rather than only posts and pages, and "missing"
matches both ways a thumbnail can be absent: no meta row at all, or a row left
holding an empty value when an image was removed. The second case is the one
that looks like a working filter and quietly omits results.

Also removes a duplicate Tags column on the Pages list. Attaching the post tag
taxonomy to pages makes WordPress add a Tags column of its own, and the
Utilities module was adding a second with the same heading. The filter stays,
since core provides none for a non-hierarchical taxonomy.

## 0.15.0

Adds image compression, aimed at page weight rather than at a number.

Quality is the smaller lever and the module says so. A file larger than
anything that displays it costs far more than the difference between quality
82 and 75, so the weight audit ranks oversized images above over-quality ones,
separates photographs saved as PNG where the format is the whole problem, and
lists the heaviest offenders rather than whatever it happened to scan first.

Rather than a quality dial set on folklore, a finder encodes one of the site's
own photographs at six qualities and reports what each saves and how far it
drifts from the original. The difference figure is a stated approximation —
both versions reduced to a thumbnail and compared channel by channel — which
is enough to separate "no visible change" from "visible on a gradient", and
that is the decision being made.

Compression of new uploads is opt-in and touches nothing already in the
library. A re-encode that would make a file larger is discarded, the result is
written to a temporary file and moved into place, and an image too large to
open safely is skipped and logged.

That guard is not optional on this host. Without ImageMagick, GD holds a whole
decoded image in memory — roughly four bytes a pixel, with a source and a
destination live at once during a re-encode, so a 24 megapixel photograph
wants over 200MB. Exceeding the limit produces a truncated file rather than an
error, and a truncated image is a corrupt one that has replaced something
unrecoverable.

Nothing rewrites a file already in the library. A lossy re-encode is the only
irreversible thing this toolkit could do, and that stays a deliberate decision
rather than a default. (0.18.0 adds the job that does it, behind the
destructive guard.)

## 0.14.0

Adds a page-level view of internal linking.

The phrases table answers how a phrase is being used. It cannot answer the
other half, which is the half somebody actually asks about: which pages link
out, which pages are linked to, and which do neither. A page nothing points at
is invisible to the site's own structure however well its phrases are
configured, and nothing on the screen could show that.

The scan builds the index as it walks, so there is no second pass over the
content. Each page shows links out, links in, and how they were placed, with
views for linking out, linking to nothing, nothing linking to them, and
neither. A page at the per-page limit is marked, as is one with nothing
pointing at it.

Counts cover every internal link, not only the ones this module placed. A link
written by hand carries the same weight, and a report that ignored them would
describe this module's work rather than the site.

A link counts as internal when it resolves to a post here. Off-site links,
in-page anchors, mailto and tel, and URLs matching no page are ignored, so the
figures are about internal linking rather than links in general. Resolutions
are cached per request, since the same navigation link appears on every page.

## 0.13.0

The per-page limit counted only links this module had placed.

Set to one link per page it would add its link to a page already carrying a
hand-written link on the same phrase, consider itself compliant, and leave the
page with two. Seen on the canary: a page linking "open houses in Phoenix"
twice, one by hand and one from the module, with the rule set to one per page.

What matters for a linking profile is how many links a page carries on a
phrase, not who put them there. Links already present — written by hand, or
placed by this module on an earlier run — count against the allowance now, and
a page at its limit is left alone. A limit of two allows one more alongside an
existing link, which is what raising it should mean.

A page skipped for this reason says so, instead of reporting a zero
indistinguishable from a phrase that was never there.

## 0.12.1

A dry run reported "8 scanned, 8 changed" — the same sentence a real run
reports, distinguished only by "(dry run)" at the end. Nothing had been
changed.

It surfaced on the canary as eight links surviving a removal, which turned out
never to have been run live at all. The word "changed" was the problem. A dry
run now says how many were examined, how many would change, and that nothing
has been changed yet. When it finds work to do, a destructive job offers a
second button beside the first that runs it for real, so the obvious next
action sits where the result is rather than back at a checkbox above it.

A refused live run is visible now too. The guard has always returned a plain
explanation of what to do about it, and it was being rendered as small grey
text in the same spot a successful run reports its total.

The fourth time on this project the defect has been correct behaviour that
could not be told apart from a fault. The engine strips the site's markup
correctly; nothing had asked it to.

## 0.12.0

Counts the links that were already there.

Deleting a phrase left its links behind in the content. They still pointed
somewhere real, but belonged to no rule, so nothing counted them and the
reported linking profile quietly stopped describing the site. The scan finds
them now, the dashboard reports how many exist, and a job removes them —
leaving every live phrase and every hand-written link alone.

Links a person wrote themselves are counted as well. The matcher has always
skipped a phrase already inside a link, which is right, but those links are
part of the profile: a phrase linked twenty times by hand is carrying twenty
links whoever typed them. Each phrase shows what this module placed alongside
what was there already, and every share is worked out on the total rather than
on this module's own work.

That feeds the per-page balancing too. When a page has more candidates than
its limit allows, the allowance goes to the least-used phrases — and a phrase
heavily linked by hand was reading as under-used, which would have given it
still more.

## 0.11.1

A phrase plainly present in a published post matched nothing.

A phrase typed into the settings screen is separated by ordinary spaces. The
same words in a post frequently are not: editors and pasted text carry
non-breaking spaces, and HTML source wraps lines wherever it likes. The two
are indistinguishable on screen.

A space in a phrase now matches any run of whitespace, including U+00A0 and
its relatives and an ordinary newline. The phrase itself is normalised the
same way on save and again on every match, so rules saved before this are
covered without being retyped.

Normalising the content instead would have been simpler and wrong: it moves
every byte offset after the first substitution, and those offsets are where
links are spliced in. Tests cover that the splice still lands correctly either
side of a non-breaking space, and that tolerance has not turned into matching
things that are not the phrase.

Also fixes a reason recorded from an irrelevant page masking the real one:
scanning the destination early in a run recorded "points at itself", and a
different reason found on a later page never replaced it. Reasons are ranked
now, and one naming a setting the operator can change wins.

Check one page moved up beside the phrases table rather than sitting below
three other sections, and the table shows each destination's ID next to its
title, since a rule pointing at the page being tested is the commonest cause
of nothing happening and was invisible before.

## 0.11.0

Caps links per page, and spends the allowance on the quietest phrases.

A page may carry at most ten links from this module, counting every phrase
together and counting links placed on an earlier run — without that second
part, each pass would add a fresh set. The number is a setting, and zero
removes the limit.

What matters is what happens when the limit binds. Keeping candidates in
document order would give the allowance to whichever phrases happen to appear
near the top of the page, and a phrase already carrying most of the site's
links would go on taking more of them: the limit would entrench an unbalanced
profile rather than correct it.

So the phrases competing for a page are ordered by how many links they have
placed across the site, fewest first, and given one slot each in turn. Every
phrase gets its first link on a page before any phrase gets a second. Over
repeated runs that pulls a lopsided profile back towards the middle, which is
the point of having the limit at all. Ties break on rule id, so the same page
is decided the same way every time.

The dry run says when a page hit the limit, because which links were kept was
a decision and not an accident.

## 0.10.0

Rebuilds the Internal Links screen for a real keyword list.

The screen was built for a handful of phrases and would not survive a hundred:
one unpaginated table, no search, no way to act on more than one row, and a
form at the top that everything else scrolled past.

A summary strip leads now: phrases, links in place, places still available,
which phrase carries the largest share of the profile, and how many phrases
are doing nothing. The last links straight to those rows, because a phrase
placing no links is the one worth reading.

The table gained search, filtering by state, sortable columns, pagination and
bulk switch-on, switch-off and delete. Adding phrases moved into a panel that
collapses once there are phrases to look at, and gained an import: one phrase
per line, destination written as an ID, a URL, a path or the exact title, with
the optional limits after it. Refused lines are listed with the reason rather
than dropped.

Also fixes a fault introduced in 0.9.3, before it could matter. Per-phrase
scan and apply jobs were registered for every rule on every admin request —
three phrases is six closures, two hundred phrases is four hundred, built and
thrown away on every page load. Only the phrase being worked on is registered
now, resolved from the screen or, for the runner's own callback where no page
state exists, from the job name.

And the import panel's example did not work: a destination written as
`/agents/` resolved only when written as a full URL, which is not how anyone
writes one by hand.

## 0.9.3

Gives each phrase its own rescan and apply.

A phrase set up early in a site's life goes stale as content is added: the
places it could now link did not exist when it was last scanned. Answering
that by rescanning every phrase across every post is more than the question
deserves, and on a large site slow enough that nobody asks it.

Each row in the phrases table carries a Rescan link, which opens a scan and an
apply scoped to that phrase alone. Both run on the shared batch runner, so
neither can time out on a long library, and the apply keeps the dry run and
typed confirmation every destructive job has. Rescanning one phrase resets
only that phrase's figures.

Separately, adds tests for the behaviour asked about rather than described:
one page can carry links to several different destinations at once, each
phrase keeps its own per-page allowance rather than drawing on a shared
budget, and a page that is itself the destination of one phrase still links
out on the others while never linking to itself.

## 0.9.2

Adds "Check one page" to the Internal Links screen.

Working out why a phrase produced no links took two rounds of guesswork from
the outside, and the second round did not settle it either. The plugin has the
answer and was not being asked.

It runs every configured phrase against a single post, named by ID or URL, and
reports four numbers side by side: how many times the phrase occurs in the
text at all, how many of those are in linkable positions, how many this rule
would actually link, and which rule or limit accounted for the difference. It
also shows the post's type, status and content length, because a draft or an
empty revision explains a zero on its own.

Nothing is changed by running it.

## 0.9.1

The Internal Links dashboard reported nothing to link without saying why.

Found on the canary: a rule matched a phrase that plainly existed in a
published post, and every count read zero. Several different causes produce
that same zero and none of them were distinguishable from outside — the
destination being the only page containing the phrase, a rule set to leave the
first occurrence alone on a page that has only one, a percentage limit that
excluded every candidate, or the phrase existing only inside a heading or list
where links are never placed.

The scan now records which of those it was and the dashboard says so in plain
words next to the count, with what to change.

## 0.9.0

Adds the Internal Links module: a keyword phrase, a page it should point at,
and limits on how often it gets used.

Links are written into post content rather than added as a page renders, so
both the applying and the removing are destructive jobs and go through the
guard — a dry run naming every page it would change, then a typed
confirmation.

What it will not link, because a link there either competes with the author's
own emphasis or breaks something: headings, bold, lists, tables, existing
links, code, preformatted text, shortcodes and attributes. A page never links
to itself. A phrase must be at least two words, since one word matches too
much in too many senses to say anything about where it points.

Three limits per phrase: how many links from a single page, whether the first
occurrence is linked or left alone, and a percentage cap on how many of the
available places are used at all. The last is the one that keeps a single
phrase from carrying the whole internal-linking profile, and the dashboard
reports each phrase's share so it is visible when one is.

Two decisions worth knowing. The engine edits the markup as a string rather
than through DOMDocument, which would rewrite entities and close tags on the
way back out — tolerable when rendering, not when saving over someone's post.
And it writes `post_content` directly rather than through `wp_update_post()`,
so adding a link does not stamp every page on the site as modified today.

## 0.8.1

Absorbs the Last Updated Column plugin into Utilities: a sortable Last Updated
column in the admin list tables, and an optional "Updated:" line in front of
the published date on the front end. Both only appear when a post was
genuinely edited after publishing, since WordPress records a modified time a
second or two after publishing everything.

Two faults were fixed on the way in. The original shared one callback between
the `the_date` and `get_the_date` filters, which pass different arguments, so
`the_date` silently did nothing on any theme that passed a `$before` string.

More seriously, it filtered `get_the_date` on every singular view — and the
SEO module reads its schema and Open Graph timestamps through that same
function. Installed as it was, every post would have published
`<span>Updated: …</span> | 2026-01-15T…` inside `article:published_time` and
`datePublished`. The SEO module now reads dates through `get_post_time()`,
which display filters never see, and the feature refuses to decorate a
machine-readable format regardless.

## 0.8.0

Adds the Redirects & 404s module.

The 404 log and the redirect table are one feature: the log is where
redirects come from, and a log you have to retype into a form is busywork.
Each logged 404 has a button that opens the redirect form prefilled and
clears the log row once the rule exists.

Matching is exact paths only, compared lowercase without a trailing slash,
with any query string on the incoming URL carried across to the target. That
covers what the log actually produces — a specific dead URL — and cannot
swallow a section of the site the way a bad wildcard can.

Renaming a published page creates the redirect automatically, which heads off
the most common way a site breaks its own links. Each one is marked as such
and can be removed.

Two things the module refuses rather than repairs. A rule that would close a
loop, checked by walking the existing chain. And a target that is neither an
absolute http/https URL nor a path: `//evil.example/x` begins with a slash and
passes any "is it internal" test that looks for one, then sends the visitor to
another origin under this site's name.

The log records one row per path with a counter rather than one row per hit,
and filters the probe traffic every public site receives, so that what is left
is broken links rather than somebody fishing for wp-config.

## 0.7.6

Documentation pass. The Images module's description promised alt-text bulk
fill and oversized-file reports, neither of which was ever built — the alt
audit reports and deliberately never writes, because a wrong description is
worse than none. The plugin's own Updates section still named a repository
that does not exist and framed the access token as a private-repository
concern. The porting roadmap still described work that is finished.

No behaviour changes; the version moves so sites pick up the corrected text.

## 0.7.5

A finished run left the panel describing the previous one. The "last run"
line and the destructive-job clearance line are written when the page loads
and were never refreshed, so after a dry run that found nothing the panel
still read "cleared for a live run: found 1 items". Both now update from the
result, and a dry run that finds nothing says so rather than appearing to
authorise a deletion.

## 0.7.4

Corrects the token guidance, which was wrong in the settings screen and in
the README. Both said a token was only needed for a private repository. That
is true of access and false of rate limits: unauthenticated requests are
capped at 60 an hour per IP, and on shared hosting that IP is shared with
every other site on the server. The canary hit HTTP 403 for exactly this
reason. A token with no permissions at all raises the cap to 5,000, since it
only identifies the request.

## 0.7.3

The usage scan skipped posts, and reported the images in them as unused.

The scan runs two phases through one offset space, and the batch runner
advances that offset by a fixed stride whatever a step actually processed. The
boundary between phases sat at the raw attachment count, which is almost never
a multiple of the stride, so the offset stepped over it and the content phase
began partway in. Every post before that point was never scanned, and its
featured and inline images were left marked unused and offered for deletion.

Found on the canary: a dry run proposed deleting an image that was the
featured image of a published post and appeared on three category archives.

The first phase is now padded to a whole number of batches. A test drives the
real scan through the real runner across seven library shapes and asserts that
no post is missed.

## 0.7.2

Two faults in the media jobs, both found before anything was deleted.

The usage scan read `post_content` only. Page builders — Themify, Elementor,
Beaver Builder, WPBakery, ACF — keep their layouts in post meta, so on a
builder site almost every image would have been reported as unused. The scan
now reads post meta too, skipping its own bookkeeping keys.

The usage scan also offered a dry run, which for that job does no work at all:
it writes nothing but the usage records other jobs read. Running it as a dry
run therefore left the delete job with no data while appearing to have
scanned. Jobs like it now declare `always_live` and the runner hides the
choice.

The delete job's description now states what the scan does not cover —
widgets, menus, theme options, stylesheets — rather than leaving that to be
discovered.

## 0.7.1

Three things found by auditing the first live site, openhousesinphoenix.com.

Titles reached JSON-LD still carrying HTML entities, so structured data
consumers read `&#8211;` where an en dash was meant. Entities are correct in
markup and wrong in data; titles now decode before they are used in schema.

`llms.txt` printed a section heading with nothing under it when a section's
only entries were skipped. Entries are built before the heading is written.

The site's theme emits its own WebSite and WebPage schema, so those pages
carried two sets. Conflict detection only knows about plugins, so nothing
warned. The SEO module gained an Organization only mode for exactly this.

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
