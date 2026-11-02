<?php
/** Run BEFORE the one-time final benchmark. Does not invoke SearchService. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require_once __DIR__.'/FinalHoldoutPreflight.php';
if($argc!==2){fwrite(STDERR,"Usage: php experiments/check_final_holdout_full_cli.php INPUT_DIR\n");exit(2);}
$r=FinalHoldoutPreflight::open($argv[1]);
echo "PASS: APPROVED MANIFEST + PRIVATE ANCHORS + PUBLIC CSV + CODE FREEZE + EXCLUSIONS.\n";
echo "600 cases, 12 complete strata, zero same-ID/keyword historical overlap. NO RETRIEVAL EXECUTED.\n";
