"""Precompute all reference descriptors; query callers request only enabled signals."""
from time import perf_counter
import numpy as np
from .preprocessing import cv2

VERSION = 'visual-v1'
SIGNALS = ('color','texture','shape','proportion','local')


def normalized(values):
    values = np.asarray(values,np.float64).ravel()
    total = float(values.sum())
    return (values/total).round(6).tolist() if total else np.zeros_like(values).tolist()


def describe(prepared, enabled=None):
    enabled = set(SIGNALS if enabled is None else enabled)
    if not enabled.issubset(SIGNALS):
        raise ValueError('unknown_descriptor')
    if cv2 is None:
        return {'version':VERSION,'status':'unavailable'}
    result = {'version':VERSION,'status':'ready'}
    times = {}
    rgb = np.asarray(prepared.image)
    mask = prepared.mask
    selected = np.ones(rgb.shape[:2],bool) if mask is None else mask>0
    if 'color' in enabled:
        stage = perf_counter()
        hsv = cv2.cvtColor(rgb,cv2.COLOR_RGB2HSV)
        color,_ = np.histogramdd(hsv[selected,:2],bins=(8,4),range=((0,180),(0,256)))
        result['color'] = normalized(color)
        times['color_ms'] = (perf_counter()-stage)*1000
    if enabled & {'texture','local'}:
        gray = cv2.cvtColor(rgb,cv2.COLOR_RGB2GRAY)
    if 'texture' in enabled:
        stage = perf_counter()
        lbp = np.zeros_like(gray,dtype=np.uint8)
        center = gray[1:-1,1:-1]
        for bit,(dy,dx) in enumerate(((-1,-1),(-1,0),(-1,1),(0,1),(1,1),(1,0),(1,-1),(0,-1))):
            neighbor = gray[1+dy:gray.shape[0]-1+dy,1+dx:gray.shape[1]-1+dx]
            lbp[1:-1,1:-1] |= ((neighbor>=center).astype(np.uint8)<<bit)
        texture = normalized(np.histogram(lbp[1:-1,1:-1][selected[1:-1,1:-1]],bins=16,range=(0,256))[0])
        gx = cv2.Sobel(gray,cv2.CV_32F,1,0)
        gy = cv2.Sobel(gray,cv2.CV_32F,0,1)
        magnitude,angle = cv2.cartToPolar(gx,gy)
        pattern = normalized(np.histogram(angle[selected]%np.pi,bins=8,range=(0,np.pi),weights=magnitude[selected])[0])
        result['texture'] = texture+pattern
        times['texture_ms'] = (perf_counter()-stage)*1000
    if enabled & {'shape','proportion'}:
        stage = perf_counter()
        if 'shape' in enabled: result['shape'] = None
        if 'proportion' in enabled: result['proportion'] = None
        if mask is not None:
            ys,xs = np.where(selected)
            if 'proportion' in enabled:
                result['proportion'] = float((xs.max()-xs.min()+1)/(ys.max()-ys.min()+1))
            if 'shape' in enabled:
                contours,_ = cv2.findContours(mask,cv2.RETR_EXTERNAL,cv2.CHAIN_APPROX_NONE)
                contour = max(contours,key=cv2.contourArea)[:,0,:].astype(float)
                delta = contour-np.array([xs.mean(),ys.mean()])
                angles = (np.arctan2(delta[:,1],delta[:,0])+np.pi)/(2*np.pi)
                bins = np.minimum((angles*16).astype(int),15)
                radii = np.zeros(16)
                np.maximum.at(radii,bins,np.linalg.norm(delta,axis=1))
                result['shape'] = normalized(radii)
        times['shape_proportion_ms'] = (perf_counter()-stage)*1000
    if 'local' in enabled:
        stage = perf_counter()
        orb = cv2.ORB_create(nfeatures=32)
        _,local = orb.detectAndCompute(gray,None if mask is None else mask*255)
        result['local'] = [] if local is None else [row.tobytes().hex() for row in local[:32]]
        times['local_ms'] = (perf_counter()-stage)*1000
    result['timings'] = {key:round(value,3) for key,value in times.items()}
    return result
