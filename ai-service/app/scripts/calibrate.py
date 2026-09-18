"""Offline calibration from labelled scored queries. Evaluation never selects a cutoff."""
import argparse
import re
import json
import math
import statistics
from pathlib import Path


def validate(rows, require_both=False):
    if not isinstance(rows,list) or not rows:
        raise ValueError('Queries must be a nonempty list')
    hashes, groups = set(), set()
    for row in rows:
        digest, group = row.get('photo_hash'), row.get('capture_group')
        if not isinstance(digest,str) or not re.fullmatch('[a-fA-F0-9]{64}',digest) or not isinstance(group,str) or not group.strip():
            raise ValueError('photo_hash and capture_group required')
        digest = digest.lower()
        if digest in hashes:
            raise ValueError('Duplicate query photo')
        hashes.add(digest); groups.add(group)
        if not isinstance(row['relevant_skus'],list) or not all(isinstance(s,str) for s in row['relevant_skus']):
            raise ValueError('Invalid relevant_skus')
        seen = set()
        for candidate in row['results']:
            if not isinstance(candidate['sku'],str) or isinstance(candidate['score'],bool) or not isinstance(candidate['score'],(float,int)) or not math.isfinite(candidate['score']) or not -1 <= candidate['score'] <= 1:
                raise ValueError('Invalid candidate score')
            if candidate['sku'] in seen:
                raise ValueError('Duplicate candidate SKU')
            seen.add(candidate['sku'])
    if require_both and (not any(row['relevant_skus'] for row in rows) or not any(not row['relevant_skus'] for row in rows)):
        raise ValueError('Tuning requires positive and negative queries')
    return hashes, groups


def metrics(rows, threshold):
    tp=fp=fn=tn=negative=positive=rejected=negative_fp=0
    for row in rows:
        expected = set(row['relevant_skus'])
        returned = {item['sku'] for item in sorted(row['results'], key=lambda v: -v['score'])[:5] if item['score'] >= threshold}
        tp += len(returned & expected); fp += len(returned-expected); fn += len(expected-returned)
        positive += bool(expected); negative += not expected
        tn += not expected and not returned
        rejected += bool(expected) and not returned
        negative_fp += not expected and bool(returned)
    return summarize(tp,fp,fn,tn,negative,positive,rejected,negative_fp)


def summarize(tp,fp,fn,tn,negative,positive,rejected,negative_fp):
    precision=tp/max(1,tp+fp); recall=tp/max(1,tp+fn)
    return {'tp':tp,'fp':fp,'tn':tn,'fn':fn,'precision':precision,'positive_recall':recall,
            'false_positive_rate':negative_fp/max(1,negative), 'false_negative_rate':fn/max(1,tp+fn),
            'negative_rejection':tn/max(1,negative),'positive_query_false_rejection':rejected/max(1,positive),
            'f1':2*precision*recall/max(1e-12,precision+recall)}


def tune(report):
    if report.get('gate_applied'):
        raise ValueError('Tuning requires unfiltered candidate scores')
    rows = report['queries']; hashes,groups = validate(rows, require_both=True)
    scores = sorted({item['score'] for row in rows for item in row['results']})
    if not scores: raise ValueError('No candidate scores to calibrate')
    # Sweep score boundaries once instead of rescanning all candidates per cutoff.
    # Complexity O(C log C), where C is the number of labelled candidates.
    expected = [set(row['relevant_skus']) for row in rows]
    events = sorted((item['score'],i,item['sku']) for i,row in enumerate(rows) for item in sorted(row['results'],key=lambda v: -v['score'])[:5])
    positive = sum(bool(values) for values in expected)
    counts = dict(tp=0,fp=0,fn=sum(map(len,expected)),tn=len(rows)-positive,
                  negative=len(rows)-positive,positive=positive,rejected=positive,negative_fp=0)
    trials = [{'threshold':math.nextafter(max(scores),math.inf), **summarize(**counts)}]
    accepted = set()
    while events:
        threshold = events[-1][0]
        while events and events[-1][0] == threshold:
            _,index,sku = events.pop()
            if sku in expected[index]: counts['tp'] += 1; counts['fn'] -= 1
            else: counts['fp'] += 1
            if index not in accepted:
                accepted.add(index)
                if expected[index]: counts['rejected'] -= 1
                else: counts['tn'] -= 1; counts['negative_fp'] += 1
        trials.append({'threshold':threshold, **summarize(**counts)})
    selected = max(trials, key=lambda row:(row['f1'], row['negative_rejection'], row['threshold']))
    return {'signature':report['evaluation_signature'],'synthetic':bool(report.get('synthetic')),'trials':trials,'selected_threshold':selected['threshold'],
            'tuning_hashes':sorted(hashes),'tuning_groups':sorted(groups), 'objective':'candidate_f1_then_negative_rejection'}


