"""object-v2: cheap clean-background path, otherwise bounded 160px/one-pass GrabCut.

Versioned separately from object-v1. Geometric priors are not semantic confidence.
"""
from time import perf_counter
import numpy as np
from PIL import Image
from .preprocessing import Prepared, cv2

VERSION = 'object-v2'
WORK_SIZE = 256
SEGMENT_SIZE = 160


def bounded_segment(rgb):
    height, width = rgb.shape[:2]
    scale = min(1, SEGMENT_SIZE/max(height, width))
    small = cv2.resize(rgb, (max(16, round(width*scale)), max(16, round(height*scale))), interpolation=cv2.INTER_AREA)
    h, w = small.shape[:2]
    margin = max(1, round(min(h,w)*.02))
    mask = np.zeros((h,w),np.uint8)
    cv2.setRNGSeed(0)
    cv2.grabCut(small, mask, (margin,margin,w-2*margin,h-2*margin),
                np.zeros((1,65),np.float64),np.zeros((1,65),np.float64),1,cv2.GC_INIT_WITH_RECT)
    return cv2.resize(np.isin(mask,(1,3)).astype(np.uint8),(width,height),interpolation=cv2.INTER_NEAREST)


def prepare(image, segmenter=bounded_segment):
    started = perf_counter()
    times = {key:0.0 for key in ('resize_ms','prior_ms','grabcut_ms','component_selection_ms','crop_ms','normalization_ms')}
    working = image.copy()
    working.thumbnail((WORK_SIZE,WORK_SIZE),Image.Resampling.LANCZOS)
    rgb = np.asarray(working)
    height,width = rgb.shape[:2]
    times['resize_ms'] = (perf_counter()-started)*1000
    meta = {'version':VERSION,'mode':'original','reason':'uncertain',
            'working_size':[width,height],'bbox':[0,0,width,height]}
    object_mask = None
    try:
        if cv2 is None or min(width,height)<16:
            raise ValueError('segmenter_unavailable_or_small_image')
        stage = perf_counter()
        border = np.concatenate((rgb[0],rgb[-1],rgb[:,0],rgb[:,-1])).astype(np.float32)
        background = np.median(border,axis=0)
        uniformity = float((np.max(np.abs(border-background),axis=1)<24).mean())
        difference = np.max(np.abs(rgb.astype(np.float32)-background),axis=2)
        mask = (difference>32).astype(np.uint8)
        fraction = float(mask.mean())
        clean = uniformity >= .92
        meta.update(border_uniformity=round(uniformity,4),prior_foreground_fraction=round(fraction,4))
        times['prior_ms'] = (perf_counter()-stage)*1000
        if clean and (fraction<.02 or fraction>.90):
            raise ValueError('uniform_or_frame_filling')
        if not clean:
            stage = perf_counter()
            try:
                mask = segmenter(rgb)
            finally:
                times['grabcut_ms'] = (perf_counter()-stage)*1000
        stage = perf_counter()
        try:
            if mask.shape != (height,width):
                raise ValueError('invalid_mask')
            count, labels, stats, centers = cv2.connectedComponentsWithStats(mask.astype(np.uint8))
            if count<2:
                raise ValueError('empty_mask')
            areas = stats[1:,cv2.CC_STAT_AREA]
            distance = np.linalg.norm((centers[1:]-[width/2,height/2])/[width,height],axis=1)
            label = int(np.argmax(areas*(1-np.minimum(distance,.8))))+1
            x,y,w,h,area = [int(v) for v in stats[label]]
            fraction = area/(width*height)
            dominance = area/max(int(mask.sum()),1)
            center_distance = float(distance[label-1])
            bbox_fill = area/max(w*h,1)
            full_mask = (labels==label).astype(np.uint8)
            contours,_ = cv2.findContours(full_mask,cv2.RETR_EXTERNAL,cv2.CHAIN_APPROX_SIMPLE)
            contour = max(contours,key=cv2.contourArea)
            perimeter = cv2.arcLength(contour,True)
            compactness = min(1.0,4*np.pi*area/max(perimeter*perimeter,1))
            edge_band = max(2,round(min(width,height)*.03))
            border_touch = x<=edge_band or y<=edge_band or x+w>=width-edge_band or y+h>=height-edge_band
            checks = {'fraction':.03<=fraction<=.88, 'dominance':dominance>=.80,
                      'center':center_distance<=.28, 'border':not border_touch,
                      'bbox_fill':bbox_fill>=.18, 'compactness':compactness>=.015}
            meta['geometry'] = {'foreground_fraction':round(fraction,4),'dominance':round(dominance,4),
                                'center_distance':round(center_distance,4),'bbox_fill':round(bbox_fill,4),
                                'compactness':round(float(compactness),4),'fragmentation':round(1-dominance,4),
                                'border_touch':bool(border_touch),'components':int(count-1),
                                'failed_checks':[key for key,passed in checks.items() if not passed]}
            if not all(checks.values()):
                raise ValueError('low_confidence')
        finally:
            times['component_selection_ms'] = (perf_counter()-stage)*1000
        stage = perf_counter()
        pad = max(2,round(max(w,h)*.08))
        left,top,right,bottom = max(0,x-pad),max(0,y-pad),min(width,x+w+pad),min(height,y+h+pad)
        full_mask = (labels==label).astype(np.uint8)
        cropped = rgb[top:bottom,left:right]
        object_mask = full_mask[top:bottom,left:right]
        if not clean:
            alpha = object_mask[...,None]*.8+.2
            cropped = np.clip(cropped*alpha+127*(1-alpha),0,255).astype(np.uint8)
        working = Image.fromarray(cropped)
        meta.update(mode='clean_crop' if clean else 'segmented',reason='uniform_background' if clean else 'accepted',
                    bbox=[left,top,right,bottom],foreground_fraction=round(fraction,4),dominance=round(dominance,4))
        times['crop_ms'] = (perf_counter()-stage)*1000
    except Exception as error:
        meta['reason'] = str(error) if isinstance(error,ValueError) else 'segmentation_unavailable_or_error'
    stage = perf_counter()
    side = max(working.size)
    canvas = Image.new('RGB',(side,side),(127,127,127))
    offset = ((side-working.width)//2,(side-working.height)//2)
    canvas.paste(working,offset)
    if object_mask is not None:
        padded = np.zeros((side,side),np.uint8)
        padded[offset[1]:offset[1]+working.height,offset[0]:offset[0]+working.width] = object_mask
        object_mask = padded
    times['normalization_ms'] = (perf_counter()-stage)*1000
    meta['timings'] = {key:round(value,3) for key,value in times.items()}
    meta['preprocessing_ms'] = round((perf_counter()-started)*1000,3)
    return Prepared(canvas,object_mask,meta)
