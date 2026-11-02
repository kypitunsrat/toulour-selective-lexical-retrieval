"""Independent Python translation of the R01 sampler, packaged 2026-11-01.
This is a post-review consistency check, not original PHP execution, preregistration,
or a new retrieval experiment. Reads the approved-only public fixture; local packaging paths are adapted.
"""
from pathlib import Path
import collections
import csv
import hashlib
import json
import math
import re
import statistics
import unicodedata

ROOT = Path(__file__).resolve().parents[1]
PROJECT = ROOT / 'project'
BENCH = ROOT / 'data'
OUT = ROOT / 'verification_output'
OUT.mkdir(exist_ok=True)
db = json.loads((ROOT / 'data/lexical/approved_corpus.json').read_text())
entries = sorted((r for r in db['dictionary'] if r['status'] == 'disetujui'), key=lambda r:r['id'])
anchor = json.loads(next((BENCH/'holdout').glob('*PRIVATE*.json')).read_text())
historical = json.loads((PROJECT/'fixtures/toulour_historical_exclusions.json').read_text())
SEED = anchor['seed']

def normalize(text):
    return ' '.join(unicodedata.normalize('NFKC', text.strip()).lower().split())

def qkey(direction, text):
    return hashlib.sha256((direction+'\x1f'+normalize(text)).encode()).hexdigest()

def choose(tag, n):
    return int(hashlib.sha256((SEED+'|'+tag).encode()).hexdigest()[:7],16)%n

def letter_tokens(text):
    runs=[]; current=[]
    for char in text+' ':
        if unicodedata.category(char).startswith('L'):
            current.append(char)
        elif current:
            runs.append(''.join(current)); current=[]
    # Same greedy bounded matching as PHP /\p{L}{2,16}/u.
    return [run[i:i+16] for run in runs for i in range(0,len(run),16) if len(run[i:i+16])>=2]

excluded_ids=set(historical['used_source_ids'])
excluded_keys={qkey(r['direction'],r['keyword']) for r in historical['used_queries']}
pool=collections.defaultdict(list)
for row in entries:
    if row['id'] in excluded_ids:
        continue
    for direction in ['tolour_to_id','id_to_tolour']:
        if direction=='tolour_to_id':
            base=normalize(row['tolour_word'])
            if not (2<=len(base)<=16 and all(unicodedata.category(c).startswith('L') for c in base)):
                continue
        else:
            tokens=list(dict.fromkeys(letter_tokens(normalize(row['indonesian_meaning']))))
            if not tokens:
                continue
            base=tokens[choose('token|'+str(row['id']),len(tokens))]
        pool[direction].append({'id':row['id'],'base':base})

def eligible(base, operation):
    n=len(base)
    if operation=='adjacent_swap':
        return 2<=n<=4 and any(base[i]!=base[i+1] for i in range(n-1))
    if operation=='prefix':
        return 5<=n<=16
    return 4<=n<=16

LETTERS=list('bdfghjklmnprstvwxzaeiou')

def mutate(base, operation, context):
    chars=list(base); n=len(chars)
    if operation=='exact': return base
    if operation=='prefix': return base[:max(2,math.floor(n*.65))]
    position=choose(context+'|position',n)
    if operation=='adjacent_swap':
        positions=[i for i in range(n-1) if chars[i]!=chars[i+1]]
        at=positions[choose(context+'|swap',len(positions))]
        chars[at],chars[at+1]=chars[at+1],chars[at]
        return ''.join(chars)
    first=choose(context+'|letter',len(LETTERS))
    replacement=next(LETTERS[(first+i)%len(LETTERS)] for i in range(len(LETTERS)) if LETTERS[(first+i)%len(LETTERS)]!=chars[position])
    if operation=='substitution': chars[position]=replacement
    elif operation=='deletion': del chars[position]
    elif operation=='insertion': chars.insert(position,replacement)
    return ''.join(chars)

selected=set(); used=set(); regenerated=[]; allocation={}
for direction in ['tolour_to_id','id_to_tolour']:
    allocation[direction]={}
    for operation in ['exact','prefix','substitution','deletion','insertion','adjacent_swap']:
        candidates=[r for r in pool[direction] if eligible(r['base'],operation)]
        candidates.sort(key=lambda r:hashlib.sha256((SEED+'|'+direction+'|'+operation+'|'+str(r['id'])+'|'+r['base']).encode()).hexdigest())
        stat={'eligible_pool':len(candidates),'selected':0,'excluded_source':0,'excluded_historical_keyword':0,'excluded_duplicate_keyword':0}
        for row in candidates:
            if stat['selected']>=50: break
            if row['id'] in selected:
                stat['excluded_source']+=1; continue
            context=direction+'|'+operation+'|'+str(row['id'])+'|'+row['base']
            query=mutate(row['base'],operation,context); key=qkey(direction,query)
            if key in excluded_keys:
                stat['excluded_historical_keyword']+=1; continue
            if key in used:
                stat['excluded_duplicate_keyword']+=1; continue
            stat['selected']+=1; selected.add(row['id']); used.add(key)
            regenerated.append({'case_id':f'F{len(regenerated)+1:04d}','direction':direction,'operation':operation,'keyword':query,'source_dictionary_id':row['id'],'source_base':row['base'],'source_root_id':row['id'],'label_scope':'SOURCE_ANCHOR_ONLY_NOT_EXHAUSTIVE','sampling_scope':'corpus_derived_synthetic_NOT_real_user'})
        allocation[direction][operation]=stat

sampler_check={'independent_python_translation':True,'not_php_execution':True,
    'cases_match':regenerated==anchor['cases'],'allocation_match':allocation==anchor['allocation'],
    'source_overlap_with_exclusion_manifest':len(selected&excluded_ids),
    'query_overlap_with_exclusion_manifest':len(used&excluded_keys),'unique_source_roots':len(selected)}
(OUT/'sampler_verification.json').write_text(json.dumps(sampler_check,indent=2))
assert sampler_check['cases_match'] and sampler_check['allocation_match'],sampler_check

print(json.dumps(sampler_check,indent=2))
