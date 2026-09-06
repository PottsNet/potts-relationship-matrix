# Changelog

## 0.1.0-alpha.11

- Changes **Graph connected relationships** to a top-down family-network layout so older generations appear above later generations.
- Keeps spouses/partners in the same generation and draws their family junction before descendant branches continue downward.
- Retains solid parent-child connectors and a visually distinct dashed spouse/partner connector.
- Adds generic **historical event associations** to connected graphs using explicit GEDCOM family-event `_ASSO`/`ASSO` links.
- Reads the event association `RELA` value as the displayed role, allowing labels such as `officiating minister`, `witness`, `informant`, `executor` or similar roles without hard-coding them.
- Historical links are displayed with a separate dotted connector and event card so they cannot be mistaken for blood descent or marriage.
- Event cards show the event label plus recorded date and place where available.
- Historical links are privacy-safe and are never inferred from matching names: the associated person must be explicitly linked in the GEDCOM, visible to the current visitor and already present in the connected graph.
- Provides the foundation for examples where two family branches crossed historically before later descendants married, such as a minister from one ancestral branch officiating at a marriage in another branch.

## 0.1.0-alpha.10

- Adds **Graph connected relationships** as a second multi-person graph mode alongside the strict shared-ancestry graph.
- Keeps **Graph selected people** unchanged: it still requires an ancestor or ancestral family shared by everyone.
- Builds the connected graph by merging the best route from **Person 1** to each other selected person.
- Prefers blood/common-ancestor routes, then falls back to the broader visible family graph when a selected person is connected only through spouse/partner or other family links.
- Merges repeated people and families so separate ancestry branches can meet naturally at a marriage/partnership.
- Uses family roles from webtrees to distinguish **parent-child descent** from **spouse/partner links**.
- Draws parent-child descent with solid connectors and spouse/partner links with dashed connectors and a legend.
- Keeps relationship labels relative to Person 1 and adds `via <person>` context where the first branch person helps explain the connection.
- Highlights important branch people used to connect Person 1 to other selected people.
- Uses a generation solver centred on Person 1: parent/child changes generation by one while spouses remain in the same generation. This keeps separate ancestral branches aligned when they meet in a marriage.
- Preserves broader-family relationship labels where the blood matrix has no relationship.
- Uses the same native webtrees `chart-box` cards, privacy checks, photo/date controls and optional fit-to-width behaviour.

## 0.1.0-alpha.9

- Changes the multi-person shared ancestry graph from left-to-right to a **top-down descendant pedigree**.
- Places the shared ancestor or shared ancestral couple at the top and flows descendants downward by generation.
- Keeps people in the same generation on the same horizontal row where practical.
- Draws a shared ancestral couple as a family unit with one descendant junction so duplicate parent-to-child lines are reduced.
- Adds relationship labels to selected people relative to **Person 1**. For example, selected people may be labelled `elder brother`, `first cousin` or `second cousin` using the same relationship names already calculated for the matrix.
- Marks Person 1 as the **Reference person** and adds a **Show relationship labels** control.
- Keeps the complete matrix available for every pairwise relationship rather than attempting to draw all pairwise labels on the graph.
- Retains photos, dates/places, shared-ancestor highlighting and optional fit-to-width controls.

## 0.1.0-alpha.8

- Adds a **Graph selected people** workflow for three or more selected individuals.
- Finds the nearest ancestor or ancestral family shared by all selected people rather than combining separate pair charts.
- Groups two shared ancestors into one ancestral family when every selected person reaches them through the same descendant-family path.
- Builds one merged descendant graph from the shared ancestor/family to all selected people using webtrees-native `chart-box` person cards.
- Merges overlapping descendant branches so the same intermediate person is not repeated unnecessarily.
- Shows generation distance from the shared ancestor/family to each selected person.
- Adds **Nearest shared ancestor** and **More shared ancestors** modes.
- Adds multi-person graph controls for photos, dates/places, shared-ancestor highlighting and optional fit-to-width.
- Keeps full-size cards and horizontal scrolling as the default for readability.
- Refactors alpha presentation/support code into `src/RelationshipMatrixSupport.php` while keeping the relationship engine separate.

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
- Added all-family-link path searching using the webtrees relationship graph and Dijkstra shortest paths.
- Added alternative-path discovery by excluding family nodes from previously discovered routes.
- Added directional webtrees-native relationship names for matrix cells.
- Added pair detail with relationship path counts, common ancestors and generation notation.
- Added an initial merged SVG relationship network for pair analysis.
- Added initial privacy filtering using webtrees `canShow()` checks for individuals and families.

### Current known limitations

- This remains an early development alpha and needs live-tree testing across varied relationships.
- Common-ancestor mode follows recorded GEDCOM parent-child links and does not yet distinguish genetic, adoptive, foster or other parentage types.
- The relationship pedigree uses custom merged-path layouts rather than the full webtrees pedigree layout engine.
- Multi-person shared ancestry currently chooses the shortest visible path from each selected person to each shared ancestor and is still an alpha implementation for pedigree-collapse cases.
- Connected relationships currently merges the closest route from Person 1 to each selected person; a future version may offer alternate-route selection and richer cluster controls.
- Historical event links require explicit GEDCOM `_ASSO`/`ASSO` data on the relevant family event and currently display only when the associated person is already present in the connected graph.
- Multi-person relationship labels use Person 1 as the reference; all pairwise relationship labels remain available in the matrix.
- A formal relationship/kinship coefficient is not yet calculated.
- Path/route searches are bounded by safety/performance limits and all-family mode depends on the selected alternative-search depth.
