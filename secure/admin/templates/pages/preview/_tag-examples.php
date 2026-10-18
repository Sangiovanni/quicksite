<?php
/**
 * Tag Examples Mapping
 * 
 * Maps HTML tag names to visual example HTML strings.
 * Examples are designed to fit within a ~200px preview area.
 * Uses CSS classes from tag-examples.css
 * Translatable text comes from __admin(), escaped where it enters the markup.
 * Each example is a whole fragment (a table part inside its <table>, a list item inside its list):
 * _tag-selector.php writes it into a <template> as it is, and the editor shows it as written.
 * 
 * Values:
 * - string: HTML example to render
 * - false: Tag cannot be visually rendered (shows "No visual preview")
 */

function getTagExamples(): array {
    $t = fn(string $key): string => adminEscape(__admin($key));
    return [
        // ===== LAYOUT TAGS =====
        'div' => '<div class="tag-ex-container">' . $t('preview.example.container') . '</div>',
        
        'span' => '<p>' . $t('preview.example.textBefore') . ' <span class="tag-ex-span-highlight">' . $t('preview.example.highlighted') . '</span> ' . $t('preview.example.textAfter') . '</p>',
        
        'section' => '<section class="tag-ex-section"><strong class="tag-ex-section-title">' . $t('preview.example.sectionTitle') . '</strong><p class="tag-ex-section-content">' . $t('preview.example.sectionContent') . '</p></section>',
        
        'article' => '<article class="tag-ex-article"><h4 class="tag-ex-article-title">' . $t('preview.example.articleTitle') . '</h4><p class="tag-ex-article-content">' . $t('preview.example.articleContent') . '</p></article>',
        
        'aside' => '<aside class="tag-ex-aside"><strong class="tag-ex-aside-title">💡 ' . $t('preview.example.tip') . '</strong><br>' . $t('preview.example.asideContent') . '</aside>',
        
        'header' => '<header class="tag-ex-header">🏠 ' . $t('preview.example.siteTitle') . '</header>',
        
        'footer' => '<footer class="tag-ex-footer">© 2026 ' . $t('preview.example.company') . '</footer>',
        
        'main' => '<main class="tag-ex-main"><strong class="tag-ex-main-title">' . $t('preview.example.mainContent') . '</strong><p class="tag-ex-main-content">' . $t('preview.example.mainDesc') . '</p></main>',
        
        'nav' => '<nav class="tag-ex-nav"><a href="#" class="tag-ex-nav-link">' . $t('preview.example.home') . '</a><a href="#" class="tag-ex-nav-link">' . $t('preview.example.about') . '</a><a href="#" class="tag-ex-nav-link">' . $t('preview.example.contact') . '</a></nav>',
        
        'figure' => '<figure class="tag-ex-figure"><div class="tag-ex-figure-placeholder">🖼️</div><figcaption class="tag-ex-figcaption">' . $t('preview.example.imageCaption') . '</figcaption></figure>',
        
        'figcaption' => '<figure class="tag-ex-figure"><div class="tag-ex-figure-placeholder">🖼️</div><figcaption class="tag-ex-figcaption">' . $t('preview.example.figcaptionText') . '</figcaption></figure>',
        
        // ===== TEXT TAGS =====
        'p' => '<p class="tag-ex-paragraph">' . $t('preview.example.paragraph') . '</p>',
        
        'h1' => '<h1 class="tag-ex-heading-1">' . $t('preview.example.heading1') . '</h1>',
        'h2' => '<h2 class="tag-ex-heading-2">' . $t('preview.example.heading2') . '</h2>',
        'h3' => '<h3 class="tag-ex-heading-3">' . $t('preview.example.heading3') . '</h3>',
        'h4' => '<h4 class="tag-ex-heading-4">' . $t('preview.example.heading4') . '</h4>',
        'h5' => '<h5 class="tag-ex-heading-5">' . $t('preview.example.heading5') . '</h5>',
        'h6' => '<h6 class="tag-ex-heading-6">' . $t('preview.example.heading6') . '</h6>',
        
        'strong' => '<p>' . $t('preview.example.textNormal') . ' <strong class="tag-ex-strong">' . $t('preview.example.textImportant') . '</strong></p>',
        
        'em' => '<p>' . $t('preview.example.textNormal') . ' <em class="tag-ex-em">' . $t('preview.example.textEmphasis') . '</em></p>',
        
        'b' => '<p>' . $t('preview.example.textNormal') . ' <b class="tag-ex-bold">' . $t('preview.example.textBold') . '</b></p>',
        
        'i' => '<p>' . $t('preview.example.textNormal') . ' <i class="tag-ex-italic">' . $t('preview.example.textItalic') . '</i></p>',
        
        'u' => '<p>' . $t('preview.example.textNormal') . ' <u class="tag-ex-underline">' . $t('preview.example.textUnderline') . '</u></p>',
        
        's' => '<p><s class="tag-ex-price-old">' . $t('preview.example.priceOld') . '</s> <strong class="tag-ex-price-new">' . $t('preview.example.priceNew') . '</strong></p>',
        
        'del' => '<p><del class="tag-ex-deleted">' . $t('preview.example.textDeleted') . '</del></p>',
        
        'ins' => '<p><ins class="tag-ex-inserted">' . $t('preview.example.textInserted') . '</ins></p>',
        
        'mark' => '<p>' . $t('preview.example.textSearch') . ' <mark class="tag-ex-mark">' . $t('preview.example.textHighlighted') . '</mark></p>',
        
        'small' => '<p>' . $t('preview.example.textNormal') . ' <small class="tag-ex-small">' . $t('preview.example.textSmall') . '</small></p>',
        
        'sub' => '<p>H<sub class="tag-ex-sub">2</sub>O (' . $t('preview.example.water') . ')</p>',
        
        'sup' => '<p>E = mc<sup class="tag-ex-sup">2</sup></p>',
        
        'abbr' => '<p><abbr title="' . $t('preview.example.htmlFull') . '" class="tag-ex-abbr">HTML</abbr> ' . $t('preview.example.abbrDesc') . '</p>',
        
        'code' => '<p>' . $t('preview.example.useFunction') . ' <code class="tag-ex-code">console.log()</code></p>',
        
        'pre' => '<pre class="tag-ex-pre">function hello() {
    console.log("Hello!");
}</pre>',
        
        'kbd' => '<p>' . $t('preview.example.pressCopy') . ' <kbd class="tag-ex-kbd">Ctrl</kbd> + <kbd class="tag-ex-kbd">C</kbd></p>',
        
        'samp' => '<p>' . $t('preview.example.output') . ' <samp class="tag-ex-samp">' . $t('preview.example.sampleOutput') . '</samp></p>',
        
        'var' => '<p>' . $t('preview.example.formula') . ': <var class="tag-ex-var">x</var> + <var class="tag-ex-var">y</var> = <var class="tag-ex-var">z</var></p>',
        
        'blockquote' => '<blockquote class="tag-ex-blockquote">"' . $t('preview.example.quote') . '"<footer class="tag-ex-blockquote-footer">— ' . $t('preview.example.quoteAuthor') . '</footer></blockquote>',
        
        'q' => '<p>' . $t('preview.example.heSaid') . ' <q class="tag-ex-q">' . $t('preview.example.shortQuote') . '</q></p>',
        
        'cite' => '<p>' . $t('preview.example.source') . ' <cite class="tag-ex-cite">' . $t('preview.example.bookTitle') . '</cite></p>',
        
        'dfn' => '<p><dfn class="tag-ex-dfn">' . $t('preview.example.term') . '</dfn>: ' . $t('preview.example.termDef') . '</p>',
        
        'address' => '<address class="tag-ex-address">📍 123 ' . $t('preview.example.street') . '<br>📧 contact@example.com</address>',
        
        'time' => '<p>' . $t('preview.example.published') . ' <time datetime="2026-02-13" class="tag-ex-time">13 ' . $t('preview.example.february') . ' 2026</time></p>',
        
        'br' => '<p>' . $t('preview.example.line1') . '<br>' . $t('preview.example.line2') . '<br>' . $t('preview.example.line3') . '</p>',
        
        'hr' => '<p>' . $t('preview.example.before') . '</p><hr class="tag-ex-hr"><p>' . $t('preview.example.after') . '</p>',
        
        'wbr' => '<p>' . $t('preview.example.longWord') . '<wbr>international<wbr>ization</p>',
        
        // ===== INTERACTIVE TAGS =====
        'a' => '<a href="#" class="tag-ex-link">🔗 ' . $t('preview.example.clickHere') . '</a>',
        
        'button' => '<button class="tag-ex-button">' . $t('preview.example.clickMe') . '</button>',
        
        'details' => '<details class="tag-ex-details"><summary class="tag-ex-summary">' . $t('preview.example.moreInfo') . '</summary><p class="tag-ex-details-content">' . $t('preview.example.hiddenContent') . '</p></details>',
        
        'summary' => '<details open class="tag-ex-details"><summary class="tag-ex-summary">▼ ' . $t('preview.example.clickToToggle') . '</summary><p class="tag-ex-details-content">' . $t('preview.example.expandedContent') . '</p></details>',
        
        // ===== LIST TAGS =====
        'ul' => '<ul class="tag-ex-ul"><li>' . $t('preview.example.item1') . '</li><li>' . $t('preview.example.item2') . '</li><li>' . $t('preview.example.item3') . '</li></ul>',
        
        'ol' => '<ol class="tag-ex-ol"><li>' . $t('preview.example.step1') . '</li><li>' . $t('preview.example.step2') . '</li><li>' . $t('preview.example.step3') . '</li></ol>',
        
        'li' => '<ul class="tag-ex-ul"><li class="tag-ex-li-highlight">' . $t('preview.example.listItem') . '</li></ul>',
        
        'dl' => '<dl class="tag-ex-dl"><dt class="tag-ex-dt">HTML</dt><dd class="tag-ex-dd">' . $t('preview.example.htmlDef') . '</dd><dt class="tag-ex-dt">CSS</dt><dd class="tag-ex-dd">' . $t('preview.example.cssDef') . '</dd></dl>',
        
        'dt' => '<dl class="tag-ex-dl"><dt class="tag-ex-dt tag-ex-li-highlight">' . $t('preview.example.definedTerm') . '</dt><dd class="tag-ex-dd">' . $t('preview.example.definition') . '</dd></dl>',
        
        'dd' => '<dl class="tag-ex-dl"><dt class="tag-ex-dt">' . $t('preview.example.term') . '</dt><dd class="tag-ex-dd-styled">' . $t('preview.example.definitionText') . '</dd></dl>',
        
        'menu' => '<menu class="tag-ex-menu"><li><button class="tag-ex-menu-btn">📋 ' . $t('preview.example.copy') . '</button></li><li><button class="tag-ex-menu-btn">📁 ' . $t('preview.example.paste') . '</button></li></menu>',
        
        // ===== MEDIA TAGS =====
        'img' => '<div style="text-align:center;"><div class="tag-ex-img-placeholder"><span class="tag-ex-img-icon">🖼️</span><span class="tag-ex-img-label">image.jpg</span></div></div>',
        
        'picture' => '<div class="tag-ex-figure"><div class="tag-ex-img-placeholder"><span class="tag-ex-img-icon">🖼️</span></div><p class="tag-ex-figcaption">' . $t('preview.example.responsiveImage') . '</p></div>',
        
        'video' => '<video class="tag-ex-video" controls><source src="/admin/assets/media/sample.mp4" type="video/mp4">' . $t('preview.example.videoNotSupported') . '</video>',
        
        'audio' => '<audio class="tag-ex-audio" controls><source src="/admin/assets/media/sample.wav" type="audio/wav">' . $t('preview.example.audioNotSupported') . '</audio>',
        
        'source' => '<video class="tag-ex-video" controls><source src="/admin/assets/media/sample.mp4" type="video/mp4"></video>',
        
        'track' => '<video class="tag-ex-video" controls><source src="/admin/assets/media/sample.mp4" type="video/mp4"><track kind="subtitles" srclang="en" label="' . $t('preview.example.trackLabel') . '"></video>',
        
        'iframe' => '<div class="tag-ex-iframe"><span class="tag-ex-iframe-icon">🌐</span><p class="tag-ex-iframe-label">' . $t('preview.example.embeddedContent') . '</p></div>',
        
        'embed' => '<div class="tag-ex-embed"><span class="tag-ex-iframe-icon">📦</span><p class="tag-ex-iframe-label">' . $t('preview.example.externalContent') . '</p></div>',
        
        'object' => '<div class="tag-ex-embed"><span class="tag-ex-iframe-icon">📄</span><p class="tag-ex-iframe-label">' . $t('preview.example.embeddedObject') . '</p></div>',
        
        'canvas' => '<div class="tag-ex-canvas">🎨 ' . $t('preview.example.canvasLabel') . '</div>',
        
        'svg' => '<div class="tag-ex-svg-demo"><svg width="80" height="60" viewBox="0 0 80 60"><rect x="5" y="5" width="70" height="50" fill="#e3f2fd" stroke="#1976d2" stroke-width="2" rx="5"/><circle cx="25" cy="30" r="10" fill="#1976d2"/><polygon points="50,15 65,45 35,45" fill="#4caf50"/></svg></div>',
        
        'map' => '<div class="tag-ex-map-container"><div class="tag-ex-map-placeholder"><span class="tag-ex-img-icon">🖼️</span><br><span class="tag-ex-img-label">' . $t('preview.example.clickableAreas') . '</span></div><div class="tag-ex-map-area tag-ex-map-area-1"></div><div class="tag-ex-map-area tag-ex-map-area-2"></div></div>',
        
        'area' => '<div class="tag-ex-norender-msg"><code class="tag-ex-code">&lt;area shape="rect" coords="0,0,50,50"&gt;</code><p>' . $t('preview.example.clickableZone') . '</p></div>',
        
        // ===== FORM TAGS =====
        'form' => '<form class="tag-ex-form"><label class="tag-ex-form-label">' . $t('preview.example.email') . '<input type="email" class="tag-ex-form-input"></label><button class="tag-ex-form-submit">' . $t('preview.example.submit') . '</button></form>',
        
        'input' => '<div style="display:flex;flex-direction:column;gap:8px;"><input type="text" placeholder="' . $t('preview.example.textPlaceholder') . '" class="tag-ex-form-input"><input type="password" placeholder="••••••••" class="tag-ex-form-input"><label class="tag-ex-checkbox-label"><input type="checkbox" class="tag-ex-checkbox"> ' . $t('preview.example.checkbox') . '</label></div>',
        
        'textarea' => '<textarea class="tag-ex-textarea" placeholder="' . $t('preview.example.textareaPlaceholder') . '"></textarea>',
        
        'select' => '<select class="tag-ex-select"><option>' . $t('preview.example.option1') . '</option><option>' . $t('preview.example.option2') . '</option><option>' . $t('preview.example.option3') . '</option></select>',
        
        'option' => '<select class="tag-ex-select"><option selected>' . $t('preview.example.selectedOption') . '</option><option>' . $t('preview.example.otherOption') . '</option></select>',
        
        'optgroup' => '<select class="tag-ex-select"><optgroup label="' . $t('preview.example.fruits') . '"><option>🍎 ' . $t('preview.example.apple') . '</option><option>🍌 ' . $t('preview.example.banana') . '</option></optgroup><optgroup label="' . $t('preview.example.vegetables') . '"><option>🥕 ' . $t('preview.example.carrot') . '</option></optgroup></select>',
        
        'label' => '<label class="tag-ex-checkbox-label"><input type="checkbox" class="tag-ex-checkbox"><span>' . $t('preview.example.labelText') . '</span></label>',
        
        'fieldset' => '<fieldset class="tag-ex-fieldset"><legend class="tag-ex-legend">' . $t('preview.example.personalInfo') . '</legend><input type="text" placeholder="' . $t('preview.example.name') . '" class="tag-ex-form-input"></fieldset>',
        
        'legend' => '<fieldset class="tag-ex-fieldset"><legend class="tag-ex-legend">📋 ' . $t('preview.example.legendTitle') . '</legend><span>' . $t('preview.example.fieldsetContent') . '</span></fieldset>',
        
        'datalist' => '<div><input list="ex-browsers" placeholder="' . $t('preview.example.chooseBrowser') . '" class="tag-ex-form-input"><datalist id="ex-browsers"><option value="Chrome"><option value="Firefox"><option value="Safari"></datalist></div>',
        
        'output' => '<div class="tag-ex-output"><span class="tag-ex-output-label">' . $t('preview.example.result') . '</span><br><output class="tag-ex-output-value">42</output></div>',
        
        'progress' => '<div><label class="tag-ex-progress-label">' . $t('preview.example.loading') . ' 70%</label><progress value="70" max="100" class="tag-ex-progress"></progress></div>',
        
        'meter' => '<div><label class="tag-ex-progress-label">' . $t('preview.example.diskUsage') . '</label><meter value="0.6" min="0" max="1" low="0.3" high="0.7" optimum="0.5" class="tag-ex-meter"></meter><span class="tag-ex-meter-label">60% ' . $t('preview.example.used') . '</span></div>',
        
        // ===== TABLE TAGS =====
        'table' => '<table class="tag-ex-table"><tr class="tag-ex-table-header"><th class="tag-ex-th">' . $t('preview.example.name') . '</th><th class="tag-ex-th">' . $t('preview.example.age') . '</th></tr><tr><td class="tag-ex-td">Alice</td><td class="tag-ex-td">28</td></tr><tr class="tag-ex-tr-alt"><td class="tag-ex-td">Bob</td><td class="tag-ex-td">34</td></tr></table>',
        
        'thead' => '<table class="tag-ex-table"><thead class="tag-ex-thead"><tr><th class="tag-ex-th">' . $t('preview.example.header1') . '</th><th class="tag-ex-th">' . $t('preview.example.header2') . '</th></tr></thead><tbody><tr><td class="tag-ex-td">' . $t('preview.example.data') . '</td><td class="tag-ex-td">' . $t('preview.example.data') . '</td></tr></tbody></table>',
        
        'tbody' => '<table class="tag-ex-table"><thead><tr class="tag-ex-tr-alt"><th class="tag-ex-th">#</th><th class="tag-ex-th">' . $t('preview.example.value') . '</th></tr></thead><tbody class="tag-ex-tbody-highlight"><tr><td class="tag-ex-td">1</td><td class="tag-ex-td">A</td></tr><tr><td class="tag-ex-td">2</td><td class="tag-ex-td">B</td></tr></tbody></table>',
        
        'tfoot' => '<table class="tag-ex-table"><tbody><tr><td class="tag-ex-td">' . $t('preview.example.item1') . '</td><td class="tag-ex-td">$10</td></tr><tr><td class="tag-ex-td">' . $t('preview.example.item2') . '</td><td class="tag-ex-td">$15</td></tr></tbody><tfoot class="tag-ex-tfoot"><tr><td class="tag-ex-td">' . $t('preview.example.total') . '</td><td class="tag-ex-td">$25</td></tr></tfoot></table>',
        
        'tr' => '<table class="tag-ex-table"><tr class="tag-ex-tr-alt"><td class="tag-ex-td">' . $t('preview.example.cell1') . '</td><td class="tag-ex-td">' . $t('preview.example.cell2') . '</td><td class="tag-ex-td">' . $t('preview.example.cell3') . '</td></tr></table>',
        
        'th' => '<table class="tag-ex-table"><tr class="tag-ex-table-header"><th class="tag-ex-th">' . $t('preview.example.headerCell') . '</th><th class="tag-ex-th">' . $t('preview.example.headerCell') . '</th></tr></table>',
        
        'td' => '<table class="tag-ex-table"><tr><td class="tag-ex-td tag-ex-li-highlight">' . $t('preview.example.dataCell') . '</td></tr></table>',
        
        'caption' => '<table class="tag-ex-table"><caption class="tag-ex-caption">📊 ' . $t('preview.example.tableCaption') . '</caption><tr><td class="tag-ex-td">' . $t('preview.example.data') . '</td><td class="tag-ex-td">' . $t('preview.example.data') . '</td></tr></table>',
        
        'colgroup' => '<table class="tag-ex-table"><colgroup><col class="tag-ex-colgroup-1"><col class="tag-ex-colgroup-2"><col class="tag-ex-colgroup-3"></colgroup><tr><td class="tag-ex-td">' . $t('preview.example.col1') . '</td><td class="tag-ex-td">' . $t('preview.example.col2') . '</td><td class="tag-ex-td">' . $t('preview.example.col3') . '</td></tr></table>',
        
        'col' => '<table class="tag-ex-table"><colgroup><col class="tag-ex-colgroup-1" style="width:30%;"><col class="tag-ex-colgroup-3"></colgroup><tr><td class="tag-ex-td">30%</td><td class="tag-ex-td">' . $t('preview.example.auto') . '</td></tr></table>',
        
        // ===== OTHER TAGS =====
        'data' => '<p>' . $t('preview.example.product') . ': <data value="978-3-16-148410-0" class="tag-ex-data">ISBN 978-3-16-148410-0</data></p>',
        
        'bdi' => '<p>' . $t('preview.example.user') . ': <bdi class="tag-ex-bdi">مستخدم</bdi> (' . $t('preview.example.rtlText') . ')</p>',
        
        'bdo' => '<p><bdo dir="rtl" class="tag-ex-bdo">' . $t('preview.example.helloWorld') . '</bdo><br><span class="tag-ex-small">(' . $t('preview.example.reversed') . ')</span></p>',
        
        'ruby' => '<p class="tag-ex-ruby-text"><ruby>漢<rp>(</rp><rt class="tag-ex-rt">hàn</rt><rp>)</rp></ruby><ruby>字<rp>(</rp><rt class="tag-ex-rt">zì</rt><rp>)</rp></ruby> <span class="tag-ex-small">(' . $t('preview.example.chineseChars') . ')</span></p>',
        
        'rt' => '<p class="tag-ex-ruby-text"><ruby>東<rt class="tag-ex-rt">トウ</rt></ruby><ruby>京<rt class="tag-ex-rt">キョウ</rt></ruby></p>',
        
        'rp' => '<p class="tag-ex-ruby-text"><ruby>日本<rp class="tag-ex-rp">(</rp><rt>にほん</rt><rp class="tag-ex-rp">)</rp></ruby></p>',
        
        'slot' => '<div class="tag-ex-slot"><code class="tag-ex-slot-code">&lt;slot name="header"&gt;&lt;/slot&gt;</code><p class="tag-ex-slot-desc">' . $t('preview.example.webComponent') . '</p></div>',
        
        'portal' => '<div class="tag-ex-portal"><span class="tag-ex-portal-icon">🚪</span><p class="tag-ex-portal-desc">' . $t('preview.example.portalDesc') . '</p></div>',
        
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
