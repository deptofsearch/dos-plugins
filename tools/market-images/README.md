# Market images tooling

The scripts and data that make the images the **DoS - Market Images** plugin
shows (`plugins/dos-market-images`). Nothing here ships in the plugin ZIP.

The images, logs and credentials are kept out of git (see the root
`.gitignore`): `raw/`, `raw_posts/`, `web/`, `web_posts/`, `post-sheets/`,
`*.log`, any image under `tools/`, and any `wp.env`.

## Files

| File | What it is |
|---|---|
| `generate_all.py` | Generates the 53 state and 100 city images from `prompts.json` through the `media-gen` skill's `generate.py` (`~/.claude/skills/media-gen/`). Writes `raw/<key>.png`; skips what already exists. |
| `process_upload.py` | `process`: trims the poster margin and writes `web/<key>.webp` at 1200x900. `upload`: sends them to the media library (`--dry` only checks login). `register`: sends the list to the plugin. |
| `post_images.py` | The same for blog posts that have no featured image: `generate`, `process`, `upload`. Upload sets the featured image through the plugin's `/set-thumbnail`, and skips any post that already has one. |
| `prompts.json` | One item per image (key, label, kind city or state, WordPress page id, prompt) and the palettes. The input to everything. |
| `final100.json` | The 100 cities in carousel order, busiest market first. `register` uses it for ordering. |
| `palettes.json` | Colour palettes sampled from the state images. The offline fallback for `post_images.py`; the dashboard under Tools → Market Images is the source of truth. |
| `post_scenes.json`, `post_meta.json`, `post_palettes.json` | Per-post scene prompts, titles and tags, and which palette each post got. |
| `uploads.json`, `post_uploads.json` | Ledgers of key (or post id) to media id. Written after every upload so a rerun resumes, and the reason uploads are idempotent. |
| `featured_plan.json`, `home_ids.txt` | Records of the state page featured-image run and the homepage post ids. |

## Order

1. `python3 generate_all.py` (and `python3 post_images.py generate` for posts)
2. `python3 process_upload.py process` (`python3 post_images.py process`)
3. `python3 process_upload.py upload` (`python3 post_images.py upload`)
4. `python3 process_upload.py register`

`register` POSTs the city and state lists to `/wp-json/revnm/v1/market-images`,
which is what makes the plugin show them. Uploading alone changes nothing on
the site.

## Credentials

`upload` and `register` need `WP_URL`, `WP_USER` and `WP_APP_PASSWORD` (a
WordPress application password for an administrator) in a `wp.env` file of
`KEY=value` lines. The scripts read the file named by the `WP_ENV` environment
variable, and otherwise `../wp.env` next to this folder. Keep it out of the
repository; it is ignored if it is under `tools/`.

Needs Python 3, `Pillow` and `requests`.
