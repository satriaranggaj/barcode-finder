/**
 * Object Selection Editor
 * Allows users to drag, resize, and adjust bounding box on uploaded images
 */

class ObjectSelectionEditor {
    constructor(container, options = {}) {
        this.container = container;
        this.canvas = null;
        this.ctx = null;
        this.image = null;
        this.box = null; // {x, y, width, height} in normalized coordinates (0..1)
        this.isDragging = false;
        this.isResizing = false;
        this.resizeHandle = null;
        this.dragStart = { x: 0, y: 0 };
        this.initialBox = null;
        this.padding = options.padding || 0.08; // 8% default padding
        this.onBoxChange = options.onBoxChange || (() => {});
        
        this.setupCanvas();
        this.bindEvents();
    }

    setupCanvas() {
        this.canvas = document.createElement('canvas');
        this.canvas.className = 'object-selection-canvas';
        this.canvas.style.cursor = 'crosshair';
        this.container.appendChild(this.canvas);
        this.ctx = this.canvas.getContext('2d');
    }

    loadImage(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                this.image = new Image();
                this.image.onload = () => {
                    this.resizeCanvas();
                    this.draw();
                    resolve();
                };
                this.image.onerror = reject;
                this.image.src = e.target.result;
            };
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    resizeCanvas() {
        if (!this.image) return;
        
        const maxWidth = this.container.clientWidth || 800;
        const maxHeight = this.container.clientHeight || 600;
        
        const scale = Math.min(
            maxWidth / this.image.width,
            maxHeight / this.image.height,
            1
        );
        
        this.canvas.width = this.image.width * scale;
        this.canvas.height = this.image.height * scale;
        this.scale = scale;
    }

    draw() {
        if (!this.ctx || !this.image) return;
        
        // Clear canvas
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        
        // Draw image
        this.ctx.drawImage(this.image, 0, 0, this.canvas.width, this.canvas.height);
        
        // Draw selection box if exists
        if (this.box) {
            this.drawBox();
        }
    }

    drawBox() {
        const { x, y, width, height } = this.box;
        const px = x * this.canvas.width;
        const py = y * this.canvas.height;
        const w = width * this.canvas.width;
        const h = height * this.canvas.height;
        
        // Draw semi-transparent overlay outside box
        this.ctx.fillStyle = 'rgba(0, 0, 0, 0.5)';
        this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
        
        // Clear box area
        this.ctx.clearRect(px, py, w, h);
        
        // Redraw image in box area
        this.ctx.drawImage(
            this.image,
            px / this.scale, py / this.scale, w / this.scale, h / this.scale,
            px, py, w, h
        );
        
        // Draw border
        this.ctx.strokeStyle = '#3b82f6';
        this.ctx.lineWidth = 3;
        this.ctx.strokeRect(px, py, w, h);
        
        // Draw handles
        this.drawHandles(px, py, w, h);
    }

    drawHandles(x, y, w, h) {
        const handleSize = 10;
        this.ctx.fillStyle = '#3b82f6';
        
        // Corner handles
        const handles = [
            [x, y], // top-left
            [x + w, y], // top-right
            [x, y + h], // bottom-left
            [x + w, y + h], // bottom-right
            [x + w/2, y], // top-center
            [x + w/2, y + h], // bottom-center
            [x, y + h/2], // left-center
            [x + w, y + h/2], // right-center
        ];
        
        handles.forEach(([hx, hy]) => {
            this.ctx.fillRect(
                hx - handleSize/2,
                hy - handleSize/2,
                handleSize,
                handleSize
            );
        });
    }

    getMousePos(e) {
        const rect = this.canvas.getBoundingClientRect();
        return {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };
    }

    isInsideHandle(pos, handleIndex) {
        if (!this.box) return false;
        
        const { x, y, width, height } = this.box;
        const px = x * this.canvas.width;
        const py = y * this.canvas.height;
        const w = width * this.canvas.width;
        const h = height * this.canvas.height;
        const handleSize = 10;
        
        const handles = [
            [px, py],
            [px + w, py],
            [px, py + h],
            [px + w, py + h],
            [px + w/2, py],
            [px + w/2, py + h],
            [px, py + h/2],
            [px + w, py + h/2],
        ];
        
        const [hx, hy] = handles[handleIndex];
        return (
            pos.x >= hx - handleSize &&
            pos.x <= hx + handleSize &&
            pos.y >= hy - handleSize &&
            pos.y <= hy + handleSize
        );
    }

