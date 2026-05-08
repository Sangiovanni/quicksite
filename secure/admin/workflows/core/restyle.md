# QuickSite Restyle Website Specification

You are restyling an existing website. You have access to the complete current CSS and can modify it using either `editStyles` (full CSS replacement) or `setRootVariables` (just update CSS variables), or both.

{{> output-json-only}}
---

## Current CSS Variables (:root)

These are the design tokens currently in use:

```json
{{json rootVariables}}
```

---

## Current Full CSS

```css
{{#if styles.css}}{{styles.css}}{{else}}{{styles.content}}{{/if}}
```

---

## Available Commands

{{> command.editStyles}}

---

{{> command.setRootVariables}}

---

## Command Selection Rules

- **Theme/color changes only** → use `setRootVariables` alone
- **Layout, animations, new selectors, structural CSS** → use `editStyles` (include complete CSS)
- **Full visual overhaul** → use `editStyles` FIRST, then `setRootVariables` AFTER (order matters!)

⚠️ When using `editStyles`, provide the **complete CSS** — it replaces the entire stylesheet. Reference `var(--variable-name)` in your CSS when possible.

{{> styles-order}}

---

## Complete Example Output

{{> example.restyle-theme-only}}

{{> example.restyle-full-overhaul}}

**⚠️ Your entire response must be a JSON array like the examples above. No text before or after the JSON.**
