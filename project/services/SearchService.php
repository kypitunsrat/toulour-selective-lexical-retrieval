<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/RankingService.php';
require_once __DIR__ . '/ExperimentScenarioRegistry.php';
require_once __DIR__ . '/ContextAwareRankingService.php';
require_once __DIR__ . '/GlossProximityRankingService.php';
require_once __DIR__ . '/EvidenceDrivenSelectionService.php';

/**
 * SearchService
 *
 * Mesin pencarian Kamus Digital Toulour, scientific hardening Langkah 16.
 *
 * Kompatibilitas:
 * - Signature empat parameter lama tetap dapat digunakan.
 * - Field lama distance, confidence, dan is_prefix tetap dikembalikan.
 * - Field baru match_type, edit_distance, completion_length,
 *   similarity_percentage, threshold_used, matched_on, dan matched_text
 *   ditambahkan agar hasil dapat dijelaskan dan dievaluasi dengan benar.
 */
class SearchService
{
    private const RESULT_LIMIT = 5;
    private const ADAPTIVE_PRECISION_GUARD_VERSION = 'operation_aware_nearest_band_v2';
    private const DIRECTION_TOLOUR_TO_ID = 'tolour_to_id';
    private const DIRECTION_ID_TO_TOLOUR = 'id_to_tolour';

    private $db;
    private $rankingService;
    private ?array $experimentCandidateSnapshot = null;
    private ?int $experimentSnapshotCategoryId = null;
    private array $experimentExactMatchCache = [];
    private array $experimentTextLengthCache = [];
    private array $experimentAsciiCache = [];
    private ?ContextAwareRankingService $experimentalV2Ranking = null;
    private ?GlossProximityRankingService $experimentalV23Ranking = null;
    /** Research-only short swap variants computed once per distinct short query. */
    private array $experimentFastSwapCache = [];

    private array $indonesianStopwords = [
        'yang', 'di', 'ke', 'dari', 'pada', 'dalam', 'untuk', 'dengan',
        'dan', 'atau', 'ini', 'itu', 'adalah', 'sebuah', 'suatu', 'tentang',
        'seperti', 'sangat', 'paling', 'yg'
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->rankingService = new RankingService();
    }

    /**
     * Mengubah mode ranking tanpa mengekspos objek RankingService.
     *
     * Mode application digunakan oleh pencarian publik. Mode experiment
     * menonaktifkan pengaruh popularity dan search_count agar benchmark
     * dapat direplikasi dengan dataset dan konfigurasi yang sama.
     */
    public function setRankingMode($mode): self
    {
        $this->rankingService->setMode($mode);
        return $this;
    }

    public function getRankingMode(): string
    {
        return $this->rankingService->getMode();
    }

    /**
     * Konfigurasi guard dipublikasikan agar setiap benchmark dapat merekam
     * aturan Adaptive Hybrid yang benar-benar digunakan.
     */
    public function getAdaptivePrecisionGuardConfiguration(): array
    {
        return [
            'version' => self::ADAPTIVE_PRECISION_GUARD_VERSION,
            'exact_policy' => 'exact_and_prefix_only',
            'prefix_policy' => 'exact_and_prefix_only',
            'fuzzy_policy' => 'minimum_edit_distance_tier',
            'transposition_policy' => 'prefer_single_adjacent_transposition_within_nearest_tier',
            'transposition_scope' => 'adaptive_hybrid_only',
            'max_results' => self::RESULT_LIMIT
        ];
    }

    /**
     * Memuat dan menormalisasi korpus sekali untuk sekumpulan pencarian
     * benchmark dalam request yang sama. Fitur ini hanya aktif pada mode
     * experiment sehingga perilaku pencarian publik tidak berubah.
     */
    public function prepareExperimentSnapshot($categoryId = null): array
    {
        if ($this->rankingService->getMode() !== RankingService::MODE_EXPERIMENT) {
            throw new RuntimeException('Snapshot korpus hanya boleh digunakan pada mode experiment.');
        }

        $categoryId = $this->normalizeCategoryId($categoryId);
        if (
            $this->experimentCandidateSnapshot !== null
            && $this->experimentSnapshotCategoryId === $categoryId
        ) {
            return [
                'candidate_count' => count($this->experimentCandidateSnapshot),
                'preparation_ms' => 0.0,
                'reused' => true
            ];
        }

        $startedAt = $this->startTimer();
        $prepared = [];
        foreach ($this->findApprovedCandidates($categoryId) as $row) {
            $normalizedTolour = $this->normalizeText((string) $row['tolour_word']);
            $normalizedMeaning = $this->normalizeText((string) $row['indonesian_meaning']);
            $meaningTokens = $this->tokenizeIndonesian($normalizedMeaning);
            $this->cacheExperimentTextMetadata($normalizedTolour);
            $this->cacheExperimentTextMetadata($normalizedMeaning);
            foreach ($meaningTokens as $meaningToken) {
                $this->cacheExperimentTextMetadata((string) $meaningToken);
            }
            $prepared[] = [
                'row' => $row,
                'normalized_tolour' => $normalizedTolour,
                'normalized_meaning' => $normalizedMeaning,
                'meaning_tokens' => $meaningTokens
            ];
        }

        $this->experimentCandidateSnapshot = $prepared;
        $this->experimentSnapshotCategoryId = $categoryId;
        $this->experimentExactMatchCache = [];
        $this->experimentalV2Ranking = null;
        $this->experimentalV23Ranking = null;
        $this->experimentFastSwapCache = [];

        return [
            'candidate_count' => count($prepared),
            'preparation_ms' => $this->elapsedMilliseconds($startedAt),
            'reused' => false
        ];
    }

