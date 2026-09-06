# Potts Relationship Matrix

Potts Relationship Matrix is a custom module for webtrees 2.2.x that explores multiple relationships between a selected group of people.

The project is inspired by GeneWeb's Relation Matrix, but is implemented natively for webtrees and uses webtrees records, privacy rules and relationship naming.

## Current alpha features

- Select up to eight visible individuals from a webtrees tree.
- Build an N × N relationship matrix.
- Calculate blood/ancestral relationships through common ancestors.
- Group the two members of an ancestral couple into one genealogical relationship route where they lead through the same descendant path.
- Preserve genuinely different relationship routes caused by pedigree collapse or independent ancestral connections.
- Calculate broader family-link paths, including spouses, using the webtrees relationship graph.
- Show the closest relationship, relationship-route count, common-ancestor count and generation information in matrix cells.
- Open a pair analysis showing grouped relationship routes.
- Display the underlying relationship paths as a merged pedigree-style graphical relationship chart.
- Reuse webtrees' native person cards, including photographs, lifespan and configured chart-box facts.
- Highlight common ancestors and selected endpoints.
- Add a family-unit marker around common ancestors belonging to the same grouped route.
- Toggle photos, dates/places, common-ancestor highlighting and fit-to-width.
- Use webtrees relationship labels and privacy checks rather than maintaining a separate genealogy database.

## Relationship modes

### Blood / common ancestors

This mode builds ancestor paths for each selected person and combines paths that meet at a common ancestor. Paths that reduce to the same descendant-family sequence are grouped into one **relationship route**.

For example, two full siblings normally have two common ancestors — their two parents — but only one sibling relationship route. Likewise, ordinary first cousins may share two grandparents while still having one cousin relationship route. A second independent ancestral branch remains a separate route.

### All family links

This mode searches the visible webtrees family graph and may include spouse links. It is useful for finding connections that are genealogically meaningful even when two people do not share a known common ancestor.

## Graphical relationship view

Click a non-diagonal relationship-matrix cell to open the pair analysis. The graphical relationship view uses webtrees' own `chart-box` renderer, so cards follow the active webtrees theme and the tree's chart-box settings.

Shared people are merged where practical. Selected people receive endpoint highlighting and common ancestors receive separate highlighting. Where two common ancestors form the same grouped route, the chart adds a family-unit marker to make the ancestral couple easier to recognise.

The current layout is a custom left-to-right merged relationship pedigree. It is designed for relationship explanation rather than as a replacement for webtrees' normal ancestor pedigree chart.

## Requirements

- webtrees 2.2.x
- PHP 8.3 or later

## Installation

This repository is currently an early alpha and should be tested carefully before general production use.

1. Download or clone the `alpha-mvp` branch while testing the alpha.
2. Place the module folder in `modules_v4/potts-relationship-matrix` (or keep the existing folder name used by your installation).
3. Enable **Potts Relationship Matrix** in **Control panel > Modules > All modules**.
4. Open **Charts > Potts Relationship Matrix**.

The chart can be opened directly from the Charts menu. If no individual XREF is supplied in the route, the module uses webtrees' significant/default individual for the current user and tree.

Release ZIP packaging will be added after the alpha has been tested successfully on real trees and relationship patterns.

## Privacy

The module is designed to respect webtrees privacy. Selected individuals must be visible to the current visitor and hidden individuals/families are not intentionally exposed through relationship output.

Because this is an alpha, privacy behaviour should be tested while signed out and with representative member accounts before production use.

## Status

Current development version: `0.1.0-alpha.5`.

Planned follow-up work includes native route filtering in the graphical view, performance caching, pairwise coefficient calculations, richer relationship filtering and release packaging.

## Support

Report issues at the GitHub issue tracker for this repository.

## Licence

GPL-3.0-or-later.