def freeze(tuning, threshold):
    if not any(row['threshold'] == threshold for row in tuning['trials']):
        raise ValueError('Threshold was never tested on tuning')
    return {'frozen':True, 'synthetic':bool(tuning.get('synthetic')), 'threshold':threshold, 'signature':tuning['signature'],
            'tuning_hashes':tuning['tuning_hashes'],'tuning_groups':tuning['tuning_groups']}


def evaluate_policy(policy, report):
    if report.get('gate_applied'):
        raise ValueError('Offline policy evaluation requires unfiltered candidate scores')
    if not policy.get('frozen') or policy['signature'] != report['evaluation_signature']:
        raise ValueError('Frozen policy/pipeline mismatch')
    rows = report['queries']; hashes, groups = validate(rows)
    if hashes & set(policy['tuning_hashes']) or groups & set(policy['tuning_groups']):
        raise ValueError('Tuning/evaluation leakage')
    return {**metrics(rows,policy['threshold']), **ranking_metrics(rows,policy['threshold'])}


def ranking_metrics(rows, threshold=-math.inf, mrr_cutoff=5):
    positive = [row for row in rows if row['relevant_skus']]
    totals = {1:0,3:0,5:0}; recall = {1:0.,3:0.,5:0.}; reciprocal = 0.
    for row in positive:
        expected = set(row['relevant_skus'])
        returned = [r['sku'] for r in sorted(row['results'],key=lambda v:-v['score']) if r['score'] >= threshold][:mrr_cutoff]
        rank = next((i+1 for i,sku in enumerate(returned) if sku in expected),None)
        reciprocal += 1/rank if rank else 0
        for k in totals:
            totals[k] += int(rank is not None and rank <= k)
            recall[k] += len(set(returned[:k]) & expected)/len(expected)
    latency = sorted(row['latency_ms'] for row in rows if isinstance(row.get('latency_ms'),(int,float)) and math.isfinite(row['latency_ms']) and row['latency_ms'] >= 0)
    pos = .95*(len(latency)-1)
    p95 = latency[int(pos)] + (latency[math.ceil(pos)]-latency[int(pos)])*(pos-int(pos)) if latency else None
    return {**{f'top_{k}_accuracy':v/max(1,len(positive)) for k,v in totals.items()},
            **{f'recall_at_{k}':v/max(1,len(positive)) for k,v in recall.items()},
            'mrr':reciprocal/max(1,len(positive)), 'mrr_cutoff':mrr_cutoff,
            'median_latency_ms':statistics.median(latency) if latency else None, 'p95_latency_ms':p95,
            'latency_samples':len(latency), 'result_limit':5}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action',choices=['tune','freeze','evaluate'])
    parser.add_argument('--input',required=True,type=Path)
    parser.add_argument('--policy',type=Path)
    parser.add_argument('--threshold',type=float)
    parser.add_argument('--output',required=True,type=Path)
    args=parser.parse_args(); data=json.loads(args.input.read_text(encoding='utf-8'))
    if args.action=='tune': result=tune(data)
    elif args.action=='freeze': result=freeze(data,args.threshold if args.threshold is not None else data['selected_threshold'])
    else:
        if args.policy is None: parser.error('--policy is required')
        result=evaluate_policy(json.loads(args.policy.read_text(encoding='utf-8')),data)
    with args.output.open('x',encoding='utf-8') as stream: json.dump(result,stream,indent=2)


if __name__=='__main__': main()
