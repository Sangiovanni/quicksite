# QuickSite Create Multi-Page Website Specification

You are creating a complete multi-page website from a blank QuickSite project. Generate a JSON command sequence.

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
   - `setMultilingual` → enable multi-language mode AFTER having 2+ languages
   - Languages: {{param.languages}}

2. **Routes**:
   - `addRoute` → creates new pages (home exists by default)
   - Note: "home" route exists by default (accessible as "/" or "/home")

3. **Structures** (in this order):
   - `editStructure` type="menu" → navigation (shared across all pages)
   - `editStructure` type="footer" → footer (shared across all pages)
   - `editStructure` type="page", name="$routeName" → each page content

4. **Translations**:
   - `setTranslationKeys` → MUST cover ALL textKeys used in structures
   - **Repeat for each language:** {{param.languages}}
   - Note: `addRoute` auto-creates `page.titles.$routeName` - set this too!

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

Components let you define reusable structures with variables:

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
        { "tag": "span", "params": { "class": "icon" }, "children": [{ "textKey": "__RAW__{{icon}}" }] },
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
  "component": "service-card",
  "data": { "icon": "🔧", "titleKey": "services.item1.title", "descKey": "services.item1.desc" }
}
```

---

{{#if param.multilingual === true}}
## Language Switcher (Required for Multilingual)

**Create the lang-switch component:**
```json
{
  "command": "editStructure",
  "params": {
    "type": "component",
    "name": "lang-switch",
    "structure": {
      "tag": "div",
      "params": { "class": "lang-switch" },
      "children": [
{{#each languages}}
        { "tag": "a", "params": { "href": "{{__current_page;lang={{this}}}}", "class": "lang-link" }, "children": [{ "textKey": "__RAW__{{langName this}}" }] }{{#unless @last}},{{/unless}}
{{/each}}
      ]
    }
  }
}
```

**Add to footer using:** `{ "component": "lang-switch", "data": {} }`

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
            }
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

Create a complete multi-page website based on the user's requirements. Ensure ALL textKeys have matching translations.
