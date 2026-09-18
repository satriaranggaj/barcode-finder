"""Explicit ablation runner. Each representation has a separately signed index."""
import argparse
from dataclasses import asdict, replace
from datetime import datetime, timezone
import json
from pathlib import Path
from ..config import Settings
from ..search.service import RetrievalService, load_encoders
from ..search.faiss_index import FaissIndexManager, current_generation
from .build_index import build
from .evaluate import evaluate


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--references',required=True,type=Path)
    parser.add_argument('--evaluation',required=True,type=Path)
    parser.add_argument('--output',required=True,type=Path)
    parser.add_argument('--patch-weight',type=float,default=0)
    parser.add_argument('--secondary-weight',type=float,default=0)
    parser.add_argument('--description-weight',type=float,default=0)
    parser.add_argument('--ocr',action='store_true')
    args=parser.parse_args()
    base=replace(Settings.from_env(),legacy_embed=False,relevance_policy='')
    encoders=load_encoders(base)
    if set(encoders) != {'siglip','dino'}: raise RuntimeError('Comparisons require both models')
    args.output.mkdir(parents=True,exist_ok=False)
    scenarios=[('A-global',replace(base,preprocessing_mode='original',local_weight=0,global_weight=0,patch_weight=0,secondary_weight=0)),
               ('B-existing-dual',replace(base,preprocessing_mode='original',patch_weight=0,secondary_weight=0)),
               ('C-selected-object',replace(base,preprocessing_mode='object',patch_weight=0,secondary_weight=0))]
    if args.patch_weight > 0:
        scenarios.append(('D-patch',replace(base,preprocessing_mode='object',patch_weight=args.patch_weight,secondary_weight=0)))
    scenarios.append(('E-multiple-references',replace(base,preprocessing_mode='object',patch_weight=args.patch_weight,secondary_weight=0)))
    if args.secondary_weight > 0 and args.description_weight > 0:
        scenarios.append(('F-description',replace(base,preprocessing_mode='object',patch_weight=args.patch_weight,
            secondary_weight=args.secondary_weight,description_text_weight=args.description_weight,ocr_enabled=False)))
    if args.ocr:
        if args.secondary_weight <= 0: parser.error('--ocr requires a tested nonzero --secondary-weight')
        from shutil import which
        if which(base.ocr_binary) is None: parser.error('OCR executable unavailable')
        scenarios.append(('G-ocr',replace(base,preprocessing_mode='object',patch_weight=args.patch_weight,
            secondary_weight=args.secondary_weight,description_text_weight=args.description_weight,ocr_enabled=True)))
    summary={}
    for name,settings in scenarios:
        print('Running '+name,flush=True)
        root=args.output/name
        service=RetrievalService(settings,encoders)
        single = name[0] in 'ABCD'
        selected = name[0] not in 'AB'
        build(args.references,root,service,reference_limit_per_sku=1 if single else None,use_selection=selected)
        index=FaissIndexManager.load_index(current_generation(root))
        try:
            report=evaluate(args.evaluation,RetrievalService(settings,encoders,index),use_selection=selected)
            report['reference_policy'] = 'one_per_sku' if single else 'all_references'
            (args.output/(name+'.json')).write_text(json.dumps(report,indent=2),encoding='utf-8')
            summary[name]={k:v for k,v in report.items() if k!='queries'}
            print(json.dumps(summary[name]),flush=True)
        finally:index.close()
    (args.output/'summary.json').write_text(json.dumps(summary,indent=2),encoding='utf-8')
    # Run metadata lives beside (never inside) the per-scenario summary so
    # scenario counts stay comparable; each scenario report already carries
    # its own evaluation_signature. No conclusions are drawn here: enable a
    # feature in production only if held-out numbers justify it.
    run = {'tool': 'compare', 'created_at': datetime.now(timezone.utc).isoformat(),
           'references': str(args.references), 'evaluation': str(args.evaluation),
           'patch_weight': args.patch_weight, 'secondary_weight': args.secondary_weight,
           'description_weight': args.description_weight, 'ocr': args.ocr,
           'scenarios': [name for name, _ in scenarios],
           'base_settings': {k: (str(v) if isinstance(v, Path) else v)
                             for k, v in asdict(base).items()}}
    (args.output/'run.json').write_text(json.dumps(run,indent=2),encoding='utf-8')


if __name__=='__main__':main()
