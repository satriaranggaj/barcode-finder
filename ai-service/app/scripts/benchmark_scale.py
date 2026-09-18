"""Synthetic index/storage load test. Measures no model inference or retrieval accuracy."""
import argparse
import hashlib
import json
import statistics
import tempfile
import time
from pathlib import Path
import faiss
import numpy as np
from ..search.faiss_index import FaissIndexManager


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--references',type=int,required=True)
    parser.add_argument('--queries',type=int,default=100)
    parser.add_argument('--threads',type=int,default=4)
    parser.add_argument('--output',type=Path,required=True)
    args=parser.parse_args()
    if not 1 <= args.references <= 1000000 or not 1 <= args.queries <= 10000 or not 1 <= args.threads <= 64:
        parser.error('Invalid workload bounds')
    faiss.omp_set_num_threads(args.threads)
    rng=np.random.default_rng(20260916)
    signature={'models':{'siglip':{'model':'synthetic-768'},'dino':{'model':'synthetic-384'}},'preprocessing':{}}
    with tempfile.TemporaryDirectory(prefix='lensku-scale-') as temporary:
        path=Path(temporary)/'refs.sqlite'; index=FaissIndexManager(path,signature)
        try:
            started=time.perf_counter()
            index.db.execute('BEGIN')
            for i in range(args.references):
                vectors={}
                for name,dim in [('siglip',768),('dino',384)]:
                    vector=rng.normal(size=dim).astype('float32'); vector/=np.linalg.norm(vector)
                    vectors[name]={crop:vector for crop in ('global','context','center','left','right','top','bottom')}
                index.add_product_embedding({'sku':str(i//3),'image_id':str(i),'photo_hash':hashlib.sha256(str(i).encode()).hexdigest()},vectors)
                if (i+1)%100 == 0: index.db.commit(); index.db.execute('BEGIN')
                if (i+1)%10000 == 0: print(f'Indexed {i+1}',flush=True)
            index.db.commit()
            build_seconds=time.perf_counter()-started
            latency=[]
            for _ in range(args.queries):
                started=time.perf_counter(); ids=set()
                for name,dim in [('siglip',768),('dino',384)]:
                    vector=rng.normal(size=dim).astype('float32'); vector/=np.linalg.norm(vector)
                    ids.update(i for i,_ in index.search(name,vector,50))
                for ref_id in sorted(ids)[:50]: index.reference(ref_id)
                latency.append((time.perf_counter()-started)*1000)
            report={'synthetic':True,'scope':'FAISS retrieval plus 50 reference reads; excludes image/model/patch/OCR inference',
                    'references':args.references,'queries':args.queries,'dimensions':{'siglip':768,'dino':384},
                    'build_seconds':build_seconds,'sqlite_bytes':path.stat().st_size,
                    'median_latency_ms':statistics.median(latency),'p95_latency_ms':float(np.percentile(latency,95))}
            with args.output.open('x',encoding='utf-8') as output: json.dump(report,output,indent=2)
            print(json.dumps(report,indent=2))
        finally: index.close()


if __name__ == '__main__': main()
