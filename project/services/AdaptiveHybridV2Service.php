<?php
/**
 * Experimental V2 entry-point. V1 is never replaced in public application.
 * The V2.1 change is confined to lexical evidence tie-breaking for gloss lookup.
 */
require_once __DIR__.'/SearchService.php';
final class AdaptiveHybridV2Service
{
    private SearchService $search;
    public function __construct(SearchService $search)
    {
        if ($search->getRankingMode() !== RankingService::MODE_EXPERIMENT) {
            throw new RuntimeException('AdaptiveHybridV2Service is research-only.');
        }
        $this->search = $search;
    }
    public function search(string $keyword, string $direction, string $scenario = 'v2_context_rank'): array
    {
        if (!str_starts_with($scenario, 'v2_') && !str_starts_with($scenario, 'v22_')) {
            throw new InvalidArgumentException('V2 service accepts only v2_* or v22_* registry IDs.');
        }
        return $this->search->searchExperimental($keyword, $scenario, $direction);
    }
}
