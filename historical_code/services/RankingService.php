<?php

/**
 * RankingService
 *
 * Menyediakan ranking pencarian yang deterministik dan dua mode penggunaan:
 *
 * 1. application: relevansi tetap menjadi faktor utama, popularitas hanya
 *    dipakai sebagai tie-breaker yang dibatasi.
 * 2. experiment: popularitas dan search_count diabaikan agar hasil benchmark
 *    dapat direplikasi menggunakan dataset dan konfigurasi yang sama.
 *
 * Prinsip skor: nilai yang lebih kecil memperoleh peringkat lebih tinggi.
 */
class RankingService
{
    public const MODE_APPLICATION = 'application';
    public const MODE_EXPERIMENT = 'experiment';

    public const MATCH_EXACT = 'exact';
    public const MATCH_PREFIX = 'prefix';
    public const MATCH_FUZZY = 'fuzzy';

    /**
     * Match type dipisahkan dengan rentang besar agar popularitas tidak dapat
     * membuat fuzzy match mengalahkan exact atau prefix match.
     */
    private const MATCH_TYPE_PENALTIES = [
        self::MATCH_EXACT => 0,
        self::MATCH_PREFIX => 1000000,
        self::MATCH_FUZZY => 2000000
    ];

    /** Setiap kenaikan satu edit distance mempunyai penalti tetap. */
    private const EDIT_DISTANCE_WEIGHT = 10000;

    /** Setiap penurunan satu persen similarity mempunyai penalti tetap. */
    private const SIMILARITY_WEIGHT = 100;

    /**
     * Bonus popularitas harus lebih kecil daripada satu poin similarity.
     * Dengan demikian popularitas hanya memecahkan hasil yang hampir setara.
     */
    private const MAX_POPULARITY_BONUS = 99.0;
    private const POPULARITY_LOG_WEIGHT = 4.0;
    private const SEARCH_COUNT_LOG_WEIGHT = 2.0;

    private string $mode;

    public function __construct($mode = self::MODE_APPLICATION)
    {
        $this->mode = $this->normalizeMode($mode);
    }