    public function clearExperimentSnapshot(): void
    {
        $this->experimentCandidateSnapshot = null;
        $this->experimentSnapshotCategoryId = null;
        $this->experimentExactMatchCache = [];
        $this->experimentTextLengthCache = [];
        $this->experimentAsciiCache = [];
        $this->experimentalV2Ranking = null;
        $this->experimentalV23Ranking = null;
        $this->experimentFastSwapCache = [];
    }

    /**
     * Entry point khusus eksperimen. Tidak dipanggil pencarian publik.
     * Parameter per komponen divalidasi registry, bukan berdasarkan gold labels.
     */
    public function searchExperimental(
        string $inputKeyword,
        string $scenarioId,
        string $direction = self::DIRECTION_TOLOUR_TO_ID,
        $categoryId = null
    ): array {
        if ($this->getRankingMode() !== RankingService::MODE_EXPERIMENT) {
            throw new RuntimeException('searchExperimental requires experiment ranking mode.');
        }
        $config = ExperimentScenarioRegistry::get($scenarioId);
        return $this->search(
            $inputKeyword,
            $config['fixed_threshold'],
            $config['prefix'],
            $direction,
            $categoryId,
            $config
        );
    }

    /**
     * Melakukan pencarian kamus.
     *
     * @param string   $inputKeyword  Query pengguna.
     * @param int|null $forceThreshold Nilai tetap untuk eksperimen. Null memakai threshold adaptif.
     * @param bool     $usePrefix     Aktifkan pencocokan prefix/autocomplete.
     * @param string   $direction     tolour_to_id atau id_to_tolour.
     * @param int|null $categoryId    Filter kategori opsional; belum wajib dikirim controller lama.
     */
    public function search(
        $inputKeyword,
        $forceThreshold = null,
        $usePrefix = true,
        $direction = self::DIRECTION_TOLOUR_TO_ID,
        $categoryId = null,
        ?array $experimentOptions = null
    ) {
        if ($experimentOptions !== null) {
            if ($this->getRankingMode() !== RankingService::MODE_EXPERIMENT) {
                throw new RuntimeException('Experimental configurations are not allowed in public search.');
            }
            ExperimentScenarioRegistry::validateConfiguration($experimentOptions);
            if ($experimentOptions['threshold_policy'] === 'adaptive' && $forceThreshold !== null) {
                throw new InvalidArgumentException('Adaptive research scenario cannot force a threshold.');
            }
            if ($experimentOptions['threshold_policy'] === 'fixed'
                && $forceThreshold !== $experimentOptions['fixed_threshold']) {
                throw new InvalidArgumentException('Fixed research threshold mismatch.');
            }
            if ((bool)$usePrefix !== $experimentOptions['prefix']) {
                throw new InvalidArgumentException('Research prefix parameter mismatch.');
            }
        }
        $startTime = $this->startTimer();
        $direction = $this->normalizeDirection($direction);
        $keyword = $this->normalizeText((string) $inputKeyword);
        $keywordLength = $this->textLength($keyword);
        $categoryId = $this->normalizeCategoryId($categoryId);

        if ($keywordLength === 0) {
            return [
                'data' => [],
                'time_ms' => 0,
                'meta' => $this->buildMeta($direction, 0, (bool) $usePrefix, $forceThreshold, $categoryId)
            ];
        }

        // EDR-V3 candidate discovery is deliberately broad (distance <= 3).
        // Actual acceptance and ranking are determined by observed lexical evidence.
        $evidenceDriven = ($experimentOptions['threshold_policy'] ?? '') === 'evidence';
        $threshold = $evidenceDriven ? 3 : $this->resolveThreshold($keywordLength, $forceThreshold);
        // For EDR, do not reintroduce length-only token thresholds in candidate discovery.
        $usesAdaptiveThreshold = !$evidenceDriven && ($forceThreshold === null || !is_numeric($forceThreshold));
        $detectTransposition = $experimentOptions !== null
            ? $experimentOptions['transposition_preference']
            : $usesAdaptiveThreshold && (bool) $usePrefix;
        $usePrecisionGuard = $experimentOptions !== null
            ? $experimentOptions['precision_guard']
            : $usesAdaptiveThreshold && (bool) $usePrefix;
        $distanceModel = $experimentOptions['distance_model'] ?? 'levenshtein';
        $rankingPolicy = $experimentOptions['ranking_policy'] ?? 'legacy';
        $v23ProximityRanking = $rankingPolicy === 'gloss_proximity_v23';
        $v2ContextRanking = $rankingPolicy === 'context_v2' || $v23ProximityRanking;
        // V2.2 experiment only: permit precisely one adjacent swap on strings
        // of 2-4 Unicode characters, without raising generic edit tolerance.
        $editPolicy = $experimentOptions['edit_policy'] ?? 'standard';
        $v22ShortSwap = in_array($editPolicy, ['short_adjacent_swap', 'short_adjacent_swap_fast'], true);
        $fastShortSwap = $editPolicy === 'short_adjacent_swap_fast';
        if ($v2ContextRanking && $direction === self::DIRECTION_ID_TO_TOLOUR) {
            if ($this->experimentCandidateSnapshot === null
                || $this->experimentSnapshotCategoryId !== $categoryId) {
                throw new RuntimeException('V2 requires prepareExperimentSnapshot() before benchmarking.');
            }
            if ($this->experimentalV2Ranking === null) {
                $this->experimentalV2Ranking = new ContextAwareRankingService();
                $this->experimentalV2Ranking->prepare($this->experimentCandidateSnapshot);
            }
            if ($v23ProximityRanking && $this->experimentalV23Ranking === null) {
                $this->experimentalV23Ranking = new GlossProximityRankingService();
                $this->experimentalV23Ranking->prepare($this->experimentCandidateSnapshot);
            }
        }

        // Exact match diberi prioritas, tetapi pencarian tetap dilanjutkan agar
        // homonim/varian seperti "siri", "siri (2)", dan "siri (3)" tidak hilang.
        $exactResults = $this->findExactMatches($keyword, $direction, $categoryId);
        $matchedResults = [];
        foreach ($this->formatExactResults($exactResults, $threshold, $direction) as $exactResult) {
            $matchedResults[(int) $exactResult['id']] = $exactResult;
        }

        $usesPreparedSnapshot = $this->experimentCandidateSnapshot !== null
            && $this->experimentSnapshotCategoryId === $categoryId;
        $allWords = $usesPreparedSnapshot
            ? $this->experimentCandidateSnapshot
            : $this->findApprovedCandidates($categoryId);
        $queryTokens = $direction === self::DIRECTION_ID_TO_TOLOUR
            ? $this->tokenizeIndonesian($keyword)
            : [];

        foreach ($allWords as $candidate) {
            $row = $usesPreparedSnapshot ? $candidate['row'] : $candidate;
            if ($direction === self::DIRECTION_TOLOUR_TO_ID) {
                $wordTarget = $usesPreparedSnapshot
                    ? (string) $candidate['normalized_tolour']
                    : $this->normalizeText((string) $row['tolour_word']);
                $match = $this->evaluateHybrid(
                    $keyword,
                    $wordTarget,
                    $row,
                    $threshold,
                    (bool) $usePrefix,
                    'tolour_word',
                    $detectTransposition,
                    $distanceModel,
                    $v22ShortSwap,
                    $fastShortSwap,
                    $evidenceDriven
                );
            } else {
                $match = $this->evaluateIndonesianMeaning(
                    $keyword,
                    $queryTokens,
                    $row,
                    $threshold,
                    (bool) $usePrefix,
                    $usesAdaptiveThreshold,
                    $usesPreparedSnapshot ? (string) $candidate['normalized_meaning'] : null,
                    $usesPreparedSnapshot ? (array) $candidate['meaning_tokens'] : null,
                    $detectTransposition,
                    $distanceModel,
                    $v22ShortSwap,
                    $fastShortSwap,
                    $evidenceDriven
                );
            }

            if ($match !== null) {
                $rowId = (int) $match['id'];
                $matchedResults[$rowId] = $this->selectBetterMatch(
                    $matchedResults[$rowId] ?? null,
                    $match
                );
            }
        }

        $matchedResults = array_values($matchedResults);
        $rankingMode = $this->rankingService->getMode();
        usort($matchedResults, function ($a, $b) use ($rankingMode) {
            $scoreComparison = ($a['final_score'] ?? 0) <=> ($b['final_score'] ?? 0);
            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            $similarityComparison = ($b['similarity_percentage'] ?? 0)
                <=> ($a['similarity_percentage'] ?? 0);
            if ($similarityComparison !== 0) {
                return $similarityComparison;
            }

            if ($rankingMode === RankingService::MODE_APPLICATION) {
                $popularityComparison = ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0);
                if ($popularityComparison !== 0) {
                    return $popularityComparison;
                }

                $searchCountComparison = ($b['search_count'] ?? 0) <=> ($a['search_count'] ?? 0);
                if ($searchCountComparison !== 0) {
                    return $searchCountComparison;
                }
            }

            // Tie-breaker deterministik agar urutan stabil saat benchmark diulang.
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });

