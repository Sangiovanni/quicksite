# QuickSite Global Design Rework Specification

You are redesigning the color scheme and design variables of an existing website. The site uses CSS custom properties (variables) defined in the `:root` selector. Your task is to create a new cohesive design by updating these variables.

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

### Common Variable Categories:

**Colors:**
- `--color-primary` - Main brand color (buttons, links, accents)
- `--color-secondary` - Supporting color
- `--color-accent` - Highlight color
- `--color-text` - Main text color
- `--color-text-muted` - Secondary text
- `--color-bg` - Main background
- `--color-bg-alt` - Alternate/card backgrounds

**Spacing:**
- `--spacing-xs`, `--spacing-sm`, `--spacing-md`, `--spacing-lg`, `--spacing-xl`

**Typography:**
- `--font-family` - Main font stack
- `--font-size-base` - Base font size
- `--line-height` - Default line height

**Effects:**
- `--border-radius` - Corner rounding
- `--shadow` - Box shadow
- `--transition` - Animation timing

---

## Color Theme Examples

### Dark Theme
```json
{
  "--color-bg": "#0f172a",
  "--color-bg-alt": "#1e293b",
  "--color-text": "#f1f5f9",
  "--color-text-muted": "#94a3b8",
  "--color-primary": "#3b82f6",
  "--color-accent": "#22d3ee"
}
```

### Warm Earth Tones
```json
{
  "--color-bg": "#fef7ed",
  "--color-bg-alt": "#fff7ed",
  "--color-text": "#451a03",
  "--color-text-muted": "#78350f",
  "--color-primary": "#d97706",
  "--color-accent": "#dc2626"
}
```

### Corporate Blue
```json
{
  "--color-bg": "#ffffff",
  "--color-bg-alt": "#f8fafc",
  "--color-text": "#0f172a",
  "--color-text-muted": "#64748b",
  "--color-primary": "#1e40af",
  "--color-accent": "#0ea5e9"
}
```

---

Based on the user's request, generate a single `setRootVariables` command that transforms the design while keeping the site functional and visually appealing.
