<?php
/**
 * Isolated research-only scenario definitions. The seven legacy benchmark
 * configurations in BenchmarkProgressService are deliberately untouched.
 *
 * Experiment IDs are stable identifiers; never choose parameter values from
 * gold-standard relevance labels or search outcomes.
 */
final class ExperimentScenarioRegistry
{
    public static function all(): array
    {
        return [
            'edr_v3_conservative' => [
                'label'=>'EDR-V3 evidence-driven conservative (prototype)',
                'threshold_policy'=>'evidence','fixed_threshold'=>null,
                'distance_model'=>'levenshtein','prefix'=>true,
                'precision_guard'=>true,'transposition_preference'=>true,
                'ranking_policy'=>'context_v2','edit_policy'=>'short_adjacent_swap_fast',
                'selection_policy'=>'evidence_conservative',
            ],
            'edr_v3_ambiguity' => [
                'label'=>'EDR-V3 controlled ambiguity relaxation (prototype)',
                'threshold_policy'=>'evidence','fixed_threshold'=>null,
                'distance_model'=>'levenshtein','prefix'=>true,
                'precision_guard'=>true,'transposition_preference'=>true,
                'ranking_policy'=>'context_v2','edit_policy'=>'short_adjacent_swap_fast',
                'selection_policy'=>'evidence_ambiguity',
            ],
            'adaptive_full' => [
                'label'=>'Adaptive Hybrid (frozen policy)', 'threshold_policy'=>'adaptive',
                'fixed_threshold'=>null, 'distance_model'=>'levenshtein',
                'prefix'=>true, 'precision_guard'=>true, 'transposition_preference'=>true,
            ],
            'adaptive_no_guard' => [
                'label'=>'Adaptive, no precision guard', 'threshold_policy'=>'adaptive',
                'fixed_threshold'=>null, 'distance_model'=>'levenshtein',
                'prefix'=>true, 'precision_guard'=>false, 'transposition_preference'=>false,
            ],
            'adaptive_guard_no_trans' => [
                'label'=>'Adaptive, guard without transposition preference',
                'threshold_policy'=>'adaptive', 'fixed_threshold'=>null,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>false,
            ],
            'fixed2_guard_trans' => [
                'label'=>'Fixed threshold 2, guard with transposition preference',
                'threshold_policy'=>'fixed', 'fixed_threshold'=>2,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
            ],
            'fixed2_guard_no_trans' => [
                'label'=>'Fixed threshold 2, guard without transposition preference',
                'threshold_policy'=>'fixed', 'fixed_threshold'=>2,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>false,
            ],
            'fixed2_no_guard' => [
                'label'=>'Fixed threshold 2, original hybrid',
                'threshold_policy'=>'fixed', 'fixed_threshold'=>2,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>false, 'transposition_preference'=>false,
            ],
            'fixed1_guard_trans' => [
                'label'=>'Fixed threshold 1, same guard and transposition preference',
                'threshold_policy'=>'fixed', 'fixed_threshold'=>1,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
            ],
            'fixed3_guard_trans' => [
                'label'=>'Fixed threshold 3, same guard and transposition preference',
                'threshold_policy'=>'fixed', 'fixed_threshold'=>3,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
            ],
            'v2_context_rank' => [
                'label'=>'V2.1 Context-Aware Ranking (proposed experimental)',
                'threshold_policy'=>'adaptive', 'fixed_threshold'=>null,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
                'ranking_policy'=>'context_v2'
            ],
            'v2_context_no_guard' => [
                'label'=>'V2.1 Context Ranking WITHOUT guard (ablation)',
                'threshold_policy'=>'adaptive', 'fixed_threshold'=>null,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>false, 'transposition_preference'=>false,
                'ranking_policy'=>'context_v2'
            ],
            'v2_context_no_trans' => [
                'label'=>'V2.1 Context Ranking WITHOUT transposition preference (ablation)',
                'threshold_policy'=>'adaptive', 'fixed_threshold'=>null,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>false,
                'ranking_policy'=>'context_v2'
            ],
            // V2.2 adds only short adjacent swap recognition to V2.1.
            'v22_context_short_swap' => [
                'label'=>'V2.2 context ranking + short adjacent swap (experimental)',
                'threshold_policy'=>'adaptive', 'fixed_threshold'=>null,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
                'ranking_policy'=>'context_v2', 'edit_policy'=>'short_adjacent_swap',
            ],
            // Optimized V2.2: same match policy, cached adjacent variants.
            'v22_context_short_swap_fast' => [
                'label'=>'V2.2 short swap optimized (same retrieval policy)',
                'threshold_policy'=>'adaptive', 'fixed_threshold'=>null,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
                'ranking_policy'=>'context_v2', 'edit_policy'=>'short_adjacent_swap_fast',
            ],
            // V2.3 only alters tie-breaking on multi-token Indonesian glosses.
            'v23_gloss_proximity_fast' => [
                'label'=>'V2.3 ordered gloss proximity + cached short swap (experimental)',
                'threshold_policy'=>'adaptive', 'fixed_threshold'=>null,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
                'ranking_policy'=>'gloss_proximity_v23', 'edit_policy'=>'short_adjacent_swap_fast',
            ],
            'v23_gloss_proximity_no_swap' => [
                'label'=>'V2.3 gloss proximity without short swap (ablation)',
                'threshold_policy'=>'adaptive', 'fixed_threshold'=>null,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
                'ranking_policy'=>'gloss_proximity_v23',
            ],
            'fixed2_gloss_proximity_guard' => [
                'label'=>'Fixed-2 + V2.3 gloss proximity + guard (fair control)',
                'threshold_policy'=>'fixed', 'fixed_threshold'=>2,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
                'ranking_policy'=>'gloss_proximity_v23',
            ],
            'fixed3_gloss_proximity_guard' => [
                'label'=>'Fixed-3 + V2.3 gloss proximity + guard (fair control)',
                'threshold_policy'=>'fixed', 'fixed_threshold'=>3,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
                'ranking_policy'=>'gloss_proximity_v23',
            ],
            'fixed2_osa_gloss_proximity_guard' => [
                'label'=>'Fixed-2 OSA + V2.3 gloss proximity + guard (fair control)',
                'threshold_policy'=>'fixed', 'fixed_threshold'=>2,
                'distance_model'=>'osa', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
                'ranking_policy'=>'gloss_proximity_v23',
            ],
            'v22_short_swap_legacy_rank' => [
                'label'=>'V2.2 short swap, original ranking (ranking ablation)',
                'threshold_policy'=>'adaptive', 'fixed_threshold'=>null,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
                'edit_policy'=>'short_adjacent_swap',
            ],
            'v22_context_short_swap_no_guard' => [
                'label'=>'V2.2 context + short swap WITHOUT precision guard',
                'threshold_policy'=>'adaptive', 'fixed_threshold'=>null,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>false, 'transposition_preference'=>false,
                'ranking_policy'=>'context_v2', 'edit_policy'=>'short_adjacent_swap',
            ],
            // Equal ranking/guard fixed baselines for fair comparison.
            'fixed2_context_guard' => [
                'label'=>'Fixed threshold 2 + context ranking + guard',
                'threshold_policy'=>'fixed', 'fixed_threshold'=>2,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
                'ranking_policy'=>'context_v2',
            ],
            'fixed3_context_guard' => [
                'label'=>'Fixed threshold 3 + context ranking + guard',
                'threshold_policy'=>'fixed', 'fixed_threshold'=>3,
                'distance_model'=>'levenshtein', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
                'ranking_policy'=>'context_v2',
            ],
            'fixed2_osa_context_guard' => [
                'label'=>'Fixed threshold 2 + OSA + context ranking + guard',
                'threshold_policy'=>'fixed', 'fixed_threshold'=>2,
                'distance_model'=>'osa', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>true,
                'ranking_policy'=>'context_v2',
            ],
            'fixed2_osa_guard' => [
                'label'=>'Fixed threshold 2, OSA distance and guard',
                'threshold_policy'=>'fixed', 'fixed_threshold'=>2,
                'distance_model'=>'osa', 'prefix'=>true,
                'precision_guard'=>true, 'transposition_preference'=>false,
            ],
        ];
    }