        // Research-only V2.1 gloss specificity: sort before the frozen guard.
        // All V1 scenarios and public searches retain their historical ordering.
        if ($v2ContextRanking && $direction === self::DIRECTION_ID_TO_TOLOUR) {
            $matchedResults = $this->experimentalV2Ranking->rank(
                $keyword, $queryTokens, $matchedResults
            );
            if ($v23ProximityRanking) {
                $matchedResults = $this->experimentalV23Ranking->rerank($queryTokens, $matchedResults);
            }
        }

        $precisionGuard = [
            'applied' => false,
            'version' => self::ADAPTIVE_PRECISION_GUARD_VERSION,
            'rule' => 'not_adaptive_hybrid',
            'before_count' => count($matchedResults),
            'after_count' => min(count($matchedResults), self::RESULT_LIMIT)
        ];
        if ($evidenceDriven) {
            $edr = new EvidenceDrivenSelectionService();
            [$matchedResults, $precisionGuard] = $edr->select(
                $keyword, $direction, $matchedResults,
                ($experimentOptions['selection_policy'] ?? '') === 'evidence_ambiguity'
            );
        } elseif ($usePrecisionGuard) {
            [$matchedResults, $precisionGuard] = $this->applyAdaptivePrecisionGuard(
                $matchedResults,
                $detectTransposition
            );
        }

        $meta = $this->buildMeta(
            $direction,
            $threshold,
            (bool) $usePrefix,
            $forceThreshold,
            $categoryId
        );
        $meta['adaptive_precision_guard'] = $precisionGuard;
        if ($evidenceDriven) {
            $meta['evidence_driven_v3'] = [
                'version'=>EvidenceDrivenSelectionService::VERSION,
                'discovery_distance_cap'=>3,
                'selection_policy'=>$experimentOptions['selection_policy'] ?? null,
                'no_gold_ids_or_query_types_used'=>true,
                'not_validated'=>true
            ];
        }
        if ($v22ShortSwap) {
            $meta['v22_edit_policy'] = [
                'version' => 'short_adjacent_swap_v2_2',
                'scope' => 'experiment_only_length_2_to_4',
                'operation' => 'single_adjacent_transposition_cost_1',
                'ordinary_levenshtein_unmodified' => true,
                'guard_unchanged' => !$evidenceDriven,
                'effective_preference_even_within_discovery_threshold' => $evidenceDriven,
                'implementation' => $fastShortSwap ? 'cached_swap_variants_v2_2_fast' : 'pairwise_character_check_v2_2',
            ];
        }
        if ($experimentOptions !== null) {
            $meta['experimental_configuration'] = $experimentOptions;
            $meta['significant_query_token_count'] = count($queryTokens);
            if ($v23ProximityRanking) {
                $meta['v23_ranking'] = $direction === self::DIRECTION_ID_TO_TOLOUR
                    ? $this->experimentalV23Ranking->getConfiguration()
                    : ['scope'=>'not_applied_in_tolour_to_id'];
            }
            if ($v2ContextRanking) {
                $meta['v2_ranking'] = $direction === self::DIRECTION_ID_TO_TOLOUR
                    ? $this->experimentalV2Ranking->getConfiguration()
                    : ['scope'=>'not_applied_in_tolour_to_id'];
            }
        }

