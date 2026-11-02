<?php
/**
 * Research-only, label-free candidate arbitration. NOT a learned relevance model.
 * Two preregistered hypotheses are tested: conservative evidence-first (EDR-C)
 * and controlled ambiguity relaxation (EDR-A). No gold IDs or query types are read.
 */
final class EvidenceDrivenSelectionService
{
    public const VERSION = 'edr-v3-prototype-2026-10-19';
    private const LIMIT = 5;

    public function select(string $query, string $direction, array $candidates, bool $ambiguityRelaxation): array
    {
        $queryLength = $this->length($query);
        $counts = ['exact'=>0,'prefix'=>0,'fuzzy'=>0];
        foreach ($candidates as &$candidate) {
            $type = (string)($candidate['match_type'] ?? '');
            if (isset($counts[$type])) $counts[$type]++;
            if ($type === 'fuzzy' && $queryLength >= 2 && $queryLength <= 4
                && $this->adjacentSwap($query, (string)($candidate['matched_text'] ?? ''))) {
                $raw = (int)($candidate['edit_distance'] ?? 0);
                if ($raw > 1) {
                    $candidate['raw_levenshtein_edit_distance'] = $raw;
                    $candidate['edit_distance'] = 1;
                    $candidate['distance'] = 1;
                    $candidate['short_swap_rescued'] = true;
                    $candidate['effective_edit_model'] = 'levenshtein_plus_adjacent_swap';
                    $candidate['is_adjacent_transposition'] = true;
                    $candidate['similarity_percentage'] = round((1-1/$queryLength)*100,2);
                }
            }
            $similarity = (float)($candidate['similarity_percentage'] ?? 0) / 100;
            $candidate['edr_similarity'] = $similarity;
        }
        unset($candidate);
        $ambiguousExact = $ambiguityRelaxation && $direction === 'id_to_tolour'
            && $queryLength >= 5 && $counts['exact'] > self::LIMIT;
        $minFuzzy = null;
        foreach ($candidates as $candidate) {
            if (($candidate['match_type'] ?? '') === 'fuzzy') {
                $distance = (int)$candidate['edit_distance'];
                $minFuzzy = $minFuzzy === null ? $distance : min($minFuzzy, $distance);
            }
        }
        // A single exact finding is considered decisive. With >5 exact gloss
        // hits, only EDR-A may test high-confidence distance-one alternatives.
        $allowFuzzy = $counts['exact'] === 0 || $ambiguousExact;
        $hasPrefix = $counts['prefix'] > 0;
        $selected = [];
        $eligibleMinCount = 0;
        foreach ($candidates as $candidate) {
            $type = $candidate['match_type'] ?? '';
            if ($type !== 'fuzzy') continue;
            $distance = (int)$candidate['edit_distance'];
            $sim = $candidate['edr_similarity'];
            if ($allowFuzzy && $distance === $minFuzzy
                && ($sim >= 0.50 || !empty($candidate['is_adjacent_transposition']))) {
                $eligibleMinCount++;
            }
        }
        foreach ($candidates as $candidate) {
            $type = (string)($candidate['match_type'] ?? '');
            $sim = $candidate['edr_similarity'];
            $distance = (int)($candidate['edit_distance'] ?? PHP_INT_MAX);
            if ($type === 'exact') {
                $eligible = true;
            } elseif ($type === 'prefix') {
                $eligible = true;
            } else {
                $eligible = $allowFuzzy && (
                    ($ambiguousExact && $distance === 1 && $sim >= .85)
                    || ($distance === $minFuzzy && ($sim >= .50 || !empty($candidate['is_adjacent_transposition'])))
                    || (!$ambiguousExact && $counts['exact'] === 0
                        && ($counts['prefix'] + $eligibleMinCount) < self::LIMIT
                        && $distance === ($minFuzzy ?? 10) + 1 && $distance <= 3 && $sim >= .75)
                );
            }
            if (!$eligible) continue;
            // This evidence scale permits strong fuzzy evidence to outrank weak
            // prefixes, while preserving strict exact precedence unless an
            // Indonesian gloss has more than five exact interpretations.
            $score = $type === 'exact' ? ($ambiguousExact ? 82.0 : 100.0)
                : ($type === 'prefix' ? 75.0 + 25.0*$sim
                    : 30.0 + 65.0*$sim + (!empty($candidate['is_adjacent_transposition']) ? 5.0 : 0.0));
            $candidate['edr_evidence_score'] = round($score,6);
            $candidate['edr_selected_reason'] = $type === 'exact' ? 'exact'
                : ($type === 'prefix' ? 'prefix'
                    : ($ambiguousExact ? 'ambiguous_exact_distance1_rescue'
                        : ($distance === $minFuzzy ? 'nearest_fuzzy' : 'evidence_fuzzy_expansion')));
            $selected[] = $candidate;
        }
        usort($selected, static function(array $a,array $b): int {
            $cmp = $b['edr_evidence_score'] <=> $a['edr_evidence_score'];
            if ($cmp !== 0) return $cmp;
            $ctx = ($b['v2_context_score'] ?? 0.0) <=> ($a['v2_context_score'] ?? 0.0);
            if ($ctx !== 0) return $ctx;
            $cmp = ($a['edit_distance'] ?? 0) <=> ($b['edit_distance'] ?? 0);
            if ($cmp !== 0) return $cmp;
            return ((int)$a['id']) <=> ((int)$b['id']);
        });
        $allCount = count($selected);
        $selected = array_slice($selected,0,self::LIMIT);
        foreach ($selected as &$r) {
            $r['adaptive_guard_rule'] = 'evidence_driven_v3';
            $r['adaptive_guard_before_count'] = count($candidates);
            $r['adaptive_guard_after_count'] = count($selected);
        }
        unset($r);
        return [$selected,[
            'applied'=>true, 'version'=>self::VERSION,'rule'=>'evidence_driven_v3',
            'before_count'=>count($candidates), 'eligible_count'=>$allCount,
            'after_count'=>count($selected),'candidate_tiers'=>$counts,
            'minimum_fuzzy_distance'=>$minFuzzy, 'ambiguous_exact'=>$ambiguousExact,
            'ambiguity_relaxation_enabled'=>$ambiguityRelaxation
        ]];
    }

    private function length(string $text): int
    {
        if (function_exists('mb_strlen')) return mb_strlen($text,'UTF-8');
        $c=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY);
        return $c===false ? strlen($text) : count($c);
    }

    private function adjacentSwap(string $source, string $target): bool
    {
        $a=preg_split('//u',$source,-1,PREG_SPLIT_NO_EMPTY);
        $b=preg_split('//u',$target,-1,PREG_SPLIT_NO_EMPTY);
        if ($a===false || $b===false || count($a)!==count($b) || count($a)<2) return false;
        $diff=[];
        foreach ($a as $i=>$value) {
            if ($value!==$b[$i]) $diff[]=$i;
            if (count($diff)>2) return false;
        }
        return count($diff)===2 && $diff[1]===$diff[0]+1
            && $a[$diff[0]]===$b[$diff[1]] && $a[$diff[1]]===$b[$diff[0]];
    }
}
