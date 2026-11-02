"""Recompute paired R01 statistics from saved outputs only; no PHP/SQL execution.
Usage: python recompute_paired_statistics.py path/to/query_outputs.csv output_dir
See POST_REVIEW_ANALYSIS_PROTOCOL.md for provenance and interpretation.
"""
from pathlib import Path
import argparse,csv,json,hashlib,math,platform
import numpy as np
ap=argparse.ArgumentParser();ap.add_argument('queries',type=Path);ap.add_argument('output',type=Path);args=ap.parse_args();args.output.mkdir(parents=True,exist_ok=True)
rows=list(csv.DictReader(args.queries.open(encoding='utf-8-sig')))
pro='v22_context_short_swap_fast';baseline='fixed2_context_guard'
by_case={}
for r in rows:by_case.setdefault(r['case_id'],{})[r['scenario_id']]=r
assert len(by_case)==600
metrics=['source_hit_at5','source_hit_at1','source_reciprocal_rank']
strata={};diff=[];b=c=0
for cid in sorted(by_case):
 methods=by_case[cid];a=methods[pro];z=methods[baseline]
 assert (a['direction'],a['operation'],a['source_dictionary_id'])==(z['direction'],z['operation'],z['source_dictionary_id'])
 values=np.array([float(a[m])-float(z[m]) for m in metrics]);diff.append(values)
 strata.setdefault((a['direction'],a['operation']),[]).append(values)
 if int(a['source_hit_at5']) and not int(z['source_hit_at5']):b+=1
 if int(z['source_hit_at5']) and not int(a['source_hit_at5']):c+=1
assert len(strata)==12 and all(len(v)==50 for v in strata.values())
diff=np.asarray(diff);point=diff.mean(axis=0)
protocols=[('revision_12000_int_seed',12000,20261019),('sensitivity_10000_string_sha256_seed',10000,int.from_bytes(hashlib.sha256(b'TOULOUR-FINAL-ANALYSIS-20261019-R01').digest(),'big'))]
outputs=[]
for name,n,seed in protocols:
 rng=np.random.Generator(np.random.PCG64(seed));boot=np.zeros((n,3))
 for k in sorted(strata):
  v=np.asarray(strata[k]);idx=rng.integers(0,50,size=(n,50));boot+=v[idx].sum(axis=1)/600
 lo,hi=np.quantile(boot,[.025,.975],axis=0,method='linear')
 for j,m in enumerate(metrics):outputs.append({'analysis_id':name,'metric':m,'comparison':'V2.2 Fast minus Fixed-2','paired_n':600,'strata_n':12,'resamples':n,'point_difference':float(point[j]),'ci95_low':float(lo[j]),'ci95_high':float(hi[j]),'analysis_provenance':'POST_REVIEW_RECOMPUTATION_NOT_ORIGINAL_SCRIPT'})
with (args.output/'paired_statistics_recomputed.csv').open('w',newline='',encoding='utf-8-sig') as f:
 w=csv.DictWriter(f,fieldnames=list(outputs[0]));w.writeheader();w.writerows(outputs)
p=min(1,2*sum(math.comb(b+c,k) for k in range(min(b,c)+1))/(2**(b+c))) if b+c else 1.0
meta={'date':'2026-10-31','provenance':'post-review analysis on frozen saved outputs; not retrieval rerun','input_name':args.queries.name,'input_sha256':hashlib.sha256(args.queries.read_bytes()).hexdigest(),'script_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),'python':platform.python_version(),'numpy':np.__version__,'rng':'PCG64','discordant_hit5_proposed_only':b,'discordant_hit5_fixed2_only':c,'exact_two_sided_mcnemar_p':p,'secondary_cis':'marginal and exploratory; no multiplicity adjustment','original_script_recovered':False,'original_plan_rng_and_seed_string_conversion':'not specified; sensitivity mapping defined in revision protocol'}
(args.output/'paired_statistics_metadata.json').write_text(json.dumps(meta,indent=2))
print(json.dumps({'results':outputs,'metadata':meta},indent=2))