    public static function get(string $id): array
    {
        $all = self::all();
        if (!isset($all[$id])) {
            throw new InvalidArgumentException('Unknown experimental scenario: '.$id);
        }
        return $all[$id] + ['id'=>$id];
    }

    public static function validateConfiguration(array $configuration): void
    {
        $keys = [
            'threshold_policy', 'fixed_threshold', 'distance_model', 'prefix',
            'precision_guard', 'transposition_preference'
        ];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $configuration)) {
                throw new InvalidArgumentException('Experimental parameter missing: '.$key);
            }
        }
        if (!in_array($configuration['threshold_policy'], ['adaptive','fixed','evidence'], true)) {
            throw new InvalidArgumentException('Invalid threshold_policy');
        }
        if (!in_array($configuration['distance_model'], ['levenshtein','osa'], true)) {
            throw new InvalidArgumentException('Invalid distance_model');
        }
        if ($configuration['threshold_policy']==='fixed' && (
            !is_int($configuration['fixed_threshold']) || $configuration['fixed_threshold']<0
            || $configuration['fixed_threshold']>10
        )) {
            throw new InvalidArgumentException('Invalid fixed_threshold');
        }
        if (in_array($configuration['threshold_policy'], ['adaptive','evidence'], true) && $configuration['fixed_threshold']!==null) {
            throw new InvalidArgumentException('Adaptive/evidence threshold requires null fixed_threshold');
        }
        if (array_key_exists('selection_policy', $configuration)) {
            if ($configuration['threshold_policy'] !== 'evidence'
                || !in_array($configuration['selection_policy'], ['evidence_conservative','evidence_ambiguity'],true)
                || !$configuration['precision_guard']
                || $configuration['ranking_policy'] !== 'context_v2'
                || $configuration['distance_model'] !== 'levenshtein') {
                throw new InvalidArgumentException('Evidence-driven scenario must use matching configuration.');
            }
        } elseif ($configuration['threshold_policy'] === 'evidence') {
            throw new InvalidArgumentException('Evidence-driven requires selection_policy.');
        }
        if (array_key_exists('ranking_policy', $configuration)
            && !in_array($configuration['ranking_policy'], ['legacy', 'context_v2', 'gloss_proximity_v23'], true)) {
            throw new InvalidArgumentException('Invalid ranking_policy');
        }
        if (array_key_exists('edit_policy', $configuration)
            && !in_array($configuration['edit_policy'], ['standard','short_adjacent_swap','short_adjacent_swap_fast'], true)) {
            throw new InvalidArgumentException('Invalid edit_policy');
        }
        if (in_array(($configuration['edit_policy'] ?? 'standard'), ['short_adjacent_swap', 'short_adjacent_swap_fast'], true)
            && ($configuration['distance_model'] !== 'levenshtein'
                || !in_array($configuration['threshold_policy'], ['adaptive','evidence'],true))) {
            throw new InvalidArgumentException('Short-swap policy requires adaptive Levenshtein');
        }
        foreach (['prefix','precision_guard','transposition_preference'] as $key) {
            if (!is_bool($configuration[$key])) {
                throw new InvalidArgumentException('Experimental parameter must be boolean: '.$key);
            }
        }
        if ($configuration['transposition_preference'] && !$configuration['precision_guard']) {
            throw new InvalidArgumentException('Transposition preference is only meaningful with precision guard');
        }
        if (!$configuration['prefix']) {
            throw new InvalidArgumentException('Research scenario requires prefix matching');
        }
    }
}
