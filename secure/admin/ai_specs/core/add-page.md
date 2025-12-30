# QuickSite Add New Page Specification

You are adding a new page to an existing website. Generate a JSON command sequence.

## Output Format
```json
[
  { "command": "commandName", "params": { "key": "value" } }
]
```

---

## Current Website Info

**Existing Pages:** {{#if routes}}{{#each routes}}{{this}}{{#unless @last}}, {{/unless}}{{/each}}{{else}}home (default){{/if}}
**Available Languages:** {{#if languages.list}}{{#each languages.list}}{{this}}{{#unless @last}}, {{/unless}}{{/each}}{{else}}en{{/if}}

---

## CRITICAL: Command Order

1. **addRoute** - Create the new page route FIRST
{{#if param.navPlacement === "none"}}
2. **editStructure** (page) - Add page content
3. **setTranslationKeys** - Provide translations for ALL languages
{{else}}
2. **editStructure** (menu/footer) - Add navigation link(s)
3. **editStructure** (page) - Add page content
4. **setTranslationKeys** - Provide translations for ALL languages
{{/if}}

---

{{#if param.navPlacement === "none"}}
**Note:** Navigation placement set to "none" - no menu or footer link will be added. This page will only be accessible via direct URL.
{{/if}}

{{#if menuStructure}}
{{#if param.navPlacement === "menu"}}
## Menu Structure (with nodeIds)

Add navigation link to the MENU. Use these nodeIds to position your link:

```json
{{json menuStructure}}
```
{{/if}}
{{#if param.navPlacement === "both"}}
## Menu Structure (with nodeIds)

Add navigation link to the MENU. Use these nodeIds to position your link:

```json
{{json menuStructure}}
```
{{/if}}
{{/if}}

{{#if footerStructure}}
{{#if param.navPlacement === "footer"}}
## Footer Structure (with nodeIds)

Add navigation link to the FOOTER. Use these nodeIds to position your link:

```json
{{json footerStructure}}
```
{{/if}}
{{#if param.navPlacement === "both"}}
## Footer Structure (with nodeIds)

Add navigation link to the FOOTER. Use these nodeIds to position your link:

```json
{{json footerStructure}}
```
{{/if}}
{{/if}}

---

## Structure Types

| type | Description | Required params |
|------|-------------|-----------------|
| `menu` | Navigation (shared) | type + structure |
| `footer` | Footer (shared) | type + structure |
| `page` | Page content | type + **name** + structure |

**Important:** For `page`, the `name` parameter is REQUIRED and must match the route name.

---

## Node Actions (for adding to menu/footer)

| Action | Description |
|--------|-------------|
| `insertBefore` | Insert BEFORE the specified nodeId |
| `insertAfter` | Insert AFTER the specified nodeId |
| `appendChild` | Add as last child of nodeId |
| `prependChild` | Add as first child of nodeId |

---

## Available CSS Classes

{{#if styles.css}}
Classes from current stylesheet:
```
{{extractClasses styles.css}}
```
{{else}}
No existing CSS detected. You may add new classes.
{{/if}}

---

## Available Commands

{{#each commands}}
{{formatCommand @key this}}

---
{{/each}}

---

## Example: Adding a Blog Page (Menu Link)

```json
[
  {
    "command": "addRoute",
    "params": { "name": "blog" }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "menu",
      "action": "appendChild",
      "nodeId": "nav_links_container_id",
      "structure": {
        "tag": "a",
        "params": { "href": "/blog" },
        "children": [{ "textKey": "menu.blog" }]
      }
    }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "page",
      "name": "blog",
      "structure": [
        {
          "tag": "section",
          "children": [
            { "tag": "h1", "children": [{ "textKey": "blog.title" }] },
            { "tag": "p", "children": [{ "textKey": "blog.intro" }] }
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
        "page": { "titles": { "blog": "Blog | My Website" } },
        "menu": { "blog": "Blog" },
        "blog": { "title": "Our Blog", "intro": "Latest news and updates" }
      }
    }
  }
]
```

---

## Translation Requirements

You MUST provide translations for ALL languages: **{{#if languages.list}}{{#each languages.list}}{{this}}{{#unless @last}}, {{/unless}}{{/each}}{{else}}en{{/if}}**

Required translation keys:
- `page.titles.$routeName` - Page <title> tag
{{#if param.navPlacement === "menu"}}
- `menu.$routeName` - Navigation link text in menu
{{/if}}
{{#if param.navPlacement === "footer"}}
- `footer.$routeName` - Navigation link text in footer
{{/if}}
{{#if param.navPlacement === "both"}}
- `menu.$routeName` - Navigation link text in menu
- `footer.$routeName` - Navigation link text in footer
{{/if}}
- All page content textKeys

---

Add the requested page to the website. Ensure translations are provided for ALL languages.
