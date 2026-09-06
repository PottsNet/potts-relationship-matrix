# Changelog

## 0.1.0-alpha.7

- Makes the graphical relationship chart readable by default rather than relying on automatic width fitting.
- Starts relationship cards at normal size and uses horizontal scrolling where the relationship is wider than the viewport.
- Leaves **Fit chart to width** available as an explicit user option instead of the default presentation.
- Removes the hard 480 px visual minimum from the rendered relationship viewport and sizes the visible chart area from the cards actually being displayed.
- Caps the visible chart height for complicated routes while retaining scrolling when additional vertical space is required.
- Keeps relationship calculation, route grouping, common-ancestor family units and webtrees-native person cards unchanged.

## 0.1.0-alpha.6

- Improves the pedigree-style relationship chart presentation without changing the relationship calculation engine.
- Draws a shared ancestral couple as a clearer family unit, with brackets on both sides of the common-ancestor cards and descendant branches connected to that family junction.
- Labels grouped ancestral couples as a **Shared ancestral family** in the graphical view.
- Replaces the route-card `family steps` wording with clearer generation wording such as `2 generations up · 2 generations down`.
- Protects person-card readability by automatically turning off `Fit chart to width` on first load when fitting would shrink a wide relationship chart too aggressively; users can still turn fitting back on manually.
- Keeps native webtrees `chart-box` person cards, route grouping, privacy filtering and relationship naming unchanged.

## 0.1.0-alpha.5

- Groups common ancestors that reduce to the same normalised descendant path into one genealogical **relationship route**.
- Treats the two parents of full siblings, or the two grandparents of ordinary cousins, as one relationship route with two common ancestors rather than two separate relationships.
- Preserves genuinely different descendant routes caused by pedigree collapse or multiple independent ancestral connections.
- Adds route counts and common-ancestor counts to matrix presentation.
- Replaces duplicate path cards in pair detail with one card per relationship route and lists all common ancestors for that route.
- Adds a graphical family-unit bracket linking common ancestors who belong to the same grouped route.
- Temporarily disables the closest-route selector where one route contains multiple raw ancestor paths so the complete ancestral couple remains visible.

## 0.1.0-alpha.4

- Corrected common-ancestor relationship naming when both descendants reach a common ancestor through the same family.
- Collapses duplicated `family → common ancestor → same family` segments for webtrees relationship naming while preserving the full ancestral route for the graphical view.
- Prevents full-sibling paths from being misread as half-sibling paths and reduces unnecessarily long relationship descriptions such as `grandfather's granddaughter` where a direct family relationship is available.
- Rebuilds both directions of matrix relationship labels from the corrected path while retaining distinct common-ancestor path counts.
- Keeps the pedigree graph data unchanged so shared parents/common ancestors can still be displayed visually.

## 0.1.0-alpha.3

- Replaced the generic SVG person boxes with a pedigree-style graphical relationship view.
- Reused webtrees' native `chart-box` person cards so the graph can display the same photographs, names, lifespan details, birth/death facts, zoom control and links menu used by webtrees pedigree charts.
- Added merged person nodes for relationship paths so shared people appear once where practical.
- Added common-ancestor highlighting and selected-person highlighting.
- Added orthogonal relationship connectors with dashed spouse links and dotted sibling links.
- Added client-side controls for closest/all displayed paths, photos, dates/places, common-ancestor highlighting and fit-to-width.
- Kept the existing relationship matrix and path detail workflow intact.

## 0.1.0-alpha.2

- Fixed chart access from the main Charts menu when no `xref` parameter is supplied by falling back to webtrees' significant/default individual.
- Standardised the public module name as **Potts Relationship Matrix**.
- Fixed the view namespace after splitting the public wrapper from the relationship calculation core.

## 0.1.0-alpha.1

Initial development alpha.

- Added a webtrees Chart module.
- Added selection of up to eight visible individuals.
- Added common-ancestor relationship calculation using recorded parent-child links.
- Added multiple ancestral-path discovery for pedigree-collapse style relationships.
- Added all-family-link path searching using the webtrees link graph and Dijkstra shortest paths.
- Added alternative-path discovery by excluding family nodes from previously discovered routes.
- Added directional webtrees-native relationship names for matrix cells.
- Added pair detail with relationship path counts, common ancestors and generation notation.
- Added an initial merged SVG relationship network for pair analysis.
- Added initial privacy filtering using webtrees `canShow()` checks for individuals and families.

### Current known limitations

- This remains an early development alpha and needs live-tree testing across varied relationships.
- Common-ancestor mode follows recorded GEDCOM parent-child links and does not yet distinguish genetic, adoptive, foster or other parentage types.
- The relationship pedigree uses a custom merged-path layout rather than the full webtrees pedigree layout engine.
- A formal relationship/kinship coefficient is not yet calculated.
- Path/route searches are bounded by safety/performance limits and all-family mode depends on the selected alternative-search depth.
