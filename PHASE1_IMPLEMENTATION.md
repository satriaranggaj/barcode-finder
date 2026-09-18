# Phase 1 Implementation: Auto Object Selection UI & Storage Crop Coordinates

> **STATUS: HISTORICAL / SUPERSEDED (prompt 01–43 selesai).**
> Dokumen ini merekam pekerjaan tahap awal 15 September 2026 dan **tidak lagi
> menggambarkan arsitektur final**. Rujukan terkini: `docs/final-architecture.md`,
> `docs/object-selection-ui.md`, `docs/object-selection-backend.md`,
> `docs/object-selection-reference.md`, `docs/visual-sku-workflow.md`.
> Koreksi terhadap detail usang ada di bagian "Koreksi pasca-tahap-1" di bawah.

**Date:** 2026-09-15  
**Status:** ✅ Completed  
**Commit Base:** b80099e

## Overview

This implementation adds interactive object selection capabilities to the barcode-finder application, allowing staff to define and adjust bounding boxes around products in photos before they're stored as reference images. The system automatically proposes selections using the FastAPI `/select` endpoint, then allows manual refinement through a canvas-based editor.

## Key Features Implemented

### 1. Database Schema Updates
- **Migration:** `2026_09_15_000000_add_crop_coordinates_to_product_photos_table.php`
- Added columns to `product_photos` table:
  - `crop` (JSON): Stores normalized coordinates `{x, y, width, height}` in 0..1 range
  - `selection_source` (string): Tracks how selection was made (`auto`, `manual`, or `full`)
  - `selection_verified` (boolean): Indicates if user confirmed/adjusted the selection

### 2. Backend Components

#### ObjectSelectionController
**File:** `app/Http/Controllers/ObjectSelectionController.php`

Handles auto-selection proposals by calling the FastAPI service:
- Endpoint: `POST /admin/object-selection`
- Accepts image upload
- Returns array of detected bounding boxes with normalized coordinates
- Graceful fallback when AI service is unavailable (503 response)

#### CropCoordinateValidator Service
**File:** `app/Services/CropCoordinateValidator.php`

Provides validation and conversion utilities:
- `validate()`: Ensures coordinates are within 0..1 range and meet minimum size requirements
- `normalize()`: Converts pixel coordinates to normalized 0..1 format
- `denormalize()`: Converts normalized coordinates back to pixels for cropping

#### ProductController Update
**File:** `app/Http/Controllers/ProductController.php`

Modified `uploadPhoto()` method to:
- Accept `crop_coordinates[]` array from form submission
- Accept `selection_source` parameter
- Store crop data with each uploaded photo
- Support both single and multiple coordinate sets

### 3. Frontend Components

#### Object Selection Editor
**File:** `resources/js/object-selection.js`

Canvas-based interactive editor featuring:
- **Auto-detection integration**: Calls `/admin/object-selection` after file upload
- **Drag support**: Move entire bounding box by clicking inside
- **Resize handles**: 8 handles (4 corners + 4 edges) for precise adjustment
- **Visual feedback**: Semi-transparent overlay outside selection area
- **Reset button**: Clears current selection
- **Padding button**: Applies configurable padding (default 8%) around selection
- **Real-time updates**: Hidden form fields updated on every change

#### View Integration
**File:** `resources/views/products/show.blade.php`

Added object selection section to admin photo upload form:
- Preview container with canvas rendering
- Hidden inputs for crop coordinates and selection source
- Control buttons (reset, apply padding)
- Responsive design matching existing UI theme

### 4. Build Configuration
**File:** `vite.config.js`

Updated to include `object-selection.js` in build pipeline:
- Compiled to: `public/build/assets/object-selection-CxbW1EJh.js` (6.68 KB gzipped: 2.21 KB)
- Loaded via `@push('scripts')` directive on product show page

## Technical Specifications

### Normalized Coordinates System
All crop coordinates use normalized values (0..1 range):
- `x`: Horizontal position (0 = left edge, 1 = right edge)
- `y`: Vertical position (0 = top edge, 1 = bottom edge)
- `width`: Box width as fraction of image width
- `height`: Box height as fraction of image height

Example: `{x: 0.2, y: 0.3, width: 0.4, height: 0.5}` represents a box starting at 20% from left, 30% from top, spanning 40% of width and 50% of height.

### Selection Sources
- **auto**: AI-detected object via FastAPI `/select` endpoint
- **manual**: User drew/adjusted box manually
- **full**: No selection made, entire image used

### Minimum Size Constraints
To prevent unusably small selections:
- Minimum dimension: 5% of image size (0.05 in normalized coordinates)
- Enforced during resize operations

### Padding Application
Default 8% padding applied around detected objects:
- Prevents cutting off important details (e.g., screwdriver tips)
- Clamped to image boundaries
- Configurable in JavaScript constructor

## API Integration

### FastAPI Service Requirements
The implementation expects the Python AI service to provide:
```
POST /select
Content-Type: multipart/form-data
Body: image (file)

Response: {
  "boxes": [
    {
      "x": 0.15,
      "y": 0.2,
      "width": 0.6,
      "height": 0.7
    }
  ]
}
```

