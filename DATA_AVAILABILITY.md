# Data availability — updated 2 November 2026

This local package now includes the approved-only 4,587-entry lexical snapshot,
a derived SQL fixture, the 600 frozen R01 source anchors/query specifications,
historical exclusions, 3,600 saved query-level outputs, summaries and historical
curated-label records. R01 outputs and the seven frozen services are unchanged.
Original dictionary PDF, user/account tables, authentication values, individual
contribution histories and human free-text ratings are not redistributed.

## Published source-license basis

The lexical source is *Kamus Tondano–Indonesia* (1985), ministry repository item
https://repositori.kemendikdasmen.go.id/23919/.
The official information page https://repositori.kemendikdasmen.go.id/information.html
states that the repository materials use Creative Commons Attribution-
NonCommercial 4.0 and requires source attribution and noncommercial use.
This express repository-level statement is the source-license basis for including
the source-derived lexical materials, rather than an inference that every
government work is public domain or that all academic uses are unrestricted.

The item also carries an All Rights Reserved label, and the original printed
book p. iv describes a written-permission reproduction rule with an exception
for scientific quotations. Those notices are retained in SOURCE_ATTRIBUTION.md;
the package does not claim that they disappeared. The repository's guide for
uploaders uses All Rights Reserved to identify works fully published by ministry
units and leaves the item-level license field unspecified in that case.
The published repository-level CC statement is relied on for this noncommercial
research release. This is not a representation that a project-specific written
permission letter or an institutional/community approval was obtained.

CC BY-NC terms, including attribution, linking the license, identifying changes
and noncommercial reuse, remain applicable to the source-derived portions.
See https://creativecommons.org/licenses/by-nc/4.0/.
No additional restriction is imposed on rights already granted by that license.
This note is not a definitive legal adjudication of every possible downstream
use; commercial reuse and other rights are not granted by this preparation.

## Reproducibility and limits

The numerical analysis and an independent Python translation of the R01 sampler
can be checked from the included inputs. The latter matches all 600 cases and
allocation counts and is not PHP execution or external preregistration proof.
The SQL fixture is a sanitized derivative, not a full original database dump;
its fingerprinted lexical fields match the frozen R01 snapshot. Aggregate
popularity/search_count fields remain because the frozen exact SQL path reads
them. They are not individual natural-query logs.

Full PHP/MariaDB execution, fixture import and a new retrieval timing study were
not performed in this environment. The original lock is preserved. The original
historical generator seed/rejection counts and original bootstrap script were
not recovered. Human free-text assessment files remain outside this public
package; their inclusion is not inferred from the book's CC license.

This package is prepared locally. A GitHub upload, public DOI, public
preregistration, journal transfer and separate permission letter are not claimed.
