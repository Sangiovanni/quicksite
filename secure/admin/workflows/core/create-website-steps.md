# QuickSite Create Website Specification (in phases)

{{#if param.onePage === true}}
You are creating a one-page website (a landing page) from a blank QuickSite project.
{{else}}
You are creating a multi-page website from a blank QuickSite project.
{{/if}}

**This answer is the first of several phases.** Here you write the site's structure, its texts in the default language, and a base stylesheet of its layout. Later phases translate the texts into the site's other languages and design its look (colours, fonts, decoration) from the stylesheet you write: do not write them here.

{{> output-json-only}}

---

## CRITICAL: Command Order Rules

**⚠️ MANDATORY ORDER:**

{{#if param.multilingual === true}}
**Note: Languages are already configured.** The project has multilingual mode enabled with these languages: {{data.langData.languages}} (default: {{data.langData.default_language}}). **Write the texts of {{data.langData.default_language}} only:** the other languages are translated from them in later phases.
{{else}}
**Note: The language is already configured:** {{data.langData.default_language}}.
{{/if}}

{{#if param.onePage === true}}
1. **One page only**:
   - Build the home page only: do NOT call `addRoute`
   - The menu links to the home page's sections with `#section-id` anchors; give each section that `id`
{{else}}
1. **Routes**:
   - `addRoute` → creates new pages (home exists by default)
   - Note: "home" route exists by default (accessible as "/" or "/home")
{{/if}}

2. **Structures** (in this order):
   - `editStructure` type="menu" → navigation (shared across all pages)
   - `editStructure` type="footer" → footer (shared across all pages){{#if param.multilingual === true}} **⚠️ MUST include `{ "component": "lang-switch", "data": {} }`**{{/if}}
{{#if param.onePage === true}}
   - `editStructure` type="page", name="home" → the page, its sections in order
{{else}}
   - `editStructure` type="page", name="$routeName" → each page content
{{/if}}

3. **Translations** — the default language only:
   - `setTranslationKeys` with `"language": "{{data.langData.default_language}}"` → MUST cover ALL textKeys used in structures
{{#if param.onePage === true}}
   - Set `page.titles.home` too
{{else}}
   - Note: `addRoute` auto-creates `page.titles.$routeName` - set this too!
{{/if}}

   {{> warning.no-extra-keys}}

4. **Base stylesheet LAST**:
   - `editStyles` → a rule for EVERY class you use in the structures, with its layout only: display, grid or flex, gaps, padding, margins, widths, alignment
   - Colours and fonts only through CSS variables declared in `:root` with plain values (e.g. `--color-primary: #333333;`), used as `var(--color-primary)`
   - No decoration: no shadows, gradients, rounded corners, animations or transitions — the look is designed in a later phase, which reads this stylesheet

---

{{> link-format-rules}}

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
{{> use-lang-switch-component}}

---
{{/if}}

## Available Commands

{{> command.$relatedCommands}}

---

{{#if param.onePage === true}}
## One-Page Website Example

```json
[
  {
    "command": "editStructure",
    "params": {
      "type": "menu",
      "structure": [
        {
          "tag": "nav",
          "children": [
            { "tag": "a", "params": { "href": "/" }, "children": [{ "textKey": "menu.logo" }] },
            { "tag": "a", "params": { "href": "#features" }, "children": [{ "textKey": "menu.features" }] },
            { "tag": "a", "params": { "href": "#contact" }, "children": [{ "textKey": "menu.contact" }] }
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
          "params": { "id": "hero" },
          "children": [
            { "tag": "h1", "children": [{ "textKey": "home.hero.title" }] },
            { "tag": "p", "children": [{ "textKey": "home.hero.subtitle" }] },
            { "tag": "a", "params": { "href": "#contact", "class": "btn" }, "children": [{ "textKey": "home.hero.cta" }] }
          ]
        },
        {
          "tag": "section",
          "params": { "id": "features" },
          "children": [
            { "tag": "h2", "children": [{ "textKey": "home.features.title" }] },
            {
              "tag": "div",
              "params": { "class": "grid" },
              "children": [
                { "tag": "div", "children": [{ "tag": "h3", "children": [{ "textKey": "home.features.item1.title" }] }, { "tag": "p", "children": [{ "textKey": "home.features.item1.desc" }] }] },
                { "tag": "div", "children": [{ "tag": "h3", "children": [{ "textKey": "home.features.item2.title" }] }, { "tag": "p", "children": [{ "textKey": "home.features.item2.desc" }] }] }
              ]
            }
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
        "page": { "titles": { "home": "My Landing Page" } },
        "menu": { "logo": "Brand", "features": "Features", "contact": "Contact" },
        "home": {
          "hero": { "title": "Welcome", "subtitle": "Your tagline here", "cta": "Get Started" },
          "features": {
            "title": "Features",
            "item1": { "title": "Feature 1", "desc": "Description..." },
            "item2": { "title": "Feature 2", "desc": "Description..." }
          }
        },
        "footer": { "copyright": "© 2025 Brand" }
      }
    }
  },
  {
    "command": "editStyles",
    "params": {
      "css": ":root { --color-primary: #333333; --color-text: #222222; --color-bg: #ffffff; --font-body: sans-serif; }\nbody { margin: 0; font-family: var(--font-body); color: var(--color-text); background: var(--color-bg); }\nnav { display: flex; gap: 1rem; padding: 1rem; }\nsection { padding: 3rem 2rem; }\n.btn { display: inline-block; padding: 0.75rem 1.5rem; background: var(--color-primary); color: var(--color-bg); }\n.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }\nfooter { padding: 2rem; text-align: center; }"
    }
  }
]
```
{{else}}
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
      "css": ":root { --color-primary: #333333; --color-text: #222222; --color-bg: #ffffff; --font-body: sans-serif; }\nbody { margin: 0; font-family: var(--font-body); color: var(--color-text); background: var(--color-bg); }\n.main-nav { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; }\n.nav-links { display: flex; gap: 1.5rem; }\nsection { padding: 4rem 2rem; max-width: 1200px; margin: 0 auto; }\n.hero { text-align: center; padding: 6rem 2rem; }\n.btn { display: inline-block; padding: 0.75rem 1.5rem; background: var(--color-primary); color: var(--color-bg); }\n.services-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem; }\n.footer-links { display: flex; gap: 1rem; justify-content: center; }\nfooter { padding: 2rem; text-align: center; }"
    }
  }
]
```
{{/if}}

---

## ✅ FINAL CHECKLIST (Verify before outputting)

{{#if param.multilingual === true}}
- [ ] Footer contains `{ "component": "lang-switch", "data": {} }` ← **REQUIRED!**
{{/if}}
- [ ] Links follow the link rules above: `/route-name` to a page, `#section-id` to a section of the same page
- [ ] External links have `target="_blank"`
- [ ] All text uses `textKey`, never hardcoded strings
- [ ] Translations for the default language only: {{data.langData.default_language}}
- [ ] Every class used in the structures has a rule in the base stylesheet, its colours and fonts through `var(--…)`

---

{{#if param.includeAssets === true}}
## Available Assets

The user has provided assets for this project. **Use these real assets where appropriate** — don't invent placeholder filenames.

Path format: `/assets/{category}/{filename}` (leading slash required).

### Starred Assets:
{{json assetList}}

{{> asset-usage-instructions}}
{{#if param.includeFavicon === true}}

{{> favicon-asset-rule}}
{{/if}}
{{/if}}

---

## 🚀 NOW GENERATE

{{#if param.onePage === true}}
Create the one-page website (a landing page) based on the user's requirements: its structure, its texts in {{data.langData.default_language}}, and its base stylesheet.
{{else}}
Create the multi-page website based on the user's requirements: its pages, their texts in {{data.langData.default_language}}, and its base stylesheet.
{{/if}}
- If information is missing, **assume reasonable defaults** (don't ask)
- **Output ONLY the JSON array** - no explanations before or after
- Ensure ALL textKeys have matching translations in {{data.langData.default_language}}
- Do NOT write the other languages or the site's look: later phases do
