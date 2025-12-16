<?php
/**
 * Admin AI Integration Page
 * 
 * Generates AI-ready specifications for QuickSite API.
 * Split into Setup, Build, and Deploy specs for focused AI assistance.
 * 
 * @version 1.6.0
 */
?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('ai.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('ai.subtitle') ?></p>
</div>

<!-- Introduction -->
<div class="admin-card">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <path d="M12 16v-4"/>
                <path d="M12 8h.01"/>
            </svg>
            <?= __admin('ai.howItWorks') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-ai-intro">
            <p>Use AI assistants (ChatGPT, Claude, Gemini...) to generate QuickSite commands automatically.</p>
            
            <div class="admin-ai-steps">
                <div class="admin-ai-step">
                    <span class="admin-ai-step__number">1</span>
                    <div class="admin-ai-step__content">
                        <strong>Choose a Spec</strong>
                        <p>Select the specification matching your task: Setup, Build, or Deploy.</p>
                    </div>
                </div>
                <div class="admin-ai-step">
                    <span class="admin-ai-step__number">2</span>
                    <div class="admin-ai-step__content">
                        <strong>Copy & Paste</strong>
                        <p>Copy the spec and paste it at the start of a new AI conversation.</p>
                    </div>
                </div>
                <div class="admin-ai-step">
                    <span class="admin-ai-step__number">3</span>
                    <div class="admin-ai-step__content">
                        <strong>Describe Your Goal</strong>
                        <p>Tell the AI what you want to build in plain language.</p>
                    </div>
                </div>
                <div class="admin-ai-step">
                    <span class="admin-ai-step__number">4</span>
                    <div class="admin-ai-step__content">
                        <strong>Import & Execute</strong>
                        <p>Paste the AI's JSON output in the <a href="<?= $router->url('batch') ?>">Batch page</a>.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Spec Selector -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            <?= __admin('ai.specification') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <!-- Spec Tabs -->
        <div class="admin-ai-tabs">
            <button type="button" class="admin-ai-tab" data-spec="setup" onclick="selectSpec('setup')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                    <path d="M2 17l10 5 10-5"/>
                    <path d="M2 12l10 5 10-5"/>
                </svg>
                <span class="admin-ai-tab__title">Setup</span>
                <span class="admin-ai-tab__desc">Initial configuration</span>
            </button>
            <button type="button" class="admin-ai-tab admin-ai-tab--active" data-spec="build" onclick="selectSpec('build')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <line x1="3" y1="9" x2="21" y2="9"/>
                    <line x1="9" y1="21" x2="9" y2="9"/>
                </svg>
                <span class="admin-ai-tab__title">Build Visual</span>
                <span class="admin-ai-tab__desc">Pages, content, styles</span>
            </button>
            <button type="button" class="admin-ai-tab" data-spec="deploy" onclick="selectSpec('deploy')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <path d="M22 2L11 13"/>
                    <path d="M22 2l-7 20-4-9-9-4 20-7z"/>
                </svg>
                <span class="admin-ai-tab__title">Deploy</span>
                <span class="admin-ai-tab__desc">Build & publish</span>
            </button>
        </div>
        
        <!-- Spec Description -->
        <div class="admin-ai-spec-desc" id="spec-description">
            <div class="admin-ai-spec-desc__content" id="spec-desc-setup" style="display: none;">
                <strong>🏗️ Setup Specification</strong>
                <p>Use this when first installing QuickSite or configuring the project structure. Includes folder management, authentication setup, and multilingual configuration.</p>
            </div>
            <div class="admin-ai-spec-desc__content" id="spec-desc-build">
                <strong>🎨 Build Visual Specification</strong>
                <p>The main spec for building your website. Create pages, design structures, add translations, manage assets, and customize styles. Includes detailed JSON format examples.</p>
            </div>
            <div class="admin-ai-spec-desc__content" id="spec-desc-deploy" style="display: none;">
                <strong>🚀 Deploy Specification</strong>
                <p>Use this when ready to publish. Generate static builds, manage versions, deploy to production, and configure URL aliases.</p>
            </div>
        </div>
        
        <!-- Copy Button -->
        <div class="admin-ai-spec-actions">
            <button type="button" class="admin-btn admin-btn--primary admin-btn--large" onclick="copyCurrentSpec()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                </svg>
                Copy <span id="copy-spec-name">Build Visual</span> Specification
            </button>
            <button type="button" class="admin-btn admin-btn--secondary" onclick="toggleSpecPreview()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                Preview
            </button>
        </div>
        
        <!-- Spec Preview (collapsed by default) -->
        <div id="spec-preview" class="admin-ai-spec-preview" style="display: none;">
            <div class="admin-ai-spec-stats" id="ai-spec-stats"></div>
            <textarea id="ai-spec-content" class="admin-textarea admin-textarea--code" rows="15" readonly></textarea>
        </div>
    </div>
</div>

<!-- Quick Prompts -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
            </svg>
            <?= __admin('ai.quickPrompts') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <p class="admin-hint" style="margin-bottom: var(--space-md);">
            After pasting the spec, try these prompts (click to copy):
        </p>
        
        <!-- Prompts by category -->
        <div id="prompts-setup" class="admin-ai-prompts" style="display: none;">
            <div class="admin-ai-prompt" onclick="copyPrompt(this)">
                <div class="admin-ai-prompt__title">🌍 Enable Multilingual</div>
                <div class="admin-ai-prompt__text">Enable multilingual mode and add French and Spanish languages to my site.</div>
            </div>
            <div class="admin-ai-prompt" onclick="copyPrompt(this)">
                <div class="admin-ai-prompt__title">🔐 Generate Token</div>
                <div class="admin-ai-prompt__text">Generate a new API token with admin permissions that expires in 30 days.</div>
            </div>
        </div>
        
        <div id="prompts-build" class="admin-ai-prompts">
            <div class="admin-ai-prompt" onclick="copyPrompt(this)">
                <div class="admin-ai-prompt__title">🏢 Business Website</div>
                <div class="admin-ai-prompt__text">Create a professional business website with home, about us, services, team, and contact pages. Include a hero section, feature cards, and call-to-action buttons. Add English translations.</div>
            </div>
            <div class="admin-ai-prompt" onclick="copyPrompt(this)">
                <div class="admin-ai-prompt__title">📸 Portfolio Site</div>
                <div class="admin-ai-prompt__text">Build a minimal portfolio website for a photographer with home, gallery, about, and contact pages. The gallery should have a grid layout for images.</div>
            </div>
            <div class="admin-ai-prompt" onclick="copyPrompt(this)">
                <div class="admin-ai-prompt__title">🎨 Update Colors</div>
                <div class="admin-ai-prompt__text">Update the CSS variables to use a modern blue theme: primary color #3B82F6, secondary #1E40AF, accent #60A5FA, and dark background #0F172A.</div>
            </div>
            <div class="admin-ai-prompt" onclick="copyPrompt(this)">
                <div class="admin-ai-prompt__title">🌐 Add Translations</div>
                <div class="admin-ai-prompt__text">Add French translations for my navigation: Home = Accueil, About = À propos, Services = Services, Contact = Contact, and common buttons: Learn More = En savoir plus, Get Started = Commencer.</div>
            </div>
            <div class="admin-ai-prompt" onclick="copyPrompt(this)">
                <div class="admin-ai-prompt__title">📄 Landing Page</div>
                <div class="admin-ai-prompt__text">Create a single-page landing site with: hero section with title and CTA, features section with 3 cards, testimonials, pricing table, and footer with social links.</div>
            </div>
        </div>
        
        <div id="prompts-deploy" class="admin-ai-prompts" style="display: none;">
            <div class="admin-ai-prompt" onclick="copyPrompt(this)">
                <div class="admin-ai-prompt__title">🚀 Production Build</div>
                <div class="admin-ai-prompt__text">Create a production build of my site with minification enabled and all languages included.</div>
            </div>
            <div class="admin-ai-prompt" onclick="copyPrompt(this)">
                <div class="admin-ai-prompt__title">🧹 Clean Old Builds</div>
                <div class="admin-ai-prompt__text">List all builds, then clean up builds older than 7 days, keeping only the 3 most recent ones.</div>
            </div>
        </div>
    </div>
</div>

<style>
.admin-ai-intro p {
    margin-bottom: var(--space-md);
    color: var(--admin-text-muted);
    font-size: var(--font-size-sm);
}

.admin-ai-steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-md);
}

