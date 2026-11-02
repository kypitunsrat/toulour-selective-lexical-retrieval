<?php

require_once __DIR__ . '/../config/database.php';

/**
 * Membentuk kandidat gold-standard secara deterministik dari kamus approved.
 * Hasil generator tetap berstatus pending setelah diimpor dan harus ditinjau
 * validator, terutama untuk arah Indonesia -> Toulour.
 */
class GoldStandardGeneratorService
{
    private const AMBIGUITY_GUARD_VERSION = 'operation_aware_nearest_tier_v3';
    private const DIRECTIONS = ['tolour_to_id', 'id_to_tolour'];
    private const QUERY_TYPES = [
        'exact',
        'prefix',
        'substitution',
        'deletion',
        'insertion',
        'transposition',
        'normalization_tokenization'
    ];
    private const LENGTH_GROUPS = ['short', 'medium', 'long'];

    private $db;

    public function __construct($databaseConnection = null)
    {
        $this->db = $databaseConnection ?: Database::getInstance();
    }

    public function getCorpusProfile(): array
    {
        $entries = $this->loadCorpus();
        if (empty($entries)) {
            throw new RuntimeException('Korpus approved kosong.');
        }
        [$shortMax, $mediumMax] = $this->calculateLengthBoundaries($entries);
        $counts = ['short' => 0, 'medium' => 0, 'long' => 0];
        foreach ($entries as $entry) {
            $counts[$this->lengthGroup((int) $entry['word_length'], $shortMax, $mediumMax)]++;
        }

        return [
            'approved_count' => count($entries),
            'short_max' => $shortMax,
            'medium_max' => $mediumMax,
            'group_counts' => $counts,
            'method' => 'tercile_of_normalized_tolour_word_length'
        ];
    }

