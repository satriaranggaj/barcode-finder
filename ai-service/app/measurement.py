"""Optional planar measurement, requiring a known marker and explicit object corners.

This is NOT monocular 3D size estimation. The caller must confirm coplanarity.
"""
import numpy as np
from .preprocessing import cv2


def measure(image, marker_cm, corners, coplanar, marker_id=0):
    if coplanar is not True:
        raise ValueError('coplanar_confirmation_required')
    if cv2 is None or not hasattr(cv2, 'aruco'):
        raise ValueError('marker_detector_unavailable')
    if not np.isfinite(marker_cm) or not .5 <= marker_cm <= 100:
        raise ValueError('invalid_marker_size')
    points = np.asarray(corners, np.float32)
    if points.shape != (4,2) or not np.isfinite(points).all():
        raise ValueError('four_object_corners_required')
    if (points < 0).any() or (points[:,0] >= image.width).any() or (points[:,1] >= image.height).any():
        raise ValueError('object_corners_outside_image')
    if not cv2.isContourConvex(points) or abs(cv2.contourArea(points)) < 16:
        raise ValueError('corners_must_be_ordered_convex_boundary')
    gray = cv2.cvtColor(np.asarray(image), cv2.COLOR_RGB2GRAY)
    detector = cv2.aruco.ArucoDetector(cv2.aruco.getPredefinedDictionary(cv2.aruco.DICT_4X4_50))
    markers, ids, _ = detector.detectMarkers(gray)
    matching = [] if ids is None else [marker for marker, identifier in zip(markers, ids.flatten()) if identifier == marker_id]
    if len(matching) != 1:
        raise ValueError('one_matching_marker_required')
    marker = matching[0].reshape(4,2).astype(np.float32)
    if abs(cv2.contourArea(marker)) < 400:
        raise ValueError('marker_too_small')
    destination = np.array([[0,0],[marker_cm,0],[marker_cm,marker_cm],[0,marker_cm]], np.float32)
    homography = cv2.getPerspectiveTransform(marker, destination)
    metric = cv2.perspectiveTransform(points[None, ...], homography)[0]
    if not np.isfinite(metric).all():
        raise ValueError('unstable_perspective')
    _, (width, height), _ = cv2.minAreaRect(metric)
    return {'mode': 'marker_planar_estimate', 'marker_id': int(marker_id),
            'long_side_cm': round(max(width,height),2), 'short_side_cm': round(min(width,height),2),
            'limitations': 'Same plane only; manual corners, printing scale and uncorrected lens distortion affect accuracy. Not depth, thickness or 3D dimensions.'}
