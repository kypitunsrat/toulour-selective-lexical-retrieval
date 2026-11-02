<?php
/** php experiments/prepare_final_holdout_cli.php C:\toulour_final_holdout_input */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require_once __DIR__.'/FinalHoldoutSampler.php';
require_once __DIR__.'/IndependentBenchmarkCommon.php';
require_once __DIR__.'/../config/database.php';
if($argc!==2){fwrite(STDERR,"Usage: php experiments/prepare_final_holdout_cli.php OUTPUT_DIR\n");exit(2);}
$projectRoot=dirname(__DIR__);
$freezeFile=__DIR__.'/../fixtures/toulour_final_freeze_policy.json';
$historicalFile=__DIR__.'/../fixtures/toulour_historical_exclusions.json';
if(!is_readable($freezeFile)||!is_readable($historicalFile))throw new RuntimeException('Missing frozen policy/legacy exclusions');
$policy=json_decode(file_get_contents($freezeFile),true,512,JSON_THROW_ON_ERROR);
$historical=json_decode(file_get_contents($historicalFile),true,512,JSON_THROW_ON_ERROR);
foreach(($policy['code_hashes']??[]) as $relative=>$expected){
    $path=$projectRoot.'/'.str_replace('\\','/',$relative);
    if(!is_file($path)||hash_file('sha256',$path)!==$expected)throw new RuntimeException('CODE FREEZE MISMATCH: '.$relative.'. STOP, do not modify or run final benchmark.');
}
if(hash_file('sha256',$historicalFile)!==($policy['exclusions_sha256']??''))throw new RuntimeException('Historical blacklist hash mismatch');
$db=Database::getInstance();
$before=IndependentBenchmarkCommon::dictionaryFingerprint($db);
if($before['sha256']!==$policy['approved_corpus_sha256'] || $before['count']!==(int)$policy['approved_corpus_count'])throw new RuntimeException('CORPUS SNAPSHOT MISMATCH: stop and re-audit, do not sample.');
$entries=$db->query("SELECT id,tolour_word,indonesian_meaning FROM dictionary WHERE status='disetujui' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$dataset=FinalHoldoutSampler::generate($entries,$historical);
$after=IndependentBenchmarkCommon::dictionaryFingerprint($db);
if($after['sha256']!==$before['sha256']||$after['count']!==$before['count'])throw new RuntimeException('Corpus changed during generation. STOP.');
$dataset['approved_corpus_sha256']=$before['sha256'];$dataset['approved_corpus_count']=$before['count'];
$dataset['frozen_comparison_scenarios']=$policy['comparison_scenarios'];
$dataset['pre_registration_sha256']=hash_file('sha256',$freezeFile);
$dataset['historical_exclusion_sha256']=hash_file('sha256',$historicalFile);
$out=IndependentBenchmarkCommon::outputDir($argv[1]);
$run=substr(hash('sha256',FinalHoldoutSampler::SEED.'|'.$before['sha256']),0,12);
$paths=['private'=>$out.DIRECTORY_SEPARATOR.'FINAL_HOLDOUT_PRIVATE_ANCHORS_'.$run.'.json',
        'public'=>$out.DIRECTORY_SEPARATOR.'FINAL_HOLDOUT_PUBLIC_QUERIES_'.$run.'.csv',
        'manifest'=>$out.DIRECTORY_SEPARATOR.'FINAL_HOLDOUT_QA_MANIFEST_'.$run.'.json'];
foreach($paths as $p)if(file_exists($p))throw new RuntimeException('File already exists, frozen output cannot be replaced: '.$p);
$public=fopen($paths['public'],'xb');if(!$public)throw new RuntimeException('Cannot create public query CSV');
fputcsv($public,['case_id','direction','operation','keyword','label_scope']);
foreach($dataset['cases'] as $r){
    fputcsv($public,[$r['case_id'],$r['direction'],$r['operation'],IndependentBenchmarkCommon::safeOutput($r['keyword']),$r['label_scope']]);
}
fclose($public);
$private=fopen($paths['private'],'xb');if(!$private)throw new RuntimeException('Cannot create PRIVATE source anchor file');
fwrite($private,json_encode($dataset,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));fclose($private);
$manifest=['protocol'=>'FINAL_HOLDOUT_SAMPLING_LOCKED_R01','status'=>'PREPARED_NOT_BENCHMARKED',
   'generated_at'=>date(DATE_ATOM),'seed'=>FinalHoldoutSampler::SEED,'source_label_policy'=>'ONLY source Hit@1 / Hit@5 / MRR; no F1, no validated negatives',
   'approved_corpus_sha256'=>$before['sha256'],'approved_corpus_count'=>$before['count'],
   'project_code_hashes'=>$policy['code_hashes'],'freeze_policy_sha256'=>hash_file('sha256',$freezeFile),
   'generator_sha256'=>hash_file('sha256',__DIR__.'/FinalHoldoutSampler.php'),
   'runner_sha256'=>hash_file('sha256',__FILE__),
   'historical_exclusion_sha256'=>hash_file('sha256',$historicalFile),
   'counts'=>$dataset['counts'],'allocation'=>$dataset['allocation'],
   'historical_exclusion_counts'=>$dataset['historical_exclusion_counts'],
   'frozen_comparison_scenarios'=>$dataset['frozen_comparison_scenarios'],
   'files'=>['private_anchors'=>['name'=>basename($paths['private']),'sha256'=>hash_file('sha256',$paths['private'])],
             'public_queries'=>['name'=>basename($paths['public']),'sha256'=>hash_file('sha256',$paths['public'])]],
   'warning'=>'DO NOT RUN FINAL BENCHMARK UNTIL QA MANIFEST IS APPROVED. Do not tune using selected cases.' ];
$m=fopen($paths['manifest'],'xb');if(!$m)throw new RuntimeException('Cannot create QA manifest');
fwrite($m,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fclose($m);
echo "PASS: FROZEN SAMPLE PREPARED, NOT BENCHMARKED\n";
echo "600 source-anchored cases, 600 unique roots, 300 per direction.\n";
echo 'Private dataset: '.$paths['private']."\n";
echo 'Public queries: '.$paths['public']."\n";
echo 'Upload ONLY QA manifest for review: '.$paths['manifest']."\n";
echo "Never edit the frozen case files or run benchmark before QA approval.\n";
