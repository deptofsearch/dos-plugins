# DoS - Market Images

Illustrated market images for Real Estate Values Near Me: a city carousel in
the homepage "Popular Real Estate Markets" section, image buttons with a filter
on the By State page, a hero on each state page, and 2-post topic grids under
mapped homepage sections. Manage it under **Tools → Market Images**.

Shortcodes: `[revnm_market_carousel]`, `[revnm_state_buttons]`. Neither takes
attributes.

The homepage, By State and state-page changes are made by a `the_content`
filter at run time, so page content that other tools regenerate is never
edited.

## Loading the images

The images are generated and uploaded by the scripts in `tools/market-images/`
at the repository root, which finish by POSTing the list to
`/wp-json/revnm/v1/market-images`. See the README there for the order.

## REST endpoints

All under `revnm/v1`, all behind a capability check:

| Route | Method | Needs | Does |
|---|---|---|---|
| `/market-images` | GET, POST | `manage_options` | Read, or replace, the city and state lists. A hide set on the dashboard survives a reload. |
| `/palettes` | GET, POST | `manage_options` | Colour palettes used as reference when generating images. |
| `/set-thumbnail` | POST | `edit_others_posts` | Set a post's featured image without saving the post, so its modified date is untouched. |
| `/reset-modified` | POST | `edit_others_posts` | Put a post's modified date back to its publish date. |

`/reset-modified` writes `wp_posts` directly rather than through
`wp_update_post()`: saving would bump the modified date again, which is what it
exists to undo. It also skips the save hooks, so nothing else reacts to the
change.

## Updates

Updates come from this repository's releases (tag
`dos-market-images-v<version>`, asset `dos-market-images.zip`) through the
shared updater. See the root README for the `DOS_GITHUB_TOKEN` constant.
