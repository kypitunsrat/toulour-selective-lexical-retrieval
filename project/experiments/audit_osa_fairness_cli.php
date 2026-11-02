<?php
/**
 * Supplemental OSA implementation fairness microbenchmark.
 * NO database, NO holdout queries, NO modifications to the frozen algorithms.
 * Usage: php experiments/audit_osa_fairness_cli.php C:\\toulour_osa_audit_results [repetitions]
 */
declare(strict_types=1);
require_once __DIR__ . '/../services/SearchService.php';
require_once __DIR__ . '/../tests/RegressionFixture.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Run via CLI.\n"); exit(2); }
$asciiOnlyDiagnostic = getenv('TOULOUR_AUDIT_ASCII_ONLY') === '1';
if (!function_exists('mb_strlen') && !$asciiOnlyDiagnostic) {
    fwrite(STDERR, "ERROR: mbstring is required for correct Unicode length handling in the current SearchService. Enable extension=mbstring in php.ini and rerun.\n");
    exit(3);
}
if ($asciiOnlyDiagnostic) fwrite(STDERR, "WARNING: ASCII-only local diagnostic; not sufficient for full Unicode audit.\n");
$outDir = $argv[1] ?? (__DIR__ . '/../../osa_fairness_results');
$repetitions = max(3, min(15, (int) ($argv[2] ?? 7)));
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    throw new RuntimeException('Cannot create output dir: ' . $outDir);
}
// Audit only the frozen source used by the final holdout R01.
$frozenSearchSha = 'bd92f47062730315e21939ca2f0eb3c94dd8e28b30a9db93e816d0f75f30e95f';
$installedSha = hash_file('sha256',__DIR__.'/../services/SearchService.php');
if ($installedSha !== $frozenSearchSha) {
    fwrite(STDERR, "ERROR: SearchService.php differs from final holdout code freeze.\nExpected: $frozenSearchSha\nFound:    $installedSha\n");
    exit(4);
}
$svc = fixtureSearchService();
$rc = new ReflectionClass(SearchService::class);
$levMethod = $rc->getMethod('unicodeLevenshtein');
$osaMethod = $rc->getMethod('unicodeOptimalStringAlignment');

