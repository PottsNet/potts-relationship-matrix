# Changelog

## 0.1.0-alpha.1

Initial development alpha.

- Added a webtrees Chart module named **Relationship Matrix**.
- Added selection of up to eight visible individuals.
- Added common-ancestor relationship calculation using recorded parent-child links.
- Added multiple ancestral-path discovery for pedigree-collapse style relationships.
- Added all-family-link path searching using the webtrees link graph and Dijkstra shortest paths.
- Added alternative-path discovery by excluding family nodes from previously discovered routes.
- Added directional webtrees-native relationship names for matrix cells.
- Added pair detail with relationship path counts, common ancestors and generation notation.
- Added a merged SVG relationship network for pair analysis.
- Added initial privacy filtering using webtrees `canShow()` checks for individuals and families.

### Known limitations

- This is an early alpha and has not yet been tested on a live webtrees installation.
- Common-ancestor mode follows recorded GEDCOM parent-child links and does not yet distinguish genetic, adoptive, foster or other parentage types.
- The graph uses a simple breadth-based network layout rather than a full pedigree layout engine.
- A formal relationship/kinship coefficient is not yet calculated.
- Path counts are bounded by safety/performance limits and all-family mode depends on the selected alternative-search depth.
