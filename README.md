# Potts Relationship Matrix

Potts Relationship Matrix is a custom module for webtrees 2.2.x that explores multiple relationships between a selected group of people.

The project is inspired by GeneWeb's Relation Matrix, but is implemented natively for webtrees and uses webtrees records, privacy rules and relationship naming.

## Current alpha goals

- Select up to eight visible individuals from a webtrees tree.
- Build an N x N relationship matrix.
- Calculate blood/ancestral relationship paths through common ancestors.
- Calculate broader family-link paths, including spouses, using the webtrees relationship graph.
- Show the closest relationship, path count and generation/step information in each matrix cell.
- Open a pair analysis showing multiple relationship paths.
- Display those paths in a merged graphical relationship network.
- Use webtrees relationship labels and privacy checks rather than maintaining a separate genealogy database.

## Relationship modes

### Blood / common ancestors

This mode builds ancestor paths for each selected person and combines paths that meet at a common ancestor. It is intended for ancestral, cousin and pedigree-collapse analysis.

### All family links

This mode searches the visible webtrees family graph and may include spouse links. It is useful for finding connections that are genealogically meaningful even when two people do not share a known common ancestor.

## Requirements

- webtrees 2.2.x
- PHP 8.3 or later

## Installation

This repository is currently an early alpha and should be tested on a non-production webtrees installation first.

1. Download or clone the repository.
2. Place the module folder in `modules_v4/potts_relationship_matrix`.
3. Enable **Potts Relationship Matrix** in **Control panel > Modules > All modules**.
4. Open **Charts > Relationship Matrix**.

Release ZIP packaging will be added once the first alpha has been tested successfully.

## Privacy

The module is designed to respect webtrees privacy. Selected individuals must be visible to the current visitor and hidden individuals/families are not intentionally exposed through relationship output.

Because this is an alpha, privacy behaviour should be tested while signed out and with representative member accounts before production use.

## Status

Version `0.1.0-alpha.1` is the initial working scaffold.

Planned follow-up work includes performance caching, improved graph layout, pairwise coefficient calculations, richer path filtering and release packaging.

## Support

Report issues at the GitHub issue tracker for this repository.

## Licence

GPL-3.0-or-later.