    getResizeHandle(pos) {
        for (let i = 0; i < 8; i++) {
            if (this.isInsideHandle(pos, i)) {
                return i;
            }
        }
        return null;
    }

    isInsideBox(pos) {
        if (!this.box) return false;
        
        const { x, y, width, height } = this.box;
        const px = x * this.canvas.width;
        const py = y * this.canvas.height;
        const w = width * this.canvas.width;
        const h = height * this.canvas.height;
        
        return (
            pos.x >= px &&
            pos.x <= px + w &&
            pos.y >= py &&
            pos.y <= py + h
        );
    }

    bindEvents() {
        this.canvas.addEventListener('mousedown', (e) => {
            const pos = this.getMousePos(e);
            
            // Check if clicking on a handle
            const handle = this.getResizeHandle(pos);
            if (handle !== null) {
                this.isResizing = true;
                this.resizeHandle = handle;
                this.dragStart = pos;
                this.initialBox = { ...this.box };
                return;
            }
            
            // Check if inside box
            if (this.isInsideBox(pos)) {
                this.isDragging = true;
                this.dragStart = pos;
                this.initialBox = { ...this.box };
                this.canvas.style.cursor = 'move';
                return;
            }
            
            // Create new box
            this.createBox(pos);
        });
        
        this.canvas.addEventListener('mousemove', (e) => {
            const pos = this.getMousePos(e);
            
            if (this.isResizing) {
                this.resizeBox(pos);
            } else if (this.isDragging) {
                this.moveBox(pos);
            } else {
                // Update cursor based on position
                const handle = this.getResizeHandle(pos);
                if (handle !== null) {
                    const cursors = ['nw-resize', 'ne-resize', 'sw-resize', 'se-resize', 'n-resize', 's-resize', 'w-resize', 'e-resize'];
                    this.canvas.style.cursor = cursors[handle];
                } else if (this.isInsideBox(pos)) {
                    this.canvas.style.cursor = 'move';
                } else {
                    this.canvas.style.cursor = 'crosshair';
                }
            }
        });
        
        this.canvas.addEventListener('mouseup', () => {
            this.isDragging = false;
            this.isResizing = false;
            this.resizeHandle = null;
            this.canvas.style.cursor = 'crosshair';
        });
        
        this.canvas.addEventListener('mouseleave', () => {
            this.isDragging = false;
            this.isResizing = false;
            this.resizeHandle = null;
        });
    }

    createBox(pos) {
        // Start with small box at click position
        const size = 0.1; // 10% of canvas
        this.box = {
            x: Math.max(0, Math.min(1, (pos.x / this.canvas.width) - size/2)),
            y: Math.max(0, Math.min(1, (pos.y / this.canvas.height) - size/2)),
            width: size,
            height: size
        };
        this.draw();
        this.onBoxChange(this.box);
    }

    moveBox(pos) {
        if (!this.initialBox) return;
        
        const dx = (pos.x - this.dragStart.x) / this.canvas.width;
        const dy = (pos.y - this.dragStart.y) / this.canvas.height;
        
        let newX = this.initialBox.x + dx;
        let newY = this.initialBox.y + dy;
        
        // Clamp to canvas bounds
        newX = Math.max(0, Math.min(1 - this.initialBox.width, newX));
        newY = Math.max(0, Math.min(1 - this.initialBox.height, newY));
        
        this.box = {
            ...this.initialBox,
            x: newX,
            y: newY
        };
        
        this.draw();
        this.onBoxChange(this.box);
    }

