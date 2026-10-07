# BREANM agent tags

Filter chips for agent cards on bestrealestateagentsnearme.com. Approved by
Ryan 2026-10-07. The future `dos-agents` plugin and the n8n review workflows
both read this list; change it here first.

Every tag describes a **service or a property type**, never a kind of person.
See "Excluded" below: nothing close to Fair Housing protected classes.

## Review tags

Assigned by Claude Sonnet 5.5 (`claude-sonnet-5-5`, the BLNM review model) in
the same call that writes the agent's review summary. The model picks only from
this list; it never invents a tag.

| Slug | Label | Clients mention | Keyword check (at least one cited review) |
|---|---|---|---|
| `first-time-buyers` | First-time buyers | guiding a first purchase, explaining the process | first home, first-time, first time |
| `listing-marketing` | Listing & marketing | pricing the home, photos, staging, showings, sold fast | list, listing, staging, photos, showings, sold |
| `negotiation` | Negotiation | offers, counteroffers, inspection or repair asks | negotiat, offer, counter |
| `market-knowledge` | Local market knowledge | pricing, comparable sales, the area's market | market, comps, comparable, pricing |
| `relocation-remote` | Relocation & remote buying | buying from out of state, virtual tours, remote signing | out of state, relocat, virtual, remote, moving from |
| `investors-rentals` | Investors & rentals | investment property, rental numbers, repeat purchases | invest, rental, flip, portfolio |
| `new-construction` | New construction | builders, lots, new-build contracts | new construction, builder, new build, lot |
| `rural-land` | Rural & land | acreage, wells, septic, farm or ranch | acre, land, well, septic, farm, ranch |
| `condos-townhomes` | Condos & townhomes | HOA questions, condo purchases | condo, townhome, townhouse, hoa |
| `complex-transactions` | Complex transactions | appraisal gaps, inspection problems, short sales, near-failed deals | appraisal, inspection, short sale, fell through, complicated |
| `tight-timelines` | Tight timelines | quick closing, short deadlines | quick, fast, deadline, timeline, closed in |
| `responsive` | Responsive | calls back, evenings and weekends | respons, quick to reply, available, called back |

`responsive` will likely apply to most agents; the pilot decides whether it stays.

### Rules

- **Evidence:** 3 supporting reviews, or 2 when the agent has fewer than 15 reviews.
- The model returns, per tag, the indices of the reviews that support it.
- **Code check** (the BLNM `Validate` node pattern) rejects the tag unless it is
  on this list, the count meets the minimum, the indices are real, and at least
  one cited review matches the tag's keyword check.
- Positive only. No rankings or superlatives (same banned-word rule as BLNM).
- Cards show the evidence: "Negotiation · mentioned in 5 reviews".

## Data tags

Computed in n8n from the agent's numbers. No model involved.

| Slug | Label | Rule |
|---|---|---|
| `years-10-plus` / `years-3-9` / `years-0-2` | 10+ years in business, etc. | Licensed or active years |
| `high-volume` | High volume in [city] | Closings in the last 12 months in the city's top 25% |
| `mostly-listings` / `mostly-buyers` / `both-sides` | Mostly listings, etc. | 70%+ of recent deals on one side, otherwise both |
| `price-starter` / `price-mid` / `price-upper` | Price range | Relative to the city's median sale price |
| `many-reviews` | Many reviews | 50+ reviews, or the city's top 25% |
| `recently-reviewed` | Recently reviewed | At least one review in the last 12 months |

Rating is a sort option, not a tag.

"10+ years" is worded that way on purpose: "veteran" reads as military status.

## Excluded

- Kinds of people: families, kids, seniors, retirees, downsizing, singles, couples
- Disability or accessibility needs
- Military, veterans, VA buyers (military status is protected in some states)
- Languages spoken (close to national origin)
- Neighborhood character: schools, safe, quiet, good area, church or community fit
- Anything ranking agents against each other: best, top-rated, number one

## Pilot checks

- Drop a tag that covers more than 70% of agents in a city (it filters nothing)
  or fewer than 5% statewide (too rare to earn a chip).
- Confirm the Zillow agent response has the fields the data tags need: buyer vs.
  listing side, years active, price range. Sales, rating, review count and
  average price are known to be there (they appear on today's pages).
- Tag landing pages ("First-time buyer agents in Boise") are out of scope until
  the pilot shows how many agents each city has per tag.
