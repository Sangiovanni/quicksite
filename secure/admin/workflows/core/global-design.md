# QuickSite Global Design Rework Specification

You are redesigning the color scheme and design variables of an existing website. The site uses CSS custom properties (variables) defined in the `:root` selector. Your task is to create a new cohesive design by updating these variables.

## ⚠️ IMPORTANT: Output Rules

1. **OUTPUT JSON ONLY** - No explanations, no questions, no commentary
2. **DO NOT ASK** for missing information - make reasonable creative choices
3. **IMAGINE** what the user wants if their request is vague - pick a bold, cohesive theme
4. Your response must be a valid JSON array and nothing else
5. **NO previews, NO descriptions, NO "here's what I did"** - ONLY the JSON array

## Output Format
```json
[
  { "command": "setRootVariables", "params": { "variables": { "--var-name": "value" } } }
]
```

---

## Current Design Variables

These are the existing CSS variables used by this website:

```json
{{json rootVariables}}
```

---

## Available Commands

{{#each commands}}
{{formatCommand @key this}}

---
{{/each}}

---

## Guidelines

1. **Preserve all variable names** - Only change values, not names
2. **Maintain semantic meaning** - `--color-primary` should stay the main brand color
3. **Ensure contrast** - Text colors must be readable against their backgrounds
4. **Be consistent** - Related colors should work harmoniously together
5. **Consider accessibility** - Aim for WCAG AA contrast ratios (4.5:1 for text)

---

## Complete Example Output

```json
[
  {
    "command": "setRootVariables",
    "params": {
      "variables": {
        "--color-primary": "#2563eb",
        "--color-secondary": "#7c3aed",
        "--color-accent": "#f59e0b",
        "--color-text": "#1e293b",
        "--color-text-muted": "#64748b",
        "--color-bg": "#ffffff",
        "--color-bg-alt": "#f8fafc",
        "--border-radius": "8px",
        "--shadow": "0 2px 8px rgba(0,0,0,0.08)",
        "--font-family": "'Inter', sans-serif"
      }
    }
  }
]
```

**⚠️ Your entire response must look like the example above: a JSON array with one `setRootVariables` command containing ALL the variables. No text before or after the JSON.**
