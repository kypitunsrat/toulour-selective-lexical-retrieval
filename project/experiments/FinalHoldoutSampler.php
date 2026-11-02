<?php
/**
 * Toulour held-out source-recovery sampler, preregistered 2026-10-19.
 * DOES NOT call SearchService, scoring, relevance labels or any ranking logic.
 * Exactly 600 anchored synthetic queries: 50 per direction x six operations.
 * Every case uses one source root globally. No OOV / no assumed no-match labels.
 */
final class FinalHoldoutSampler
{
    public const FORMAT = 'tolour_final_source_recovery_holdout_v1';
    public const SEED = 'TOULOUR-FINAL-HOLDOUT-20261019-LOCKED-R01';
    public const QUOTA_PER_OPERATION_DIRECTION = 50;
    public const OPERATIONS = ['exact','prefix','substitution','deletion','insertion','adjacent_swap'];
    public const DIRECTIONS = ['tolour_to_id','id_to_tolour'];
    private const REPLACEMENTS = ['b','d','f','g','h','j','k','l','m','n','p','r','s','t','v','w','x','z','a','e','i','o','u'];

    public static function normalize(string $text): string
    {
        $text=trim($text);
        if (class_exists('Normalizer')) $text=Normalizer::normalize($text,Normalizer::FORM_KC) ?: $text;
        $text=function_exists('mb_strtolower')? mb_strtolower($text,'UTF-8'):strtolower($text);
        $text=preg_replace('/\s+/u',' ',$text);
        if ($text===null) throw new RuntimeException('Invalid Unicode query');
        return trim($text);
    }
    public static function queryKey(string $direction,string $query): string
    {
        if(!in_array($direction,self::DIRECTIONS,true)) throw new InvalidArgumentException('Bad direction');
        return hash('sha256',$direction."\x1f".self::normalize($query));
    }
    public static function characters(string $v): array
    {
        $x=preg_split('//u',$v,-1,PREG_SPLIT_NO_EMPTY);
        if($x===false) throw new RuntimeException('Invalid UTF-8');
        return $x;
    }
    private static function choose(string $seed,string $tag,int $n):int
    {
        if($n<=0) throw new InvalidArgumentException('Empty choice');
        return hexdec(substr(hash('sha256',$seed.'|'.$tag),0,7))%$n;
    }
    public static function baseForRow(array $row,string $direction): ?string
    {
        if($direction==='tolour_to_id') {
            $word=self::normalize((string)$row['tolour_word']);
            return preg_match('/^\p{L}{2,16}$/u',$word) ? $word : null;
        }
        $meaning=self::normalize((string)$row['indonesian_meaning']);
        preg_match_all('/\p{L}{2,16}/u',$meaning,$matches);
        $tokens=array_values(array_unique($matches[0]??[]));
        // Stopwords remain eligible; query ambiguity is a measured property, not a selection filter.
        if(!$tokens) return null;
        $seed=self::SEED;
        return $tokens[self::choose($seed,'token|'.$row['id'],count($tokens))];
    }
    private static function eligible(string $base,string $operation):bool
    {
        $chars=self::characters($base);$n=count($chars);
        if($operation==='adjacent_swap'){
            if($n<2||$n>4)return false;
            for($i=0;$i<$n-1;$i++)if($chars[$i]!==$chars[$i+1])return true;
            return false;
        }
        if($operation==='prefix')return $n>=5&&$n<=16;
        return $n>=4&&$n<=16;
    }
    public static function mutation(string $base,string $operation,string $seed,string $context): string
    {
        $c=self::characters($base);$n=count($c);
        if(!self::eligible($base,$operation))throw new InvalidArgumentException('Ineligible operation');
        if($operation==='exact')return $base;
        if($operation==='prefix')return implode('',array_slice($c,0,max(2,(int)floor($n*0.65))));
        $pos=self::choose($seed,$context.'|position',$n);
        if($operation==='adjacent_swap'){
            $positions=[];
            for($i=0;$i<$n-1;$i++)if($c[$i]!==$c[$i+1])$positions[]=$i;
            $at=$positions[self::choose($seed,$context.'|swap',count($positions))];
            [$c[$at],$c[$at+1]]=[$c[$at+1],$c[$at]];
            return implode('',$c);
        }
        $first=self::choose($seed,$context.'|letter',count(self::REPLACEMENTS));
        $replacement=null;
        for($i=0;$i<count(self::REPLACEMENTS);$i++){
            $try=self::REPLACEMENTS[($first+$i)%count(self::REPLACEMENTS)];
            if($try!==$c[$pos]){$replacement=$try;break;}
        }
        if($replacement===null)throw new RuntimeException('No letter replacement');
        if($operation==='substitution')$c[$pos]=$replacement;
        elseif($operation==='deletion')array_splice($c,$pos,1);
        elseif($operation==='insertion')array_splice($c,$pos,0,[$replacement]);
        else throw new InvalidArgumentException('Unknown operation');
        return implode('',$c);
    }
    public static function historicalExclusions(array $historic):array
    {
        if(($historic['format']??'')!=='tolour_historical_exclusions_v1')throw new InvalidArgumentException('Incorrect exclusion format');
        if(count($historic['used_source_ids']??[])!==(int)($historic['source_id_count']??-1))throw new RuntimeException('Excluded ID count mismatch');
        if(count($historic['used_queries']??[])!==(int)($historic['query_count']??-1))throw new RuntimeException('Excluded query count mismatch');
        $ids=[];$keys=[];
        foreach($historic['used_source_ids'] as $id){$id=(int)$id;if($id<=0)throw new RuntimeException('Bad excluded ID');$ids[$id]=true;}
        foreach($historic['used_queries'] as $record){
            $keys[self::queryKey($record['direction'],(string)$record['keyword'])]=true;
        }
        return [$ids,$keys];
    }
    /** Precommitted selection: deterministic candidate permutation; never consult output scores. */
    public static function generate(array $entries,array $historic,string $seed=self::SEED):array
    {
        if($seed!==self::SEED)throw new InvalidArgumentException('Seed is frozen; no manual re-seeding');
        [$excludedIds,$excludedKeys]=self::historicalExclusions($historic);
        $original=array_values($entries);
        usort($original,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
        $seenInput=[];$pool=[];$technical=0;
        foreach($original as $row){
            $id=(int)$row['id'];
            if($id<=0||isset($seenInput[$id]))throw new RuntimeException('Bad/duplicate dictionary ID');
            $seenInput[$id]=true;
            if(isset($excludedIds[$id]))continue;
            foreach(self::DIRECTIONS as $dir){
                $base=self::baseForRow($row,$dir);
                if($base!==null)$pool[$dir][]=['id'=>$id,'base'=>$base];
                else $technical++;
            }
        }
        $chosen=[];$usedKeys=[];$cases=[];$metrics=[];$ordinal=0;
        foreach(self::DIRECTIONS as $dir)foreach(self::OPERATIONS as $operation){
            $eligible=array_values(array_filter($pool[$dir]??[],static fn($r)=>self::eligible($r['base'],$operation)));
            usort($eligible,static function($a,$b)use($seed,$dir,$operation){
                return strcmp(hash('sha256',"$seed|$dir|$operation|".$a['id'].'|'.$a['base']),hash('sha256',"$seed|$dir|$operation|".$b['id'].'|'.$b['base']));
            });
            $stat=['eligible_pool'=>count($eligible),'selected'=>0,'excluded_source'=>0,'excluded_historical_keyword'=>0,'excluded_duplicate_keyword'=>0];
            foreach($eligible as $r){
                if($stat['selected']>=self::QUOTA_PER_OPERATION_DIRECTION)break;
                if(isset($chosen[$r['id']])){$stat['excluded_source']++;continue;}
                $ctx=$dir.'|'.$operation.'|'.$r['id'].'|'.$r['base'];
                $keyword=self::mutation($r['base'],$operation,$seed,$ctx);
                $key=self::queryKey($dir,$keyword);
                if(isset($excludedKeys[$key])){$stat['excluded_historical_keyword']++;continue;}
                if(isset($usedKeys[$key])){$stat['excluded_duplicate_keyword']++;continue;}
                $stat['selected']++;$ordinal++;$chosen[$r['id']]=true;$usedKeys[$key]=true;
                $cases[]=['case_id'=>sprintf('F%04d',$ordinal),'direction'=>$dir,'operation'=>$operation,
                    'keyword'=>$keyword,'source_dictionary_id'=>$r['id'],'source_base'=>$r['base'],
                    'source_root_id'=>$r['id'],'label_scope'=>'SOURCE_ANCHOR_ONLY_NOT_EXHAUSTIVE',
                    'sampling_scope'=>'corpus_derived_synthetic_NOT_real_user'];
            }
            $metrics[$dir][$operation]=$stat;
            if($stat['selected']!==self::QUOTA_PER_OPERATION_DIRECTION)
                throw new RuntimeException("PRECOMMITTED QUOTA FAILED $dir/$operation: selected {$stat['selected']} of ".self::QUOTA_PER_OPERATION_DIRECTION.'. STOP: do not lower quota after inspecting retrieval.');
        }
        if(count($cases)!==600||count($usedKeys)!==600||count($chosen)!==600)throw new RuntimeException('Holdout cardinality/uniqueness failure');
        return ['format'=>self::FORMAT,'status'=>'PREPARED_NOT_BENCHMARKED','seed'=>$seed,'sampling_uses_search_outputs'=>false,
            'not_human_validated'=>true,'not_real_user_queries'=>true,'labels_not_exhaustive'=>true,
            'primary_metric'=>'source_hit_at_5_not_F1','cases'=>$cases,'allocation'=>$metrics,
            'counts'=>['cases'=>count($cases),'unique_source_roots'=>count($chosen),'distinct_direction_query_pairs'=>count($usedKeys)],
            'historical_exclusion_counts'=>['source_ids'=>count($excludedIds),'direction_query_keys'=>count($excludedKeys)]];
    }
}
