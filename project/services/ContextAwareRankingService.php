<?php
/**
 * Context-aware GLOSS ranking V2.1: research-only, no gold labels or source IDs.
 * Deliberately a tie-breaker WITHIN the legacy lexical matching band.
 * This does not solve semantic ambiguity for very generic single-token queries.
 */
final class ContextAwareRankingService
{
    public const VERSION = 'gloss_specificity_v2_1_preregistered';

    private array $glosses = [];
    private array $documentFrequency = [];
    private int $totalDocuments = 0;

    public function prepare(array $preparedSnapshot): void
    {
        $this->glosses = [];
        $this->documentFrequency = [];
        $this->totalDocuments = 0;
        foreach ($preparedSnapshot as $item) {
            $row = $item['row'] ?? null;
            if (!is_array($row) || !isset($row['id'])) {
                throw new InvalidArgumentException('V2 ranking requires preloaded dictionary rows.');
            }
            $id = (int)$row['id'];
            $tokens = array_values(array_unique(array_map('strval', (array)($item['meaning_tokens'] ?? []))));
            $this->glosses[$id] = [
                'meaning' => (string)($item['normalized_meaning'] ?? ''),
                'tokens' => $tokens,
            ];
            foreach ($tokens as $token) {
                $this->documentFrequency[$token] = ($this->documentFrequency[$token] ?? 0) + 1;
            }
            $this->totalDocuments++;
        }
    }

    /**
     * Annotate candidates and deterministically sort them. Legacy final_score
     * remains the FIRST ranking key: match tier and distance are unchanged.
     * Context evidence only breaks ties within the same legacy final_score.
     * No explicit source_dictionary_id or gold labels appear in this method.
     */
    public function rank(string $normalizedQuery, array $significantQueryTokens, array $candidates): array
    {
        if ($this->totalDocuments === 0) {
            throw new RuntimeException('V2 gloss ranking index not initialized.');
        }
        $queryTokens = array_values(array_unique(array_map('strval', $significantQueryTokens)));
        $queryTokenCount = count($queryTokens);
        foreach ($candidates as &$candidate) {
            $id = (int)($candidate['id'] ?? 0);
            $gloss = $this->glosses[$id] ?? ['meaning'=>'', 'tokens'=>[]];
            $tokens = $gloss['tokens'];
            $tokenLookup = array_fill_keys($tokens, true);
            $overlap = 0;
            $idfSum = 0.0;
            foreach ($queryTokens as $queryToken) {
                if (isset($tokenLookup[$queryToken])) {
                    $overlap++;
                    // Smoothed IDF: based only on validated corpus lexical frequencies.
                    $idfSum += log(1.0 + (($this->totalDocuments + 1.0) /
                        (($this->documentFrequency[$queryToken] ?? 0) + 1.0)));
                }
            }
            $coverage = $queryTokenCount > 0 ? $overlap / $queryTokenCount : 0.0;
            $specificity = count($tokens) > 0 ? $overlap / count($tokens) : 0.0;
            $completePhrase = $queryTokenCount > 1 && $this->containsWholePhrase(
                $gloss['meaning'], $normalizedQuery
            );
            $startsWith = $queryTokenCount > 0 && isset($tokens[0]) && $tokens[0] === $queryTokens[0];
            $matchesPhrase = ($candidate['matched_on'] ?? '') === 'indonesian_phrase';
            // Fixed, hypothesis-driven weights. Do not fit these on silver audit cases.
            // A one-word query cannot disambiguate among equal-sense dictionary IDs.
            $score = 2.5 * $coverage
                + 1.5 * $specificity
                + ($completePhrase ? 1.8 : 0.0)
                + ($startsWith ? 0.25 : 0.0)
                + ($matchesPhrase ? 0.5 : 0.0)
                + 0.10 * $idfSum;
            $candidate['v2_context_score'] = round($score, 6);
            $candidate['v2_context_trace'] = [
                'token_coverage' => round($coverage, 6),
                'gloss_specificity' => round($specificity, 6),
                'contiguous_phrase' => $completePhrase,
                'gloss_starts_with_query_token' => $startsWith,
                'idf_sum' => round($idfSum, 6),
                'gloss_token_count' => count($tokens),
                'ranking_version' => self::VERSION
            ];
        }
        unset($candidate);
        usort($candidates, static function (array $left, array $right): int {
            $score = ($left['final_score'] ?? 0) <=> ($right['final_score'] ?? 0);
            if ($score !== 0) return $score;
            $context = ($right['v2_context_score'] ?? 0) <=> ($left['v2_context_score'] ?? 0);
            if ($context !== 0) return $context;
            $similarity = ($right['similarity_percentage'] ?? 0) <=> ($left['similarity_percentage'] ?? 0);
            if ($similarity !== 0) return $similarity;
            return ((int)($left['id'] ?? 0)) <=> ((int)($right['id'] ?? 0));
        });
        return $candidates;
    }

    private function containsWholePhrase(string $meaning, string $query): bool
    {
        if ($query === '' || $meaning === '') return false;
        // Unicode letter/digit boundaries, not substring inside another token.
        return preg_match('~(?<![\\p{L}\\p{N}])'.preg_quote($query, '~').'(?![\\p{L}\\p{N}])~u', $meaning) === 1;
    }

    public function getConfiguration(): array
    {
        return [
            'version' => self::VERSION,
            'primary_rank' => 'original_final_score_ascending',
            'tie_break' => 'context_score_desc_similarity_desc_id_asc',
            'weights' => [
                'token_coverage' => 2.5,
                'gloss_specificity' => 1.5,
                'contiguous_phrase' => 1.8,
                'starts_with_query_token' => 0.25,
                'matched_on_phrase' => 0.5,
                'idf_sum' => 0.10
            ],
            'scope' => 'experiment_id_to_tolour_only',
            'no_relevance_labels_used' => true
        ];
    }
}