.admin-ai-step {
    display: flex;
    gap: var(--space-sm);
    padding: var(--space-md);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-md);
}

.admin-ai-step__number {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--admin-accent);
    color: #000;
    border-radius: 50%;
    font-weight: var(--font-weight-bold);
    font-size: var(--font-size-sm);
    flex-shrink: 0;
}

.admin-ai-step__content {
    flex: 1;
}

.admin-ai-step__content strong {
    display: block;
    margin-bottom: var(--space-xs);
    font-size: var(--font-size-sm);
}

.admin-ai-step__content p {
    margin: 0;
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
}

/* Tabs */
.admin-ai-tabs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
}

.admin-ai-tab {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-md);
    background: var(--admin-bg-secondary);
    border: 2px solid var(--admin-border);
    border-radius: var(--radius-lg);
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
}

.admin-ai-tab:hover {
    border-color: var(--admin-accent);
    background: var(--admin-surface);
}

.admin-ai-tab--active {
    border-color: var(--admin-accent);
    background: var(--admin-accent-muted);
}

.admin-ai-tab__title {
    font-weight: var(--font-weight-bold);
    font-size: var(--font-size-base);
}

.admin-ai-tab__desc {
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
}

.admin-ai-tab--active .admin-ai-tab__desc {
    color: var(--admin-text);
}

