<?php
/**
 * V2.3 EXPERIMENTAL proximity reranker, Indonesian => Toulour only.
 *
 * Lexical evidence ONLY; no gold labels, expected IDs, source anchors,
 * popularity or user history. Run after the frozen V2.1 reranker.
 * Legacy final_score matching tiers remain the highest-priority comparison.
 *
 * For a SINGLE significant token the whole V2.1 ranking is returned unchanged:
 * a generic word cannot identify an arbitrary dictionary source entry.
 *
 * For >=2 significant tokens prefer exact tokens appearing in the original
 * order within a compact glossary window. If unavailable, prefer higher
 * exact-token coverage. The frozen V2.1 rank breaks remaining ties.
 */
final class GlossProximityRankingService
{
    public const VERSION = 'gloss_ordered_proximity_v2_3_exploratory_v1';
    private array $byId = [];
    private bool $prepared = false;

    public function prepare(array $snapshot): void
    {
        $index = [];
        foreach ($snapshot as $item) {
            $row = $item['row'] ?? null;
            if (!is_array($row) || !isset($row['id'])) {
                throw new InvalidArgumentException('V2.3 requires dictionary snapshot rows.');
            }
            $id = (int)$row['id'];
            if ($id <= 0 || isset($index[$id])) throw new InvalidArgumentException('V2.3 invalid/duplicate ID.');
            // Re-tokenize the ORIGINAL normalized gloss with positions intact.
            // The V2.1 'meaning_tokens' array is UNIQUE and cannot represent
            // repeated words or the true distance across Indonesian stopwords.
            $gloss = (string)($item['normalized_meaning'] ?? '');
            $matches = [];
            preg_match_all('/[\p{L}\p{N}]+/u', $gloss, $matches);
            $index[$id] = array_values((array)($matches[0] ?? []));
        }
        $this->byId = $index;
        $this->prepared = true;
    }

    /**
     * CANDIDATES MUST ALREADY HAVE V2.1 ranking. The old input position is the
     * deterministic final tie-break. Never change candidate membership.
     */
    public function rerank(array $queryTokens, array $v21Candidates): array
    {
        if (!$this->prepared) throw new RuntimeException('V2.3 index not prepared');
        $tokens = array_values(array_unique(array_map('strval', $queryTokens)));
        if (count($tokens) < 2) return $v21Candidates;

        $items = [];
        foreach ($v21Candidates as $position => $candidate) {
            $id = (int)($candidate['id'] ?? 0);
            $gloss = $this->byId[$id] ?? [];
            $positions = [];
            foreach ($tokens as $queryIndex => $token) {
                $positions[$queryIndex] = [];
                foreach ($gloss as $glossIndex => $glossToken) {
                    if ($token === $glossToken) $positions[$queryIndex][] = $glossIndex;
                }
            }
            $exactCount = count(array_filter($positions, static fn($p) => count($p) > 0));
            $span = $this->shortestOrderedSpan($positions);
            $ordered = $span !== null;
            $gap = $ordered ? ($span - count($tokens)) : null;
            $candidate['v23_gloss_trace'] = [
                'version' => self::VERSION,
                'query_significant_token_count' => count($tokens),
                'exact_token_coverage_count' => $exactCount,
                'ordered_full_exact_match' => $ordered,
                'minimum_ordered_gap' => $gap,
                'previous_v21_rank' => $position + 1,
                'fallback_to_v21' => !$ordered,
            ];
            $items[] = ['row' => $candidate, 'position' => $position,
                'final_score' => (float)($candidate['final_score'] ?? 0),
                'exact' => $exactCount, 'ordered' => $ordered, 'gap' => $gap];
        }
        usort($items, static function (array $a, array $b): int {
            // Strictly retain old exact / prefix / fuzzy tier and edit-score ordering.
            $cmp = $a['final_score'] <=> $b['final_score'];
            if ($cmp !== 0) return $cmp;
            // Complete ordered evidence before incomplete/unordered evidence.
            $cmp = ((int)$b['ordered']) <=> ((int)$a['ordered']);
            if ($cmp !== 0) return $cmp;
            // When both cover a query in order, the shortest span is preferred.
            if ($a['ordered'] && $b['ordered']) {
                $cmp = $a['gap'] <=> $b['gap'];
                if ($cmp !== 0) return $cmp;
            }
            $cmp = $b['exact'] <=> $a['exact'];
            if ($cmp !== 0) return $cmp;
            // Deterministic fallback to V2.1, including its original contextual score.
            return $a['position'] <=> $b['position'];
        });
        return array_map(static fn($a) => $a['row'], $items);
    }

    /**
     * Small dynamic program over exact-token positions, strictly increasing.
     * Returns the shortest inclusive token window across all query tokens.
     * No combinatorial enumeration, repeated token positions cannot be reused.
     */
    private function shortestOrderedSpan(array $positions): ?int
    {
        if ($positions === []) return null;
        foreach ($positions as $list) if (!$list) return null;
        $best = null;
        foreach ($positions[0] as $first) {
            $prev = $first;
            $good = true;
            for ($i = 1, $n = count($positions); $i < $n; $i++) {
                $next = null;
                foreach ($positions[$i] as $pos) {
                    if ($pos > $prev) { $next = $pos; break; }
                }
                if ($next === null) { $good = false; break; }
                $prev = $next;
            }
            if ($good) {
                $span = $prev - $first + 1;
                $best = $best === null ? $span : min($best, $span);
            }
        }
        return $best;
    }

    public function getConfiguration(): array
    {
        return [
            'version' => self::VERSION,
            'scope' => 'experiment_only_id_to_tolour_multitoken',
            'unchanged_for_significant_single_token' => true,
            'lexical_evidence' => 'ordered_exact_gloss_token_span_and_exact_coverage',
            'primary_rank' => 'legacy_final_score_ascending',
            'tie_break' => 'ordered_full_exact_then_smallest_gap_then_exact_coverage_then_v21_rank',
            'guard_policy_and_pre_guard_candidate_pool_unchanged' => true,
            'uses_labels_or_source_ids' => false,
        ];
    }
}
