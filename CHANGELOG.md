# Changelog

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
- Path counts are bounded by safety/performance limits and all-family mode depends on the selected alternative-search depth.
