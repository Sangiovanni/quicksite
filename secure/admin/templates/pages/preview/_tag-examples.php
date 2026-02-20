<?php
/**
 * Tag Examples Mapping
 * 
 * Maps HTML tag names to visual example HTML strings.
 * Examples are designed to fit within a ~200px preview area.
 * Uses CSS classes from tag-examples.css
 * Uses __admin() for translatable text content.
 * 
 * Values:
 * - string: HTML example to render
 * - false: Tag cannot be visually rendered (shows "No visual preview")
 */

function getTagExamples(): array {
    return [
        // ===== LAYOUT TAGS =====
        'div' => '<div class="tag-ex-container">' . __admin('preview.example.container') . '</div>',
        
        'span' => '<p>' . __admin('preview.example.textBefore') . ' <span class="tag-ex-span-highlight">' . __admin('preview.example.highlighted') . '</span> ' . __admin('preview.example.textAfter') . '</p>',
        
        'section' => '<section class="tag-ex-section"><strong class="tag-ex-section-title">' . __admin('preview.example.sectionTitle') . '</strong><p class="tag-ex-section-content">' . __admin('preview.example.sectionContent') . '</p></section>',
        
        'article' => '<article class="tag-ex-article"><h4 class="tag-ex-article-title">' . __admin('preview.example.articleTitle') . '</h4><p class="tag-ex-article-content">' . __admin('preview.example.articleContent') . '</p></article>',
        
        'aside' => '<aside class="tag-ex-aside"><strong class="tag-ex-aside-title">💡 ' . __admin('preview.example.tip') . '</strong><br>' . __admin('preview.example.asideContent') . '</aside>',
        
        'header' => '<header class="tag-ex-header">🏠 ' . __admin('preview.example.siteTitle') . '</header>',
        
        'footer' => '<footer class="tag-ex-footer">© 2026 ' . __admin('preview.example.company') . '</footer>',
        
        'main' => '<main class="tag-ex-main"><strong class="tag-ex-main-title">' . __admin('preview.example.mainContent') . '</strong><p class="tag-ex-main-content">' . __admin('preview.example.mainDesc') . '</p></main>',
        
        'nav' => '<nav class="tag-ex-nav"><a href="#" class="tag-ex-nav-link">' . __admin('preview.example.home') . '</a><a href="#" class="tag-ex-nav-link">' . __admin('preview.example.about') . '</a><a href="#" class="tag-ex-nav-link">' . __admin('preview.example.contact') . '</a></nav>',
        
        'figure' => '<figure class="tag-ex-figure"><div class="tag-ex-figure-placeholder">🖼️</div><figcaption class="tag-ex-figcaption">' . __admin('preview.example.imageCaption') . '</figcaption></figure>',
        
        'figcaption' => '<figure class="tag-ex-figure"><div class="tag-ex-figure-placeholder">🖼️</div><figcaption class="tag-ex-figcaption">' . __admin('preview.example.figcaptionText') . '</figcaption></figure>',
        
        // ===== TEXT TAGS =====
        'p' => '<p class="tag-ex-paragraph">' . __admin('preview.example.paragraph') . '</p>',
        
        'h1' => '<h1 class="tag-ex-heading-1">' . __admin('preview.example.heading1') . '</h1>',
        'h2' => '<h2 class="tag-ex-heading-2">' . __admin('preview.example.heading2') . '</h2>',
        'h3' => '<h3 class="tag-ex-heading-3">' . __admin('preview.example.heading3') . '</h3>',
        'h4' => '<h4 class="tag-ex-heading-4">' . __admin('preview.example.heading4') . '</h4>',
        'h5' => '<h5 class="tag-ex-heading-5">' . __admin('preview.example.heading5') . '</h5>',
        'h6' => '<h6 class="tag-ex-heading-6">' . __admin('preview.example.heading6') . '</h6>',
        
        'strong' => '<p>' . __admin('preview.example.textNormal') . ' <strong class="tag-ex-strong">' . __admin('preview.example.textImportant') . '</strong></p>',
        
        'em' => '<p>' . __admin('preview.example.textNormal') . ' <em class="tag-ex-em">' . __admin('preview.example.textEmphasis') . '</em></p>',
        
        'b' => '<p>' . __admin('preview.example.textNormal') . ' <b class="tag-ex-bold">' . __admin('preview.example.textBold') . '</b></p>',
        
        'i' => '<p>' . __admin('preview.example.textNormal') . ' <i class="tag-ex-italic">' . __admin('preview.example.textItalic') . '</i></p>',
        
        'u' => '<p>' . __admin('preview.example.textNormal') . ' <u class="tag-ex-underline">' . __admin('preview.example.textUnderline') . '</u></p>',
        
        's' => '<p><s class="tag-ex-price-old">' . __admin('preview.example.priceOld') . '</s> <strong class="tag-ex-price-new">' . __admin('preview.example.priceNew') . '</strong></p>',
        
        'del' => '<p><del class="tag-ex-deleted">' . __admin('preview.example.textDeleted') . '</del></p>',
        
        'ins' => '<p><ins class="tag-ex-inserted">' . __admin('preview.example.textInserted') . '</ins></p>',
        
        'mark' => '<p>' . __admin('preview.example.textSearch') . ' <mark class="tag-ex-mark">' . __admin('preview.example.textHighlighted') . '</mark></p>',
        
        'small' => '<p>' . __admin('preview.example.textNormal') . ' <small class="tag-ex-small">' . __admin('preview.example.textSmall') . '</small></p>',
        
        'sub' => '<p>H<sub class="tag-ex-sub">2</sub>O (' . __admin('preview.example.water') . ')</p>',
        
        'sup' => '<p>E = mc<sup class="tag-ex-sup">2</sup></p>',
        
        'abbr' => '<p><abbr title="' . __admin('preview.example.htmlFull') . '" class="tag-ex-abbr">HTML</abbr> ' . __admin('preview.example.abbrDesc') . '</p>',
        
        'code' => '<p>' . __admin('preview.example.useFunction') . ' <code class="tag-ex-code">console.log()</code></p>',
        
        'pre' => '<pre class="tag-ex-pre">function hello() {
    console.log("Hello!");
}</pre>',
        
        'kbd' => '<p>' . __admin('preview.example.pressCopy') . ' <kbd class="tag-ex-kbd">Ctrl</kbd> + <kbd class="tag-ex-kbd">C</kbd></p>',
        
        'samp' => '<p>' . __admin('preview.example.output') . ' <samp class="tag-ex-samp">Error: File not found</samp></p>',
        
        'var' => '<p>' . __admin('preview.example.formula') . ': <var class="tag-ex-var">x</var> + <var class="tag-ex-var">y</var> = <var class="tag-ex-var">z</var></p>',
        
        'blockquote' => '<blockquote class="tag-ex-blockquote">"' . __admin('preview.example.quote') . '"<footer class="tag-ex-blockquote-footer">— ' . __admin('preview.example.quoteAuthor') . '</footer></blockquote>',
        
        'q' => '<p>' . __admin('preview.example.heSaid') . ' <q class="tag-ex-q">' . __admin('preview.example.shortQuote') . '</q></p>',
        
        'cite' => '<p>' . __admin('preview.example.source') . ' <cite class="tag-ex-cite">' . __admin('preview.example.bookTitle') . '</cite></p>',
        
        'dfn' => '<p><dfn class="tag-ex-dfn">' . __admin('preview.example.term') . '</dfn>: ' . __admin('preview.example.termDef') . '</p>',
        
        'address' => '<address class="tag-ex-address">📍 123 ' . __admin('preview.example.street') . '<br>📧 contact@example.com</address>',
        
        'time' => '<p>' . __admin('preview.example.published') . ' <time datetime="2026-02-13" class="tag-ex-time">13 ' . __admin('preview.example.february') . ' 2026</time></p>',
        
        'br' => '<p>' . __admin('preview.example.line1') . '<br>' . __admin('preview.example.line2') . '<br>' . __admin('preview.example.line3') . '</p>',
        
        'hr' => '<p>' . __admin('preview.example.before') . '</p><hr class="tag-ex-hr"><p>' . __admin('preview.example.after') . '</p>',
        
        'wbr' => '<p>' . __admin('preview.example.longWord') . '<wbr>international<wbr>ization</p>',
        
        // ===== INTERACTIVE TAGS =====
        'a' => '<a href="#" class="tag-ex-link">🔗 ' . __admin('preview.example.clickHere') . '</a>',
        
        'button' => '<button class="tag-ex-button">' . __admin('preview.example.clickMe') . '</button>',
        
        'details' => '<details class="tag-ex-details"><summary class="tag-ex-summary">' . __admin('preview.example.moreInfo') . '</summary><p class="tag-ex-details-content">' . __admin('preview.example.hiddenContent') . '</p></details>',
        
        'summary' => '<details open class="tag-ex-details"><summary class="tag-ex-summary">▼ ' . __admin('preview.example.clickToToggle') . '</summary><p class="tag-ex-details-content">' . __admin('preview.example.expandedContent') . '</p></details>',
        
        'dialog' => '<div class="tag-ex-dialog-backdrop"><div class="tag-ex-dialog"><strong class="tag-ex-dialog-title">' . __admin('preview.example.dialogTitle') . '</strong><p class="tag-ex-dialog-content">' . __admin('preview.example.dialogContent') . '</p><button class="tag-ex-dialog-btn">OK</button></div></div>',
        
        // ===== LIST TAGS =====
        'ul' => '<ul class="tag-ex-ul"><li>' . __admin('preview.example.item1') . '</li><li>' . __admin('preview.example.item2') . '</li><li>' . __admin('preview.example.item3') . '</li></ul>',
        
        'ol' => '<ol class="tag-ex-ol"><li>' . __admin('preview.example.step1') . '</li><li>' . __admin('preview.example.step2') . '</li><li>' . __admin('preview.example.step3') . '</li></ol>',
        
        'li' => '<ul class="tag-ex-ul"><li class="tag-ex-li-highlight">' . __admin('preview.example.listItem') . '</li></ul>',
        
        'dl' => '<dl class="tag-ex-dl"><dt class="tag-ex-dt">HTML</dt><dd class="tag-ex-dd">' . __admin('preview.example.htmlDef') . '</dd><dt class="tag-ex-dt">CSS</dt><dd class="tag-ex-dd">' . __admin('preview.example.cssDef') . '</dd></dl>',
        
        'dt' => '<dl class="tag-ex-dl"><dt class="tag-ex-dt tag-ex-li-highlight">' . __admin('preview.example.definedTerm') . '</dt><dd class="tag-ex-dd">' . __admin('preview.example.definition') . '</dd></dl>',
        
        'dd' => '<dl class="tag-ex-dl"><dt class="tag-ex-dt">' . __admin('preview.example.term') . '</dt><dd class="tag-ex-dd-styled">' . __admin('preview.example.definitionText') . '</dd></dl>',
        
        'menu' => '<menu class="tag-ex-menu"><li><button class="tag-ex-menu-btn">📋 ' . __admin('preview.example.copy') . '</button></li><li><button class="tag-ex-menu-btn">📁 ' . __admin('preview.example.paste') . '</button></li></menu>',
        
        // ===== MEDIA TAGS =====
        'img' => '<div style="text-align:center;"><div class="tag-ex-img-placeholder"><span class="tag-ex-img-icon">🖼️</span><span class="tag-ex-img-label">image.jpg</span></div></div>',
        
        'picture' => '<div class="tag-ex-figure"><div class="tag-ex-img-placeholder"><span class="tag-ex-img-icon">🖼️</span></div><p class="tag-ex-figcaption">' . __admin('preview.example.responsiveImage') . '</p></div>',
        
        'video' => '<video class="tag-ex-video" controls><source src="/admin/assets/media/sample.mp4" type="video/mp4">' . __admin('preview.example.videoNotSupported') . '</video>',
        
        'audio' => '<audio class="tag-ex-audio" controls><source src="/admin/assets/media/sample.wav" type="audio/wav">' . __admin('preview.example.audioNotSupported') . '</audio>',
        
        'source' => '<video class="tag-ex-video" controls><source src="/admin/assets/media/sample.mp4" type="video/mp4"></video>',
        
        'track' => '<video class="tag-ex-video" controls><source src="/admin/assets/media/sample.mp4" type="video/mp4"><track kind="subtitles" srclang="en" label="English"></video>',
        
        'iframe' => '<div class="tag-ex-iframe"><span class="tag-ex-iframe-icon">🌐</span><p class="tag-ex-iframe-label">' . __admin('preview.example.embeddedContent') . '</p></div>',
        
        'embed' => '<div class="tag-ex-embed"><span class="tag-ex-iframe-icon">📦</span><p class="tag-ex-iframe-label">' . __admin('preview.example.externalContent') . '</p></div>',
        
        'object' => '<div class="tag-ex-embed"><span class="tag-ex-iframe-icon">📄</span><p class="tag-ex-iframe-label">' . __admin('preview.example.embeddedObject') . '</p></div>',
        
        'canvas' => '<div class="tag-ex-canvas">🎨 Canvas (200×100)</div>',
        
        'svg' => '<div class="tag-ex-svg-demo"><svg width="80" height="60" viewBox="0 0 80 60"><rect x="5" y="5" width="70" height="50" fill="#e3f2fd" stroke="#1976d2" stroke-width="2" rx="5"/><circle cx="25" cy="30" r="10" fill="#1976d2"/><polygon points="50,15 65,45 35,45" fill="#4caf50"/></svg></div>',
        
        'map' => '<div class="tag-ex-map-container"><div class="tag-ex-map-placeholder"><span class="tag-ex-img-icon">🖼️</span><br><span class="tag-ex-img-label">' . __admin('preview.example.clickableAreas') . '</span></div><div class="tag-ex-map-area tag-ex-map-area-1"></div><div class="tag-ex-map-area tag-ex-map-area-2"></div></div>',
        
        'area' => '<div class="tag-ex-norender-msg"><code class="tag-ex-code">&lt;area shape="rect" coords="0,0,50,50"&gt;</code><p>' . __admin('preview.example.clickableZone') . '</p></div>',
        
        // ===== FORM TAGS =====
        'form' => '<form class="tag-ex-form"><label class="tag-ex-form-label">' . __admin('preview.example.email') . '<input type="email" class="tag-ex-form-input"></label><button class="tag-ex-form-submit">' . __admin('preview.example.submit') . '</button></form>',
        
        'input' => '<div style="display:flex;flex-direction:column;gap:8px;"><input type="text" placeholder="' . __admin('preview.example.textPlaceholder') . '" class="tag-ex-form-input"><input type="password" placeholder="••••••••" class="tag-ex-form-input"><label class="tag-ex-checkbox-label"><input type="checkbox" class="tag-ex-checkbox"> ' . __admin('preview.example.checkbox') . '</label></div>',
        
        'textarea' => '<textarea class="tag-ex-textarea" placeholder="' . __admin('preview.example.textareaPlaceholder') . '"></textarea>',
        
        'select' => '<select class="tag-ex-select"><option>' . __admin('preview.example.option1') . '</option><option>' . __admin('preview.example.option2') . '</option><option>' . __admin('preview.example.option3') . '</option></select>',
        
        'option' => '<select class="tag-ex-select"><option selected>' . __admin('preview.example.selectedOption') . '</option><option>' . __admin('preview.example.otherOption') . '</option></select>',
        
        'optgroup' => '<select class="tag-ex-select"><optgroup label="' . __admin('preview.example.fruits') . '"><option>🍎 ' . __admin('preview.example.apple') . '</option><option>🍌 ' . __admin('preview.example.banana') . '</option></optgroup><optgroup label="' . __admin('preview.example.vegetables') . '"><option>🥕 ' . __admin('preview.example.carrot') . '</option></optgroup></select>',
        
        'label' => '<label class="tag-ex-checkbox-label"><input type="checkbox" class="tag-ex-checkbox"><span>' . __admin('preview.example.labelText') . '</span></label>',
        
        'fieldset' => '<fieldset class="tag-ex-fieldset"><legend class="tag-ex-legend">' . __admin('preview.example.personalInfo') . '</legend><input type="text" placeholder="' . __admin('preview.example.name') . '" class="tag-ex-form-input"></fieldset>',
        
        'legend' => '<fieldset class="tag-ex-fieldset"><legend class="tag-ex-legend">📋 ' . __admin('preview.example.legendTitle') . '</legend><span>' . __admin('preview.example.fieldsetContent') . '</span></fieldset>',
        
        'datalist' => '<div><input list="ex-browsers" placeholder="' . __admin('preview.example.chooseBrowser') . '" class="tag-ex-form-input"><datalist id="ex-browsers"><option value="Chrome"><option value="Firefox"><option value="Safari"></datalist></div>',
        
        'output' => '<div class="tag-ex-output"><span class="tag-ex-output-label">' . __admin('preview.example.result') . '</span><br><output class="tag-ex-output-value">42</output></div>',
        
        'progress' => '<div><label class="tag-ex-progress-label">' . __admin('preview.example.loading') . ' 70%</label><progress value="70" max="100" class="tag-ex-progress"></progress></div>',
        
        'meter' => '<div><label class="tag-ex-progress-label">' . __admin('preview.example.diskUsage') . '</label><meter value="0.6" min="0" max="1" low="0.3" high="0.7" optimum="0.5" class="tag-ex-meter"></meter><span class="tag-ex-meter-label">60% ' . __admin('preview.example.used') . '</span></div>',
        
        // ===== TABLE TAGS =====
        'table' => '<table class="tag-ex-table"><tr class="tag-ex-table-header"><th class="tag-ex-th">' . __admin('preview.example.name') . '</th><th class="tag-ex-th">' . __admin('preview.example.age') . '</th></tr><tr><td class="tag-ex-td">Alice</td><td class="tag-ex-td">28</td></tr><tr class="tag-ex-tr-alt"><td class="tag-ex-td">Bob</td><td class="tag-ex-td">34</td></tr></table>',
        
        'thead' => '<table class="tag-ex-table"><thead class="tag-ex-thead"><tr><th class="tag-ex-th">' . __admin('preview.example.header1') . '</th><th class="tag-ex-th">' . __admin('preview.example.header2') . '</th></tr></thead><tbody><tr><td class="tag-ex-td">' . __admin('preview.example.data') . '</td><td class="tag-ex-td">' . __admin('preview.example.data') . '</td></tr></tbody></table>',
        
        'tbody' => '<table class="tag-ex-table"><thead><tr class="tag-ex-tr-alt"><th class="tag-ex-th">#</th><th class="tag-ex-th">' . __admin('preview.example.value') . '</th></tr></thead><tbody class="tag-ex-tbody-highlight"><tr><td class="tag-ex-td">1</td><td class="tag-ex-td">A</td></tr><tr><td class="tag-ex-td">2</td><td class="tag-ex-td">B</td></tr></tbody></table>',
        
        'tfoot' => '<table class="tag-ex-table"><tbody><tr><td class="tag-ex-td">' . __admin('preview.example.item1') . '</td><td class="tag-ex-td">$10</td></tr><tr><td class="tag-ex-td">' . __admin('preview.example.item2') . '</td><td class="tag-ex-td">$15</td></tr></tbody><tfoot class="tag-ex-tfoot"><tr><td class="tag-ex-td">' . __admin('preview.example.total') . '</td><td class="tag-ex-td">$25</td></tr></tfoot></table>',
        
        'tr' => '<table class="tag-ex-table"><tr class="tag-ex-tr-alt"><td class="tag-ex-td">' . __admin('preview.example.cell1') . '</td><td class="tag-ex-td">' . __admin('preview.example.cell2') . '</td><td class="tag-ex-td">' . __admin('preview.example.cell3') . '</td></tr></table>',
        
        'th' => '<table class="tag-ex-table"><tr class="tag-ex-table-header"><th class="tag-ex-th">' . __admin('preview.example.headerCell') . '</th><th class="tag-ex-th">' . __admin('preview.example.headerCell') . '</th></tr></table>',
        
        'td' => '<table class="tag-ex-table"><tr><td class="tag-ex-td tag-ex-li-highlight">' . __admin('preview.example.dataCell') . '</td></tr></table>',
        
        'caption' => '<table class="tag-ex-table"><caption class="tag-ex-caption">📊 ' . __admin('preview.example.tableCaption') . '</caption><tr><td class="tag-ex-td">' . __admin('preview.example.data') . '</td><td class="tag-ex-td">' . __admin('preview.example.data') . '</td></tr></table>',
        
        'colgroup' => '<table class="tag-ex-table"><colgroup><col class="tag-ex-colgroup-1"><col class="tag-ex-colgroup-2"><col class="tag-ex-colgroup-3"></colgroup><tr><td class="tag-ex-td">' . __admin('preview.example.col1') . '</td><td class="tag-ex-td">' . __admin('preview.example.col2') . '</td><td class="tag-ex-td">' . __admin('preview.example.col3') . '</td></tr></table>',
        
        'col' => '<table class="tag-ex-table"><colgroup><col class="tag-ex-colgroup-1" style="width:30%;"><col class="tag-ex-colgroup-3"></colgroup><tr><td class="tag-ex-td">30%</td><td class="tag-ex-td">' . __admin('preview.example.auto') . '</td></tr></table>',
        
        // ===== OTHER TAGS =====
        'data' => '<p>' . __admin('preview.example.product') . ': <data value="978-3-16-148410-0" class="tag-ex-data">ISBN 978-3-16-148410-0</data></p>',
        
        'bdi' => '<p>' . __admin('preview.example.user') . ': <bdi class="tag-ex-bdi">مستخدم</bdi> (' . __admin('preview.example.rtlText') . ')</p>',
        
        'bdo' => '<p><bdo dir="rtl" class="tag-ex-bdo">Hello World</bdo><br><span class="tag-ex-small">(' . __admin('preview.example.reversed') . ')</span></p>',
        
        'ruby' => '<p class="tag-ex-ruby-text"><ruby>漢<rp>(</rp><rt class="tag-ex-rt">hàn</rt><rp>)</rp></ruby><ruby>字<rp>(</rp><rt class="tag-ex-rt">zì</rt><rp>)</rp></ruby> <span class="tag-ex-small">(' . __admin('preview.example.chineseChars') . ')</span></p>',
        
        'rt' => '<p class="tag-ex-ruby-text"><ruby>東<rt class="tag-ex-rt">トウ</rt></ruby><ruby>京<rt class="tag-ex-rt">キョウ</rt></ruby></p>',
        
        'rp' => '<p class="tag-ex-ruby-text"><ruby>日本<rp class="tag-ex-rp">(</rp><rt>にほん</rt><rp class="tag-ex-rp">)</rp></ruby></p>',
        
        'slot' => '<div class="tag-ex-slot"><code class="tag-ex-slot-code">&lt;slot name="header"&gt;&lt;/slot&gt;</code><p class="tag-ex-slot-desc">' . __admin('preview.example.webComponent') . '</p></div>',
        
        'portal' => '<div class="tag-ex-portal"><span class="tag-ex-portal-icon">🚪</span><p class="tag-ex-portal-desc">' . __admin('preview.example.portalDesc') . '</p></div>',
        
        // ===== NON-RENDERABLE TAGS =====
        'head' => false,
        'meta' => false,
        'title' => false,
        'link' => false,
        'style' => false,
        'script' => false,
        'noscript' => false,
        'base' => false,
        'html' => false,
        'body' => false,
        'template' => false,
        'param' => false,
    ];
}

/**
 * Get example HTML for a specific tag
 * 
 * @param string $tag The tag name
 * @return string|false HTML string, or false if non-renderable
 */
function getTagExample(string $tag): string|false {
    $examples = getTagExamples();
    return $examples[$tag] ?? false;
}

/**
 * Check if a tag has a visual example
 * 
 * @param string $tag The tag name
 * @return bool
 */
function hasTagExample(string $tag): bool {
    $example = getTagExample($tag);
    return $example !== false;
}