    resizeBox(pos) {
        if (!this.initialBox || this.resizeHandle === null) return;
        
        const dx = (pos.x - this.dragStart.x) / this.canvas.width;
        const dy = (pos.y - this.dragStart.y) / this.canvas.height;
        
        let { x, y, width, height } = this.initialBox;
        
        // Resize based on which handle is being dragged
        switch (this.resizeHandle) {
            case 0: // top-left
                x += dx;
                y += dy;
                width -= dx;
                height -= dy;
                break;
            case 1: // top-right
                y += dy;
                width += dx;
                height -= dy;
                break;
            case 2: // bottom-left
                x += dx;
                width -= dx;
                height += dy;
                break;
            case 3: // bottom-right
                width += dx;
                height += dy;
                break;
            case 4: // top-center
                y += dy;
                height -= dy;
                break;
            case 5: // bottom-center
                height += dy;
                break;
            case 6: // left-center
                x += dx;
                width -= dx;
                break;
            case 7: // right-center
                width += dx;
                break;
        }
        
        // Ensure minimum size
        const minSize = 0.05;
        if (width < minSize) {
            width = minSize;
            if (this.resizeHandle === 0 || this.resizeHandle === 2) {
                x = this.initialBox.x + this.initialBox.width - minSize;
            }
        }
        if (height < minSize) {
            height = minSize;
            if (this.resizeHandle === 0 || this.resizeHandle === 1) {
                y = this.initialBox.y + this.initialBox.height - minSize;
            }
        }
        
        // Clamp to canvas bounds
        x = Math.max(0, Math.min(1 - minSize, x));
        y = Math.max(0, Math.min(1 - minSize, y));
        width = Math.min(width, 1 - x);
        height = Math.min(height, 1 - y);
        
        this.box = { x, y, width, height };
        this.draw();
        this.onBoxChange(this.box);
    }

    setBox(box) {
        this.box = box;
        this.draw();
    }

    reset() {
        this.box = null;
        this.draw();
        this.onBoxChange(null);
    }

    getBox() {
        return this.box;
    }

    applyPadding() {
        if (!this.box) return;
        
        const p = this.padding;
        let { x, y, width, height } = this.box;
        
        // Apply padding around the box
        x = Math.max(0, x - width * p);
        y = Math.max(0, y - height * p);
        width = Math.min(1 - x, width * (1 + 2 * p));
        height = Math.min(1 - y, height * (1 + 2 * p));
        
        this.box = { x, y, width, height };
        this.draw();
        this.onBoxChange(this.box);
    }
}

// Initialize editor when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const containers = document.querySelectorAll('[data-object-selection]');
    
    containers.forEach(container => {
        const fileInput = container.closest('form').querySelector('[data-photo-source], [data-image-preview]');
        const previewContainer = container.querySelector('[data-preview]');
        const resultInput = container.querySelector('[data-result-input]');
        const sourceInput = container.querySelector('[data-source-input]');
        const resetButton = container.querySelector('[data-reset-selection]');
        const paddingButton = container.querySelector('[data-apply-padding]');
        
        if (!previewContainer) return;
        
        let editor = null;
        let currentFile = null;
        
        // Handle file selection from multiple possible inputs
        const setupFileHandler = (input) => {
            if (!input) return;
            
            input.addEventListener('change', async (e) => {
                const file = e.target.files?.[0];
                if (!file) return;
                
                currentFile = file;
                
                // Clear previous editor
                previewContainer.innerHTML = '';
                
                // Create new editor
                editor = new ObjectSelectionEditor(previewContainer, {
                    padding: 0.08,
                    onBoxChange: (box) => {
                        if (box) {
                            resultInput.value = JSON.stringify(box);
                            sourceInput.value = 'manual';
                        } else {
                            resultInput.value = '';
                            sourceInput.value = 'full';
                        }
                    }
                });
                
                try {
                    await editor.loadImage(file);
                    
                    // Try auto-selection
                    const formData = new FormData();
                    formData.append('image', file);
                    
                    fetch('/admin/object-selection', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        }
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.boxes && data.boxes.length > 0) {
                            // Use first detected box
                            const box = data.boxes[0];
                            editor.setBox(box);
                            resultInput.value = JSON.stringify(box);
                            sourceInput.value = 'auto';
                            
                            // Apply padding automatically
                            editor.applyPadding();
                        }
                    })
                    .catch(err => {
                        console.log('Auto-selection not available:', err);
                        sourceInput.value = 'full';
                    });
                } catch (err) {
                    console.error('Failed to load image:', err);
                }
            });
        };
        
        // Setup handlers for all possible file inputs
        if (fileInput) {
            setupFileHandler(fileInput);
        } else {
            // Fallback: look for any file input in the form
            const form = container.closest('form');
            if (form) {
                const inputs = form.querySelectorAll('input[type="file"]');
                inputs.forEach(setupFileHandler);
            }
        }
        
        // Reset button
        if (resetButton) {
            resetButton.addEventListener('click', () => {
                if (editor) {
                    editor.reset();
                }
            });
        }
        
        // Padding button
        if (paddingButton) {
            paddingButton.addEventListener('click', () => {
                if (editor) {
                    editor.applyPadding();
                }
            });
        }
    });
});

export { ObjectSelectionEditor };
