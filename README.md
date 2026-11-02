# Toulour selective lexical retrieval — VOICE research materials

Materials for **Selective Transposition-Aware Hybrid Lexical Retrieval for a
Low-Resource Toulour–Indonesian Digital Dictionary: Ablation and Held-Out Evaluation**.
Proposed method: V2.2 Fast (`v22_context_short_swap_fast`).

Package version: VOICE-R01-CODE-DATA-20261102-v2. Prepared after peer review.
No public preregistration, Git commit, DOI, GitHub upload or journal transfer
is claimed by this local packaging step. Archival JSON status strings do not
establish external preregistration before R01 outcomes were known.

## Contents

- `project/services`: seven unchanged frozen services and six-scenario registry.
- `project/experiments`: archived sampler, preflight, runner/check scripts and
  isolated OSA implementation microbenchmark.
- `project/fixtures`: frozen policy/analysis plan, approved manifest and
  historical source/query exclusions.
- `data/lexical`: approved-only 4,587-entry JSON/SQL fixture and provenance.
- `data/holdout`: 600 original R01 lexical queries, anchors and QA manifest.
- `data/historical`, `historical_code` and `results/historical`: internally curated
  historical labels, generator and saved benchmark results.
- `results`: 3,600 original query-level R01 outputs and OSA microbenchmark files.
- `analysis`: saved statistics, full 12-stratum results and post-review paired
  analysis script/protocol. `data/R01_numeric_outputs.csv` retains the earlier
  lexical-free numerical export for convenient analysis.
- `tools`: file-integrity and independent sampler-translation checks.

## Source attribution and reuse

Source-derived lexical material is included on the basis of the official
repository information page's CC BY-NC 4.0 statement, with attribution and
marked digitization/research changes. See SOURCE_ATTRIBUTION.md and
DATA_AVAILABILITY.md. Preserve attribution, the license link and change notices;
reuse of the licensed portions must be noncommercial. This does not assert
public-domain status or a separate written permission letter. Code licensing
remains separate; no new software license is chosen by this packaging step.

No original book PDF, account dump, credentials, individual contribution history,
or human free-text assessment files are included.

## Offline checks

Python 3.12.14 and NumPy 2.3.5 were used for revision numerical analyses:

    python -m pip install -r requirements-analysis.txt
    python tools/verify_archive.py
    python tools/verify_sampler.py
    python analysis/recompute_paired_statistics.py results/toulour_final_holdout_results/final_holdout_queries_20261018_200757_4ead1cc1bf5f.csv verification_output/stats

The sampler check is an independent Python translation and must match all 600
cases and allocation counts. The paired script recomputes the three metric
intervals under the documented post-review specifications. Outputs go to a
separate verification folder; frozen sources and benchmark outputs remain intact.

## Runtime replication limits

Original measurements: Windows 10, i7-7700HQ, 16 GB RAM, PHP 8.2.12 and
MariaDB 10.4.32. See ENVIRONMENT.json. PHP/SQL execution and fixture import were
not tested during revision preparation. The database adapter uses environment
variables and the isolated research-database name. Exact PHP extension state is
not fully documented. Read the archived CLI arguments/preflight before execution.

The original one-time benchmark lock is preserved. Do not overwrite R01 or use
it for tuning. A new replication must use a separate output location and be
reported separately. Application `search()` is not equivalent to invoking the
V2.2 experimental scenario. Historical seed/rejection counts, original bootstrap
script and raw individual timing calls remain unavailable. Human free-text
ratings are not included in this code/lexical-data public-release package.
