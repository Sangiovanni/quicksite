# QuickSite Create Landing Page Specification

You are creating a complete single-page landing website from a blank QuickSite project. Generate a JSON command sequence.

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
1. **Multilingual FIRST**:
   - `setMultilingual` → enables multi-language mode
   - `addLang` → for each additional language (languages: {{param.languages}})
{{/if}}

{{#if param.multilingual === true}}2{{else}}1{{/if}}. **Structures** (in this order):
   - `editStructure` type="menu" → navigation (can be minimal for landing)
   - `editStructure` type="footer" → footer with copyright, links
   - `editStructure` type="page", name="home" → main landing page content

{{#if param.multilingual === true}}3{{else}}2{{/if}}. **Translations**:
   - `setTranslationKeys` → MUST cover ALL textKeys used in structures
{{#if param.multilingual === true}}
   - Repeat for each language: {{param.languages}}
{{/if}}

{{#if param.multilingual === true}}4{{else}}3{{/if}}. **Styles LAST** (order matters!):
   - `editStyles` → MUST come BEFORE setRootVariables
   - `setRootVariables` → AFTER editStyles (optional helper)

**Why order matters:** editStyles can reset CSS variables, so setRootVariables must apply after.

---

## Core Concept: Structure → Translation

This system separates **structure** (HTML) from **content** (text). ALL text must use `textKey` references:

```json
{ "tag": "h1", "children": [{ "textKey": "home.title" }] }
```

**Naming convention for textKeys:**
- `menu.*` → navigation items
- `footer.*` → footer content
- `home.*` → page content (home.hero.title, home.features.title, etc.)
- `page.titles.home` → page <title> tag

**⚠️ NEVER hardcode text. Always use textKey.**

---

## Structure Types

| type | Description | Required params |
|------|-------------|-----------------|
| `menu` | Navigation (shared) | type + structure |
| `footer` | Footer (shared) | type + structure |
| `page` | Page content | type + **name** + structure |
| `component` | Reusable template | type + **name** + structure |

---

## Components (Reusable Templates)

Components are reusable structure fragments with variable placeholders using `{{varName}}` syntax.

### Creating a Component
```json
{
  "command": "editStructure",
  "params": {
    "type": "component",
    "name": "feature-card",
    "structure": {
      "tag": "div",
      "params": { "class": "card" },
      "children": [
        { "tag": "h3", "children": [{ "textKey": "{{titleKey}}" }] },
        { "tag": "p", "children": [{ "textKey": "{{descKey}}" }] }
      ]
    }
  }
}
```

### Using a Component
```json
{
  "component": "feature-card",
  "data": { "titleKey": "home.features.card1.title", "descKey": "home.features.card1.desc" }
}
```

---

{{#if param.multilingual === true}}
## Language Switcher (Required for Multilingual)

**Create the lang-switch component:**
```json
{
  "tag": "div",
  "params": { "class": "lang-switch" },
  "children": [
{{#each languages}}
    { "tag": "a", "params": { "href": "{{__current_page;lang={{this}}}}", "class": "lang-link" }, "children": [{ "textKey": "__RAW__{{langName this}}" }] }{{#unless @last}},{{/unless}}
{{/each}}
  ]
}
```

**Add to footer:**
```json
{ "component": "lang-switch", "data": {} }
```

---
{{/if}}

## Available Commands

{{#each commands}}
{{formatCommand @key this}}

---
{{/each}}

---

## Minimal Landing Page Example

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
        { "tag": "footer", "children": [{ "tag": "p", "children": [{ "textKey": "footer.copyright" }] }] }
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
      "css": ":root { --color-primary: #6366f1; --color-text: #333; }\nbody { font-family: sans-serif; color: var(--color-text); }\nnav { display: flex; gap: 1rem; padding: 1rem; }\nsection { padding: 3rem 2rem; }\n.btn { display: inline-block; padding: 0.75rem 1.5rem; background: var(--color-primary); color: white; text-decoration: none; border-radius: 4px; }\n.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }\nfooter { text-align: center; padding: 2rem; }"
    }
  }
]
```

---

Create a landing page based on the user's requirements. Ensure ALL textKeys have matching translations.
