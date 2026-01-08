# QuickSite Add New Page Specification

You are adding content to a **new page** on an existing website. The route has already been created.

## Output Format
```json
[
  { "command": "commandName", "params": { "key": "value" } }
]
```

---

## Page Information

**New Page Route:** `{{param.routeName}}`{{#if param.routeParent}} (under `{{param.routeParent}}`){{/if}}
**Full Route Path:** `{{#if param.routeParent}}{{param.routeParent}}/{{/if}}{{param.routeName}}`
**URL Path:** `/{{#if param.routeParent}}{{param.routeParent}}/{{/if}}{{param.routeName}}`
**Available Languages:** {{#if languages.languages}}{{join languages.languages ", "}}{{else}}en{{/if}}

{{#if param.routeParent}}
> ⚠️ **Use full route path** `{{param.routeParent}}/{{param.routeName}}` for the `name` parameter in page editStructure!
{{/if}}

---

## Commands to Generate

{{#if param.navPlacement === "none"}}
Since navigation placement is "none", generate commands for:
1. **editStructure** (page) - Add page content
2. **setTranslationKeys** - Provide translations for ALL languages
{{else}}
Generate commands for:
1. **editStructure** (menu and/or footer) - Add navigation link(s)
2. **editStructure** (page) - Add page content
3. **setTranslationKeys** - Provide translations for ALL languages
{{/if}}

---

{{#if param.navPlacement === "none"}}
**Note:** Navigation placement is "none" - no menu or footer link will be added. This page will only be accessible via direct URL.
{{/if}}

{{#if param.navPosition}}
## Navigation Link Position

User specified where to add the navigation link:
```
{{param.navPosition}}
```

Parse this value - format is: `structure:type|page:pageName (optional)|node:nodeId|action:actionType`

Use this EXACT information for the editStructure command:
- `type`: The structure type (menu, footer, or page)
- `name`: If type is "page", use the page name from the navPosition
- `nodeId`: The target node ID for the action
- `action`: insertBefore, insertAfter, update, or delete

Example: If navPosition is `structure:menu|node:0.1|action:insertAfter`:
```json
{
  "command": "editStructure",
  "params": {
    "type": "menu",
    "nodeId": "0.1",
    "action": "insertAfter",
    "structure": { "tag": "a", "params": { "href": "/{{param.routeName}}" }, "children": [{ "textKey": "menu.{{param.routeName}}" }] }
  }
}
```
{{else}}
{{#if param.navPlacement === "menu"}}
## Menu Structure (with nodeIds)

Add navigation link to the MENU. Choose an appropriate position:

```json
{{json menuStructure}}
```
{{/if}}
{{#if param.navPlacement === "footer"}}
## Footer Structure (with nodeIds)

Add navigation link to the FOOTER. Choose an appropriate position:

```json
{{json footerStructure}}
```
{{/if}}
{{#if param.navPlacement === "both"}}
## Menu Structure (with nodeIds)

Add navigation link to the MENU:

```json
{{json menuStructure}}
```

## Footer Structure (with nodeIds)

Add navigation link to the FOOTER:

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

{{#if param.routeParent}}
**IMPORTANT:** For `page`, the `name` parameter MUST be exactly `{{param.routeParent}}/{{param.routeName}}` (the full path including parent)
{{else}}
**IMPORTANT:** For `page`, the `name` parameter MUST be exactly `{{param.routeName}}`
{{/if}}

---

## Page Structure Format

**CRITICAL:** For pages, the `structure` parameter MUST be an **array** of nodes, NOT a single object!

✅ **CORRECT** (array of nodes):
```json
{
  "command": "editStructure",
  "params": {
    "type": "page",
    "name": "{{#if param.routeParent}}{{param.routeParent}}/{{/if}}{{param.routeName}}",
    "structure": [
      { "tag": "section", "params": { "class": "hero" }, "children": [...] },
      { "tag": "section", "params": { "class": "content" }, "children": [...] }
    ]
  }
}
```

❌ **WRONG** (single object - will cause rendering errors):
```json
{
  "structure": { "tag": "div", "children": [...] }
}
```

---

## Node Actions (for editStructure)

| Action | Description |
|--------|-------------|
| `insertBefore` | Insert BEFORE the specified nodeId |
| `insertAfter` | Insert AFTER the specified nodeId |
| `update` | Update/replace the node with specified nodeId |
| `delete` | Delete the node with specified nodeId |

**CRITICAL:** The `nodeId` parameter MUST use the `_nodeId` values from the structure above (e.g., `"0"`, `"0.1"`, `"0.1.0"`). Do NOT use element IDs or class names!

---

## Available CSS Classes

{{#if styles.css}}
Classes from current stylesheet:
```
{{extractClasses styles.css}}
```
{{else}}
Use semantic class names. Example: `page-{{param.routeName}}`, `{{param.routeName}}-section`, etc.
{{/if}}

---

## Available Commands

{{#each commands}}
{{formatCommand @key this}}

---
{{/each}}

---

## Translation Requirements

**IMPORTANT:** The `setTranslationKeys` command takes ONE language at a time!

Format:
```json
{ "command": "setTranslationKeys", "params": { "language": "en", "translations": { "key": "value" } } }
```

You MUST generate **one setTranslationKeys command per language**: **{{#if languages.languages}}{{join languages.languages ", "}}{{else}}en{{/if}}**

Required translation keys:
- `page.titles.{{param.routeName}}` - Page <title> tag
{{#if param.navPlacement === "menu"}}
- `menu.{{param.routeName}}` - Navigation link text in menu
{{/if}}
{{#if param.navPlacement === "footer"}}
- `footer.{{param.routeName}}` - Navigation link text in footer
{{/if}}
{{#if param.navPlacement === "both"}}
- `menu.{{param.routeName}}` - Navigation link text in menu
- `footer.{{param.routeName}}` - Navigation link text in footer
{{/if}}
- All page content textKeys (use namespace `{{param.routeName}}.xxx`)

---

Add the requested page content. Remember:
- Route already exists (do NOT use addRoute command)
{{#if param.routeParent}}
- Use `name: "{{param.routeParent}}/{{param.routeName}}"` in the page editStructure (FULL PATH required!)
{{else}}
- Use `name: "{{param.routeName}}"` in the page editStructure
{{/if}}
- Provide translations for ALL {{#if languages.languages}}{{languages.languages.length}}{{else}}1{{/if}} languages
