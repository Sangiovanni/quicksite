# QuickSite Add Section to Page Specification

You are adding a new section to an existing page.

## ⚠️ IMPORTANT: Output Rules

1. **OUTPUT JSON ONLY** - No explanations, no questions, no commentary
2. **DO NOT ASK** for missing information - make reasonable assumptions
3. Your response must be a valid JSON array and nothing else

## Output Format
```json
[
  { "command": "commandName", "params": { "key": "value" } }
]
```

---

## Current Website Info

**Available Pages:** {{#if routes}}{{#each routes}}{{this}}{{#unless @last}}, {{/unless}}{{/each}}{{else}}home (default){{/if}}
**Available Languages:** {{#if languages.languages}}{{join languages.languages ", "}}{{else}}en{{/if}}
**Target Page:** {{#if param.targetPage}}**{{param.targetPage}}**{{else}}⚠️ NO PAGE SELECTED{{/if}}

---

## Understanding Node IDs

Each element in a page structure has a unique `_nodeId`. These are used to specify WHERE to insert new content.

**Actions available:**
- `insertBefore` - Insert new content BEFORE the specified nodeId
- `insertAfter` - Insert new content AFTER the specified nodeId
- `update` - Replace the node with new content
- `delete` - Remove the node

**User describes position naturally** (e.g., "after the hero section", "before the footer", "at the end of features"). Your job is to find the corresponding nodeId from the structure below.

{{#if pageStructure}}
---

## Current "{{param.targetPage}}" Page Structure (with nodeIds)

Use these `_nodeId` values to position your new section:

```json
{{json pageStructure}}
```

**CRITICAL:** The `nodeId` parameter MUST use the `_nodeId` values shown above (e.g., `"0"`, `"0.1"`, `"0.1.0"`). Do NOT use element IDs or class names!
{{else}}
---

## ⚠️ PAGE STRUCTURE NOT LOADED

No target page was selected. The structure will load when a page is selected.
{{/if}}

{{#if param.sectionPosition}}
---

## Section Position

User specified where to add the section:
```
{{param.sectionPosition}}
```

Parse this value - format is: `structure:page|page:pageName|node:nodeId|action:actionType`

Use this EXACT information for the editStructure command:
- `type`: "page"
- `name`: "{{param.targetPage}}"
- `nodeId`: The node ID from the sectionPosition
- `action`: insertBefore or insertAfter from the sectionPosition
{{/if}}

---

## Structure Types

| type | Description | Required params |
|------|-------------|-----------------|
| `page` | Page content | type + **name** + action + nodeId + structure |

**IMPORTANT:** For `page`, the `name` parameter MUST be exactly `{{param.targetPage}}`

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
{ "tag": "h2", "children": [{ "textKey": "{{param.targetPage}}.newSection.title" }] }
```

### ⚠️ TRANSLATIONS FOR ALL LANGUAGES!
You MUST provide translations for ALL available languages: **{{#if languages.languages}}{{join languages.languages ", "}}{{else}}en{{/if}}**

Generate **one setTranslationKeys command per language**.

---

## Available Commands

{{#each commands}}
{{formatCommand @key this}}

---
{{/each}}

---

## Example: Adding a Testimonials Section

If the page structure shows `_nodeId: "0"` for the hero section:

```json
[
  {
    "command": "editStructure",
    "params": {
      "type": "page",
      "name": "home",
      "action": "insertAfter",
      "nodeId": "0",
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

## 🚀 NOW GENERATE

Add the requested section to **{{param.targetPage}}** page.
- Use the `_nodeId` values from the structure above
- Provide translations for ALL languages
- **Output ONLY the JSON array** - no explanations