// All-userland PHP Unicode Levenshtein reference: OSA comparator codepath parity.
function auditPhpUnicodeLevenshtein(string $source, string $target, int $cap): int {
    $a = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY);
    $b = preg_split('//u', $target, -1, PREG_SPLIT_NO_EMPTY);
    if ($a === false || $b === false) throw new InvalidArgumentException('Bad UTF-8');
    $m = count($a); $n = count($b);
    if (abs($m-$n) > $cap) return $cap+1;
    if ($m===0 || $n===0) return min($cap+1, max($m,$n));
    $previous = range(0,$n);
    for ($i=1; $i<=$m; $i++) {
        $current = [$i];
        for ($j=1; $j<=$n; $j++) {
            $current[$j] = min($current[$j-1]+1, $previous[$j]+1,
                $previous[$j-1]+($a[$i-1] === $b[$j-1] ? 0 : 1));
        }
        $previous = $current;
    }
    return min($cap+1, $previous[$n]);
}
function auditRandomChars(int $n, bool $unicode): array {
    $alphabet = $unicode ? ['a','é','b','ñ','c','ö','d','a','f','ú','g']
                         : ['a','b','c','d','e','f','g','h','i','j','k','l'];
    $s=[];
    for ($k=0; $k<$n; $k++) $s[]=$alphabet[mt_rand(0, count($alphabet)-1)];
    return $s;
}
mt_srand(20261019);
$pairs=[];
foreach ([3,4,6,8,11,16] as $length) {
    for ($seed=0; $seed<32; $seed++) {
        $unicode=(!$asciiOnlyDiagnostic && $seed % 5 === 0);
        $a=auditRandomChars($length, $unicode);
        $pos=(int) floor(($length-1)/2);
        $base=implode('',$a);
        $variants=['exact'=>$a];
        $swap=$a; [$swap[$pos],$swap[$pos+1]] = [$swap[$pos+1],$swap[$pos]];
        $variants['adjacent_swap']=$swap;
        $sub=$a; $sub[$pos] = $sub[$pos]==='z'?'y':'z';
        $variants['substitution']=$sub;
        $ins=$a; array_splice($ins,$pos,0,[$unicode?'é':'q']);
        $variants['insertion']=$ins;
        $del=$a; array_splice($del,$pos,1);
        $variants['deletion']=$del;
        $variants['unrelated']=auditRandomChars($length,$unicode);
        foreach ($variants as $name=>$v) {
            $other=implode('',$v);
            foreach ([1,2,3] as $cap) {
                $pairs[]=['group'=>$name, 'length'=>$length, 'unicode'=>$unicode,
                    'query'=>$base, 'candidate'=>$other, 'cap'=>$cap];
            }
        }
    }
}
$impls = [
 'app_levenshtein' => static function($p) use ($levMethod,$svc): int {
     return $levMethod->invoke($svc,$p['query'],$p['candidate'],$p['cap']);
 },
 'pure_php_levenshtein' => static function($p): int {
     return auditPhpUnicodeLevenshtein($p['query'],$p['candidate'],$p['cap']);
 },
 'app_osa' => static function($p) use ($osaMethod,$svc): int {
     return $osaMethod->invoke($svc,$p['query'],$p['candidate'],$p['cap']);
 },
];
// Verify equivalent cutoff semantics before timing; OSA can legitimately
// differ in distance where a transposition lowers the edit cost.
foreach ($pairs as $ix=>$p) {
 $lev=$impls['app_levenshtein']($p);
 $php=$impls['pure_php_levenshtein']($p);
 $osa=$impls['app_osa']($p);
 if (min($p['cap']+1,$lev)!==$php || $osa>min($p['cap']+1,$lev)) {
   throw new RuntimeException("Correctness check FAILED at case $ix (appLev=$lev phpLev=$php osa=$osa)");
 }
}
$warmups=2;
foreach ($impls as $fn) for($k=0;$k<$warmups;$k++) foreach($pairs as $p) $fn($p);
// Whole-set timed passes with rotating order. Do not use frozen test input.
$samples=[]; $names=array_keys($impls);
for($rep=0; $rep<$repetitions; $rep++) {
  $order=array_merge(array_slice($names,$rep%3),array_slice($names,0,$rep%3));
  foreach($order as $name) {
   $fn=$impls[$name];
   $start=hrtime(true);
   $checksum=0;
   foreach($pairs as $p) $checksum += $fn($p);
   $ms=(hrtime(true)-$start)/1e6;
   $samples[]=['repetition'=>$rep+1,'implementation'=>$name,
       'total_ms'=>round($ms,6), 'microseconds_per_pair'=>round($ms*1000/count($pairs),4),
       'checksummed_sum'=>$checksum];
  }
}
function auditCsv(string $path, array $rows): void {
  $f=fopen($path,'wb'); if (!$f) throw new RuntimeException('Cannot open '.$path);
  fputcsv($f,array_keys($rows[0])); foreach($rows as $row) fputcsv($f,array_values($row)); fclose($f);
}
function auditMedian(array $vals): float {
 sort($vals,SORT_NUMERIC); $n=count($vals);
 return ($n%2) ? $vals[intdiv($n,2)] : ($vals[$n/2-1]+$vals[$n/2])/2;
}
$summary=[];
foreach ($impls as $name=>$_) {
 $values=[]; foreach ($samples as $row) if ($row['implementation']===$name) $values[]=$row['microseconds_per_pair'];
 $summary[]=['implementation'=>$name,'repetitions'=>count($values), 'pair_count'=>count($pairs),
   'median_us_per_pair'=>round(auditMedian($values),4),
   'min_us_per_pair'=>round(min($values),4), 'max_us_per_pair'=>round(max($values),4)];
}
$stamp=date('Ymd_His');
$csvSamples="$outDir/osa_implementation_timing_$stamp.csv";
$csvSummary="$outDir/osa_implementation_summary_$stamp.csv";
auditCsv($csvSamples,$samples);auditCsv($csvSummary,$summary);
$files=['services/SearchService.php','tests/RegressionFixture.php','experiments/audit_osa_fairness_cli.php'];
$hashes=[];foreach($files as $f) $hashes[$f]=hash_file('sha256',__DIR__.'/../'.$f);
$manifest=['experiment'=>'SUPPLEMENTARY_OSA_CODEPATH_FAIRNESS_MICROBENCH_NOT_FINAL_RETEST',
 'date'=>date(DATE_ATOM),'case_count'=>count($pairs),'seed'=>20261019,
 'repetitions'=>$repetitions,'warmups'=>$warmups,'php_version'=>PHP_VERSION,
 'mbstring_enabled'=>function_exists('mb_strlen'),
 'no_database'=>true,'no_holdout_cases'=>true,'ascii_only_diagnostic'=>$asciiOnlyDiagnostic,
 'code_sha256'=>$hashes,
 'scope_warning'=>'Only isolated distance-function wall clock time. NOT end-to-end retrieval latency or an intrinsically fair cross-algorithm comparison. Never modify the frozen final holdout based on these timings.',
 'outputs'=>[basename($csvSamples),basename($csvSummary)]];
$manifestPath="$outDir/osa_implementation_manifest_$stamp.json";
file_put_contents($manifestPath,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
fwrite(STDOUT,"PASS: ".count($pairs)." deterministic pairs, 3 implementation paths, $repetitions repetitions.\n");
foreach($summary as $row) fwrite(STDOUT,sprintf("  %-24s median %.3f us/pair\n",$row['implementation'],$row['median_us_per_pair']));
fwrite(STDOUT,"Outputs: $csvSummary, $csvSamples, $manifestPath\n");
