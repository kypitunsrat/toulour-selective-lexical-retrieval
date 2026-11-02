<?php
/** Validate frozen sample without invoking SearchService. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require_once __DIR__.'/FinalHoldoutSampler.php';
if($argc!==2){fwrite(STDERR,"Usage: php experiments/check_final_holdout_cli.php PRIVATE_JSON\n");exit(2);}
$private=$argv[1];
if(!is_file($private))throw new RuntimeException('Private sample file not found');
$x=json_decode(file_get_contents($private),true,512,JSON_THROW_ON_ERROR);
if(($x['format']??'')!==FinalHoldoutSampler::FORMAT || ($x['seed']??'')!==FinalHoldoutSampler::SEED ||($x['status']??'')!=='PREPARED_NOT_BENCHMARKED')throw new RuntimeException('Dataset header invalid');
$before=__DIR__.'/../fixtures/toulour_historical_exclusions.json';
if(hash_file('sha256',$before)!==($x['historical_exclusion_sha256']??''))throw new RuntimeException('Blacklist changed');
$old=json_decode(file_get_contents($before),true,512,JSON_THROW_ON_ERROR);
[$historicalIds,$historicalKeys]=FinalHoldoutSampler::historicalExclusions($old);
$roots=[];$keys=[];$counts=[];
foreach(($x['cases']??[]) as $row){
    $id=(int)($row['source_root_id']??0);
    if($id<1||isset($roots[$id])||isset($historicalIds[$id]))throw new RuntimeException('Duplicate or historical source');
    $roots[$id]=true;
    $k=FinalHoldoutSampler::queryKey((string)$row['direction'],(string)$row['keyword']);
    if(isset($keys[$k])||isset($historicalKeys[$k]))throw new RuntimeException('Duplicate or historical query');
    $keys[$k]=true;
    if(($row['label_scope']??'')!=='SOURCE_ANCHOR_ONLY_NOT_EXHAUSTIVE')throw new RuntimeException('Wrong label scope');
    $key=$row['direction'].'|'.$row['operation'];$counts[$key]=($counts[$key]??0)+1;
}
if(count($roots)!==600||count($keys)!==600)throw new RuntimeException('Expected 600 unique roots and queries');
foreach(FinalHoldoutSampler::DIRECTIONS as $dir)foreach(FinalHoldoutSampler::OPERATIONS as $op)
    if(($counts["$dir|$op"]??0)!==50)throw new RuntimeException("Quota failure $dir/$op");
echo "PASS: Frozen dataset QA -- 600 cases; zero historical overlap; zero duplicate roots; zero duplicate direction/query keys.\n";
echo "Dataset is SOURCE-ANCHORED SYNTHETIC, NOT HUMAN-VALIDATED and NOT YET BENCHMARKED.\n";