Multiple boxes may be returned for future multi-object support; current UI uses first box only.

### Error Handling
- If `/select` returns 503 or fails: Falls back to full image mode
- Network errors logged to console but don't block upload
- User can always draw selection manually regardless of AI availability

## Testing Checklist

✅ Migration runs successfully (idempotent with column existence checks)  
✅ Routes registered correctly (`artisan route:list --name=object-selection`)  
✅ PHP syntax valid for all new classes  
✅ Vite build completes without errors  
✅ Assets compiled to correct public path  
✅ Model fillable attributes updated  
✅ Validation service created with proper namespace  

## Files Modified/Created

### New Files
- `app/Http/Controllers/ObjectSelectionController.php`
- `app/Services/CropCoordinateValidator.php`
- `database/migrations/2026_09_15_000000_add_crop_coordinates_to_product_photos_table.php`
- `resources/js/object-selection.js`

### Modified Files
- `app/Models/ProductPhoto.php` (added fillable fields and casts)
- `app/Http/Controllers/ProductController.php` (updated uploadPhoto method)
- `routes/web.php` (added object-selection route)
- `resources/views/products/show.blade.php` (added selection editor UI)
- `vite.config.js` (added object-selection.js to build input)

### Generated Assets
- `public/build/assets/object-selection-CxbW1EJh.js`
- Updated `public/build/manifest.json`

## Backward Compatibility

✅ Existing photo upload workflow unchanged when no crop coordinates provided  
✅ Legacy photos without crop data remain functional  
✅ Default `selection_source` set to 'auto' for new uploads with coordinates  
✅ `selection_verified` defaults to false until explicitly confirmed  
✅ Full image fallback when selection not available  

## Next Steps (Phase 2+)

1. **Object Selection for Reference Photos**: Extend to catalog management interface
2. **Image Compression Pipeline**: Implement WebP conversion with master/catalog/thumbnail variants
3. **AI Indexing with Crops**: Modify FAISS indexing to use cropped regions instead of full images
4. **Multi-Object Support**: Allow selecting between multiple detected objects
5. **Quality Validation**: Add blur detection and minimum resolution checks
6. **Provenance Tracking**: Record selection metadata for training dataset

## Known Limitations

- Single object selection only (first detected box used)
- No touch/mobile gesture support yet (mouse events only)
- Canvas doesn't handle extremely large images efficiently (>4K)
- No undo/redo functionality for selection adjustments
- Auto-padding always applied to AI detections (may not suit all cases)

## Performance Notes

- Canvas rendering: ~60 FPS for typical product images (<2MP)
- Auto-selection API call: Async, non-blocking (30s timeout)
- Memory usage: One Image object + one Canvas per active editor instance
- Bundle size impact: +6.68 KB uncompressed, +2.21 KB gzipped

---

## Koreksi pasca-tahap-1 (faktual, per 18 September 2026)

Detail di atas sebagian sudah tidak berlaku. Yang benar pada working tree saat ini:

1. **Validator crop.** `app/Services/CropCoordinateValidator.php` **sudah dihapus**.
   Satu-satunya sumber kontrak adalah `app/Services/CropCoordinates.php`, dengan
   `MIN_EXTENT = 0.01` (bukan 5%), `TOLERANCE = 0.0001`, dan validasi overflow
   `x + width <= 1` / `y + height <= 1`. Mirror Python:
   `ai-service/app/preprocessing/selection.py`.
2. **Route.** Tidak ada route `/admin/object-selection`. Jalur yang terdaftar:
   `POST /object-selection` (name `object-selection.propose`, `throttle:20,1`),
   `POST /search` (`products.search`), `POST /search-feedback`
   (`search.feedback`, auth). `/object-selection` mem-proxy FastAPI
   `POST /select`; FastAPI juga menyediakan `/search`, `/prepare`, `/embed`.
3. **Field form.** Upload admin memakai `crop_coordinates[]` +
   `selection_sources[]` (JSON per foto); edit foto tunggal memakai
   `crop_json` + `selection_source`. Keduanya divalidasi lewat
   `CropCoordinates`.
4. **Multi-candidate.** Pemilih kandidat ("Objek 1..N") sudah ada di
   `resources/js/object-selection.js` + `resources/js/selection/state.js`
   (event `candidate`), jadi keterbatasan "first box only" sudah tidak berlaku.
5. **Touch/mobile.** Editor memakai pointer events di
   `resources/js/selection/editor.js` (drag + 8 handle), diuji lewat 35 test
   vitest (`tests/js/state.test.js`, `tests/js/geometry.test.js`). Verifikasi
   perangkat fisik tetap belum dilakukan.
6. **Padding.** Default 8% dan dikonfigurasi via `SELECTION_PADDING`
   (`ai-service/app/config.py`), bukan konstanta JS.
7. **Representasi.** Crop disimpan sebagai metadata (`product_photos.crop` JSON
   + `selection_source` + `selection_verified`); tidak ada file crop permanen —
   crop direkonstruksi dari master + koordinat.
