/**
 * QSEasingPicker — cubic-bezier easing curve picker (Motion Slice 3).
 *
 * Self-contained popover. SVG curve canvas with two draggable handles +
 * preset chips + numeric inputs for x1, y1, x2, y2. Confirm returns a
 * `cubic-bezier(...)` (or named) string via opts.onConfirm. No deps.
 *
 * Usage:
 *   QSEasingPicker.open({
 *     anchor:    inputEl,                 // optional — popover sits near it
 *     value:     'cubic-bezier(.42,0,.58,1)' | 'ease' | 'linear' | ...,
 *     onConfirm: (newValue) => { ... },   // string
 *     onCancel:  () => { ... }
 *   });
 *
 *   QSEasingPicker.close();
 */
(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';

    var PRESETS = {
        'linear':      [0,    0,    1,    1],
        'ease':        [0.25, 0.1,  0.25, 1],
        'ease-in':     [0.42, 0,    1,    1],
        'ease-out':    [0,    0,    0.58, 1],
        'ease-in-out': [0.42, 0,    0.58, 1]
    };

    var CANVAS_SIZE = 220;
    var PAD = 28;
    var PLOT = CANVAS_SIZE - 2 * PAD;

    // ── State ──
    var _root          = null;
    var _backdrop      = null;
    var _dialog        = null;
    var _curvePath     = null;
    var _handle1Group  = null;
    var _handle2Group  = null;
    var _control1      = null;
    var _control2      = null;
    var _line1         = null;
    var _line2         = null;
    var _previewDot    = null;
    var _previewBar    = null;
    var _valueLabel    = null;
    var _inputs        = [];   // [x1Input, y1Input, x2Input, y2Input]
    var _presetButtons = [];
    var _applyBtn      = null;
    var _cancelBtn     = null;
    var _closeBtn      = null;
    var _replayBtn     = null;

    var _values     = [0.25, 0.1, 0.25, 1];
    var _onConfirm  = null;
    var _onCancel   = null;
    var _dragging   = -1;        // 0 = p1, 1 = p2, -1 = none
    var _dragMoveHandler = null;
    var _dragUpHandler   = null;

    // ── Public API ──

    function open(opts) {
        opts = opts || {};
        _onConfirm = (typeof opts.onConfirm === 'function') ? opts.onConfirm : null;
        _onCancel  = (typeof opts.onCancel  === 'function') ? opts.onCancel  : null;
        _values = parseValue(opts.value) || PRESETS.ease.slice();

        if (!_root) buildDom();
        _root.hidden = false;
        positionDialog(opts.anchor || null);
        syncFromValues();
        // Focus the first numeric input for quick keyboard tweaks.
        if (_inputs[0]) {
            try { _inputs[0].focus(); _inputs[0].select(); } catch (e) { /* no-op */ }
        }
    }

    function close() {
        if (!_root) return;
        endDrag();
        _root.hidden = true;
        _onConfirm = null;
        _onCancel  = null;
    }

    // ── DOM construction ──

    function buildDom() {
        _root = document.createElement('div');
        _root.className = 'qs-easing-picker';
        _root.hidden = true;

        _backdrop = document.createElement('div');
        _backdrop.className = 'qs-easing-picker__backdrop';
        _backdrop.addEventListener('click', handleCancel);
        _root.appendChild(_backdrop);

        _dialog = document.createElement('div');
        _dialog.className = 'qs-easing-picker__dialog';
        _dialog.setAttribute('role', 'dialog');
        _root.appendChild(_dialog);

        // Header
        var header = document.createElement('div');
        header.className = 'qs-easing-picker__header';
        var title = document.createElement('h3');
        title.className = 'qs-easing-picker__title';
        title.textContent = i18n('easingPickerTitle', 'Easing curve');
        _closeBtn = document.createElement('button');
        _closeBtn.type = 'button';
        _closeBtn.className = 'qs-easing-picker__close';
        _closeBtn.setAttribute('aria-label', i18n('close', 'Close'));
        _closeBtn.textContent = '×';
        _closeBtn.addEventListener('click', handleCancel);
        header.appendChild(title);
        header.appendChild(_closeBtn);
        _dialog.appendChild(header);

        // Body
        var body = document.createElement('div');
        body.className = 'qs-easing-picker__body';
        _dialog.appendChild(body);

        // Curve canvas (SVG)
        var canvasWrap = document.createElement('div');
        canvasWrap.className = 'qs-easing-picker__canvas-wrap';
        body.appendChild(canvasWrap);

        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'qs-easing-picker__canvas');
        svg.setAttribute('viewBox', '0 0 ' + CANVAS_SIZE + ' ' + CANVAS_SIZE);
        svg.setAttribute('width', String(CANVAS_SIZE));
        svg.setAttribute('height', String(CANVAS_SIZE));
        canvasWrap.appendChild(svg);

        // Grid (faint unit square)
        var grid = document.createElementNS(SVG_NS, 'rect');
        grid.setAttribute('x', String(PAD));
        grid.setAttribute('y', String(PAD));
        grid.setAttribute('width', String(PLOT));
        grid.setAttribute('height', String(PLOT));
        grid.setAttribute('class', 'qs-easing-picker__grid');
        svg.appendChild(grid);

        // Diagonal (linear reference)
        var diag = document.createElementNS(SVG_NS, 'line');
        diag.setAttribute('x1', String(PAD));
        diag.setAttribute('y1', String(PAD + PLOT));
        diag.setAttribute('x2', String(PAD + PLOT));
        diag.setAttribute('y2', String(PAD));
        diag.setAttribute('class', 'qs-easing-picker__diag');
        svg.appendChild(diag);

        // The two handle "leg" lines from corner anchors to control points.
        _line1 = document.createElementNS(SVG_NS, 'line');
        _line1.setAttribute('class', 'qs-easing-picker__leg');
        svg.appendChild(_line1);
        _line2 = document.createElementNS(SVG_NS, 'line');
        _line2.setAttribute('class', 'qs-easing-picker__leg');
        svg.appendChild(_line2);

        // The curve itself.
        _curvePath = document.createElementNS(SVG_NS, 'path');
        _curvePath.setAttribute('class', 'qs-easing-picker__curve');
        svg.appendChild(_curvePath);

        // Anchor points at (0,0) and (1,1) — small dots so the curve's
        // endpoints read visually.
        var anchorA = document.createElementNS(SVG_NS, 'circle');
        anchorA.setAttribute('cx', String(PAD));
        anchorA.setAttribute('cy', String(PAD + PLOT));
        anchorA.setAttribute('r', '3');
        anchorA.setAttribute('class', 'qs-easing-picker__anchor');
        svg.appendChild(anchorA);
        var anchorB = document.createElementNS(SVG_NS, 'circle');
        anchorB.setAttribute('cx', String(PAD + PLOT));
        anchorB.setAttribute('cy', String(PAD));
        anchorB.setAttribute('r', '3');
        anchorB.setAttribute('class', 'qs-easing-picker__anchor');
        svg.appendChild(anchorB);

        // Control point 1 — group lets us attach a larger invisible hit-area.
        _handle1Group = makeHandleGroup(0);
        svg.appendChild(_handle1Group);
        _control1 = _handle1Group.querySelector('.qs-easing-picker__handle');

        _handle2Group = makeHandleGroup(1);
        svg.appendChild(_handle2Group);
        _control2 = _handle2Group.querySelector('.qs-easing-picker__handle');

        // Presets row
        var presets = document.createElement('div');
        presets.className = 'qs-easing-picker__presets';
        Object.keys(PRESETS).forEach(function (name) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'qs-easing-picker__preset';
            btn.dataset.preset = name;
            btn.textContent = name;
            btn.addEventListener('click', function () {
                _values = PRESETS[name].slice();
                syncFromValues();
            });
            presets.appendChild(btn);
            _presetButtons.push(btn);
        });
        body.appendChild(presets);

        // Numeric inputs (2 rows of 2)
        var grid2 = document.createElement('div');
        grid2.className = 'qs-easing-picker__inputs';
        ['x1', 'y1', 'x2', 'y2'].forEach(function (label, idx) {
            var col = document.createElement('label');
            col.className = 'qs-easing-picker__input-col';
            var span = document.createElement('span');
            span.className = 'qs-easing-picker__input-label';
            span.textContent = label;
            var input = document.createElement('input');
            input.type = 'number';
            input.className = 'qs-easing-picker__input';
            input.step = '0.01';
            // x values are clamped 0..1 by CSS spec; y can overshoot.
            if (idx === 0 || idx === 2) {
                input.min = '0';
                input.max = '1';
            }
            input.addEventListener('input', function () {
                var v = parseFloat(input.value);
                if (isFinite(v)) {
                    if (idx === 0 || idx === 2) v = clamp(v, 0, 1);
                    _values[idx] = v;
                    syncFromValues(/* skipInputs */ true);
                }
            });
            col.appendChild(span);
            col.appendChild(input);
            grid2.appendChild(col);
            _inputs.push(input);
        });
        body.appendChild(grid2);

        // Value preview text
        _valueLabel = document.createElement('code');
        _valueLabel.className = 'qs-easing-picker__value';
        body.appendChild(_valueLabel);

        // Animated preview (a dot moving across with the chosen easing)
        var previewRow = document.createElement('div');
        previewRow.className = 'qs-easing-picker__preview';
        _previewBar = document.createElement('div');
        _previewBar.className = 'qs-easing-picker__preview-bar';
        _previewDot = document.createElement('div');
        _previewDot.className = 'qs-easing-picker__preview-dot';
        _previewBar.appendChild(_previewDot);
        _replayBtn = document.createElement('button');
        _replayBtn.type = 'button';
        _replayBtn.className = 'qs-easing-picker__replay';
        _replayBtn.textContent = i18n('easingPickerReplay', '▶ Replay');
        _replayBtn.addEventListener('click', replayPreview);
        previewRow.appendChild(_previewBar);
        previewRow.appendChild(_replayBtn);
        body.appendChild(previewRow);

        // Footer
        var footer = document.createElement('div');
        footer.className = 'qs-easing-picker__footer';
        _cancelBtn = document.createElement('button');
        _cancelBtn.type = 'button';
        _cancelBtn.className = 'admin-btn admin-btn--ghost admin-btn--sm';
        _cancelBtn.textContent = i18n('cancel', 'Cancel');
        _cancelBtn.addEventListener('click', handleCancel);
        _applyBtn = document.createElement('button');
        _applyBtn.type = 'button';
        _applyBtn.className = 'admin-btn admin-btn--primary admin-btn--sm';
        _applyBtn.textContent = i18n('apply', 'Apply');
        _applyBtn.addEventListener('click', handleApply);
        footer.appendChild(_cancelBtn);
        footer.appendChild(_applyBtn);
        _dialog.appendChild(footer);

        document.body.appendChild(_root);

        // Keyboard — Escape cancels.
        document.addEventListener('keydown', function (e) {
            if (!_root || _root.hidden) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                handleCancel();
            }
        });
    }

    function makeHandleGroup(idx) {
        var g = document.createElementNS(SVG_NS, 'g');
        g.setAttribute('class', 'qs-easing-picker__handle-group');
        g.dataset.handle = String(idx);

        // Larger invisible hit-area circle for easier dragging.
        var hit = document.createElementNS(SVG_NS, 'circle');
        hit.setAttribute('r', '14');
        hit.setAttribute('class', 'qs-easing-picker__handle-hit');
        g.appendChild(hit);

        // Visible handle.
        var c = document.createElementNS(SVG_NS, 'circle');
        c.setAttribute('r', '7');
        c.setAttribute('class', 'qs-easing-picker__handle');
        g.appendChild(c);

        // Pointer events on the group cover both circles.
        g.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            startDrag(idx, e);
        });
        return g;
    }

    // ── Drag handling ──

    function startDrag(idx, e) {
        _dragging = idx;
        var svg = _curvePath.ownerSVGElement;
        if (!svg) return;

        _dragMoveHandler = function (ev) {
            ev.preventDefault();
            var pt = clientPointToPlot(svg, ev.clientX, ev.clientY);
            // Clamp x to [0,1] per CSS cubic-bezier spec; let y overshoot.
            var nx = clamp(pt.x, 0, 1);
            var ny = pt.y;
            _values[_dragging * 2]     = nx;
            _values[_dragging * 2 + 1] = ny;
            syncFromValues();
        };
        _dragUpHandler = function () { endDrag(); };

        document.addEventListener('pointermove', _dragMoveHandler);
        document.addEventListener('pointerup',   _dragUpHandler);
        document.addEventListener('pointercancel', _dragUpHandler);
    }

    function endDrag() {
        if (_dragging === -1) return;
        _dragging = -1;
        if (_dragMoveHandler) document.removeEventListener('pointermove', _dragMoveHandler);
        if (_dragUpHandler) {
            document.removeEventListener('pointerup',     _dragUpHandler);
            document.removeEventListener('pointercancel', _dragUpHandler);
        }
        _dragMoveHandler = null;
        _dragUpHandler   = null;
    }

    function clientPointToPlot(svg, clientX, clientY) {
        var rect = svg.getBoundingClientRect();
        // Convert client coords to viewBox coords. SVG is sized to its
        // viewBox attr (no preserveAspectRatio surprises here).
        var sx = CANVAS_SIZE / rect.width;
        var sy = CANVAS_SIZE / rect.height;
        var vx = (clientX - rect.left) * sx;
        var vy = (clientY - rect.top) * sy;
        // Map viewBox space (origin top-left) → plot space (0..1, origin bottom-left).
        var px = (vx - PAD) / PLOT;
        var py = (PAD + PLOT - vy) / PLOT;
        return { x: px, y: py };
    }

    function plotPointToCanvas(x, y) {
        return {
            cx: PAD + x * PLOT,
            cy: PAD + PLOT - y * PLOT
        };
    }

    // ── Sync (state → DOM) ──

    function syncFromValues(skipInputs) {
        var v = _values;
        var p1 = plotPointToCanvas(v[0], v[1]);
        var p2 = plotPointToCanvas(v[2], v[3]);

        // Path: M (0,1) C cp1 cp2 (1,1)  — in plot space (0..1)
        // In canvas: M (PAD, PAD+PLOT) C cp1 cp2 (PAD+PLOT, PAD)
        var d = 'M ' + PAD + ',' + (PAD + PLOT) +
                ' C ' + p1.cx + ',' + p1.cy +
                ' '   + p2.cx + ',' + p2.cy +
                ' '   + (PAD + PLOT) + ',' + PAD;
        if (_curvePath) _curvePath.setAttribute('d', d);

        // Leg lines (anchor to control point)
        if (_line1) {
            _line1.setAttribute('x1', String(PAD));
            _line1.setAttribute('y1', String(PAD + PLOT));
            _line1.setAttribute('x2', String(p1.cx));
            _line1.setAttribute('y2', String(p1.cy));
        }
        if (_line2) {
            _line2.setAttribute('x1', String(PAD + PLOT));
            _line2.setAttribute('y1', String(PAD));
            _line2.setAttribute('x2', String(p2.cx));
            _line2.setAttribute('y2', String(p2.cy));
        }

        // Handle positions
        setHandlePos(_handle1Group, p1.cx, p1.cy);
        setHandlePos(_handle2Group, p2.cx, p2.cy);

        // Numeric inputs (skip during input-driven sync to avoid clobbering
        // the user's in-progress typing).
        if (!skipInputs) {
            _inputs.forEach(function (inp, i) {
                if (document.activeElement !== inp) {
                    inp.value = formatNum(v[i]);
                }
            });
        }

        // Value label + preset highlight
        var valueStr = currentValueString();
        if (_valueLabel) _valueLabel.textContent = valueStr;
        _presetButtons.forEach(function (btn) {
            btn.classList.toggle('qs-easing-picker__preset--active',
                btn.dataset.preset === valueStr);
        });

        // Preview animation re-armed
        updatePreviewAnimation();
    }

    function setHandlePos(g, cx, cy) {
        if (!g) return;
        g.setAttribute('transform', 'translate(' + cx + ',' + cy + ')');
    }

    // ── Preview animation ──

    function updatePreviewAnimation() {
        if (!_previewDot) return;
        var v = _values;
        _previewDot.style.animationName = 'qs-easing-picker-preview';
        _previewDot.style.animationDuration = '1.2s';
        _previewDot.style.animationTimingFunction =
            'cubic-bezier(' + v[0] + ',' + v[1] + ',' + v[2] + ',' + v[3] + ')';
        _previewDot.style.animationIterationCount = '1';
        _previewDot.style.animationFillMode = 'forwards';
        replayPreview();
    }

    function replayPreview() {
        if (!_previewDot) return;
        // Re-trigger the CSS animation by clearing + restoring it.
        _previewDot.style.animation = 'none';
        // Force layout flush
        void _previewDot.offsetWidth;
        _previewDot.style.animation = '';
        // Re-apply via style assignment so duration / timing-function reapply.
        var v = _values;
        _previewDot.style.animationName = 'qs-easing-picker-preview';
        _previewDot.style.animationDuration = '1.2s';
        _previewDot.style.animationTimingFunction =
            'cubic-bezier(' + v[0] + ',' + v[1] + ',' + v[2] + ',' + v[3] + ')';
        _previewDot.style.animationIterationCount = '1';
        _previewDot.style.animationFillMode = 'forwards';
    }

    // ── Value string formatting / parsing ──

    function currentValueString() {
        // Match a named preset if values are exactly equal — keep the
        // friendly name when possible.
        var keys = Object.keys(PRESETS);
        for (var i = 0; i < keys.length; i++) {
            var p = PRESETS[keys[i]];
            if (p[0] === _values[0] && p[1] === _values[1]
                && p[2] === _values[2] && p[3] === _values[3]) {
                return keys[i];
            }
        }
        return 'cubic-bezier(' + formatNum(_values[0]) + ', ' + formatNum(_values[1]) +
            ', ' + formatNum(_values[2]) + ', ' + formatNum(_values[3]) + ')';
    }

    function parseValue(value) {
        if (!value) return null;
        var v = String(value).trim();
        if (Object.prototype.hasOwnProperty.call(PRESETS, v)) {
            return PRESETS[v].slice();
        }
        var m = v.match(/^cubic-bezier\s*\(\s*(-?\d*\.?\d+)\s*,\s*(-?\d*\.?\d+)\s*,\s*(-?\d*\.?\d+)\s*,\s*(-?\d*\.?\d+)\s*\)$/);
        if (m) return [parseFloat(m[1]), parseFloat(m[2]), parseFloat(m[3]), parseFloat(m[4])];
        return null;
    }

    function formatNum(n) {
        return String(Math.round(n * 1000) / 1000);
    }

    function clamp(v, lo, hi) {
        return Math.max(lo, Math.min(hi, v));
    }

    // ── Anchor positioning ──

    function positionDialog(anchor) {
        if (!_dialog) return;
        if (!anchor) {
            // Centered modal — clear any inline positioning.
            _dialog.style.position = '';
            _dialog.style.left = '';
            _dialog.style.top  = '';
            _dialog.classList.remove('qs-easing-picker__dialog--anchored');
            return;
        }
        _dialog.classList.add('qs-easing-picker__dialog--anchored');
        var rect = anchor.getBoundingClientRect();
        // Render off-screen first to measure dialog size, then position.
        _dialog.style.position = 'fixed';
        _dialog.style.left = '0px';
        _dialog.style.top  = '0px';
        _dialog.style.visibility = 'hidden';
        // Force layout
        var dw = _dialog.offsetWidth;
        var dh = _dialog.offsetHeight;
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        // Default: below the anchor, left-aligned with it.
        var left = rect.left;
        var top  = rect.bottom + 6;
        // Clamp horizontally to viewport.
        if (left + dw > vw - 8) left = Math.max(8, vw - dw - 8);
        // Flip above if no room below.
        if (top + dh > vh - 8) top = Math.max(8, rect.top - dh - 6);
        _dialog.style.left = left + 'px';
        _dialog.style.top  = top  + 'px';
        _dialog.style.visibility = '';
    }

    // ── Confirm / cancel ──

    function handleApply() {
        var value = currentValueString();
        var cb = _onConfirm;
        close();
        if (cb) cb(value);
    }

    function handleCancel() {
        var cb = _onCancel;
        close();
        if (cb) cb();
    }

    // ── i18n helper (defensive — works even if PreviewConfig is absent) ──

    function i18n(key, fallback) {
        var dict = (window.PreviewConfig && PreviewConfig.i18n) || {};
        return dict['easingPicker' + capitalize(key)] || dict[key] || fallback;
    }

    function capitalize(s) {
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
    }

    // ── Public namespace ──

    window.QSEasingPicker = {
        open:  open,
        close: close,
        PRESETS: PRESETS,
        parseValue: parseValue
    };
})();