    public function generate(int $developmentCount, int $testCount, string $seed): array
    {
        if ($developmentCount < 0 || $testCount < 1) {
            throw new InvalidArgumentException('Jumlah development/test tidak valid.');
        }
        if (($developmentCount + $testCount) > 4000) {
            throw new InvalidArgumentException('Generator dibatasi maksimal 4.000 kueri per file.');
        }
        $seed = trim($seed);
        if ($seed === '' || strlen($seed) > 100) {
            throw new InvalidArgumentException('Seed wajib diisi dan maksimal 100 karakter.');
        }

        $entries = $this->loadCorpus();
        if (count($entries) < ($developmentCount + $testCount)) {
            throw new RuntimeException(
                'Jumlah entri approved tidak cukup untuk memakai satu sumber unik per kueri.'
            );
        }

        [$shortMax, $mediumMax] = $this->calculateLengthBoundaries($entries);
        $tolourIndex = [];
        $meaningIndex = [];
        foreach ($entries as &$entry) {
            $entry['length_group'] = $this->lengthGroup(
                (int) $entry['word_length'],
                $shortMax,
                $mediumMax
            );
            $entry['meaning_query'] = $this->meaningQuery((string) $entry['indonesian_meaning']);
            $tolourKey = $this->normalizeText((string) $entry['tolour_word']);
            $meaningKey = $this->normalizeText((string) $entry['meaning_query']);
            if ($tolourKey !== '') {
                $tolourIndex[$tolourKey][] = (int) $entry['id'];
            }
            if ($meaningKey !== '') {
                $meaningIndex[$meaningKey][] = (int) $entry['id'];
            }
        }
        unset($entry);

        $targetCorpora = [
            'tolour_to_id' => $this->buildTargetCorpus($tolourIndex),
            'id_to_tolour' => $this->buildTargetCorpus($meaningIndex)
        ];

        $cases = [];
        $usedSourceIds = [];
        $usedCaseKeys = [];
        $rejectionCounts = [
            'exact_collision' => 0,
            'prefix_collision' => 0,
            'nearest_tier_collision' => 0,
            'outside_adaptive_threshold' => 0,
            'invalid_case' => 0
        ];
        $splitTargets = ['development' => $developmentCount, 'test' => $testCount];

        foreach ($splitTargets as $split => $target) {
            if ($target === 0) {
                continue;
            }
            $strata = $this->strataKeys();
            $quotas = $this->allocateQuotas($target, $strata, $seed . '|' . $split);

            foreach ($strata as $stratum) {
                [$direction, $queryType, $lengthGroup] = explode('|', $stratum);
                $quota = $quotas[$stratum];
                if ($quota === 0) {
                    continue;
                }

                $candidates = array_values(array_filter(
                    $entries,
                    function (array $entry) use ($direction, $queryType, $lengthGroup, $usedSourceIds): bool {
                        return $entry['length_group'] === $lengthGroup
                            && !isset($usedSourceIds[(int) $entry['id']])
                            && $this->canGenerate($entry, $direction, $queryType);
                    }
                ));
                usort($candidates, function (array $left, array $right) use ($seed, $split, $stratum): int {
                    $leftHash = hash('sha256', $seed . '|' . $split . '|' . $stratum . '|' . $left['id']);
                    $rightHash = hash('sha256', $seed . '|' . $split . '|' . $stratum . '|' . $right['id']);
                    return $leftHash <=> $rightHash;
                });

                $selected = 0;
                foreach ($candidates as $entry) {
                    $case = $this->buildCase(
                        $entry,
                        $direction,
                        $queryType,
                        $split,
                        $tolourIndex,
                        $meaningIndex,
                        $seed,
                        $shortMax,
                        $mediumMax
                    );
                    $rejectionReason = $this->detectAmbiguity(
                        $case,
                        $targetCorpora[$direction]
                    );
                    if ($rejectionReason !== null) {
                        $rejectionCounts[$rejectionReason]++;
                        continue;
                    }
                    $caseKey = $this->normalizeText($case['keyword'])
                        . '|' . $direction . '|' . $queryType . '|' . $split;
                    if (isset($usedCaseKeys[$caseKey])) {
                        continue;
                    }

                    unset($case['_normalized_source_text']);
                    $cases[] = $case;
                    $usedCaseKeys[$caseKey] = true;
                    $usedSourceIds[(int) $entry['id']] = true;
                    // Seluruh entri yang relevan ikut ditahan agar sinonim/homonim
                    // dari satu kelompok label tidak bocor ke split lain.
                    foreach ($case['expected_ids'] as $relevantId) {
                        $usedSourceIds[(int) $relevantId] = true;
                    }
                    $selected++;
                    if ($selected >= $quota) {
                        break;
                    }
                }

                if ($selected < $quota) {
                    throw new RuntimeException(sprintf(
                        'Kandidat tidak cukup untuk strata %s/%s/%s: perlu %d, tersedia %d. '
                        . 'Gunakan target lebih kecil atau periksa kelengkapan korpus.',
                        $split,
                        $direction,
                        $queryType,
                        $quota,
                        $selected
                    ));
                }
            }
        }

        usort($cases, static function (array $left, array $right): int {
            return [$left['dataset_split'], $left['direction'], $left['query_type'],
                $left['length_group'], $left['source_dictionary_id']]
                <=> [$right['dataset_split'], $right['direction'], $right['query_type'],
                    $right['length_group'], $right['source_dictionary_id']];
        });

        return [
            'cases' => $cases,
            'meta' => [
                'seed' => $seed,
                'approved_count' => count($entries),
                'development_count' => $developmentCount,
                'test_count' => $testCount,
                'short_max' => $shortMax,
                'medium_max' => $mediumMax,
                'source_overlap_count' => 0,
                'candidate_rejected_count' => array_sum($rejectionCounts),
                'collision_rejected_count' =>
                    $rejectionCounts['exact_collision']
                    + $rejectionCounts['prefix_collision']
                    + $rejectionCounts['nearest_tier_collision'],
                'exact_collision_rejected_count' => $rejectionCounts['exact_collision'],
                'prefix_collision_rejected_count' => $rejectionCounts['prefix_collision'],
                'nearest_tier_collision_rejected_count' =>
                    $rejectionCounts['nearest_tier_collision'],
                'outside_adaptive_threshold_rejected_count' =>
                    $rejectionCounts['outside_adaptive_threshold'],
                'invalid_case_rejected_count' => $rejectionCounts['invalid_case'],
                'ambiguity_guard_version' => self::AMBIGUITY_GUARD_VERSION,
                'generation_method' =>
                    'deterministic_stratified_unique_source_nearest_tier_safe_v3'
            ]
        ];
    }

