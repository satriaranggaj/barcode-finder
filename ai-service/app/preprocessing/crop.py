from PIL import Image


def multi_crop(image: Image.Image) -> dict[str, Image.Image]:
    """Large overlapping crops retain context; no tiny patches or inferred tip labels."""
    w, h = image.size
    boxes = {'center': (.125, .125, .875, .875), 'left': (0, 0, .75, 1),
             'right': (.25, 0, 1, 1), 'top': (0, 0, 1, .75), 'bottom': (0, .25, 1, 1)}
    return {'global': image, **{name: image.crop((int(x1*w), int(y1*h),
            max(int(x1*w)+1, int(x2*w)), max(int(y1*h)+1, int(y2*h))))
            for name, (x1, y1, x2, y2) in boxes.items()}}
