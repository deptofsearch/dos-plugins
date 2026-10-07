# Plugin inventory

Status: **partial** — taken 2026-10-01 from public signals only (front-end asset
paths, `/wp-json` REST namespaces, page markup). Plugins that enqueue nothing and
register no REST routes are invisible this way, so every site still needs an
authenticated pass (`GET /wp-json/wp/v2/plugins`) before decisions are final.

## DoS source code and where it lives

| Plugin | Version | Source today | Running on |
|---|---|---|---|
| DoS Toolkit | 0.20.5 (0.21.0 on branch) | `plugins/dos-toolkit` (this repo) | not detectable publicly (no front-end assets) |
| DoS City Search | 1.1.1 | `~/Claude/realestatevaluesnearme/dos-city-search` (local only) | realestatevaluesnearme.com |
| DoS Market Images | 1.5.2 | `~/Claude/realestatevaluesnearme/dos-market-images` (local only) | realestatevaluesnearme.com |
| DoS Open Houses In Core | 0.6.0 | `deptofsearch/openhousesin-plugins` + `~/Claude/openhousesin/plugin` | Open Houses In market sites |
| DoS Open Houses In City Search | 1.0.0 | `~/Claude/openhousesin/plugin` (local only) | Open Houses In market sites |
| DoS Outdoor Seating | 0.6.0 | `plugins/dos-outdoor-seating` (this repo, moved 2026-10-07) | outdoorseatingnearme.com |
| DoS Works | 0.1.0 | `plugins/dos-works` (this repo, moved 2026-10-07) | departmentofsearch.com |
| DoS Department, DoS Magazine Child (themes) | 0.1.1, 0.1.0 | `themes/` (this repo, moved 2026-10-07) | departmentofsearch.com |
| Legacy (BREANM ×2, Media Usage Manager, Page Tags, Last Updated, SAAB Toolkit) | — | `legacy/` (this repo) | unknown until authenticated pass |

## Live sites (public signals)

| Site | Theme | Detected plugins | DoS / custom code seen |
|---|---|---|---|
| realestatevaluesnearme.com | Themify | Site Kit, TablePress Premium, WPForms, Akismet, All In One Security (`aios`) | DoS City Search, DoS Market Images, `revnm/v1` REST routes |
| bestrealestateagentsnearme.com | Themify + Builder | WPConsent cookie banner, WPForms | none visible; BREANM plugins likely present but silent |
| departmentofsearch.com | Themify (dos-magazine-child) | LiteSpeed Cache, Hostinger suite (Reach, Tools, AI Assistant, Onboarding, Amplitude), an MCP endpoint | child theme only |
| kenmoreteam.com | Astra + Beaver Builder | Yoast, Redirection, Wordfence, Formidable Pro, IDX Broker, Google Reviews widget, Schema Monkee, AI Rank Repair (WP Engine host) | none visible |
| shotandabeer.com | Themify + PTB | Contact Form 7, WPForms, FooBox/FooGallery, SiteGround Optimizer, Akismet | `saab_` taxonomies (Themify PTB) |
| outdoorseatingnearme.com | Themify + Landing | All in One SEO, TablePress Premium, UserFeedback, Akismet | none visible |
| bestmortgagebrokersnearme.com | — | not WordPress: redirects to a `l.ink` link-in-bio page | — |
| Open Houses In sites | — | not scanned yet (domain list needed) | — |

## Replacing third-party plugins

Policy: any plugin we didn't build gets replaced by a DoS plugin once a DoS
plugin covers what the site actually uses. Until then it stays, and the missing
pieces are listed here as build work.

### Ready, or close (DoS Toolkit already does most of it)

| Third-party | Site(s) | DoS replacement | Gaps to close before swapping |
|---|---|---|---|
| BREANM Clear Image Titles, BREANM Plugin Downloader, Media Usage Manager, Page Tags Tools, Last Updated Column | wherever installed (confirm) | Toolkit: Images + Utilities | None known; these were folded in already |
| SAAB Toolkit | shotandabeer | Toolkit: SEO + Images | Confirm parity on the live site |
| Redirection | kenmoreteam | Toolkit: Redirects & 404s | Importer for existing Redirection rules; Toolkit is exact-path only, so any regex rules need support or rewriting |
| Schema Monkee, AI Rank Repair | kenmoreteam | Toolkit: SEO schema + AI Search | Check what each actually outputs on the site |
| Yoast SEO | kenmoreteam | Toolkit: SEO | Per-post SEO title override (Toolkit uses the WP title); importer for Yoast titles/descriptions; XML sitemap (WP core can cover) |
| All in One SEO | outdoorseatingnearme | Toolkit: SEO | Same as Yoast, plus **AIOSEO is missing from the Toolkit's conflict check**, so enabling the SEO module beside it today would double the meta tags |

### Buildable, not built yet

| Third-party | Site(s) | Possible DoS plugin | Notes |
|---|---|---|---|
| Site Kit by Google | realestatevaluesnearme | Toolkit: Analytics tag | Site Kit is mostly a GA/GSC tag plus dashboards; a tag-only module is small |
| WPConsent cookie banner | bestrealestateagentsnearme | DoS - Consent | Small, but legal behaviour matters; ties into the analytics tag |
| Google Reviews widget | kenmoreteam | DoS - Reviews | Needs Places API key and caching |
| FooBox / FooGallery | shotandabeer | Toolkit: Images lightbox | Depends how galleries are used |
| TablePress Premium | realestatevaluesnearme, outdoorseatingnearme | DoS - Tables | **Ryan wants to build this (roadmap).** Standalone plugin since it owns data. Needs: inventory of which TablePress features the sites use (sorting, search, responsive, imports), an importer for existing tables, and shortcode compatibility so pages don't break on swap |
| UserFeedback | outdoorseatingnearme | — | Probably just remove |
| WPForms, Contact Form 7, Formidable Pro | several | DoS - Forms (later) | Bigger job: spam, email delivery, entries; consolidate to one form plugin first |

### Keep (not worth replacing)

| Plugin | Why |
|---|---|
| Akismet, Wordfence, All In One Security | Security needs a vendor's threat data and constant updates |
| LiteSpeed Cache, SiteGround Optimizer, WP Engine plugins | Tied to the host's server cache |
| IDX Broker | Licensed MLS data feed |
| Themify Builder / PTB, Astra, Beaver Builder | Theme/page-builder stack; replacing means a redesign |
| Hostinger suite (Reach, AI Assistant, Onboarding, Amplitude, Tools) | Not replace — **remove** whatever isn't used |

## Early observations

- **DoS City Search and DoS OHI City Search** are near-duplicates → merge into one `DoS - City Search` with per-site settings.
- **SEO overlap:** kenmoreteam (Yoast) and outdoorseatingnearme (AIOSEO) run third-party SEO; the Toolkit SEO module stands down when those are active, so a swap is a decision, not automatic.
- **Forms:** three different form plugins across sites (WPForms, CF7, Formidable). Out of scope for DoS plugins, but worth standardizing.
- bestmortgagebrokersnearme has an empty plugin repo and no WordPress site → archive that repo.
