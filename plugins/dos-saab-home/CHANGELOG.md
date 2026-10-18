# Changelog

Every entry says why, not only what. The reasoning is the part that stops a
later change quietly undoing a deliberate decision.

Versions are the plugin's, tagged `dos-saab-home-v<version>`.

## 0.1.1

The takeover now swaps the front-page template instead of filtering the page's
content, because 0.1.0 never showed on shotandabeer.com: the child theme's CSS
loaded but the original homepage stayed.

0.1.0 replaced `the_content` on a static front page, the way DoS Outdoor
Seating does. That only works when Settings → Reading shows a static page and
the theme runs that page through the main loop's `the_content`. A front page
set to "Your latest posts", or a Themify layout that builds the front page
another way, skips the filter and the takeover silently does nothing.

`template_include` (late, at `PHP_INT_MAX - 10`) now hands the front page to
`templates/front-page.php`: the theme's `get_header()`, this layout inside
Magazine's `#layout` / `#content` wrappers, and `get_footer()`. It runs on any
front page, static or latest posts, but not on page 2 and later of a
latest-posts home, which stays the theme's post list. The template never calls
`the_content`, so whatever Builder holds for the page has nothing to print,
and the Builder-meta filter and content filter are gone.

The CSS rule that hid `.themify_builder_content` outside the homepage markup
is gone too. Under a template swap nothing from the page's Builder layout
renders, and the rule would also have hidden a Builder header or footer
layout part.

## 0.1.0

The shotandabeer.com homepage, after the "Taproom" mockup Ryan picked on
2026-10-18 (white page, black text, grey cards, Oswald over Barlow; layout
modelled on the outdoorseatingnearme.com homepage).

- **Takeover, not a page edit.** While **Settings → SAAB Home → Homepage
  takeover** is on, the front page's content is replaced; off, the page is back
  as it was. Admins can preview it with `/?saab_home_preview=1` before
  switching it on. The front page is a Themify Builder page, so Builder's
  layout is kept out with the same three layers DoS Outdoor Seating uses (hide
  the stored layout meta, replace `the_content` last, CSS fallback).
- **Search reads the review taxonomies, not post text.** Reviews are already
  tagged `saab_restaurant`, `saab_cuisine`, `saab_dish` and `saab_city`. Core
  search only matches words in titles and content, so it cannot group
  suggestions or tell the cuisine "Mexican" from a review that mentions Mexico.
  The index (one entry per term with a published review) rides along in the
  page so suggestions need no request; `GET /wp-json/saab/v1/search?q=` gives
  the same match plus review titles. It is cached 12 hours and rebuilt when a
  review is published or unpublished or a tag changes. Pressing Enter without
  picking a suggestion falls through to core search (`/?s=`), so a query the
  tags miss still finds something.
- **Matching rules live twice**, in `Search::match()` and `assets/saab-home.js`,
  so suggestions appear without a round trip. `tests/test-saab-home-search.php`
  pins the PHP side; change both together.
- **Category cards come from real categories.** Empty setting: every category
  with a published review, most reviews first, without Uncategorized, so a new
  category shows up without editing the homepage. A slug list fixes the set
  and order. The photo is the one set on the category's edit screen
  (`saab_card_image` term meta), else the newest review's featured image, else
  a grey card.
- **Colours come from `--saab-*` CSS variables** that the DoS SAAB Child theme
  sets, with the same values as fallbacks, so the homepage matches the rest of
  the site under the child theme and still looks right without it.
