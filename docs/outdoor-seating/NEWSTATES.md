# Launching 8 new states (plugin 0.6.2)

States: New Mexico (Albuquerque), Colorado (Denver), Utah (Salt Lake City), Texas (Austin, Dallas, Houston), Illinois (Chicago), Florida (Miami), New York (New York), Georgia (Atlanta).

## What was hard-wired (and what is data-driven now)
- 0.6.0 already moved the state list into `States::TABLE` (50 states + DC): names, page slugs, timezones, takeover, SEO seeding, home tiles, city search all read it.
- State page = any published page whose slug is the state slug (`/colorado/`). Tiles on the homepage appear for every state with a published page; images come from the `state_images` option (code => attachment id).
- Still copy-only: `Home::DEFAULT_INTRO` / `DEFAULT_SEO` (only used when the options are empty) and `Seo::MAJOR_CITIES` (fallback city names; 0.6.1 adds the 8 states).
- A new state page does not need an HTML city table: its content is `<p>intro</p>[osn_state_cities state="CO"]`, and the list comes from cities that have a landing page (osn_city term with osn_landing_page_id).
- City pages use `[table id=0 filter="Denver, CO" /]` (site convention, so H1, SEO seeding and hood pages recognise them). The takeover renders cards when the city is enabled in `osn_takeover_cities`; TablePress is never asked for table 0.

## Files
- `data/newstates/<state>.json` (8) and `data/newstates/cities/<slug>.json` (10): draft pages. `data/newstates/home.json`: homepage intro + SEO copy draft. Regenerate with `build_drafts.py`.
- `home-images/newstates/`: prompts.json, gen.py, process.py, raw/, final/ (2 candidates each), contact-sheet.jpg, picks.json.
- `data/newstates/apply.py`: every launch step below; dry run unless `--live`.

## Launch steps (in order; nothing is live until you run a step with --live)
1. Install plugin 0.6.2 (dist/dos-outdoor-seating-0.6.2.zip). Existing states render byte-identically (tested).
2. `python3 data/newstates/apply.py pages --live`  (creates 8 state + 10 city pages as DRAFTS)
3. `python3 data/newstates/apply.py cities --live`  (POST /cities/register: terms + landing links)
4. `python3 data/newstates/apply.py takeover --live`  (adds the 10 cities to the takeover list; must precede publishing)
5. `python3 data/newstates/apply.py images --live`  (uploads the 8 picked tile images, writes home-images/newstates/uploaded.json)
6. `python3 data/newstates/apply.py home-draft --live`, then open `/?osn_home_draft=1` while logged in as admin to preview 14 tiles + new copy.
7. `python3 data/newstates/apply.py publish --live`  (publishes the 8 STATE pages; their tiles appear; city pages stay draft)
8. `python3 data/newstates/apply.py seed --live`  (SEO titles/descriptions for the state pages; dry run first)
9. `python3 data/newstates/apply.py home-apply --live`  (new intro + SEO copy + tile images go live)
10. Venue discovery and upserts as usual; the city terms already exist and their pages are still drafts.
11. Once a city has venues: `python3 data/newstates/apply.py publish-cities --live`, then `seed-cities --live` (so no city page goes live empty).
12. Neighborhood data (hoods.py) comes later per city.

## Rolling launch (2026-10-05: states go live one at a time, west to east)
Ryan switched from "publish all 8 together" to "publish each state as soon as its city has venues".
- Per state: `apply.py publish --only <state-slug> --live`, `apply.py publish-cities --only <city-slug> --live`, publish the city's hood pages from `data/<city>/pages_plan.json`, then seed SEO for just those pages (seo/seed with offset/limit; a full `seed` run would also reset descriptions on WA/OR/CA/ID).
- Then `apply.py home-copy --live`: rebuilds the homepage intro + SEO copy from home.json naming only PUBLISHED states (no links to drafts) and pushes all 14 tile images. Rerun after every launch. Do not use `home-apply` any more (it names all 14 states).
- Tile images: the 6 original states got outdoor-seating images too (`home-images/origstates/`, attachments 22841–22848). Pre-change homepage options backed up in `home-images/origstates/live_home_backup.json`.
- Live so far: Colorado + Denver + 41 Denver hood pages (2026-10-05).
