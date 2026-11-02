<?php
/** Research-only frozen holdout preflight. No retrieval and no database writes. */
require_once __DIR__.'/FinalHoldoutSampler.php';
require_once __DIR__.'/IndependentBenchmarkCommon.php';
final class FinalHoldoutPreflight
{
    public const APPROVED_MANIFEST_SHA256='289f86b925e41fbc022e03586fb3e59820f1a464c3a97056a43c87fa9419f618';
    public const SCENARIOS=['adaptive_full','v2_context_rank','v22_context_short_swap_fast',
        'fixed2_context_guard','fixed3_context_guard','fixed2_osa_context_guard'];
    public static function requireSame(bool $ok,string $why):void {if(!$ok)throw new RuntimeException('FINAL HOLDOUT QA FAILED: '.$why);}
    public static function open(string $inputDir):array
    {
        $in=realpath($inputDir);
        self::requireSame($in!==false && is_dir($in),'input folder missing');
        $path=$in.DIRECTORY_SEPARATOR.'FINAL_HOLDOUT_QA_MANIFEST_4ead1cc1bf5f.json';
        self::requireSame(is_file($path) && hash_file('sha256',$path)===self::APPROVED_MANIFEST_SHA256,'the approved QA manifest is missing or changed');
        $embedded=__DIR__.'/../fixtures/final_holdout_approved_manifest.json';
        self::requireSame(is_file($embedded) && hash_file('sha256',$embedded)===self::APPROVED_MANIFEST_SHA256,'embedded approval manifest changed');
        $m=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        self::requireSame(($m['protocol']??'')==='FINAL_HOLDOUT_SAMPLING_LOCKED_R01' && ($m['status']??'')==='PREPARED_NOT_BENCHMARKED','manifest status invalid');
        self::requireSame(($m['frozen_comparison_scenarios']??null)===self::SCENARIOS,'scenario whitelist differs');
        self::requireSame(($m['source_label_policy']??'')==='ONLY source Hit@1 / Hit@5 / MRR; no F1, no validated negatives','wrong label provenance');
        self::requireSame(($m['approved_corpus_count']??0)===4587,'unexpected approved corpus count');
        self::requireSame(($m['approved_corpus_sha256']??'')==='bd524c4b8e694a96434ce96939bd515aabca3fec07ed97daf7ff54ca388c5103','unexpected corpus SHA256');
        $policyPath=__DIR__.'/../fixtures/toulour_final_freeze_policy.json';
        $histPath=__DIR__.'/../fixtures/toulour_historical_exclusions.json';
        self::requireSame(hash_file('sha256',$policyPath)===($m['freeze_policy_sha256']??''),'freeze policy hash differs');
        self::requireSame(hash_file('sha256',$histPath)===($m['historical_exclusion_sha256']??''),'historical exclusion hash differs');
        self::requireSame(hash_file('sha256',__DIR__.'/FinalHoldoutSampler.php')===($m['generator_sha256']??''),'generator hash differs');
        self::requireSame(hash_file('sha256',__DIR__.'/prepare_final_holdout_cli.php')===($m['runner_sha256']??''),'generator CLI hash differs');
        $policy=json_decode(file_get_contents($policyPath),true,512,JSON_THROW_ON_ERROR);
        self::requireSame(($policy['comparison_scenarios']??null)===self::SCENARIOS,'freeze policy scenario mismatch');
        self::requireSame(($policy['code_hashes']??null)===($m['project_code_hashes']??null),'locked code hashes differ');
        $root=dirname(__DIR__);
        foreach($m['project_code_hashes'] as $relative=>$digest) {
            $file=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
            self::requireSame(is_file($file) && hash_file('sha256',$file)===$digest,'frozen PHP changed: '.$relative);
        }
        $aFile=$in.DIRECTORY_SEPARATOR.($m['files']['private_anchors']['name']??'');
        $qFile=$in.DIRECTORY_SEPARATOR.($m['files']['public_queries']['name']??'');
        self::requireSame(basename($aFile)==='FINAL_HOLDOUT_PRIVATE_ANCHORS_4ead1cc1bf5f.json','private anchor filename differs');
        self::requireSame(basename($qFile)==='FINAL_HOLDOUT_PUBLIC_QUERIES_4ead1cc1bf5f.csv','public query filename differs');
        self::requireSame(is_file($aFile)&&hash_file('sha256',$aFile)===$m['files']['private_anchors']['sha256'],'private anchor hash differs');
        self::requireSame(is_file($qFile)&&hash_file('sha256',$qFile)===$m['files']['public_queries']['sha256'],'public CSV hash differs');
        $a=json_decode(file_get_contents($aFile),true,512,JSON_THROW_ON_ERROR);
        self::requireSame(($a['format']??'')===FinalHoldoutSampler::FORMAT && ($a['seed']??'')===FinalHoldoutSampler::SEED && ($a['status']??'')==='PREPARED_NOT_BENCHMARKED','private dataset header invalid');
        self::requireSame(($a['sampling_uses_search_outputs']??null)===false && ($a['labels_not_exhaustive']??null)===true,'source label/sampling provenance differs');
        self::requireSame(($a['approved_corpus_sha256']??'')===$m['approved_corpus_sha256'],'private corpus hash differs');
        self::requireSame(($a['pre_registration_sha256']??'')===$m['freeze_policy_sha256'],'private preregistration hash differs');
        self::requireSame(($a['historical_exclusion_sha256']??'')===$m['historical_exclusion_sha256'],'private exclusion hash differs');
        self::requireSame(($a['frozen_comparison_scenarios']??null)===self::SCENARIOS,'private comparison list differs');
        self::requireSame(($a['counts']['cases']??null)===600 && count($a['cases']??[])===600,'case count differs');
        self::requireSame(($a['counts']??null)===($m['counts']??null),'private and manifest counts differ');
        self::requireSame(($a['allocation']??null)===($m['allocation']??null),'private allocation differs');
        self::requireSame(($a['historical_exclusion_counts']??null)===($m['historical_exclusion_counts']??null),'private excluded counts differ');
        $hist=json_decode(file_get_contents($histPath),true,512,JSON_THROW_ON_ERROR);
        [$historicIds,$historicQueries]=FinalHoldoutSampler::historicalExclusions($hist);
        self::requireSame(count($historicIds)===1301 && count($historicQueries)===1722,'history exclusions unexpectedly changed');
        $csv=IndependentBenchmarkCommon::readCsv($qFile,['case_id','direction','operation','keyword','label_scope']);
        self::requireSame(count($csv)===600,'public CSV count differs');
        $seenIds=[];$seenKeys=[];$seenCases=[];$quota=[];
        foreach($a['cases'] as $i=>$c) {
            $cid=$c['case_id']??'';$rootId=(int)($c['source_root_id']??0);$dir=$c['direction']??'';$op=$c['operation']??'';
            self::requireSame($cid===sprintf('F%04d',$i+1),'case ID/order invalid');
            self::requireSame($rootId>0 && $rootId===(int)($c['source_dictionary_id']??0) && !isset($seenIds[$rootId]) && !isset($historicIds[$rootId]),'duplicate/historical source');
            $seenIds[$rootId]=true;
            self::requireSame(in_array($dir,FinalHoldoutSampler::DIRECTIONS,true) && in_array($op,FinalHoldoutSampler::OPERATIONS,true),'bad direction/operation');
            $key=FinalHoldoutSampler::queryKey($dir,(string)($c['keyword']??''));
            self::requireSame(!isset($seenKeys[$key])&&!isset($historicQueries[$key]),'duplicate/historical keyword');
            $seenKeys[$key]=true;
            self::requireSame(($c['label_scope']??'')==='SOURCE_ANCHOR_ONLY_NOT_EXHAUSTIVE','label scope wrong');
            $g="$dir|$op";$quota[$g]=($quota[$g]??0)+1;
            $row=$csv[$i];
            foreach(['case_id','direction','operation','label_scope'] as $f)self::requireSame(($row[$f]??'')===($c[$f]??''),'public/private mismatch case '.$cid.' '.$f);
            self::requireSame(($row['keyword']??'')===IndependentBenchmarkCommon::safeOutput((string)$c['keyword']),'public/private keyword mismatch '.$cid);
            $seenCases[$cid]=true;
        }
        foreach(FinalHoldoutSampler::DIRECTIONS as $dir)foreach(FinalHoldoutSampler::OPERATIONS as $op)
            self::requireSame(($quota["$dir|$op"]??0)===50,'quota mismatch '.$dir.'/'.$op);
        return ['manifest'=>$m,'anchors'=>$a,'public'=>$csv,'anchor_path'=>$aFile,'public_path'=>$qFile,'manifest_path'=>$path,'historical'=>$hist];
    }
}