        return [
            'data' => array_slice($matchedResults, 0, self::RESULT_LIMIT),
            'time_ms' => $this->elapsedMilliseconds($startTime),
            'meta' => $meta
        ];
    }

    /**
     * Mencari kecocokan persis melalui database sebagai jalur cepat.
     */
    private function findExactMatches(string $keyword, string $direction, ?int $categoryId): array
    {
        $usesSnapshotCache = $this->experimentCandidateSnapshot !== null
            && $this->experimentSnapshotCategoryId === $categoryId;
        $cacheKey = $direction . '|' . ($categoryId ?? 0) . '|' . $keyword;
        if ($usesSnapshotCache && array_key_exists($cacheKey, $this->experimentExactMatchCache)) {
            return $this->experimentExactMatchCache[$cacheKey];
        }

        $params = ['kw' => $keyword];
        $categorySql = '';

        if ($categoryId !== null) {
            $categorySql = ' AND d.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        $searchColumn = $direction === self::DIRECTION_TOLOUR_TO_ID
            ? 'd.tolour_word'
            : 'd.indonesian_meaning';

        $sql = "SELECT d.*, c.name AS category_name
                FROM dictionary d
                JOIN categories c ON d.category_id = c.id
                WHERE d.status = 'disetujui'
                  AND LOWER($searchColumn) = :kw
                  $categorySql
                ORDER BY d.popularity DESC, d.search_count DESC, d.id ASC
                LIMIT " . self::RESULT_LIMIT;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $results = $stmt->fetchAll();
        if ($usesSnapshotCache) {
            $this->experimentExactMatchCache[$cacheKey] = $results;
        }
        return $results;
    }

    /**
     * Mengambil kandidat tervalidasi. Filter status ini mencegah usulan yang
     * masih menunggu atau ditolak muncul dalam pencarian publik.
     */
    private function findApprovedCandidates(?int $categoryId): array
    {
        $params = [];
        $categorySql = '';

        if ($categoryId !== null) {
            $categorySql = ' AND d.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        $sql = "SELECT d.*, c.name AS category_name
                FROM dictionary d
                JOIN categories c ON d.category_id = c.id
                WHERE d.status = 'disetujui'
                $categorySql
                ORDER BY d.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Pencarian Indonesia -> Toulour.
     * Query dan arti sama-sama ditokenisasi. Query multikata hanya diterima
     * ketika setiap token penting memiliki pasangan pada arti Indonesia.
     */
    private function evaluateIndonesianMeaning(
        string $keyword,
        array $queryTokens,
        array $row,
        int $threshold,
        bool $usePrefix,
        bool $usesAdaptiveThreshold,
        ?string $preparedMeaning = null,
        ?array $preparedTargetTokens = null,
        ?bool $detectAdjacentTransposition = null,
        string $distanceModel = 'levenshtein',
        bool $shortSwapPolicy = false,
        bool $fastSwapPolicy = false,
        bool $preferShortSwap = false
    ): ?array {
        $meaning = $preparedMeaning !== null
            ? $preparedMeaning
            : $this->normalizeText((string) $row['indonesian_meaning']);

        // Evaluasi frasa utuh tetap dipertahankan untuk arti yang berupa frasa.
        $bestMatch = $this->evaluateHybrid(
            $keyword,
            $meaning,
            $row,
            $threshold,
            $usePrefix,
            'indonesian_phrase',
            $detectAdjacentTransposition ?? ($usesAdaptiveThreshold && $usePrefix),
            $distanceModel,
            $shortSwapPolicy,
            $fastSwapPolicy,
            $preferShortSwap
        );

        $targetTokens = $preparedTargetTokens !== null
            ? $preparedTargetTokens
            : $this->tokenizeIndonesian($meaning);
        if (empty($queryTokens) || empty($targetTokens)) {
            return $bestMatch;
        }

        $tokenMatch = $this->evaluateTokenSet(
            $queryTokens,
            $targetTokens,
            $row,
            $threshold,
            $usePrefix,
            $usesAdaptiveThreshold,
            $detectAdjacentTransposition ?? ($usesAdaptiveThreshold && $usePrefix),
            $distanceModel,
            $shortSwapPolicy,
            $fastSwapPolicy,
            $preferShortSwap
        );

        return $this->selectBetterMatch($bestMatch, $tokenMatch);
    }

    /**
     * Menilai seluruh token query terhadap token arti Indonesia.
     */
    private function evaluateTokenSet(
        array $queryTokens,
        array $targetTokens,
        array $row,
        int $threshold,
        bool $usePrefix,
        bool $usesAdaptiveThreshold,
        bool $detectAdjacentTransposition,
        string $distanceModel,
        bool $shortSwapPolicy = false,
        bool $fastSwapPolicy = false,
        bool $preferShortSwap = false
    ): ?array {
        $componentMatches = [];
        $tokenThresholds = [];

        foreach ($queryTokens as $queryToken) {
            $bestTokenMatch = null;
            $tokenThreshold = $usesAdaptiveThreshold
                ? $this->resolveThreshold($this->textLength($queryToken), null)
                : $threshold;

            foreach ($targetTokens as $targetToken) {
                $candidate = $this->evaluateHybrid(
                    $queryToken,
                    $targetToken,
                    $row,
                    $tokenThreshold,
                    $usePrefix,
                    'indonesian_token',
                    $detectAdjacentTransposition,
                    $distanceModel,
                    $shortSwapPolicy,
                    $fastSwapPolicy,
                    $preferShortSwap
                );

                $bestTokenMatch = $this->selectBetterMatch($bestTokenMatch, $candidate);
            }

            // Query multikata harus memenuhi semua token penting.
            if ($bestTokenMatch === null) {
                return null;
            }

            $componentMatches[] = $bestTokenMatch;
            $tokenThresholds[] = $tokenThreshold;
        }

        $hasFuzzy = false;
        $hasPrefix = false;
        $fuzzyComponentCount = 0;
        $allFuzzyComponentsAreAdjacentTranspositions = true;
        $totalEditDistance = 0;
        $totalCompletionLength = 0;
        $totalSimilarity = 0.0;
        $matchedTexts = [];
        $rescuedComponents = 0;

        foreach ($componentMatches as $component) {
            $hasFuzzy = $hasFuzzy || $component['match_type'] === 'fuzzy';
            $hasPrefix = $hasPrefix || $component['match_type'] === 'prefix';
            if ($component['match_type'] === 'fuzzy') {
                $fuzzyComponentCount++;
                $allFuzzyComponentsAreAdjacentTranspositions =
                    $allFuzzyComponentsAreAdjacentTranspositions
                    && !empty($component['is_adjacent_transposition']);
            }
            $totalEditDistance += (int) $component['edit_distance'];
            if (!empty($component['short_swap_rescued'])) {
                $rescuedComponents++;
            }
            $totalCompletionLength += (int) ($component['completion_length'] ?? 0);
            $totalSimilarity += (float) $component['similarity_percentage'];
            $matchedTexts[] = $component['matched_text'];
        }

        if ($hasFuzzy) {
            $matchType = 'fuzzy';
        } elseif ($hasPrefix) {
            $matchType = 'prefix';
        } else {
            $matchType = 'exact';
        }

        $averageSimilarity = $totalSimilarity / count($componentMatches);
        $legacyDistance = $matchType === 'prefix'
            ? $totalCompletionLength
            : $totalEditDistance;

        if ($matchType === 'exact') {
            $finalScore = -2000;
        } elseif ($matchType === 'prefix') {
            // Offset menjaga prefix tetap di atas fuzzy match, sedangkan mode
            // ranking menentukan apakah bonus popularitas ikut digunakan.
            $finalScore = $this->rankingService->calculateScore(
                0,
                $row['popularity'] ?? 0,
                $row['search_count'] ?? 0
            ) - 1000;
        } else {
            $finalScore = $this->rankingService->calculateScore(
                $totalEditDistance,
                $row['popularity'] ?? 0,
                $row['search_count'] ?? 0
            );
        }

        $result = $this->decorateResult(
            $row,
            $matchType,
            $totalEditDistance,
            $matchType === 'prefix' ? $totalCompletionLength : null,
            $averageSimilarity,
            max($tokenThresholds),
            'indonesian_tokens',
            implode(' ', $matchedTexts),
            $finalScore,
            $legacyDistance
        );

        $result['token_thresholds'] = $tokenThresholds;
        if ($rescuedComponents > 0) {
            $result['short_swap_rescued'] = true;
            $result['short_swap_rescued_components'] = $rescuedComponents;
            $result['effective_edit_model'] = 'levenshtein_plus_short_adjacent_swap';
            $result['raw_levenshtein_edit_distance'] = $totalEditDistance + $rescuedComponents;
        }
        $result['is_adjacent_transposition'] = $fuzzyComponentCount > 0
            && $allFuzzyComponentsAreAdjacentTranspositions;
        return $result;
    }

    /**
     * Menggabungkan exact, prefix, dan Levenshtein secara berurutan.
     */
    private function evaluateHybrid(
        string $keyword,
        string $wordTarget,
        array $row,
        int $threshold,
        bool $usePrefix,
        string $matchedOn,
        bool $detectAdjacentTransposition = false,
        string $distanceModel = 'levenshtein',
        bool $shortSwapPolicy = false,
        bool $fastSwapPolicy = false,
        bool $preferShortSwap = false
    ): ?array {
        if ($keyword === '' || $wordTarget === '') {
            return null;
        }

        if ($keyword === $wordTarget) {
            return $this->decorateResult(
                $row,
                'exact',
                0,
                null,
                100.0,
                $threshold,
                $matchedOn,
                $wordTarget,
                -2000,
                0
            );
        }

        if ($usePrefix && $this->startsWith($wordTarget, $keyword)) {
            $completionLength = max(
                0,
                $this->textLength($wordTarget) - $this->textLength($keyword)
            );
            $targetLength = max(1, $this->textLength($wordTarget));
            $similarity = ($this->textLength($keyword) / $targetLength) * 100;
            $finalScore = $this->rankingService->calculateScore(
                0,
                $row['popularity'] ?? 0,
                $row['search_count'] ?? 0
            ) - 1000;

            return $this->decorateResult(
                $row,
                'prefix',
                0,
                $completionLength,
                $similarity,
                $threshold,
                $matchedOn,
                $wordTarget,
                $finalScore,
                $completionLength
            );
        }

        $editDistance = $distanceModel === 'osa'
            ? $this->unicodeOptimalStringAlignment($keyword, $wordTarget, $threshold)
            : $this->unicodeLevenshtein($keyword, $wordTarget, $threshold);
        $shortSwapRescued = false;
        if ($shortSwapPolicy && $distanceModel === 'levenshtein'
            && ($editDistance > $threshold || ($preferShortSwap && $editDistance > 1)) && $threshold >= 1
            && $this->textLength($keyword) >= 2 && $this->textLength($keyword) <= 4
            && $this->textLength($keyword) === $this->textLength($wordTarget)
            && ($fastSwapPolicy
                ? $this->isCachedShortSwap($keyword, $wordTarget)
                : $this->isSingleAdjacentTransposition($keyword, $wordTarget))) {
            // Do not call this number Levenshtein distance: it is the cost in
            // the special experimental operation model (swap costs one).
            $editDistance = 1;
            $shortSwapRescued = true;
        }
        if ($editDistance > $threshold) {
            return null;
        }

        $maxLength = max(
            $this->textLength($keyword),
            $this->textLength($wordTarget)
        );
        $similarity = $maxLength > 0
            ? (1 - ($editDistance / $maxLength)) * 100
            : 0.0;
        $finalScore = $this->rankingService->calculateScore(
            $editDistance,
            $row['popularity'] ?? 0,
            $row['search_count'] ?? 0
        );

        $result = $this->decorateResult(
            $row,
            'fuzzy',
            $editDistance,
            null,
            $similarity,
            $threshold,
            $matchedOn,
            $wordTarget,
            $finalScore,
            $editDistance
        );
        if ($detectAdjacentTransposition) {
            $result['is_adjacent_transposition'] = $shortSwapRescued
                || $this->isSingleAdjacentTransposition($keyword, $wordTarget);
        }
        if ($shortSwapRescued) {
            $result['short_swap_rescued'] = true;
            $result['effective_edit_model'] = 'levenshtein_plus_short_adjacent_swap';
            $result['raw_levenshtein_edit_distance'] = 2;
        }
        return $result;
    }

    private function decorateResult(
        array $row,
        string $matchType,
        int $editDistance,
        ?int $completionLength,
        float $similarity,
        int $threshold,
        string $matchedOn,
        string $matchedText,
        float $finalScore,
        int $legacyDistance
    ): array {
        $similarity = round(max(0, min(100, $similarity)), 2);

        // Field baru yang semantik dan dapat dipakai dalam artikel/evaluasi.
        $row['match_type'] = $matchType;
        $row['edit_distance'] = $editDistance;
        $row['completion_length'] = $completionLength;
        $row['similarity_percentage'] = $similarity;
        $row['threshold_used'] = $threshold;
        $row['matched_on'] = $matchedOn;
        $row['matched_text'] = $matchedText;
        $row['final_score'] = round($finalScore, 2);
        $row['is_adjacent_transposition'] = false;

        // Field kompatibilitas untuk home.php lama.
        $row['distance'] = $legacyDistance;
        $row['confidence'] = $similarity;
        $row['is_prefix'] = $matchType === 'prefix';

        return $row;
    }

    /**
     * Menahan kandidat lemah pada Adaptive Hybrid tanpa membaca expected ID.
     * Daftar masuk sudah terurut oleh ranking dan urutan itu dipertahankan.
     */
    private function applyAdaptivePrecisionGuard(array $results, bool $preferTranspositions = true): array
    {
        $beforeCount = count($results);
        $exactOrPrefix = array_values(array_filter($results, function ($result) {
            return in_array($result['match_type'] ?? '', ['exact', 'prefix'], true);
        }));
        $hasExact = false;
        foreach ($exactOrPrefix as $result) {
            if (($result['match_type'] ?? '') === 'exact') {
                $hasExact = true;
                break;
            }
        }

        if ($hasExact) {
            $selected = $exactOrPrefix;
            $rule = 'exact_and_prefix_only';
        } elseif (!empty($exactOrPrefix)) {
            $selected = $exactOrPrefix;
            $rule = 'prefix_only_without_fuzzy_padding';
        } else {
            $minimumDistance = null;
            foreach ($results as $result) {
                $distance = (int) ($result['edit_distance'] ?? PHP_INT_MAX);
                $minimumDistance = $minimumDistance === null
                    ? $distance
                    : min($minimumDistance, $distance);
            }
            $selected = array_values(array_filter($results, function ($result) use ($minimumDistance) {
                return (int) ($result['edit_distance'] ?? PHP_INT_MAX) === $minimumDistance;
            }));
            $transpositions = array_values(array_filter($selected, function ($result) {
                return !empty($result['is_adjacent_transposition']);
            }));
            if ($preferTranspositions && !empty($transpositions)) {
                $selected = $transpositions;
                $rule = 'nearest_adjacent_transposition_tier';
            } else {
                $rule = 'nearest_edit_distance_tier';
            }
        }

        $selected = array_slice($selected, 0, self::RESULT_LIMIT);
        $afterCount = count($selected);
        foreach ($selected as &$result) {
            $result['adaptive_guard_rule'] = $rule;
            $result['adaptive_guard_before_count'] = $beforeCount;
            $result['adaptive_guard_after_count'] = $afterCount;
        }
        unset($result);

        return [
            $selected,
            [
                'applied' => true,
                'version' => self::ADAPTIVE_PRECISION_GUARD_VERSION,
                'rule' => $rule,
                'before_count' => $beforeCount,
                'after_count' => $afterCount
            ]
        ];
    }

    /**
     * Same mathematical predicate as isSingleAdjacentTransposition(), but
     * generate the at-most-three variants once per short query. Research only.
     * For example, "wael" indexes "awel", "weal", "wale".
     */
    private function isCachedShortSwap(string $source, string $target): bool
    {
        if (!array_key_exists($source, $this->experimentFastSwapCache)) {
            $chars = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY);
            $variants = [];
            if ($chars !== false && count($chars) >= 2 && count($chars) <= 4) {
                for ($i = 0; $i + 1 < count($chars); $i++) {
                    if ($chars[$i] === $chars[$i + 1]) continue;
                    $swapped = $chars;
                    [$swapped[$i], $swapped[$i + 1]] = [$swapped[$i + 1], $swapped[$i]];
                    $variants[implode('', $swapped)] = true;
                }
            }
            $this->experimentFastSwapCache[$source] = $variants;
        }
        return isset($this->experimentFastSwapCache[$source][$target]);
    }

    /**
     * True hanya bila dua teks sama panjang dan tepat dua karakter bersebelahan
     * tertukar, misalnya "iwntus" menjadi "wintus".
     */
    private function isSingleAdjacentTransposition(string $source, string $target): bool
    {
        $sourceCharacters = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY);
        $targetCharacters = preg_split('//u', $target, -1, PREG_SPLIT_NO_EMPTY);
        if (
            $sourceCharacters === false
            || $targetCharacters === false
            || count($sourceCharacters) !== count($targetCharacters)
        ) {
            return false;
        }

        $differentIndexes = [];
        foreach ($sourceCharacters as $index => $character) {
            if ($character !== $targetCharacters[$index]) {
                $differentIndexes[] = $index;
                if (count($differentIndexes) > 2) {
                    return false;
                }
            }
        }

        if (
            count($differentIndexes) !== 2
            || $differentIndexes[1] !== $differentIndexes[0] + 1
        ) {
            return false;
        }

        [$first, $second] = $differentIndexes;
        return $sourceCharacters[$first] === $targetCharacters[$second]
            && $sourceCharacters[$second] === $targetCharacters[$first];
    }

    private function formatExactResults(array $results, int $threshold, string $direction): array
    {
        $matchedOn = $direction === self::DIRECTION_TOLOUR_TO_ID
            ? 'tolour_word'
            : 'indonesian_phrase';

        foreach ($results as &$row) {
            $matchedText = $direction === self::DIRECTION_TOLOUR_TO_ID
                ? $this->normalizeText((string) $row['tolour_word'])
                : $this->normalizeText((string) $row['indonesian_meaning']);

            $row = $this->decorateResult(
                $row,
                'exact',
                0,
                null,
                100.0,
                $threshold,
                $matchedOn,
                $matchedText,
                -2000,
                0
            );
        }
        unset($row);

        return array_slice($results, 0, self::RESULT_LIMIT);
    }

    /**
     * Memilih hasil yang lebih baik secara deterministik.
     */
    private function selectBetterMatch(?array $current, ?array $candidate): ?array
    {
        if ($candidate === null) {
            return $current;
        }
        if ($current === null) {
            return $candidate;
        }

        if ($candidate['final_score'] !== $current['final_score']) {
            return $candidate['final_score'] < $current['final_score']
                ? $candidate
                : $current;
        }

        if ($candidate['similarity_percentage'] !== $current['similarity_percentage']) {
            return $candidate['similarity_percentage'] > $current['similarity_percentage']
                ? $candidate
                : $current;
        }

        return $candidate['edit_distance'] < $current['edit_distance']
            ? $candidate
            : $current;
    }

    private function resolveThreshold(int $keywordLength, $forceThreshold): int
    {
        if ($forceThreshold !== null && is_numeric($forceThreshold)) {
            // Batas pengaman untuk mencegah pencarian publik yang terlalu mahal.
            return max(0, min(10, (int) $forceThreshold));
        }

        if ($keywordLength <= 4) {
            return 1;
        }
        if ($keywordLength <= 8) {
            return 2;
        }

        return 3;
    }

    private function normalizeDirection($direction): string
    {
        return $direction === self::DIRECTION_ID_TO_TOLOUR
            ? self::DIRECTION_ID_TO_TOLOUR
            : self::DIRECTION_TOLOUR_TO_ID;
    }

    private function normalizeCategoryId($categoryId): ?int
    {
        if ($categoryId === null || $categoryId === '' || !is_numeric($categoryId)) {
            return null;
        }

        $categoryId = (int) $categoryId;
        return $categoryId > 0 ? $categoryId : null;
    }

    /**
     * Normalisasi aman tanpa menghilangkan diakritik yang mungkin bermakna
     * dalam ortografi Toulour.
     */
    private function normalizeText(string $text): string
    {
        $text = trim($text);

        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if ($normalized !== false) {
                $text = $normalized;
            }
        }

        $text = str_replace(
            ["\u{2018}", "\u{2019}", "\u{02BC}", "\u{0060}"],
            "'",
            $text
        );
        $text = function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);

        // Pertahankan huruf Unicode, angka, apostrof, dan tanda hubung.
        $text = preg_replace("/[^\\p{L}\\p{N}\\s'\\-]/u", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text ?? '');
    }

    private function tokenizeIndonesian(string $text): array
    {
        $text = $this->normalizeText($text);
        if ($text === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return [];
        }

        $tokens = array_filter($tokens, function ($token) {
            $token = trim($token, "-' ");
            return $token !== ''
                && !is_numeric($token)
                && !in_array($token, $this->indonesianStopwords, true);
        });

        return array_values(array_unique($tokens));
    }

    /**
     * Levenshtein berbasis karakter Unicode, bukan byte UTF-8.
     * Kompleksitas waktu O(m*n), memori O(n).
     */
    /**
     * Optimal String Alignment distance (restricted Damerau-Levenshtein).
     * Adjacent transposition costs one operation; unlike full Damerau,
     * substring edits cannot be transposed more than once. Unicode-safe.
     * Kept separate from the original Levenshtein implementation.
     */
    private function unicodeOptimalStringAlignment(
        string $source,
        string $target,
        ?int $maxDistance = null
    ): int {
        $a = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY);
        $b = preg_split('//u', $target, -1, PREG_SPLIT_NO_EMPTY);
        if ($a === false || $b === false) {
            throw new InvalidArgumentException('Invalid UTF-8 for OSA distance.');
        }
        $m = count($a);
        $n = count($b);
        if ($maxDistance !== null && abs($m - $n) > $maxDistance) {
            return $maxDistance + 1;
        }
        if ($m === 0) return $n;
        if ($n === 0) return $m;
        $beforePrevious = null;
        $previous = range(0, $n);
        for ($i = 1; $i <= $m; $i++) {
            $current = [$i];
            for ($j = 1; $j <= $n; $j++) {
                $substitution = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
                $minimum = min(
                    $current[$j - 1] + 1,
                    $previous[$j] + 1,
                    $previous[$j - 1] + $substitution
                );
                if ($i > 1 && $j > 1 && $beforePrevious !== null
                    && $a[$i - 1] === $b[$j - 2]
                    && $a[$i - 2] === $b[$j - 1]) {
                    $minimum = min($minimum, $beforePrevious[$j - 2] + 1);
                }
                $current[$j] = $minimum;
            }
            $beforePrevious = $previous;
            $previous = $current;
        }
        $distance = $previous[$n];
        return ($maxDistance !== null && $distance > $maxDistance)
            ? $maxDistance + 1 : $distance;
    }

    private function unicodeLevenshtein(string $source, string $target, ?int $maxDistance = null): int
    {
        $sourceLength = $this->textLength($source);
        $targetLength = $this->textLength($target);

        // Selisih panjang adalah batas bawah edit distance. Kandidat dapat
        // dihentikan sebelum matriks Levenshtein dibentuk.
        if ($maxDistance !== null && abs($sourceLength - $targetLength) > $maxDistance) {
            return $maxDistance + 1;
        }

        // Jalur cepat untuk data ASCII yang masih mendominasi dataset.
        $isAsciiPair = $this->experimentCandidateSnapshot !== null
            ? $this->isAsciiText($source) && $this->isAsciiText($target)
            : preg_match('/^[\x00-\x7F]*$/D', $source . $target) === 1;
        if ($isAsciiPair) {
            return levenshtein($source, $target);
        }

        $sourceCharacters = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY);
        $targetCharacters = preg_split('//u', $target, -1, PREG_SPLIT_NO_EMPTY);

        if ($sourceCharacters === false || $targetCharacters === false) {
            return levenshtein($source, $target);
        }

        $sourceLength = count($sourceCharacters);
        $targetLength = count($targetCharacters);

        if ($sourceLength === 0) {
            return $targetLength;
        }
        if ($targetLength === 0) {
            return $sourceLength;
        }

        // Gunakan target terpendek sebagai kolom untuk menghemat memori.
        if ($targetLength > $sourceLength) {
            [$sourceCharacters, $targetCharacters] = [$targetCharacters, $sourceCharacters];
            [$sourceLength, $targetLength] = [$targetLength, $sourceLength];
        }

        $previousRow = range(0, $targetLength);

        for ($i = 1; $i <= $sourceLength; $i++) {
            $currentRow = [$i];

            for ($j = 1; $j <= $targetLength; $j++) {
                $insertion = $currentRow[$j - 1] + 1;
                $deletion = $previousRow[$j] + 1;
                $substitution = $previousRow[$j - 1]
                    + ($sourceCharacters[$i - 1] === $targetCharacters[$j - 1] ? 0 : 1);

                $currentRow[$j] = min($insertion, $deletion, $substitution);
            }

            if ($maxDistance !== null && min($currentRow) > $maxDistance) {
                return $maxDistance + 1;
            }

            $previousRow = $currentRow;
        }

        return $previousRow[$targetLength];
    }

    private function textLength(string $text): int
    {
        if (
            $this->experimentCandidateSnapshot !== null
            && array_key_exists($text, $this->experimentTextLengthCache)
        ) {
            return $this->experimentTextLengthCache[$text];
        }

        $length = function_exists('mb_strlen')
            ? mb_strlen($text, 'UTF-8')
            : strlen($text);
        if ($this->experimentCandidateSnapshot !== null) {
            $this->experimentTextLengthCache[$text] = $length;
        }
        return $length;
    }

    private function isAsciiText(string $text): bool
    {
        if (
            $this->experimentCandidateSnapshot !== null
            && array_key_exists($text, $this->experimentAsciiCache)
        ) {
            return $this->experimentAsciiCache[$text];
        }

        $isAscii = preg_match('/^[\x00-\x7F]*$/D', $text) === 1;
        if ($this->experimentCandidateSnapshot !== null) {
            $this->experimentAsciiCache[$text] = $isAscii;
        }
        return $isAscii;
    }

    private function cacheExperimentTextMetadata(string $text): void
    {
        if (!array_key_exists($text, $this->experimentTextLengthCache)) {
            $this->experimentTextLengthCache[$text] = function_exists('mb_strlen')
                ? mb_strlen($text, 'UTF-8')
                : strlen($text);
        }
        if (!array_key_exists($text, $this->experimentAsciiCache)) {
            $this->experimentAsciiCache[$text] = preg_match('/^[\x00-\x7F]*$/D', $text) === 1;
        }
    }

    private function startsWith(string $text, string $prefix): bool
    {
        if ($prefix === '') {
            return false;
        }

        return strncmp($text, $prefix, strlen($prefix)) === 0;
    }

    private function startTimer()
    {
        return function_exists('hrtime') ? hrtime(true) : microtime(true);
    }

    private function elapsedMilliseconds($startTime): float
    {
        if (is_int($startTime)) {
            return round((hrtime(true) - $startTime) / 1_000_000, 4);
        }

        return round((microtime(true) - $startTime) * 1000, 4);
    }

    private function buildMeta(
        string $direction,
        int $threshold,
        bool $usePrefix,
        $forceThreshold,
        ?int $categoryId
    ): array {
        if (!$usePrefix) {
            $method = 'levenshtein';
        } elseif ($forceThreshold === null || !is_numeric($forceThreshold)) {
            $method = 'adaptive_hybrid_prefix_levenshtein';
        } else {
            $method = 'hybrid_prefix_levenshtein';
        }

        return [
            'method' => $method,
            'direction' => $direction,
            'threshold_used' => $threshold,
            'use_prefix' => $usePrefix,
            'category_id' => $categoryId,
            'result_limit' => self::RESULT_LIMIT,
            'ranking_mode' => $this->rankingService->getMode()
        ];
    }
}
