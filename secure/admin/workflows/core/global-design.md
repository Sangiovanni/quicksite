# QuickSite Global Design Rework Specification

You are redesigning the color scheme and design variables of an existing website. The site uses CSS custom properties (variables) defined in the `:root` selector. Your task is to create a new cohesive design by updating these variables.

{{> output-json-only}}

---

## Current Design Variables

These are the existing CSS variables used by this website:

```json
{{json rootVariables}}
```

---

## Available Commands

{{> command.setRootVariables}}

---

## Guidelines

1. **Preserve all variable names** - Only change values, not names
2. **Maintain semantic meaning** - `--color-primary` should stay the main brand color
3. **Ensure contrast** - Text colors must be readable against their backgrounds
4. **Be consistent** - Related colors should work harmoniously together
5. **Consider accessibility** - Aim for WCAG AA contrast ratios (4.5:1 for text)

---

## Complete Example Output

{{> example.global-design-output}}

**⚠️ Your entire response must look like the example above: a JSON array with one `setRootVariables` command containing ALL the variables. No text before or after the JSON.**
