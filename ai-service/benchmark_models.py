"""Fresh-process CPU comparison; alternative models are benchmark-only dependencies.

No tuning on evaluation. Export features once, tune on `tuning`, freeze policy,
then evaluate on `evaluation`. Real catalog identity is required in the manifest.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
from time import perf_counter
import statistics
import platform

parser = argparse.ArgumentParser()
parser.add_argument('manifest', type=Path)
parser.add_argument('--model', choices=('clip','dinov2','mobileclip'), required=True)
parser.add_argument('--output', type=Path, required=True)
parser.add_argument('--repeats', type=int, default=3)
args = parser.parse_args()
if not 1 <= args.repeats <= 10:
    parser.error('repeats must be 1..10')
manifest = json.loads(args.manifest.read_text(encoding='utf-8-sig'))
groups = ('references','tuning','evaluation')
seen = set()
reference_skus = {str(row['sku']) for row in manifest['references']}
for group in groups:
    if not 1 <= len(manifest[group]) <= 1000:
        parser.error('Each split needs 1..1000 photos')
    for entry in manifest[group]:
        path = (args.manifest.parent / entry['path']).resolve()
        if path.stat().st_size > 20*1024*1024:
            parser.error('Oversized benchmark image')
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        if digest in seen:
            parser.error('Duplicate image bytes across reference/query/splits')
        if group != 'references' and entry['sku'] is not None and str(entry['sku']) not in reference_skus:
            parser.error('Query SKU not present in references; label genuine negative queries with null')
        seen.add(digest)
        entry['sha256'] = digest

startup = perf_counter()
import torch
import psutil
import numpy as np
from app.preprocessing import decode, prepare as prepare_v1, cv2
from app.conditional_preprocessing import prepare as prepare_v2
from app.descriptors import describe
torch.set_num_threads(2)
cv2.setNumThreads(1)
if args.model == 'clip':
    from app.model import model, create_image_embedding, MODEL_NAME, MODEL_REVISION
    def embed(image):
        return create_image_embedding(image)
elif args.model == 'dinov2':
    from transformers import AutoImageProcessor, AutoModel
    MODEL_NAME = 'facebook/dinov2-small'
    MODEL_REVISION = 'ed25f3a31f01632728cabb09d1542f84ab7b0056'
    processor = AutoImageProcessor.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    model = AutoModel.from_pretrained(MODEL_NAME, revision=MODEL_REVISION).eval()
    def embed(image):
        inputs = processor(images=image,return_tensors='pt')
        with torch.inference_mode():
            features = model(**inputs).last_hidden_state[:,0]
            return torch.nn.functional.normalize(features,dim=-1)[0].tolist()
else:
    import timm
    from timm.utils import reparameterize_model
    MODEL_NAME = 'timm/fastvit_mci0.apple_mclip2_dfndr2b'
    model = timm.create_model('hf_hub:'+MODEL_NAME,pretrained=True).eval()
    processor = timm.data.create_transform(**timm.data.resolve_model_data_config(model),is_training=False)
    model = reparameterize_model(model)
    from huggingface_hub import model_info
    MODEL_REVISION = model_info(MODEL_NAME).sha
    def embed(image):
        with torch.inference_mode():
            features = model(processor(image).unsqueeze(0))
            return torch.nn.functional.normalize(features,dim=-1)[0].tolist()

result = {'dataset_note':manifest['dataset_note'],'acceptance_ready':False,
          'manifest_sha256':hashlib.sha256(args.manifest.read_bytes()).hexdigest(),
          'environment':{'model':MODEL_NAME,'revision':MODEL_REVISION,'platform':platform.platform(),
                         'cpu':platform.processor(),'threads':2,'startup_s':perf_counter()-startup,
                         'parameters':sum(p.numel() for p in model.parameters()),
                         'model_bytes':sum(p.numel()*p.element_size() for p in model.parameters())},
          'references':[],'tuning':[],'evaluation':[]}
# Warm model kernels once, outside measured requests.
image,_ = decode((args.manifest.parent/manifest['references'][0]['path']).read_bytes(),legacy=True)
embed(image)
image.close()
for group in groups:
    for index,entry in enumerate(manifest[group]):
        contents = (args.manifest.parent/entry['path']).read_bytes()
        row = {key:entry.get(key) for key in ('sku','case','photo_id','sha256')}
        modes = ['original','v2','v1'] if args.model=='clip' else ['original','v2']
        if index%2: modes.reverse()
        for mode in modes:
            samples = []
            for _ in range(args.repeats):
                started = perf_counter()
                image,times = decode(contents,legacy=mode=='original')
                prepared = None if mode=='original' else (prepare_v2(image) if mode=='v2' else prepare_v1(image))
                if prepared:
                    times.update(prepared.metadata['timings'])
                    times['preprocessing_ms'] = prepared.metadata['preprocessing_ms']
                stage = perf_counter()
                vector = embed(image if prepared is None else prepared.image)
                times['embedding_ms'] = (perf_counter()-stage)*1000
                fast_total = (perf_counter()-started)*1000
                descriptors = {}
                if prepared:
                    stage = perf_counter()
                    descriptors = describe(prepared)
                    times['descriptor_ms'] = (perf_counter()-stage)*1000
                times['fast_total_ms'] = fast_total
                times['full_total_ms'] = (perf_counter()-started)*1000
                samples.append(times)
                image.close()
            row[mode] = {'embedding':vector,'descriptors':descriptors,'samples':samples,
                         'preprocessing':None if prepared is None else prepared.metadata}
        result[group].append(row)
        print(f'{args.model} {group} {index+1}/{len(manifest[group])}',flush=True)
process = psutil.Process()
result['environment'].update(dimensions=len(vector),rss_mib=process.memory_info().rss/1048576,
                             peak_rss_mib=getattr(process.memory_info(),'peak_wset',0)/1048576 or None)
result['latency_ms'] = {}
for mode in result['evaluation'][0].keys() & {'original','v2','v1'}:
    samples = [sample for row in result['evaluation'] for sample in row[mode]['samples']]
    result['latency_ms'][mode] = {key:{'median':statistics.median([r[key] for r in samples]),
                                      'p95':float(np.percentile([r[key] for r in samples],95))} for key in samples[0]}
args.output.parent.mkdir(parents=True,exist_ok=True)
args.output.write_text(json.dumps(result,allow_nan=False),encoding='utf-8')
print(json.dumps({'environment':result['environment'],'latency_ms':result['latency_ms']},indent=2))
