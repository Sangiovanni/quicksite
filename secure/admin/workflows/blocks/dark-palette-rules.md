## Dark Palette (REQUIRED with dark mode)

The site switches between a light theme and a dark one. The light colours are the CSS variables of `:root`; the dark colours are **the same variables**, given their dark values with `setRootVariables` and `"themeTarget": "dark"`:

```json
{
  "command": "setRootVariables",
  "params": {
    "themeTarget": "dark",
    "variables": { "--color-bg": "#111827", "--color-text": "#f3f4f6", "--color-primary": "#60a5fa" }
  }
}
```

- Every colour of the stylesheet goes through a CSS variable (`var(--color-…)`), so the dark theme changes each colour the light one sets
- Give **every** colour variable of `:root` its dark value: backgrounds, surfaces, text, links, buttons, borders
- Text stays readable on its background in both themes
- Send this `setRootVariables` **LAST**, after `editStyles`: a stylesheet sent after it replaces the dark colours