/* Spec Description */
.admin-ai-spec-desc {
    padding: var(--space-md);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-lg);
}

.admin-ai-spec-desc__content strong {
    display: block;
    margin-bottom: var(--space-xs);
}

.admin-ai-spec-desc__content p {
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
}

/* Actions */
.admin-ai-spec-actions {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
}

.admin-btn--large {
    padding: var(--space-md) var(--space-xl);
    font-size: var(--font-size-base);
}

/* Preview */
.admin-ai-spec-preview {
    margin-top: var(--space-lg);
    padding-top: var(--space-lg);
    border-top: 1px solid var(--admin-border);
}

.admin-ai-spec-stats {
    display: flex;
    gap: var(--space-lg);
    margin-bottom: var(--space-md);
    padding: var(--space-sm) var(--space-md);
    background: var(--admin-bg-secondary);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
}

.admin-ai-spec-stat {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}

.admin-ai-spec-stat__label {
    color: var(--admin-text-muted);
}

.admin-ai-spec-stat__value {
    font-weight: var(--font-weight-medium);
    color: var(--admin-accent);
}

/* Prompts */
.admin-ai-prompts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: var(--space-md);
}

.admin-ai-prompt {
    padding: var(--space-md);
    background: var(--admin-bg-secondary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.2s;
}

.admin-ai-prompt:hover {
    border-color: var(--admin-accent);
    transform: translateY(-2px);
}

.admin-ai-prompt__title {
    font-weight: var(--font-weight-medium);
    margin-bottom: var(--space-xs);
}

.admin-ai-prompt__text {
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
    line-height: 1.5;
}

.admin-textarea--code {
    font-family: var(--font-mono);
    font-size: var(--font-size-xs);
    line-height: 1.4;
    resize: vertical;
    background: var(--admin-bg);
}
</style>

<script>
// Current selected spec
let currentSpec = 'build';
let specsCache = {};
let commandsData = null;

document.addEventListener('DOMContentLoaded', async function() {
    // Load commands data
    try {
        const result = await QuickSiteAdmin.apiRequest('help', 'GET');
        if (result.ok && result.data.data) {
            commandsData = result.data.data;
            // Pre-generate build spec (most common)
            generateSpec('build');
        }
    } catch (e) {
        console.error('Failed to load commands:', e);
    }
});

function selectSpec(spec) {
    currentSpec = spec;
    
    // Update tabs
    document.querySelectorAll('.admin-ai-tab').forEach(tab => {
        tab.classList.toggle('admin-ai-tab--active', tab.dataset.spec === spec);
    });
    
    // Update description
    document.querySelectorAll('.admin-ai-spec-desc__content').forEach(desc => {
        desc.style.display = 'none';
    });
    document.getElementById(`spec-desc-${spec}`).style.display = 'block';
    
    // Update prompts
    document.querySelectorAll('.admin-ai-prompts').forEach(p => {
        p.style.display = 'none';
    });
    document.getElementById(`prompts-${spec}`).style.display = 'grid';
    
    // Update button text
    const names = { setup: 'Setup', build: 'Build Visual', deploy: 'Deploy' };
    document.getElementById('copy-spec-name').textContent = names[spec];
    
    // Generate spec if preview is open
    if (document.getElementById('spec-preview').style.display !== 'none') {
        generateSpec(spec);
    }
}

function toggleSpecPreview() {
    const preview = document.getElementById('spec-preview');
    const isHidden = preview.style.display === 'none';
    
    if (isHidden) {
        preview.style.display = 'block';
        generateSpec(currentSpec);
    } else {
        preview.style.display = 'none';
    }
}

function generateSpec(specType) {
    if (!commandsData) return;
    
    // Check cache
    if (specsCache[specType]) {
        displaySpec(specsCache[specType]);
        return;
    }
    
    let spec = '';
    
    switch (specType) {
        case 'setup':
            spec = generateSetupSpec();
            break;
        case 'build':
            spec = generateBuildSpec();
            break;
        case 'deploy':
            spec = generateDeploySpec();
            break;
    }
    
    specsCache[specType] = spec;
    displaySpec(spec);
}

function displaySpec(spec) {
    const content = document.getElementById('ai-spec-content');
    const stats = document.getElementById('ai-spec-stats');
    
    content.value = spec;
    
    const charCount = spec.length;
    const wordCount = spec.split(/\s+/).length;
    
    stats.innerHTML = `
        <div class="admin-ai-spec-stat">
            <span class="admin-ai-spec-stat__label">Characters:</span>
            <span class="admin-ai-spec-stat__value">${charCount.toLocaleString()}</span>
        </div>
        <div class="admin-ai-spec-stat">
            <span class="admin-ai-spec-stat__label">Words:</span>
            <span class="admin-ai-spec-stat__value">${wordCount.toLocaleString()}</span>
        </div>
        <div class="admin-ai-spec-stat">
            <span class="admin-ai-spec-stat__label">Est. tokens:</span>
            <span class="admin-ai-spec-stat__value">~${Math.ceil(wordCount * 1.3).toLocaleString()}</span>
        </div>
    `;
}

function generateSetupSpec() {
    const commands = commandsData.commands || {};
    const setupCommands = ['setPublicSpace', 'renameSecureFolder', 'renamePublicFolder', 'generateToken', 'listTokens', 'revokeToken', 'setMultilingual', 'addLang', 'deleteLang', 'setDefaultLang', 'getLangList'];
    
    return `# QuickSite Setup Specification

You are helping configure a QuickSite installation. Generate JSON command sequences for initial setup tasks.

## API Information
- **Base URL**: \`{YOUR_SITE_URL}/management\`
- **Auth**: Include token via \`X-Auth-Token\` header or \`?token=\` query param
- **Method**: Use the HTTP method specified for each command

## Output Format
Respond with a JSON array of commands:
\`\`\`json
[
  { "command": "commandName", "params": { "key": "value" } }
]
\`\`\`

## Available Setup Commands

${setupCommands.map(cmd => commands[cmd] ? formatCommandBrief(cmd, commands[cmd]) : '').filter(Boolean).join('\n')}

## Examples

### Enable Multilingual Mode
\`\`\`json
[
  { "command": "setMultilingual", "params": { "enabled": true } },
  { "command": "addLang", "params": { "code": "fr", "name": "Français" } },
  { "command": "addLang", "params": { "code": "es", "name": "Español" } }
]
\`\`\`

### Generate API Token
\`\`\`json
[
  { "command": "generateToken", "params": { "name": "dev-token", "expires": "30d" } }
]
\`\`\`

Help the user with their setup task.`;
}

function generateBuildSpec() {
    const commands = commandsData.commands || {};
    const buildCommands = {
        'Routes & Pages': ['addRoute', 'deleteRoute', 'getRoutes', 'getStructure', 'editStructure'],
        'Translations': ['setTranslationKeys', 'deleteTranslationKeys', 'getTranslation', 'getTranslations', 'validateTranslations'],
        'Assets': ['uploadAsset', 'deleteAsset', 'listAssets'],
        'Styles': ['getRootVariables', 'setRootVariables', 'getStyles', 'editStyles', 'setStyleRule', 'deleteStyleRule'],
        'CSS Animations': ['getKeyframes', 'setKeyframes', 'deleteKeyframes'],
        'Customization': ['editFavicon', 'editTitle']
    };
    
    let commandsSection = '';
    Object.entries(buildCommands).forEach(([category, cmds]) => {
        commandsSection += `### ${category}\n`;
        cmds.forEach(cmd => {
            if (commands[cmd]) {
                commandsSection += formatCommandBrief(cmd, commands[cmd]);
            }
        });
        commandsSection += '\n';
    });
    
    return `# QuickSite Build Visual Specification

You are helping build website content for QuickSite. Generate JSON command sequences to create pages, structures, translations, and styles.

## API Information
- **Base URL**: \`{YOUR_SITE_URL}/management\`
- **Auth**: Include token via \`X-Auth-Token\` header or \`?token=\` query param

## Output Format
Always respond with a JSON array:
\`\`\`json
[
  { "command": "commandName", "params": { "key": "value" } }
]
\`\`\`

## Available Commands

${commandsSection}

---

## JSON Structure Format (IMPORTANT)

Page structures use nested JSON objects representing HTML elements:

\`\`\`json
{
  "tag": "section",
  "params": { "class": "hero", "id": "main-hero" },
  "children": [
    {
      "tag": "h1",
      "children": [{ "textKey": "home.hero.title" }]
    },
    {
      "tag": "p",
      "params": { "class": "subtitle" },
      "children": [{ "textKey": "home.hero.subtitle" }]
    },
    {
      "tag": "a",
      "params": { "href": "/contact", "class": "btn btn-primary" },
      "children": [{ "textKey": "common.cta" }]
    }
  ]
}
\`\`\`

### Structure Properties
| Property | Description | Example |
|----------|-------------|---------|
| \`tag\` | HTML element name | \`div\`, \`section\`, \`h1\`, \`p\`, \`a\`, \`img\`, \`ul\`, \`li\` |
| \`params\` | HTML attributes | \`{ "class": "hero", "id": "main", "href": "/about" }\` |
| \`children\` | Child elements or text | Array of objects |
| \`textKey\` | Translation key reference | \`"home.title"\` → looks up in translations |
| \`text\` | Static text (not translated) | \`"©2024"\` |

### Common Patterns

**Image:**
\`\`\`json
{ "tag": "img", "params": { "src": "/assets/images/logo.png", "alt": "Logo", "class": "logo" } }
\`\`\`

**Link:**
\`\`\`json
{ "tag": "a", "params": { "href": "/about", "class": "nav-link" }, "children": [{ "textKey": "nav.about" }] }
\`\`\`

**List:**
\`\`\`json
{
  "tag": "ul",
  "params": { "class": "feature-list" },
  "children": [
    { "tag": "li", "children": [{ "textKey": "features.item1" }] },
    { "tag": "li", "children": [{ "textKey": "features.item2" }] }
  ]
}
\`\`\`

**Grid of cards:**
\`\`\`json
{
  "tag": "div",
  "params": { "class": "card-grid" },
  "children": [
    {
      "tag": "div",
      "params": { "class": "card" },
      "children": [
        { "tag": "h3", "children": [{ "textKey": "services.card1.title" }] },
        { "tag": "p", "children": [{ "textKey": "services.card1.desc" }] }
      ]
    }
  ]
}
\`\`\`

---

## Translation Format

Translations use nested JSON objects with dot-notation keys:

\`\`\`json
{
  "home": {
    "hero": {
      "title": "Welcome to Our Site",
      "subtitle": "Building the future together"
    }
  },
  "nav": {
    "home": "Home",
    "about": "About",
    "contact": "Contact"
  },
  "common": {
    "cta": "Get Started",
    "learnMore": "Learn More"
  }
}
\`\`\`

Reference in structures: \`"textKey": "home.hero.title"\`

---

## CSS Variables Format

Set CSS custom properties for theming:

\`\`\`json
{
  "command": "setRootVariables",
  "params": {
    "variables": {
      "--color-primary": "#3B82F6",
      "--color-secondary": "#1E40AF",
      "--color-accent": "#60A5FA",
      "--color-bg": "#ffffff",
      "--color-text": "#1f2937",
      "--font-family": "'Inter', sans-serif",
      "--font-size-base": "16px",
      "--spacing-unit": "8px",
      "--border-radius": "8px"
    }
  }
}
\`\`\`

---

## Complete Example: Create About Page

\`\`\`json
[
  {
    "command": "addRoute",
    "params": { "name": "about" }
  },
  {
    "command": "editStructure",
    "params": {
      "route": "about",
      "structure": [
        {
          "tag": "section",
          "params": { "class": "page-header" },
          "children": [
            { "tag": "h1", "children": [{ "textKey": "about.title" }] },
            { "tag": "p", "params": { "class": "lead" }, "children": [{ "textKey": "about.intro" }] }
          ]
        },
        {
          "tag": "section",
          "params": { "class": "content" },
          "children": [
            { "tag": "h2", "children": [{ "textKey": "about.mission.title" }] },
            { "tag": "p", "children": [{ "textKey": "about.mission.text" }] }
          ]
        }
      ]
    }
  },
  {
    "command": "setTranslationKeys",
    "params": {
      "language": "en",
      "translations": {
        "about": {
          "title": "About Us",
          "intro": "Learn more about our company and mission.",
          "mission": {
            "title": "Our Mission",
            "text": "We strive to deliver excellence in everything we do."
          }
        }
      }
    }
  }
]
\`\`\`

Now help the user build their website content.`;
}

function generateDeploySpec() {
    const commands = commandsData.commands || {};
    const deployCommands = ['build', 'listBuilds', 'getBuild', 'downloadBuild', 'deployBuild', 'deleteBuild', 'cleanBuilds', 'createAlias', 'deleteAlias', 'listAliases'];
    
    return `# QuickSite Deploy Specification

You are helping deploy a QuickSite website. Generate JSON command sequences for building and publishing.

## API Information
- **Base URL**: \`{YOUR_SITE_URL}/management\`
- **Auth**: Include token via \`X-Auth-Token\` header or \`?token=\` query param

## Output Format
\`\`\`json
[
  { "command": "commandName", "params": { "key": "value" } }
]
\`\`\`

## Available Deploy Commands

${deployCommands.map(cmd => commands[cmd] ? formatCommandBrief(cmd, commands[cmd]) : '').filter(Boolean).join('\n')}

## Examples

### Create Production Build
\`\`\`json
[
  { "command": "build", "params": { "minify": true, "name": "production-v1" } }
]
\`\`\`

### List and Download Latest Build
\`\`\`json
[
  { "command": "listBuilds", "params": {} }
]
\`\`\`
Then use the build ID to download.

### Clean Old Builds
\`\`\`json
[
  { "command": "cleanBuilds", "params": { "keep": 3 } }
]
\`\`\`

### Create URL Alias
\`\`\`json
[
  { "command": "createAlias", "params": { "alias": "app", "target": "application" } }
]
\`\`\`
This makes \`/app\` redirect to \`/application\`.

Help the user with their deployment task.`;
}

function formatCommandBrief(name, cmd) {
    let text = `**${name}** (\`${cmd.method || 'GET'}\`): ${cmd.description || 'No description'}\n`;
    
    if (cmd.parameters && Object.keys(cmd.parameters).length > 0) {
        const params = Object.entries(cmd.parameters)
            .map(([pName, pData]) => `\`${pName}\`${pData.required ? '*' : ''}`)
            .join(', ');
        text += `  Params: ${params}\n`;
    }
    
    return text;
}

async function copyCurrentSpec() {
    // Generate if not cached
    if (!specsCache[currentSpec]) {
        generateSpec(currentSpec);
    }
    
    const spec = specsCache[currentSpec];
    
    if (!spec) {
        QuickSiteAdmin.showToast('Specification not ready', 'warning');
        return;
    }
    
    try {
        await navigator.clipboard.writeText(spec);
        QuickSiteAdmin.showToast('Specification copied to clipboard!', 'success');
    } catch (e) {
        // Fallback
        const textarea = document.getElementById('ai-spec-content');
        textarea.value = spec;
        textarea.select();
        document.execCommand('copy');
        QuickSiteAdmin.showToast('Specification copied!', 'success');
    }
}

function copyPrompt(element) {
    const text = element.querySelector('.admin-ai-prompt__text').textContent;
    
    navigator.clipboard.writeText(text).then(() => {
        QuickSiteAdmin.showToast('Prompt copied!', 'success');
    }).catch(() => {
        QuickSiteAdmin.showToast('Could not copy prompt', 'error');
    });
}
</script>
