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
- Display pair relationships as a merged pedigree-style graphical relationship chart.
- **Graph three or more selected people together** from the ancestor or ancestral family shared by everyone.
- Draw the multi-person graph **top-down**, with shared ancestry at the top and descendants flowing down through generations.
- Merge overlapping descendant branches rather than repeating the same person for each selected endpoint.
- Label each selected person relative to **Person 1**, for example `elder brother`, `first cousin` or `second cousin`.
- Keep the full matrix available for every pairwise relationship while using Person 1 as the uncluttered reference in the multi-person graph.
- Switch between the nearest shared ancestor/family and additional shared ancestors.
- Reuse webtrees' native person cards, including photographs, lifespan and configured chart-box facts.
- Highlight common/shared ancestors and selected endpoints.
- Show generation distance from shared ancestry to each selected person.
- Toggle photos, dates/places, relationship labels, ancestor highlighting and fit-to-width.
- Show relationship cards at normal size by default, with scrolling for larger graphs; fit-to-width remains an explicit optional view.
- Use webtrees relationship labels and privacy checks rather than maintaining a separate genealogy database.

## Relationship modes

### Blood / common ancestors

This mode builds ancestor paths for each selected person and combines paths that meet at a common ancestor. Paths that reduce to the same descendant-family sequence are grouped into one **relationship route**.

For example, two full siblings normally have two common ancestors — their two parents — but only one sibling relationship route. Likewise, ordinary first cousins may share two grandparents while still having one cousin relationship route. A second independent ancestral branch remains a separate route.

### All family links

This mode searches the visible webtrees family graph and may include spouse links. It is useful for finding connections that are genealogically meaningful even when two people do not share a known common ancestor.

## Pair graphical relationship view

Click a non-diagonal relationship-matrix cell to open the pair analysis. The graphical relationship view uses webtrees' own `chart-box` renderer, so cards follow the active webtrees theme and the tree's chart-box settings.

Shared people are merged where practical. Selected people receive endpoint highlighting and common ancestors receive separate highlighting. Where two common ancestors form the same grouped route, they are treated as one shared ancestral family.

The default presentation keeps person cards at their normal readable size and allows scrolling where necessary. **Fit chart to width** can still be enabled manually when an overview is more useful than full-size cards.

## Multi-person shared ancestry graph

Select at least three people and choose **Graph selected people**. The module calculates the ancestors shared by every selected person before drawing the graph.

The default **Nearest shared ancestor** mode selects the closest shared ancestor or ancestral family according to the generation distances from all selected people. If two ancestors are reached through the same descendant-family paths, they are grouped as one shared ancestral family — for example, a shared grandparent couple.

The graph is drawn top-down. Shared ancestor cards appear at the top, subsequent generations are arranged on rows below them and the selected people appear further down the descendant branches. Intermediate people and overlapping branches are merged so the result behaves like one pedigree network rather than several pair charts placed beside each other.

Relationship badges on selected people use **Person 1 as the reference**. This avoids trying to display every possible pairwise relationship on the graph. The matrix remains the authoritative place to inspect all pair-to-pair relationships.

**More shared ancestors** can display additional shared ancestry groups within the current ancestor search limit. This is intended for exploring older common ancestry and pedigree-collapse situations and remains an alpha feature.

For each shared ancestry group, the panel reports the generation distance to every selected person.

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

Current development version: `0.1.0-alpha.9`.

The multi-person graph currently chooses the shortest visible path from each selected person to each shared ancestor. More complex pedigree-collapse path selection, performance caching, relationship/kinship coefficients and release packaging remain planned follow-up work.

## Support

Report issues at the GitHub issue tracker for this repository.

## Licence

GPL-3.0-or-later.
