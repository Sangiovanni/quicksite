# QuickSite Create Multi-Page Website Specification

You are creating a complete multi-page website from a blank QuickSite project.

## ⚠️ IMPORTANT: Output Rules

1. **OUTPUT JSON ONLY** - No explanations, no questions, no commentary
2. **DO NOT ASK** for missing information - make reasonable assumptions based on context
3. **IMAGINE** what the user wants if their request is vague (colors, layout, content)
4. Your response must be a valid JSON array and nothing else

## Output Format
```json
[
  { "command": "commandName", "params": { "key": "value" } }
]
```

---

## CRITICAL: Command Order Rules

**⚠️ MANDATORY ORDER:**

{{#if param.multilingual === true}}
1. **Languages FIRST** (required for multilingual):
   - `addLang` → add each additional language BEFORE enabling multilingual
   - Language codes MUST be valid **ISO 639-1** two-letter codes (e.g. `fr`, `es`, `ja`, `de`). If the user provided an incorrect code (e.g. `jp` instead of `ja`), use the correct ISO 639-1 code.
   - `setMultilingual` → enable multi-language mode AFTER having 2+ languages
   - Languages: {{param.languages}}
   - **⚠️ The default language already exists, so you MUST produce exactly one `addLang` command for EACH language in the list above EXCEPT the default. Count them — every language must be present. Do NOT skip any.**

   **⚠️ CRITICAL: setMultilingual EXACT syntax:**
   ```json
   { "command": "setMultilingual", "params": { "enabled": true } }
   ```
   **DO NOT use:** `{}`, `{"defaultLang": "en"}`, or any other format. ONLY `{"enabled": true}`.

2. **Routes**:
   - `addRoute` → creates new pages (home exists by default)
   - Note: "home" route exists by default (accessible as "/" or "/home")

3. **Structures** (in this order):
   - `editStructure` type="menu" → navigation (shared across all pages)
   - `editStructure` type="footer" → footer (shared across all pages) **⚠️ MUST include `{ "component": "lang-switch", "data": {} }`**
   - `editStructure` type="page", name="$routeName" → each page content

4. **Translations**:
   - `setTranslationKeys` → MUST cover ALL textKeys used in structures
   - **Repeat for each language:** {{param.languages}}
   - Note: `addRoute` auto-creates `page.titles.$routeName` - set this too!

   **⚠️ REQUIRED for EACH language - include 404 page translations:**
   ```json
   "404": {
     "title": "Page Not Found",
     "message": "The page you are looking for does not exist.",
     "backHome": "Back to Home"
   }
   ```

5. **Styles LAST** (order matters!):
   - `editStyles` → MUST come BEFORE setRootVariables
   - `setRootVariables` → AFTER editStyles (optional)
{{else}}
1. **Routes**:
   - `addRoute` → creates new pages (home exists by default)
   - Note: "home" route exists by default (accessible as "/" or "/home")

2. **Structures** (in this order):
   - `editStructure` type="menu" → navigation (shared across all pages)
   - `editStructure` type="footer" → footer (shared across all pages)
   - `editStructure` type="page", name="$routeName" → each page content

3. **Translations**:
   - `setTranslationKeys` → MUST cover ALL textKeys used in structures
   - Note: `addRoute` auto-creates `page.titles.$routeName` - set this too!

4. **Styles LAST** (order matters!):
   - `editStyles` → MUST come BEFORE setRootVariables
   - `setRootVariables` → AFTER editStyles (optional)
{{/if}}

---

## Link Format Rules

**⚠️ CRITICAL: Links must follow these formats:**

### Internal Links (Routes)
Use simple paths matching route names. **NO query strings (?), parameters (&=), or fragments (#).**

| Route Name | Link href |
|------------|----------|
| home | `/` or `/home` |
| about | `/about` |
| services | `/services` |
| docs/api | `/docs/api` |

```json
{ "tag": "a", "params": { "href": "/about" }, "children": [{ "textKey": "menu.about" }] }
```

### External Links
For links outside the site, use full URLs with `target="_blank"`:

```json
{ "tag": "a", "params": { "href": "https://github.com/user/repo", "target": "_blank" }, "children": [{ "textKey": "footer.github" }] }
```

**❌ NEVER use:** `href="?page=about"`, `href="index.php?route=x"`, `href="#section"`
**✅ ALWAYS use:** `href="/route-name"` for internal, `href="https://..."` with `target="_blank"` for external

---

## Core Concept: Structure → Translation

ALL text must use `textKey` references:

```json
{ "tag": "h1", "children": [{ "textKey": "about.title" }] }
```

**Naming convention:**
- `menu.*` → navigation items (menu.home, menu.about, menu.services, menu.contact)
- `footer.*` → footer content
- `$routeName.*` → page content (home.title, about.intro, services.list.item1)
- `page.titles.$routeName` → page <title> tag

**⚠️ NEVER hardcode text. Always use textKey.**

---

## Structure Types

| type | Description | Required params |
|------|-------------|-----------------|
| `menu` | Navigation (all pages) | type + structure |
| `footer` | Footer (all pages) | type + structure |
| `page` | Page content | type + **name** + structure |
| `component` | Reusable template | type + **name** + structure |

---

## Components (Reusable Templates)

Components let you define reusable structures with **variables in double brackets `{{var}}`**.

### Creating a Component
```json
{
  "command": "editStructure",
  "params": {
    "type": "component",
    "name": "service-card",
    "structure": {
      "tag": "div",
      "params": { "class": "service-card" },
      "children": [
        { "tag": "span", "params": { "class": "icon" }, "children": [{ "textKey": "{{$icon}}" }] },
        { "tag": "h3", "children": [{ "textKey": "{{$title}}" }] },
        { "tag": "p", "children": [{ "textKey": "{{$desc}}" }] }
      ]
    }
  }
}
```

### Using a Component
When using a component, the `data` object maps variable names to their values:
- For **translation keys**: pass the key string (e.g., `"services.item1.title"`)
- For **raw/untranslated text**: prefix with `__RAW__` (e.g., `"__RAW__🔧"`)

```json
{
  "component": "service-card",
  "data": {
    "icon": "__RAW__🔧",
    "title": "services.item1.title",
    "desc": "services.item1.desc"
  }
}
```

**Important:** Variables can also be used in `params` for attributes like `href`, `class`, etc.:
```json
{
  "tag": "a",
  "params": { "href": "{{$href}}", "target": "{{$target}}" },
  "children": [{ "textKey": "{{$label}}" }]
}
```

---

{{#if param.multilingual === true}}
## Language Switcher (REQUIRED for Multilingual)

**⚠️ MANDATORY:** You MUST include the `lang-switch` component in your **footer structure**.

The component will be auto-generated with this structure (for styling reference):
```json
{
  "tag": "div",
  "params": { "class": "lang-switch" },
  "children": [
    { "tag": "a", "params": { "href": "{{__current_page;lang=en}}", "class": "lang-btn", "data-lang": "en" }, "children": [{ "textKey": "__RAW__English" }] },
    { "tag": "a", "params": { "href": "{{__current_page;lang=fr}}", "class": "lang-btn", "data-lang": "fr" }, "children": [{ "textKey": "__RAW__Français" }] }
  ]
}
```

**To include it in your footer, add this child element:**
```json
{ "component": "lang-switch", "data": {} }
```

**Example footer with lang-switch:**
```json
{
  "tag": "footer",
  "children": [
    { "tag": "p", "children": [{ "textKey": "footer.copyright" }] },
    { "component": "lang-switch", "data": {} }
  ]
}
```

**DO NOT recreate this component.** Just include it using the component reference above.

---
{{/if}}

## Available Commands

{{#each commands}}
{{formatCommand @key this}}

---
{{/each}}

---

## Multi-Page Website Example

```json
[
  {
    "command": "addRoute",
    "params": { "name": "about" }
  },
  {
    "command": "addRoute",
    "params": { "name": "services" }
  },
  {
    "command": "addRoute",
    "params": { "name": "contact" }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "menu",
      "structure": [
        {
          "tag": "nav",
          "params": { "class": "main-nav" },
          "children": [
            { "tag": "a", "params": { "href": "/", "class": "logo" }, "children": [{ "textKey": "menu.logo" }] },
            {
              "tag": "div",
              "params": { "class": "nav-links" },
              "children": [
                { "tag": "a", "params": { "href": "/" }, "children": [{ "textKey": "menu.home" }] },
                { "tag": "a", "params": { "href": "/about" }, "children": [{ "textKey": "menu.about" }] },
                { "tag": "a", "params": { "href": "/services" }, "children": [{ "textKey": "menu.services" }] },
                { "tag": "a", "params": { "href": "/contact" }, "children": [{ "textKey": "menu.contact" }] }
              ]
            }
          ]
        }
      ]
    }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "footer",
      "structure": [
        {
          "tag": "footer",
          "children": [
            { "tag": "p", "children": [{ "textKey": "footer.copyright" }] },
            {
              "tag": "div",
              "params": { "class": "footer-links" },
              "children": [
                { "tag": "a", "params": { "href": "/about" }, "children": [{ "textKey": "footer.about" }] },
                { "tag": "a", "params": { "href": "/contact" }, "children": [{ "textKey": "footer.contact" }] }
              ]
            },
            { "component": "lang-switch", "data": {} }
          ]
        }
      ]
    }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "page",
      "name": "home",
      "structure": [
        {
          "tag": "section",
          "params": { "class": "hero" },
          "children": [
            { "tag": "h1", "children": [{ "textKey": "home.hero.title" }] },
            { "tag": "p", "children": [{ "textKey": "home.hero.subtitle" }] },
            { "tag": "a", "params": { "href": "/contact", "class": "btn" }, "children": [{ "textKey": "home.hero.cta" }] }
          ]
        }
      ]
    }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "page",
      "name": "about",
      "structure": [
        {
          "tag": "section",
          "children": [
            { "tag": "h1", "children": [{ "textKey": "about.title" }] },
            { "tag": "p", "children": [{ "textKey": "about.content" }] }
          ]
        }
      ]
    }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "page",
      "name": "services",
      "structure": [
        {
          "tag": "section",
          "children": [
            { "tag": "h1", "children": [{ "textKey": "services.title" }] },
            { "tag": "div", "params": { "class": "services-grid" }, "children": [
              { "tag": "div", "children": [{ "tag": "h3", "children": [{ "textKey": "services.item1.title" }] }, { "tag": "p", "children": [{ "textKey": "services.item1.desc" }] }] },
              { "tag": "div", "children": [{ "tag": "h3", "children": [{ "textKey": "services.item2.title" }] }, { "tag": "p", "children": [{ "textKey": "services.item2.desc" }] }] }
            ]}
          ]
        }
      ]
    }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "page",
      "name": "contact",
      "structure": [
        {
          "tag": "section",
          "children": [
            { "tag": "h1", "children": [{ "textKey": "contact.title" }] },
            { "tag": "p", "children": [{ "textKey": "contact.intro" }] }
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
        "404": { "title": "Page Not Found", "message": "The page you are looking for does not exist.", "backHome": "Back to Home" },
        "page": { "titles": { "home": "Home | My Website", "about": "About | My Website", "services": "Services | My Website", "contact": "Contact | My Website" } },
        "menu": { "logo": "MyBrand", "home": "Home", "about": "About", "services": "Services", "contact": "Contact" },
        "home": { "hero": { "title": "Welcome to Our Company", "subtitle": "We deliver excellence", "cta": "Get in Touch" } },
        "about": { "title": "About Us", "content": "Our story..." },
        "services": { "title": "Our Services", "item1": { "title": "Service 1", "desc": "Description..." }, "item2": { "title": "Service 2", "desc": "Description..." } },
        "contact": { "title": "Contact Us", "intro": "We'd love to hear from you" },
        "footer": { "copyright": "© 2025 MyBrand", "about": "About", "contact": "Contact" }
      }
    }
  },
  {
    "command": "editStyles",
    "params": {
      "css": ":root { --color-primary: #3b82f6; --color-text: #1f2937; --color-bg: #ffffff; }\nbody { font-family: system-ui, sans-serif; color: var(--color-text); background: var(--color-bg); margin: 0; }\n.main-nav { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; }\n.nav-links { display: flex; gap: 1.5rem; }\n.nav-links a { text-decoration: none; color: var(--color-text); }\nsection { padding: 4rem 2rem; max-width: 1200px; margin: 0 auto; }\n.hero { text-align: center; padding: 6rem 2rem; }\n.btn { display: inline-block; padding: 0.75rem 1.5rem; background: var(--color-primary); color: white; text-decoration: none; border-radius: 6px; }\n.services-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem; }\nfooter { text-align: center; padding: 2rem; background: #f3f4f6; }"
    }
  }
]
```

---

{{#if param.multilingual === true}}
## ✅ FINAL CHECKLIST (Verify before outputting)

- [ ] Footer contains `{ "component": "lang-switch", "data": {} }` ← **REQUIRED!**
- [ ] All links use `/route-name` format (no `?`, `&`, `=`)
- [ ] External links have `target="_blank"`
- [ ] All text uses `textKey`, never hardcoded strings
- [ ] Translations provided for ALL languages: {{param.languages}}
{{/if}}

---

{{#if param.includeAssets === true}}
## Available Assets

The user has provided assets for this project. **Use these real assets where appropriate** — don't invent placeholder filenames.

Path format: `/assets/{category}/{filename}` (leading slash required).

### Starred Assets:
{{json assetList}}

**Instructions:**
- Use these assets in `img`, `video`, `audio` tag `params.src` values
- Match assets to sections by their description (e.g., a "logo" asset belongs in the header/nav, a "hero" image belongs in the hero section)
- Do NOT force every asset into the page — only use what fits naturally
- If no asset matches a section, use a solid background color or gradient instead of inventing a filename
- For fonts: reference font files in editStyles @font-face declarations
{{#if param.includeFavicon === true}}

### Favicon
If an image asset looks suitable as a favicon (square/small, or has "favicon", "icon", or "logo" in its name), add an `editFavicon` command at the end of your response:
{{json helpEditFavicon}}
{{/if}}
{{/if}}

---

## 🚀 NOW GENERATE

Create a complete multi-page website based on the user's requirements.
- If information is missing, **assume reasonable defaults** (don't ask)
- **Output ONLY the JSON array** - no explanations before or after
- Ensure ALL textKeys have matching translations
