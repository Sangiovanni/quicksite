# QuickSite Add Section to Page Specification

You are adding a new section to an existing page. Generate a JSON command sequence.

## Output Format
```json
[
  { "command": "commandName", "params": { "key": "value" } }
]
```

---

## Current Website Info

**Available Pages:** {{#if routes}}{{#each routes}}{{this}}{{#unless @last}}, {{/unless}}{{/each}}{{else}}home (default){{/if}}
**Available Languages:** {{#if languages.list}}{{#each languages.list}}{{this}}{{#unless @last}}, {{/unless}}{{/each}}{{else}}en{{/if}}
**Target Page:** {{#if targetPage}}**{{targetPage}}**{{else}}⚠️ NO PAGE SELECTED - Ask user which page to add section to!{{/if}}

---

## Understanding Node IDs

Each element in a page structure has a unique `__nodeId`. These are used to specify WHERE to insert new content.

**Actions available:**
- `insertBefore` - Insert new content BEFORE the specified nodeId
- `insertAfter` - Insert new content AFTER the specified nodeId
- `appendChild` - Add content as last child inside the specified nodeId
- `prependChild` - Add content as first child inside the specified nodeId
- `replaceNode` - Replace the entire node with new content

**User describes position naturally** (e.g., "after the hero section", "before the footer", "at the end of features"). Your job is to find the corresponding nodeId from the structure below.

{{#if pageStructure}}
---

## Current "{{targetPage}}" Page Structure (with nodeIds)

Use these nodeIds to position your new section:

```json
{{json pageStructure}}
```
{{else}}
---

## ⚠️ PAGE STRUCTURE NOT LOADED

No target page was selected. Before proceeding:
1. Ask the user which page they want to add the section to
2. The structure with nodeIds will be loaded automatically
{{/if}}

---

## Structure Types

| type | Description | Required params |
|------|-------------|-----------------|
| `page` | Page content | type + **name** + action + nodeId + structure |

---

## Components (Reusable Templates)

{{#if components}}
### Existing Components

You can use these existing components:

{{#each components}}
- `{{@key}}`{{#if this.variables}} (vars: {{#each this.variables}}{{@key}}{{#unless @last}}, {{/unless}}{{/each}}){{/if}}
{{/each}}

### Using a Component
```json
{
  "component": "component-name",
  "data": { "varName": "value" }
}
```
{{else}}
No components defined yet. You can create reusable components if needed.
{{/if}}

---

## Available CSS Classes

{{#if styles.css}}
Classes from current stylesheet:
```
{{extractClasses styles.css}}
```
{{else}}
Use semantic class names that match your design.
{{/if}}

---

## CRITICAL RULES

### ⚠️ NO HARDCODED TEXT!
ALL text must use `textKey` references:
```json
{ "tag": "h2", "children": [{ "textKey": "{{targetPage}}.newSection.title" }] }
```

### ⚠️ TRANSLATIONS FOR ALL LANGUAGES!
You MUST provide translations for ALL available languages: **{{#if languages.list}}{{#each languages.list}}{{this}}{{#unless @last}}, {{/unless}}{{/each}}{{else}}en{{/if}}**

---

## Available Commands

{{#each commands}}
{{formatCommand @key this}}

---
{{/each}}

---

## Example: Adding a Testimonials Section

```json
[
  {
    "command": "editStructure",
    "params": {
      "type": "page",
      "name": "home",
      "action": "insertAfter",
      "nodeId": "features_section_id",
      "structure": {
        "tag": "section",
        "params": { "class": "testimonials-section", "id": "testimonials" },
        "children": [
          { "tag": "h2", "children": [{ "textKey": "home.testimonials.title" }] },
          {
            "tag": "div",
            "params": { "class": "testimonial-grid" },
            "children": [
              {
                "tag": "div",
                "params": { "class": "testimonial-card" },
                "children": [
                  { "tag": "p", "children": [{ "textKey": "home.testimonials.quote1" }] },
                  { "tag": "span", "children": [{ "textKey": "home.testimonials.author1" }] }
                ]
              }
            ]
          }
        ]
      }
    }
  },
  {
    "command": "setTranslationKeys",
    "params": {
      "language": "en",
      "translations": {
        "home": {
          "testimonials": {
            "title": "What Our Customers Say",
            "quote1": "\"Excellent service!\"",
            "author1": "- John Doe, CEO"
          }
        }
      }
    }
  }
]
```

---

Add the requested section to the specified page. Ensure translations are provided for ALL languages.
