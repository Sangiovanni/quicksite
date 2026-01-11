/**
 * QuickSite Color Picker
 * A lightweight, dependency-free RGBA color picker
 * 
 * Usage:
 *   const picker = new QSColorPicker(textInput, options);
 *   
 * Options:
 *   - onChange: function(color) - called when color changes
 *   - position: 'bottom' | 'top' | 'auto' (default: 'auto')
 *   - showAlpha: boolean (default: true)
 *   - format: 'auto' | 'hex' | 'rgb' | 'rgba' (default: 'auto')
 * 
 * MIT License - Use freely
 */

(function(global) {
    'use strict';

    // ==================== Color Utilities ====================
    
    /**
     * Parse any CSS color to RGBA object
     */
    function parseColor(color) {
        if (!color || color === 'transparent') {
            return { r: 0, g: 0, b: 0, a: 0 };
        }
        
        // Already RGBA object
        if (typeof color === 'object' && 'r' in color) {
            return color;
        }
        
        const str = String(color).trim().toLowerCase();
        
        // Hex format: #rgb, #rgba, #rrggbb, #rrggbbaa
        if (str.startsWith('#')) {
            return hexToRgba(str);
        }
        
        // RGB/RGBA format
        const rgbaMatch = str.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+))?\s*\)/);
        if (rgbaMatch) {
            return {
                r: parseInt(rgbaMatch[1]),
                g: parseInt(rgbaMatch[2]),
                b: parseInt(rgbaMatch[3]),
                a: rgbaMatch[4] !== undefined ? parseFloat(rgbaMatch[4]) : 1
            };
        }
        
        // Named color - use canvas to resolve
        try {
            const ctx = document.createElement('canvas').getContext('2d');
            ctx.fillStyle = str;
            const resolved = ctx.fillStyle;
            if (resolved.startsWith('#')) {
                return hexToRgba(resolved);
            }
            // Canvas might return rgb() format
            return parseColor(resolved);
        } catch (e) {
            return { r: 0, g: 0, b: 0, a: 1 };
        }
    }
    
    /**
     * Convert hex to RGBA object
     */
    function hexToRgba(hex) {
        hex = hex.replace('#', '');
        
        // Expand shorthand (#rgb → #rrggbb, #rgba → #rrggbbaa)
        if (hex.length === 3) {
            hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
        } else if (hex.length === 4) {
            hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3];
        }
        
        const r = parseInt(hex.substring(0, 2), 16) || 0;
        const g = parseInt(hex.substring(2, 4), 16) || 0;
        const b = parseInt(hex.substring(4, 6), 16) || 0;
        const a = hex.length === 8 ? parseInt(hex.substring(6, 8), 16) / 255 : 1;
        
        return { r, g, b, a };
    }
    
    /**
     * Convert RGBA object to hex string
     */
    function rgbaToHex(rgba, includeAlpha = false) {
        const r = rgba.r.toString(16).padStart(2, '0');
        const g = rgba.g.toString(16).padStart(2, '0');
        const b = rgba.b.toString(16).padStart(2, '0');
        
        if (includeAlpha && rgba.a < 1) {
            const a = Math.round(rgba.a * 255).toString(16).padStart(2, '0');
            return '#' + r + g + b + a;
        }
        
        return '#' + r + g + b;
    }
    
    /**
     * Convert RGBA object to CSS string
     */
    function rgbaToCss(rgba, forceAlpha = false) {
        if (rgba.a < 1 || forceAlpha) {
            return `rgba(${rgba.r}, ${rgba.g}, ${rgba.b}, ${rgba.a})`;
        }
        return `rgb(${rgba.r}, ${rgba.g}, ${rgba.b})`;
    }
    
    /**
     * Convert RGB to HSV
     */
    function rgbToHsv(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        
        const max = Math.max(r, g, b);
        const min = Math.min(r, g, b);
        const d = max - min;
        
        let h = 0;
        const s = max === 0 ? 0 : d / max;
        const v = max;
        
        if (max !== min) {
            switch (max) {
                case r: h = (g - b) / d + (g < b ? 6 : 0); break;
                case g: h = (b - r) / d + 2; break;
                case b: h = (r - g) / d + 4; break;
            }
            h /= 6;
        }
        
        return { h: h * 360, s: s * 100, v: v * 100 };
    }
    
    /**
     * Convert HSV to RGB
     */
    function hsvToRgb(h, s, v) {
        h /= 360; s /= 100; v /= 100;
        
        let r, g, b;
        const i = Math.floor(h * 6);
        const f = h * 6 - i;
        const p = v * (1 - s);
        const q = v * (1 - f * s);
        const t = v * (1 - (1 - f) * s);
        
        switch (i % 6) {
            case 0: r = v; g = t; b = p; break;
            case 1: r = q; g = v; b = p; break;
            case 2: r = p; g = v; b = t; break;
            case 3: r = p; g = q; b = v; break;
            case 4: r = t; g = p; b = v; break;
            case 5: r = v; g = p; b = q; break;
        }
        
        return {
            r: Math.round(r * 255),
            g: Math.round(g * 255),
            b: Math.round(b * 255)
        };
    }

    // ==================== Color Picker Class ====================
    
    class QSColorPicker {
        constructor(input, options = {}) {
            this.input = input;
            this.options = {
                onChange: options.onChange || null,
                position: options.position || 'auto',
                showAlpha: options.showAlpha !== false,
                format: options.format || 'auto',
                cssVariables: options.cssVariables || null // { '--color-primary': '#007bff', ... }
            };
            
            // Current color state
            this.color = { r: 0, g: 0, b: 0, a: 1 };
            this.hsv = { h: 0, s: 0, v: 0 };
            
            // Variable selection state
            this.selectedVariable = null; // e.g. '--color-primary'
            
            // UI elements
            this.popup = null;
            this.isOpen = false;
            
            // Drag state - prevents click-outside from closing during drag
            this._isDragging = false;
            this._activeElement = null;
            
            // Bind methods
            this._onInputClick = this._onInputClick.bind(this);
            this._onInputChange = this._onInputChange.bind(this);
            this._onDocumentClick = this._onDocumentClick.bind(this);
            this._onDocumentKeydown = this._onDocumentKeydown.bind(this);
            
            this._init();
        }
        
        _init() {
            // Parse initial color from input
            this._parseInputColor();
            
            // Add click handler to input
            this.input.addEventListener('click', this._onInputClick);
            this.input.addEventListener('focus', this._onInputClick);
            this.input.addEventListener('input', this._onInputChange);
            
            // Create popup (hidden initially)
            this._createPopup();
            
            // Initialize cursor positions
            this._updateUI();
            
            // Add color swatch indicator to input
            this._createSwatch();
        }
        
        _createSwatch() {
            // Create a small color preview next to the input
            const wrapper = document.createElement('div');
            wrapper.className = 'qs-cp-input-wrap';
            wrapper.style.cssText = 'position:relative;display:inline-flex;align-items:center;';
            
            this.input.parentNode.insertBefore(wrapper, this.input);
            wrapper.appendChild(this.input);
            
            this.swatch = document.createElement('div');
            this.swatch.className = 'qs-cp-swatch';
            this.swatch.style.cssText = `
                width: 20px;
                height: 20px;
                border-radius: 3px;
                border: 1px solid rgba(0,0,0,0.2);
                margin-left: 6px;
                cursor: pointer;
                background: repeating-conic-gradient(#ccc 0% 25%, #fff 0% 50%) 50% / 8px 8px;
                flex-shrink: 0;
            `;
            
            // Inner color layer (on top of checkerboard)
            this.swatchColor = document.createElement('div');
            this.swatchColor.style.cssText = `
                width: 100%;
                height: 100%;
                border-radius: 2px;
            `;
            this.swatch.appendChild(this.swatchColor);
            
            wrapper.appendChild(this.swatch);
            
            this.swatch.addEventListener('click', this._onInputClick);
            
            this._updateSwatch();
        }
        
        _updateSwatch() {
            if (this.swatchColor) {
                this.swatchColor.style.backgroundColor = rgbaToCss(this.color, true);
            }
        }
        
        _createPopup() {
            this.popup = document.createElement('div');
            this.popup.className = 'qs-colorpicker';
            this.popup.style.cssText = `
                position: fixed;
                z-index: 10000;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.25);
                padding: 12px 12px 12px 12px;
                width: 260px;
                display: none;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                font-size: 12px;
            `;
            
            // Draggable header
            this.header = document.createElement('div');
            this.header.className = 'qs-cp-header';
            this.header.style.cssText = `
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin: -12px -12px 10px -12px;
                padding: 8px 12px;
                background: #f5f5f5;
                border-radius: 8px 8px 0 0;
                cursor: move;
                user-select: none;
            `;
            
            const headerTitle = document.createElement('span');
            headerTitle.textContent = 'Color Picker';
            headerTitle.style.cssText = 'font-weight: 500; color: #333;';
            
            this.closeBtn = document.createElement('button');
            this.closeBtn.innerHTML = '&times;';
            this.closeBtn.style.cssText = `
                background: none;
                border: none;
                font-size: 18px;
                cursor: pointer;
                color: #666;
                padding: 0 4px;
                line-height: 1;
            `;
            this.closeBtn.addEventListener('click', () => this.close());
            this.closeBtn.addEventListener('mouseenter', () => this.closeBtn.style.color = '#333');
            this.closeBtn.addEventListener('mouseleave', () => this.closeBtn.style.color = '#666');
            
            this.header.appendChild(headerTitle);
            this.header.appendChild(this.closeBtn);
            this.popup.appendChild(this.header);
            
            // Setup drag functionality
            this._setupDragHeader();
            
            // Saturation/Value picker area
            this.svArea = document.createElement('div');
            this.svArea.className = 'qs-cp-sv';
            this.svArea.style.cssText = `
                width: 100%;
                height: 140px;
                border-radius: 4px;
                position: relative;
                cursor: crosshair;
                margin-bottom: 10px;
            `;
            
            // SV area overlays
            this.svArea.innerHTML = `
                <div class="qs-cp-sv-white" style="position:absolute;inset:0;background:linear-gradient(to right,#fff,transparent);border-radius:4px;"></div>
                <div class="qs-cp-sv-black" style="position:absolute;inset:0;background:linear-gradient(to top,#000,transparent);border-radius:4px;"></div>
            `;
            
            // SV cursor
            this.svCursor = document.createElement('div');
            this.svCursor.className = 'qs-cp-sv-cursor';
            this.svCursor.style.cssText = `
                position: absolute;
                width: 14px;
                height: 14px;
                border: 2px solid #fff;
                border-radius: 50%;
                box-shadow: 0 0 2px rgba(0,0,0,0.5);
                transform: translate(-50%, -50%);
                pointer-events: none;
            `;
            this.svArea.appendChild(this.svCursor);
            
            this.popup.appendChild(this.svArea);
            
            // Hue slider
            this.hueSlider = document.createElement('div');
            this.hueSlider.className = 'qs-cp-hue';
            this.hueSlider.style.cssText = `
                width: 100%;
                height: 14px;
                border-radius: 7px;
                position: relative;
                cursor: pointer;
                margin-bottom: 8px;
                background: linear-gradient(to right, 
                    hsl(0,100%,50%), hsl(60,100%,50%), hsl(120,100%,50%), 
                    hsl(180,100%,50%), hsl(240,100%,50%), hsl(300,100%,50%), hsl(360,100%,50%)
                );
            `;
            
            this.hueCursor = document.createElement('div');
            this.hueCursor.className = 'qs-cp-hue-cursor';
            this.hueCursor.style.cssText = `
                position: absolute;
                top: 50%;
                width: 6px;
                height: 18px;
                background: #fff;
                border: 1px solid rgba(0,0,0,0.3);
                border-radius: 3px;
                transform: translate(-50%, -50%);
                pointer-events: none;
                box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            `;
            this.hueSlider.appendChild(this.hueCursor);
            
            this.popup.appendChild(this.hueSlider);
            
            // Alpha slider (optional)
            if (this.options.showAlpha) {
                this.alphaSlider = document.createElement('div');
                this.alphaSlider.className = 'qs-cp-alpha';
                this.alphaSlider.style.cssText = `
                    width: 100%;
                    height: 14px;
                    border-radius: 7px;
                    position: relative;
                    cursor: pointer;
                    margin-bottom: 10px;
                    background: repeating-conic-gradient(#ccc 0% 25%, #fff 0% 50%) 50% / 8px 8px;
                `;
                
                this.alphaGradient = document.createElement('div');
                this.alphaGradient.style.cssText = `
                    position: absolute;
                    inset: 0;
                    border-radius: 7px;
                `;
                this.alphaSlider.appendChild(this.alphaGradient);
                
                this.alphaCursor = document.createElement('div');
                this.alphaCursor.className = 'qs-cp-alpha-cursor';
                this.alphaCursor.style.cssText = `
                    position: absolute;
                    top: 50%;
                    width: 6px;
                    height: 18px;
                    background: #fff;
                    border: 1px solid rgba(0,0,0,0.3);
                    border-radius: 3px;
                    transform: translate(-50%, -50%);
                    pointer-events: none;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
                `;
                this.alphaSlider.appendChild(this.alphaCursor);
                
                this.popup.appendChild(this.alphaSlider);
            }
            
            // Preview + hex input row
            const previewRow = document.createElement('div');
            previewRow.style.cssText = 'display:flex;align-items:center;gap:8px;';
            
            // Color preview (with checkerboard for alpha)
            this.preview = document.createElement('div');
            this.preview.className = 'qs-cp-preview';
            this.preview.style.cssText = `
                width: 36px;
                height: 36px;
                border-radius: 4px;
                border: 1px solid rgba(0,0,0,0.15);
                background: repeating-conic-gradient(#ccc 0% 25%, #fff 0% 50%) 50% / 8px 8px;
                flex-shrink: 0;
            `;
            
            this.previewColor = document.createElement('div');
            this.previewColor.style.cssText = 'width:100%;height:100%;border-radius:3px;';
            this.preview.appendChild(this.previewColor);
            
            previewRow.appendChild(this.preview);
            
            // Hex input
            this.hexInput = document.createElement('input');
            this.hexInput.type = 'text';
            this.hexInput.className = 'qs-cp-hex';
            this.hexInput.style.cssText = `
                flex: 1;
                padding: 6px 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 12px;
                font-family: monospace;
            `;
            this.hexInput.addEventListener('input', () => this._onHexInput());
            this.hexInput.addEventListener('blur', () => this._onHexInput());
            
            previewRow.appendChild(this.hexInput);
            
            this.popup.appendChild(previewRow);
            
            // CSS Variable selector (if variables provided)
            if (this.options.cssVariables && Object.keys(this.options.cssVariables).length > 0) {
                this._createVariableSelector();
            }
            
            // Event listeners for sliders
            this._setupSliderEvents(this.svArea, this._onSVChange.bind(this));
            this._setupSliderEvents(this.hueSlider, this._onHueChange.bind(this));
            if (this.alphaSlider) {
                this._setupSliderEvents(this.alphaSlider, this._onAlphaChange.bind(this));
            }
            
            document.body.appendChild(this.popup);
        }
        
        _setupDragHeader() {
            let isDragging = false;
            let startX, startY, startLeft, startTop;
            
            this.header.addEventListener('mousedown', (e) => {
                // Don't drag if clicking the close button
                if (e.target === this.closeBtn) return;
                
                isDragging = true;
                startX = e.clientX;
                startY = e.clientY;
                
                const rect = this.popup.getBoundingClientRect();
                startLeft = rect.left;
                startTop = rect.top;
                
                e.preventDefault();
            });
            
            document.addEventListener('mousemove', (e) => {
                if (!isDragging) return;
                
                const deltaX = e.clientX - startX;
                const deltaY = e.clientY - startY;
                
                let newLeft = startLeft + deltaX;
                let newTop = startTop + deltaY;
                
                // Keep popup within viewport bounds
                const popupRect = this.popup.getBoundingClientRect();
                const viewportWidth = window.innerWidth;
                const viewportHeight = window.innerHeight;
                
                newLeft = Math.max(0, Math.min(newLeft, viewportWidth - popupRect.width));
                newTop = Math.max(0, Math.min(newTop, viewportHeight - popupRect.height));
                
                this.popup.style.left = newLeft + 'px';
                this.popup.style.top = newTop + 'px';
            });
            
            document.addEventListener('mouseup', () => {
                isDragging = false;
            });
        }
        
        _createVariableSelector() {
            // Container for variable selector
            const varContainer = document.createElement('div');
            varContainer.className = 'qs-cp-variables';
            varContainer.style.cssText = `
                margin-top: 10px;
                padding-top: 10px;
                border-top: 1px solid #eee;
            `;
            
            // Label
            const label = document.createElement('div');
            label.style.cssText = 'font-size:11px;color:#666;margin-bottom:6px;';
            label.textContent = 'CSS Variables:';
            varContainer.appendChild(label);
            
            // Scrollable list of variables
            const varList = document.createElement('div');
            varList.className = 'qs-cp-var-list';
            varList.style.cssText = `
                max-height: 120px;
                overflow-y: auto;
                display: flex;
                flex-wrap: wrap;
                gap: 4px;
            `;
            
            // Filter to only color variables (those starting with --color or containing color values)
            const colorVars = {};
            for (const [name, value] of Object.entries(this.options.cssVariables)) {
                // Include if name contains 'color' or value looks like a color
                if (name.includes('color') || 
                    name.includes('bg') || 
                    name.includes('text') ||
                    name.includes('border') ||
                    /^#[0-9a-fA-F]{3,8}$/.test(value) ||
                    /^rgba?\(/.test(value) ||
                    /^hsla?\(/.test(value)) {
                    colorVars[name] = value;
                }
            }
            
            // Create variable chips
            for (const [name, value] of Object.entries(colorVars)) {
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'qs-cp-var-chip';
                chip.dataset.varName = name;
                chip.dataset.varValue = value;
                chip.title = `${name}: ${value}`;
                chip.style.cssText = `
                    display: flex;
                    align-items: center;
                    gap: 4px;
                    padding: 3px 6px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    background: #fff;
                    cursor: pointer;
                    font-size: 10px;
                    font-family: monospace;
                    transition: border-color 0.15s, background 0.15s;
                `;
                
                // Color swatch
                const swatch = document.createElement('span');
                swatch.style.cssText = `
                    width: 12px;
                    height: 12px;
                    border-radius: 2px;
                    border: 1px solid rgba(0,0,0,0.15);
                    background: ${value};
                    flex-shrink: 0;
                `;
                chip.appendChild(swatch);
                
                // Variable name (shortened)
                const nameSpan = document.createElement('span');
                nameSpan.textContent = name.replace(/^--/, '').substring(0, 12);
                chip.appendChild(nameSpan);
                
                // Click handler
                chip.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this._selectVariable(name, value);
                });
                
                varList.appendChild(chip);
            }
            
            varContainer.appendChild(varList);
            this.varContainer = varContainer;
            this.varChips = varList.querySelectorAll('.qs-cp-var-chip');
            
            this.popup.appendChild(varContainer);
        }
        
        _selectVariable(name, value) {
            // Update selection state
            this.selectedVariable = name;
            
            // Update chip styles
            this.varChips?.forEach(chip => {
                if (chip.dataset.varName === name) {
                    chip.style.borderColor = '#007bff';
                    chip.style.background = '#e7f1ff';
                } else {
                    chip.style.borderColor = '#ddd';
                    chip.style.background = '#fff';
                }
            });
            
            // Update picker color to match variable value
            const parsed = parseColor(value);
            this.color = parsed;
            
            const newHsv = rgbToHsv(parsed.r, parsed.g, parsed.b);
            if (newHsv.s > 0) {
                this.hsv.h = newHsv.h;
            }
            this.hsv.s = newHsv.s;
            this.hsv.v = newHsv.v;
            
            this._updateUI();
            this._emitChange();
        }
        
        _deselectVariable() {
            if (this.selectedVariable) {
                this.selectedVariable = null;
                this.varChips?.forEach(chip => {
                    chip.style.borderColor = '#ddd';
                    chip.style.background = '#fff';
                });
            }
        }
        
        _setupSliderEvents(element, handler) {
            const self = this;
            
            // Store active drag state on the element itself
            element._dragHandler = null;
            element._moveHandler = null;
            element._upHandler = null;
            
            const startDrag = (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                // Store which element is being dragged
                element._dragHandler = handler;
                self._isDragging = true;
                self._activeElement = element;
                
                // Create bound handlers for this drag session
                element._moveHandler = (moveE) => {
                    if (!self._isDragging || self._activeElement !== element) return;
                    moveE.preventDefault();
                    
                    const rect = element.getBoundingClientRect();
                    const clientX = moveE.touches ? moveE.touches[0].clientX : moveE.clientX;
                    const clientY = moveE.touches ? moveE.touches[0].clientY : moveE.clientY;
                    
                    // Clamp coordinates to element bounds
                    const x = Math.max(0, Math.min(rect.width, clientX - rect.left));
                    const y = Math.max(0, Math.min(rect.height, clientY - rect.top));
                    
                    handler(x, y, rect.width, rect.height);
                };
                
                element._upHandler = (upE) => {
                    if (self._activeElement !== element) return;
                    
                    self._activeElement = null;
                    
                    // Delay clearing isDragging to prevent click-outside from closing
                    setTimeout(() => {
                        self._isDragging = false;
                    }, 50);
                    
                    // Clean up listeners
                    window.removeEventListener('mousemove', element._moveHandler, true);
                    window.removeEventListener('mouseup', element._upHandler, true);
                    window.removeEventListener('touchmove', element._moveHandler, { passive: false });
                    window.removeEventListener('touchend', element._upHandler);
                    window.removeEventListener('blur', element._upHandler);
                };
                
                // Process initial click position
                element._moveHandler(e);
                
                // Add window-level listeners for better capture outside bounds
                window.addEventListener('mousemove', element._moveHandler, true);
                window.addEventListener('mouseup', element._upHandler, true);
                window.addEventListener('blur', element._upHandler); // Handle window losing focus
            };
            
            element.addEventListener('mousedown', startDrag);
            
            element.addEventListener('touchstart', (e) => {
                startDrag(e);
                window.addEventListener('touchmove', element._moveHandler, { passive: false });
                window.addEventListener('touchend', element._upHandler);
            });
        }
        
        _onSVChange(x, y, width, height) {
            const s = Math.max(0, Math.min(100, (x / width) * 100));
            const v = Math.max(0, Math.min(100, (1 - y / height) * 100));
            
            this.hsv.s = s;
            this.hsv.v = v;
            
            // Deselect variable when user manually changes color
            this._deselectVariable();
            
            this._updateFromHSV();
        }
        
        _onHueChange(x, _y, width, _height) {
            const h = Math.max(0, Math.min(360, (x / width) * 360));
            this.hsv.h = h;
            
            // Deselect variable when user manually changes color
            this._deselectVariable();
            
            this._updateFromHSV();
        }
        
        _onAlphaChange(x, _y, width, _height) {
            const a = Math.max(0, Math.min(1, x / width));
            this.color.a = Math.round(a * 100) / 100;
            
            // Deselect variable when user manually changes color
            this._deselectVariable();
            
            this._updateUI();
            this._emitChange();
        }
        
        _onHexInput() {
            const value = this.hexInput.value.trim();
            if (value) {
                const parsed = parseColor(value);
                this.color = parsed;
                
                const newHsv = rgbToHsv(parsed.r, parsed.g, parsed.b);
                
                // Preserve hue if new color is grayscale
                if (newHsv.s > 0) {
                    this.hsv.h = newHsv.h;
                }
                this.hsv.s = newHsv.s;
                this.hsv.v = newHsv.v;
                
                // Deselect variable when user manually types a color
                this._deselectVariable();
                
                this._updateUI();
                this._emitChange();
            }
        }
        
        _updateFromHSV() {
            const rgb = hsvToRgb(this.hsv.h, this.hsv.s, this.hsv.v);
            this.color.r = rgb.r;
            this.color.g = rgb.g;
            this.color.b = rgb.b;
            
            this._updateUI();
            this._emitChange();
        }
        
        _updateUI() {
            if (!this.popup) return;
            
            // Update SV area background (pure hue)
            const pureHue = hsvToRgb(this.hsv.h, 100, 100);
            this.svArea.style.backgroundColor = `rgb(${pureHue.r}, ${pureHue.g}, ${pureHue.b})`;
            
            // Update SV cursor position
            const svX = (this.hsv.s / 100) * 100;
            const svY = (1 - this.hsv.v / 100) * 100;
            this.svCursor.style.left = svX + '%';
            this.svCursor.style.top = svY + '%';
            
            // Update hue cursor
            this.hueCursor.style.left = (this.hsv.h / 360) * 100 + '%';
            
            // Update alpha slider
            if (this.alphaSlider) {
                this.alphaGradient.style.background = `linear-gradient(to right, 
                    rgba(${this.color.r}, ${this.color.g}, ${this.color.b}, 0),
                    rgba(${this.color.r}, ${this.color.g}, ${this.color.b}, 1)
                )`;
                this.alphaCursor.style.left = (this.color.a * 100) + '%';
            }
            
            // Update preview
            this.previewColor.style.backgroundColor = rgbaToCss(this.color, true);
            
            // Update hex input
            if (this.color.a < 1) {
                this.hexInput.value = rgbaToCss(this.color);
            } else {
                this.hexInput.value = rgbaToHex(this.color);
            }
            
            // Update input swatch
            this._updateSwatch();
        }
        
        _emitChange() {
            // If a CSS variable is selected, use var(--name) instead of color value
            if (this.selectedVariable) {
                const varValue = `var(${this.selectedVariable})`;
                this.input.value = varValue;
                
                // Trigger input event
                this.input.dispatchEvent(new Event('input', { bubbles: true }));
                this.input.dispatchEvent(new Event('change', { bubbles: true }));
                
                // Callback
                if (this.options.onChange) {
                    this.options.onChange(varValue, this.color);
                }
                return;
            }
            
            // Update the original input with color value
            const format = this.options.format;
            let value;
            
            if (format === 'hex') {
                value = rgbaToHex(this.color, this.color.a < 1);
            } else if (format === 'rgb') {
                value = rgbaToCss(this.color, false);
            } else if (format === 'rgba') {
                value = rgbaToCss(this.color, true);
            } else {
                // Auto: hex if opaque, rgba if transparent
                value = this.color.a < 1 ? rgbaToCss(this.color) : rgbaToHex(this.color);
            }
            
            this.input.value = value;
            
            // Trigger input event
            this.input.dispatchEvent(new Event('input', { bubbles: true }));
            this.input.dispatchEvent(new Event('change', { bubbles: true }));
            
            // Callback
            if (this.options.onChange) {
                this.options.onChange(value, this.color);
            }
        }
        
        _parseInputColor() {
            const value = this.input.value.trim();
            if (value) {
                this.color = parseColor(value);
            } else {
                this.color = { r: 0, g: 0, b: 0, a: 1 };
            }
            
            const newHsv = rgbToHsv(this.color.r, this.color.g, this.color.b);
            
            // Preserve the current hue if the new color is grayscale (saturation = 0)
            // because grayscale colors have no defined hue mathematically
            if (newHsv.s > 0) {
                this.hsv.h = newHsv.h;
            }
            // Always update saturation and value
            this.hsv.s = newHsv.s;
            this.hsv.v = newHsv.v;
        }
        
        _onInputClick(e) {
            e.stopPropagation();
            if (!this.isOpen) {
                this.open();
            }
        }
        
        _onInputChange() {
            this._parseInputColor();
            this._updateUI();
        }
        
        _onDocumentClick(e) {
            // Don't close if we're in the middle of dragging a slider
            if (this._isDragging) return;
            
            if (!this.popup.contains(e.target) && 
                !this.input.contains(e.target) && 
                !this.swatch.contains(e.target)) {
                this.close();
            }
        }
        
        _onDocumentKeydown(e) {
            if (e.key === 'Escape') {
                this.close();
            }
        }
        
        open() {
            if (this.isOpen) return;
            
            this._parseInputColor();
            this._updateUI();
            
            // Position popup
            const inputRect = this.input.getBoundingClientRect();
            const popupHeight = 280;
            const spaceBelow = window.innerHeight - inputRect.bottom;
            const spaceAbove = inputRect.top;
            
            let top, left;
            
            if (this.options.position === 'top' || 
                (this.options.position === 'auto' && spaceBelow < popupHeight && spaceAbove > spaceBelow)) {
                // Position above
                top = inputRect.top - popupHeight - 8;
            } else {
                // Position below
                top = inputRect.bottom + 8;
            }
            
            left = inputRect.left;
            
            // Keep within viewport (260px width + 60px padding = 320px total)
            const popupWidth = 320;
            if (left + popupWidth > window.innerWidth - 10) {
                left = window.innerWidth - popupWidth - 10;
            }
            if (left < 10) left = 10;
            if (top < 10) top = 10;
            
            this.popup.style.top = top + 'px';
            this.popup.style.left = left + 'px';
            this.popup.style.display = 'block';
            
            this.isOpen = true;
            
            // Add event listeners
            setTimeout(() => {
                document.addEventListener('click', this._onDocumentClick);
                document.addEventListener('keydown', this._onDocumentKeydown);
            }, 0);
        }
        
        close() {
            if (!this.isOpen) return;
            
            // Clean up any active drag state
            this._cleanupDrag();
            
            this.popup.style.display = 'none';
            this.isOpen = false;
            
            document.removeEventListener('click', this._onDocumentClick);
            document.removeEventListener('keydown', this._onDocumentKeydown);
        }
        
        /**
         * Clean up any active drag operations
         */
        _cleanupDrag() {
            this._isDragging = false;
            
            // Clean up drag listeners from all slider elements
            [this.svArea, this.hueSlider, this.alphaSlider].forEach(el => {
                if (el && el._upHandler) {
                    window.removeEventListener('mousemove', el._moveHandler, true);
                    window.removeEventListener('mouseup', el._upHandler, true);
                    window.removeEventListener('touchmove', el._moveHandler);
                    window.removeEventListener('touchend', el._upHandler);
                    window.removeEventListener('blur', el._upHandler);
                    el._moveHandler = null;
                    el._upHandler = null;
                }
            });
            
            this._activeElement = null;
        }
        
        /**
         * Set color programmatically
         */
        setColor(color) {
            this.color = parseColor(color);
            
            const newHsv = rgbToHsv(this.color.r, this.color.g, this.color.b);
            
            // Preserve hue if new color is grayscale
            if (newHsv.s > 0) {
                this.hsv.h = newHsv.h;
            }
            this.hsv.s = newHsv.s;
            this.hsv.v = newHsv.v;
            
            this._updateUI();
            this._emitChange();
        }
        
        /**
         * Get current color
         */
        getColor() {
            return { ...this.color };
        }
        
        /**
         * Destroy the picker
         */
        destroy() {
            this.close();
            
            this.input.removeEventListener('click', this._onInputClick);
            this.input.removeEventListener('focus', this._onInputClick);
            this.input.removeEventListener('input', this._onInputChange);
            
            if (this.popup && this.popup.parentNode) {
                this.popup.parentNode.removeChild(this.popup);
            }
            
            // Unwrap input
            const wrapper = this.input.parentNode;
            if (wrapper && wrapper.classList.contains('qs-cp-input-wrap')) {
                wrapper.parentNode.insertBefore(this.input, wrapper);
                wrapper.parentNode.removeChild(wrapper);
            }
        }
    }
    
    // Export
    global.QSColorPicker = QSColorPicker;
    
    // Also export utilities for external use
    global.QSColorPicker.parseColor = parseColor;
    global.QSColorPicker.rgbaToHex = rgbaToHex;
    global.QSColorPicker.rgbaToCss = rgbaToCss;
    global.QSColorPicker.hexToRgba = hexToRgba;
    
})(typeof window !== 'undefined' ? window : this);