    /**
     * Mengubah mode ranking tanpa memutus pemanggilan lama.
     */
    public function setMode($mode): self
    {
        $this->mode = $this->normalizeMode($mode);
        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Method kompatibilitas untuk SearchService tahap 1.
     *
     * Signature tiga parameter lama tetap valid. Parameter keempat opsional
     * dapat digunakan untuk memaksa mode pada satu perhitungan.
     *
     * @param int|float   $distance
     * @param int|float   $popularity
     * @param int|float   $searchCount
     * @param string|null $mode
     */
    public function calculateScore($distance, $popularity, $searchCount, $mode = null): float
    {
        $distance = max(0, (int) $distance);
        $activeMode = $mode === null ? $this->mode : $this->normalizeMode($mode);
        $baseScore = $distance * self::EDIT_DISTANCE_WEIGHT;
        $popularityBonus = $activeMode === self::MODE_APPLICATION
            ? $this->calculatePopularityBonus($popularity, $searchCount)
            : 0.0;

        return round($baseScore - $popularityBonus, 4);
    }

    /**
     * Formula ranking lengkap yang akan digunakan setelah integrasi lanjutan
     * pada SearchService dan EvaluationController.
     *
     * Urutan kepentingan:
     * 1. match_type;
     * 2. edit_distance;
     * 3. similarity_percentage;
     * 4. popularity/search_count hanya dalam mode application.
     */
    public function calculateSearchScore(
        $matchType,
        $editDistance,
        $similarityPercentage,
        $popularity = 0,
        $searchCount = 0,
        $mode = null
    ): float {
        $breakdown = $this->explainSearchScore(
            $matchType,
            $editDistance,
            $similarityPercentage,
            $popularity,
            $searchCount,
            $mode
        );

        return $breakdown['final_score'];
    }

    /**
     * Mengembalikan komponen skor untuk audit, pengujian, dan penjelasan
     * formula di bagian metodologi artikel.
     */
    public function explainSearchScore(
        $matchType,
        $editDistance,
        $similarityPercentage,
        $popularity = 0,
        $searchCount = 0,
        $mode = null
    ): array {
        $matchType = $this->normalizeMatchType($matchType);
        $editDistance = max(0, (int) $editDistance);
        $similarityPercentage = $this->clamp((float) $similarityPercentage, 0.0, 100.0);
        $activeMode = $mode === null ? $this->mode : $this->normalizeMode($mode);

        $matchTypePenalty = self::MATCH_TYPE_PENALTIES[$matchType];
        $editDistancePenalty = $editDistance * self::EDIT_DISTANCE_WEIGHT;
        $similarityPenalty = (100.0 - $similarityPercentage) * self::SIMILARITY_WEIGHT;
        $popularityBonus = $activeMode === self::MODE_APPLICATION
            ? $this->calculatePopularityBonus($popularity, $searchCount)
            : 0.0;

        $finalScore = $matchTypePenalty
            + $editDistancePenalty
            + $similarityPenalty
            - $popularityBonus;

        return [
            'mode' => $activeMode,
            'match_type' => $matchType,
            'match_type_penalty' => $matchTypePenalty,
            'edit_distance' => $editDistance,
            'edit_distance_penalty' => $editDistancePenalty,
            'similarity_percentage' => round($similarityPercentage, 4),
            'similarity_penalty' => round($similarityPenalty, 4),
            'popularity_bonus' => round($popularityBonus, 4),
            'final_score' => round($finalScore, 4)
        ];
    }

    /**
     * Konfigurasi eksplisit agar bobot eksperimen dapat dicatat bersama hasil.
     */
    public function getSearchRankingConfiguration(): array
    {
        return [
            'mode' => $this->mode,
            'lower_score_is_better' => true,
            'match_type_penalties' => self::MATCH_TYPE_PENALTIES,
            'edit_distance_weight' => self::EDIT_DISTANCE_WEIGHT,
            'similarity_weight' => self::SIMILARITY_WEIGHT,
            'maximum_popularity_bonus' => self::MAX_POPULARITY_BONUS,
            'popularity_log_weight' => self::POPULARITY_LOG_WEIGHT,
            'search_count_log_weight' => self::SEARCH_COUNT_LOG_WEIGHT
        ];
    }

    /**
     * Menentukan tingkatan dan badge pengguna berdasarkan jumlah poin.
     * Method ini dipertahankan agar fitur gamifikasi lama tidak rusak.
     */
    public function getUserTier($points): array
    {
        $points = max(0, (int) $points);

        if ($points >= 151) {
            return [
                'name' => 'Tonaas',
                'icon' => '🛡️',
                'badge_class' => 'bg-warning text-dark',
                'text_class' => 'text-warning'
            ];
        }

        if ($points >= 76) {
            return [
                'name' => 'Taranak',
                'icon' => '🦉',
                'badge_class' => 'bg-info text-dark',
                'text_class' => 'text-info'
            ];
        }

        if ($points >= 26) {
            return [
                'name' => 'Kawanua',
                'icon' => '🏡',
                'badge_class' => 'bg-primary',
                'text_class' => 'text-primary'
            ];
        }

        return [
            'name' => 'Tou',
            'icon' => '🥉',
            'badge_class' => 'bg-secondary',
            'text_class' => 'text-secondary'
        ];
    }

    /**
     * Bonus memakai pertumbuhan logaritmik agar trafik yang sangat besar tidak
     * mendominasi relevansi linguistik. Nilainya selalu dibatasi < 100.
     */
    private function calculatePopularityBonus($popularity, $searchCount): float
    {
        $popularity = max(0, (int) $popularity);
        $searchCount = max(0, (int) $searchCount);

        $bonus = (log(1 + $popularity, 2) * self::POPULARITY_LOG_WEIGHT)
            + (log(1 + $searchCount, 2) * self::SEARCH_COUNT_LOG_WEIGHT);

        return min(self::MAX_POPULARITY_BONUS, $bonus);
    }

    private function normalizeMode($mode): string
    {
        return $mode === self::MODE_EXPERIMENT
            ? self::MODE_EXPERIMENT
            : self::MODE_APPLICATION;
    }

    private function normalizeMatchType($matchType): string
    {
        $matchType = strtolower(trim((string) $matchType));

        return array_key_exists($matchType, self::MATCH_TYPE_PENALTIES)
            ? $matchType
            : self::MATCH_FUZZY;
    }

    private function clamp(float $value, float $minimum, float $maximum): float
    {
        return max($minimum, min($maximum, $value));
    }
}
