<?php
/**
 * ONE-TIME, ALGORITHM-BLIND, SOURCE-RECOVERY HOLDOUT TEST.
 * Usage: php experiments/run_final_holdout_cli.php INPUT_DIR OUTPUT_DIR [REPS=3]
 * No tuning, no relabeling, no F1/precision/recall for source anchors.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require_once __DIR__.'/FinalHoldoutPreflight.php';
require_once __DIR__.'/../services/SearchService.php';
if($argc<3||$argc>4){fwrite(STDERR,"Usage: php experiments/run_final_holdout_cli.php INPUT_DIR OUTPUT_DIR [REPS=3]\n");exit(2);}
$reps=$argc===4?filter_var($argv[3],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>5]]):3;
if($reps===false)throw new InvalidArgumentException('Repetitions must be 1..5');
// Preflight happens before any evaluation or output creation.
$holdout=FinalHoldoutPreflight::open($argv[1]);
$m=$holdout['manifest'];$cases=$holdout['anchors']['cases'];
$db=Database::getInstance();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='kamus_tolour_research')throw new RuntimeException('Expected research DB kamus_tolour_research only');
$before=IndependentBenchmarkCommon::dictionaryFingerprint($db);
if($before['count']!==$m['approved_corpus_count']||$before['sha256']!==$m['approved_corpus_sha256'])throw new RuntimeException('Corpus fingerprint changed. DO NOT run.');
$entries=$db->query("SELECT id,tolour_word,indonesian_meaning FROM dictionary WHERE status='disetujui' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$reproduced=FinalHoldoutSampler::generate($entries,$holdout['historical']);
if($reproduced['cases']!==$cases || $reproduced['allocation']!==$holdout['anchors']['allocation'])
    throw new RuntimeException('Deterministic regeneration disagrees with private dataset');
$methods=[];
foreach(FinalHoldoutPreflight::SCENARIOS as $sid)$methods[$sid]=ExperimentScenarioRegistry::get($sid);
$search=new SearchService();$search->setRankingMode(RankingService::MODE_EXPERIMENT);
$snapshot=$search->prepareExperimentSnapshot();
if((int)$snapshot['candidate_count']!==$before['count'])throw new RuntimeException('Snapshot candidate count changed');
$out=IndependentBenchmarkCommon::outputDir($argv[2]);
$sentinel=$out.DIRECTORY_SEPARATOR.'FINAL_HOLDOUT_ONE_TIME_RUN_STARTED.lock';
$lock=fopen($sentinel,'x');
if(!$lock)throw new RuntimeException('Final benchmark already started in this output directory. DO NOT RE-RUN without audit.');
fwrite($lock,"FROZEN FINAL EXPERIMENT; do not repeat or tune.\nApproved manifest SHA256: ".FinalHoldoutPreflight::APPROVED_MANIFEST_SHA256."\nStarted: ".date(DATE_ATOM)."\n");fclose($lock);
$stamp=date('Ymd_His').'_4ead1cc1bf5f';
$qFile=$out.DIRECTORY_SEPARATOR.'final_holdout_queries_'.$stamp.'.csv';
$sFile=$out.DIRECTORY_SEPARATOR.'final_holdout_summary_'.$stamp.'.csv';
$mFile=$out.DIRECTORY_SEPARATOR.'final_holdout_manifest_'.$stamp.'.json';
$fp=fopen($qFile,'xb');if(!$fp)throw new RuntimeException('Cannot create per-query output; lock preserved');
fputcsv($fp,['case_id','direction','operation','scenario_id','keyword','source_dictionary_id','source_rank_top5','source_hit_at1','source_hit_at5','source_reciprocal_rank','returned_ids','returned_count','latency_mean_ms','latency_median_ms','latency_p95_ms']);
$sum=[];$perMethodLatency=[];$progress=0;
function finalQuantile(array $xs,float $percent):float{
    if(!$xs)return 0.0;
    sort($xs,SORT_NUMERIC);$n=count($xs);$pos=($n-1)*$percent;$lo=(int)floor($pos);$hi=(int)ceil($pos);
    return $xs[$lo]+($xs[$hi]-$xs[$lo])*($pos-$lo);
}
try {
    foreach($cases as $i=>$c){
        $q=(string)$c['keyword'];$direction=(string)$c['direction'];$src=(int)$c['source_dictionary_id'];
        $scenarioIds=FinalHoldoutPreflight::SCENARIOS;
        $rot=$i%count($scenarioIds);$order=array_merge(array_slice($scenarioIds,$rot),array_slice($scenarioIds,0,$rot));
        foreach($order as $sid){
            $search->searchExperimental($q,$sid,$direction); // one warm-up before measured calls
            $stable=null;$samples=[];
            for($r=0;$r<$reps;$r++){
                $response=$search->searchExperimental($q,$sid,$direction);
                $ids=array_values(array_unique(array_map('intval',array_column($response['data'],'id'))));
                if(count($ids)>5)throw new RuntimeException('Expected <=5 returned IDs: '.$c['case_id'].' '.$sid);
                if($stable!==null&&$stable!==$ids)throw new RuntimeException('Nondeterministic ranked IDs: '.$c['case_id'].' '.$sid);
                $stable=$ids;
                $elapsed=(float)$response['time_ms'];
                if(!is_finite($elapsed)||$elapsed<0)throw new RuntimeException('Bad latency: '.$c['case_id'].' '.$sid);
                $samples[]=$elapsed;
            }
            $p=array_search($src,$stable,true);$rank=$p===false?null:$p+1;
            $h1=$rank===1?1:0;$h5=$rank!==null?1:0;$rr=$rank===null?0.0:1.0/$rank;
            $latMean=array_sum($samples)/count($samples);
            foreach(['all','direction:'.$direction,'operation:'.$c['operation'],'direction_operation:'.$direction.'|'.$c['operation']] as $g){
                if(!isset($sum[$sid][$g]))$sum[$sid][$g]=['n'=>0,'h1'=>0,'h5'=>0,'rr'=>0.0,'returned'=>0,'latencies'=>[]];
                $s=&$sum[$sid][$g];$s['n']++;$s['h1']+=$h1;$s['h5']+=$h5;$s['rr']+=$rr;$s['returned']+=count($stable);$s['latencies'][]=$latMean;unset($s);
            }
            fputcsv($fp,[$c['case_id'],$direction,$c['operation'],$sid,IndependentBenchmarkCommon::safeOutput($q),$src,
                $rank??'',$h1,$h5,$rr,implode(';',$stable),count($stable),$latMean,finalQuantile($samples,.5),finalQuantile($samples,.95)]);
        }
        $progress++;
        if($progress%25===0)echo 'Completed '.$progress.'/600 cases'.PHP_EOL;
    }
}finally {fclose($fp);}
$after=IndependentBenchmarkCommon::dictionaryFingerprint($db);
if($after['sha256']!==$before['sha256']||$after['count']!==$before['count'])throw new RuntimeException('Corpus changed during benchmark: DISCARD RUN, keep lock');
$sf=fopen($sFile,'xb');if(!$sf)throw new RuntimeException('Cannot create summary output');
fputcsv($sf,['scenario_id','group','n','source_hit_at1','source_hit_at5','source_mrr','mean_returned_count','latency_mean_ms','latency_median_ms','latency_p95_ms']);
foreach(FinalHoldoutPreflight::SCENARIOS as $sid)foreach($sum[$sid] as $group=>$v){
    $n=$v['n'];fputcsv($sf,[$sid,$group,$n,$v['h1']/$n,$v['h5']/$n,$v['rr']/$n,$v['returned']/$n,
        array_sum($v['latencies'])/$n,finalQuantile($v['latencies'],.5),finalQuantile($v['latencies'],.95)]);
}
fclose($sf);
$manifest=[
    'experiment_type'=>'FINAL_HOLDOUT_SOURCE_RECOVERY_LOCKED_R01','status'=>'COMPLETED_NO_TUNING',
    'completed_at'=>date(DATE_ATOM),'approved_qa_manifest_sha256'=>FinalHoldoutPreflight::APPROVED_MANIFEST_SHA256,
    'anchor_sha256'=>hash_file('sha256',$holdout['anchor_path']),'public_queries_sha256'=>hash_file('sha256',$holdout['public_path']),
    'approved_corpus_sha256'=>$before['sha256'],'approved_corpus_count'=>$before['count'],
    'scenarios'=>$methods,'case_count'=>600,'strata_count'=>12,'repetitions'=>$reps,'warmup_per_case_scenario'=>1,'scenario_order'=>'rotated_by_case',
    'latency_scope'=>'single-process preloaded corpus; internal search time only','php_version'=>PHP_VERSION,
    'provenance'=>'frozen synthetic source-anchored queries; no human-validated relevance, no real-user queries',
    'primary_outcome'=>'paired source_hit_at5 difference v22_context_short_swap_fast minus fixed2_context_guard, all 600 cases',
    'secondary_outcomes'=>['source_hit_at1','source_mrr','stratified direction_and_operation analyses','latency_mean_median_p95'],
    'interpretation_warning'=>'Source recovery DOES NOT measure relevance precision, recall, F1, or verified no-match. One frozen test; do not tune on these outcomes.',
    'source_code_hashes'=>$m['project_code_hashes'],
    'runner_sha256'=>hash_file('sha256',__FILE__),
    'results'=>[
      'queries'=>['name'=>basename($qFile),'sha256'=>hash_file('sha256',$qFile)],
      'summary'=>['name'=>basename($sFile),'sha256'=>hash_file('sha256',$sFile)]
    ]
];
file_put_contents($mFile,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
echo "PASS: FROZEN FINAL HOLDOUT FINISHED. DO NOT TUNE OR REPEAT.\nQueries: $qFile\nSummary: $sFile\nManifest: $mFile\n";