    private function loadCorpus(): array
    {
        $statement = $this->db->query(
            "SELECT id, tolour_word, indonesian_meaning
             FROM dictionary
             WHERE status = 'disetujui'
               AND TRIM(tolour_word) <> ''
               AND TRIM(indonesian_meaning) <> ''
             ORDER BY id ASC"
        );
        $entries = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $normalizedWord = $this->normalizeText((string) $row['tolour_word']);
            if ($normalizedWord === '') {
                continue;
            }
            $row['word_length'] = $this->textLength($normalizedWord);
            $entries[] = $row;
        }
        return $entries;
    }

    private function calculateLengthBoundaries(array $entries): array
    {
        $lengths = array_map(static function (array $entry): int {
            return (int) $entry['word_length'];
        }, $entries);
        sort($lengths, SORT_NUMERIC);
        $count = count($lengths);
        $shortIndex = max(0, min($count - 1, (int) ceil($count / 3) - 1));
        $mediumIndex = max(0, min($count - 1, (int) ceil((2 * $count) / 3) - 1));

        return [$lengths[$shortIndex], max($lengths[$shortIndex] + 1, $lengths[$mediumIndex])];
    }

    private function lengthGroup(int $length, int $shortMax, int $mediumMax): string
    {
        if ($length <= $shortMax) {
            return 'short';
        }
        return $length <= $mediumMax ? 'medium' : 'long';
    }

    private function strataKeys(): array
    {
        $keys = [];
        foreach (self::DIRECTIONS as $direction) {
            foreach (self::QUERY_TYPES as $type) {
                foreach (self::LENGTH_GROUPS as $lengthGroup) {
                    $keys[] = $direction . '|' . $type . '|' . $lengthGroup;
                }
            }
        }
        return $keys;
    }

    private function allocateQuotas(int $target, array $strata, string $seed): array
    {
        $base = intdiv($target, count($strata));
        $remainder = $target % count($strata);
        $ordered = $strata;
        usort($ordered, static function (string $left, string $right) use ($seed): int {
            return hash('sha256', $seed . '|' . $left)
                <=> hash('sha256', $seed . '|' . $right);
        });

        $quotas = array_fill_keys($strata, $base);
        for ($index = 0; $index < $remainder; $index++) {
            $quotas[$ordered[$index]]++;
        }
        return $quotas;
    }

    private function canGenerate(array $entry, string $direction, string $queryType): bool
    {
        $base = $direction === 'tolour_to_id'
            ? (string) $entry['tolour_word']
            : (string) ($entry['meaning_query'] ?? '');
        $base = trim($base);
        if ($base === '' || $this->textLength($base) > 150) {
            return false;
        }
        $characters = $this->characters($base);
        $letterIndexes = $this->letterIndexes($characters);

        if ($queryType === 'prefix') {
            return count($letterIndexes) >= 3;
        }
        if ($queryType === 'deletion') {
            return count($letterIndexes) >= 3;
        }
        if ($queryType === 'substitution' || $queryType === 'insertion') {
            return count($letterIndexes) >= 2;
        }
        if ($queryType === 'transposition') {
            return $this->transpositionIndex($characters) !== null;
        }
        return true;
    }

    private function buildCase(
        array $entry,
        string $direction,
        string $queryType,
        string $split,
        array $tolourIndex,
        array $meaningIndex,
        string $seed,
        int $shortMax,
        int $mediumMax
    ): array {
        $base = $direction === 'tolour_to_id'
            ? trim((string) $entry['tolour_word'])
            : trim((string) $entry['meaning_query']);
        $keyword = $this->transformQuery($base, $queryType);
        $indexKey = $this->normalizeText($base);
        $expectedIds = $direction === 'tolour_to_id'
            ? ($tolourIndex[$indexKey] ?? [(int) $entry['id']])
            : ($meaningIndex[$indexKey] ?? [(int) $entry['id']]);
        $expectedIds = array_values(array_unique(array_map('intval', $expectedIds)));
        sort($expectedIds, SORT_NUMERIC);

        return [
            'keyword' => $keyword,
            'expected_ids' => $expectedIds,
            'direction' => $direction,
            'query_type' => $queryType,
            'dataset_split' => $split,
            'source_dictionary_id' => (int) $entry['id'],
            'length_group' => (string) $entry['length_group'],
            'generation_seed' => $seed,
            'length_short_max' => $shortMax,
            'length_medium_max' => $mediumMax,
            '_normalized_source_text' => $indexKey
        ];
    }

    private function transformQuery(string $base, string $queryType): string
    {
        if ($queryType === 'exact') {
            return $base;
        }
        if ($queryType === 'normalization_tokenization') {
            $withoutApostrophe = str_replace(["'", '’', '`'], '', $base);
            if ($withoutApostrophe !== $base) {
                return $withoutApostrophe;
            }
            if (strpos($base, ' ') !== false) {
                return preg_replace('/\s+/u', '   ', $this->upper($base));
            }
            return $this->upper($base);
        }

        $characters = $this->characters($base);
        $letterIndexes = $this->letterIndexes($characters);
        $middlePosition = (int) floor(count($letterIndexes) / 2);
        $targetIndex = $letterIndexes[$middlePosition] ?? 0;

        if ($queryType === 'prefix') {
            $keep = max(2, (int) floor(count($characters) * 0.65));
            return rtrim(implode('', array_slice($characters, 0, $keep)));
        }
        if ($queryType === 'deletion') {
            array_splice($characters, $targetIndex, 1);
            return implode('', $characters);
        }
        if ($queryType === 'insertion') {
            array_splice($characters, $targetIndex, 0, ['a']);
            return implode('', $characters);
        }
        if ($queryType === 'substitution') {
            $current = $this->lower($characters[$targetIndex]);
            $characters[$targetIndex] = $current === 'a' ? 'i' : 'a';
            return implode('', $characters);
        }
        if ($queryType === 'transposition') {
            $index = $this->transpositionIndex($characters);
            if ($index !== null) {
                [$characters[$index], $characters[$index + 1]] = [
                    $characters[$index + 1], $characters[$index]
                ];
            }
            return implode('', $characters);
        }
        throw new InvalidArgumentException('Tipe transformasi tidak dikenal.');
    }

    /**
     * Membentuk korpus target unik agar pemeriksaan ambiguitas tidak perlu
     * mengulang ID yang mempunyai ejaan/arti ternormalisasi sama.
     */
    private function buildTargetCorpus(array $index): array
    {
        $targets = [];
        foreach ($index as $text => $ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            sort($ids, SORT_NUMERIC);
            $targets[] = [
                'text' => (string) $text,
                'length' => $this->textLength((string) $text),
                'ids' => $ids
            ];
        }
        usort($targets, static function (array $left, array $right): int {
            return $left['text'] <=> $right['text'];
        });
        return $targets;
    }

    /**
     * Menolak kueri sintetis yang mempunyai lebih dari satu interpretasi
     * leksikal pada korpus. Aturan ini tidak membaca hasil ranking algoritma:
     * exact diperiksa pada exact tier, prefix pada seluruh prefix tier, dan
     * operasi edit pada semua kandidat dengan jarak <= jarak sumber.
     */
    private function detectAmbiguity(array $case, array $targetCorpus): ?string
    {
        $queryType = (string) ($case['query_type'] ?? '');
        $queryText = $this->normalizeText((string) ($case['keyword'] ?? ''));
        $sourceText = (string) ($case['_normalized_source_text'] ?? '');
        $expectedIds = array_values(array_unique(array_map(
            'intval',
            $case['expected_ids'] ?? []
        )));
        sort($expectedIds, SORT_NUMERIC);

        if ($queryText === '' || $sourceText === '' || empty($expectedIds)) {
            return 'invalid_case';
        }

        if (
            $queryType === 'exact'
            || ($queryType === 'normalization_tokenization' && $queryText === $sourceText)
        ) {
            foreach ($targetCorpus as $target) {
                if ($target['text'] !== $queryText) {
                    continue;
                }
                return $this->hasUnexpectedIds($target['ids'], $expectedIds)
                    ? 'exact_collision'
                    : null;
            }
            return 'invalid_case';
        }

        if ($queryType === 'prefix') {
            foreach ($targetCorpus as $target) {
                if (
                    $this->startsWith((string) $target['text'], $queryText)
                    && $this->hasUnexpectedIds($target['ids'], $expectedIds)
                ) {
                    return 'prefix_collision';
                }
            }
            return null;
        }

        if (!in_array(
            $queryType,
            [
                'substitution', 'deletion', 'insertion', 'transposition',
                'normalization_tokenization'
            ],
            true
        )) {
            return 'invalid_case';
        }

        $sourceDistance = $this->unicodeLevenshtein($queryText, $sourceText);
        if ($sourceDistance < 1) {
            return 'invalid_case';
        }
        if ($sourceDistance > $this->resolveAdaptiveThreshold($this->textLength($queryText))) {
            return 'outside_adaptive_threshold';
        }

        $queryLength = $this->textLength($queryText);
        foreach ($targetCorpus as $target) {
            if (!$this->hasUnexpectedIds($target['ids'], $expectedIds)) {
                continue;
            }
            if (abs($queryLength - (int) $target['length']) > $sourceDistance) {
                continue;
            }
            $distance = $this->unicodeLevenshtein(
                $queryText,
                (string) $target['text'],
                $sourceDistance
            );
            if ($distance <= $sourceDistance) {
                return $distance === 0
                    ? 'exact_collision'
                    : 'nearest_tier_collision';
            }
        }

        return null;
    }

    private function hasUnexpectedIds(array $candidateIds, array $expectedIds): bool
    {
        return !empty(array_diff(array_map('intval', $candidateIds), $expectedIds));
    }

    private function startsWith(string $text, string $prefix): bool
    {
        return $prefix !== '' && strncmp($text, $prefix, strlen($prefix)) === 0;
    }

    private function resolveAdaptiveThreshold(int $queryLength): int
    {
        if ($queryLength <= 4) {
            return 1;
        }
        if ($queryLength <= 8) {
            return 2;
        }
        return 3;
    }

    /**
     * Levenshtein berbasis karakter Unicode dengan penghentian awal.
     */
    private function unicodeLevenshtein(
        string $source,
        string $target,
        ?int $maxDistance = null
    ): int {
        $sourceLength = $this->textLength($source);
        $targetLength = $this->textLength($target);
        if ($maxDistance !== null && abs($sourceLength - $targetLength) > $maxDistance) {
            return $maxDistance + 1;
        }

        if (preg_match('/^[\x00-\x7F]*$/D', $source . $target) === 1) {
            return levenshtein($source, $target);
        }

        $sourceCharacters = $this->characters($source);
        $targetCharacters = $this->characters($target);
        $sourceLength = count($sourceCharacters);
        $targetLength = count($targetCharacters);
        if ($sourceLength === 0) {
            return $targetLength;
        }
        if ($targetLength === 0) {
            return $sourceLength;
        }
        if ($targetLength > $sourceLength) {
            [$sourceCharacters, $targetCharacters] = [$targetCharacters, $sourceCharacters];
            [$sourceLength, $targetLength] = [$targetLength, $sourceLength];
        }

        $previousRow = range(0, $targetLength);
        for ($i = 1; $i <= $sourceLength; $i++) {
            $currentRow = [$i];
            for ($j = 1; $j <= $targetLength; $j++) {
                $currentRow[$j] = min(
                    $currentRow[$j - 1] + 1,
                    $previousRow[$j] + 1,
                    $previousRow[$j - 1]
                        + ($sourceCharacters[$i - 1] === $targetCharacters[$j - 1] ? 0 : 1)
                );
            }
            if ($maxDistance !== null && min($currentRow) > $maxDistance) {
                return $maxDistance + 1;
            }
            $previousRow = $currentRow;
        }
        return $previousRow[$targetLength];
    }

    private function meaningQuery(string $meaning): string
    {
        $meaning = preg_replace('/\s+/u', ' ', trim($meaning));
        $parts = preg_split('/[;\n\r]+/u', $meaning, 2);
        $query = trim((string) ($parts[0] ?? $meaning), " \t\n\r\0\x0B,.");
        if ($this->textLength($query) > 120) {
            $query = $this->textSlice($query, 0, 120);
        }
        return $query;
    }

    private function normalizeText(string $value): string
    {
        $value = trim($value);
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if ($normalized !== false) {
                $value = $normalized;
            }
        }
        $value = str_replace(
            ["\u{2018}", "\u{2019}", "\u{02BC}", "\u{0060}"],
            "'",
            $value
        );
        $value = $this->lower($value);
        $value = preg_replace("/[^\\p{L}\\p{N}\\s'\\-]/u", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value ?? '');
        return trim($value ?? '');
    }

    private function characters(string $value): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($characters) ? $characters : str_split($value);
    }

    private function letterIndexes(array $characters): array
    {
        $indexes = [];
        foreach ($characters as $index => $character) {
            if (preg_match('/[\p{L}\p{N}]/u', $character)) {
                $indexes[] = $index;
            }
        }
        return $indexes;
    }

    private function transpositionIndex(array $characters): ?int
    {
        for ($index = 0, $total = count($characters) - 1; $index < $total; $index++) {
            if (
                preg_match('/\p{L}/u', $characters[$index])
                && preg_match('/\p{L}/u', $characters[$index + 1])
                && $this->lower($characters[$index]) !== $this->lower($characters[$index + 1])
            ) {
                return $index;
            }
        }
        return null;
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    private function upper(string $value): string
    {
        return function_exists('mb_strtoupper')
            ? mb_strtoupper($value, 'UTF-8')
            : strtoupper($value);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function textSlice(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, $start, $length, 'UTF-8')
            : substr($value, $start, $length);
    }
}
