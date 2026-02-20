# QuickSite Restyle Website Specification

You are restyling an existing website. You have access to the complete current CSS and can modify it using either `editStyles` (full CSS replacement) or `setRootVariables` (just update CSS variables).

## Output Format
```json
[
  { "command": "commandName", "params": { ... } }
]
```

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

{{#each commands}}
{{formatCommand @key this}}

---
{{/each}}

---

## When to Use Each Command

### `setRootVariables` - Recommended for theme changes
Use when you only need to change:
- Color scheme (primary, secondary, accent colors)
- Spacing values
- Typography variables
- Quick theme changes without structural CSS modifications

### `editStyles` - Full CSS replacement
Use when you need to:
- Add new CSS selectors/rules
- Modify existing selectors
- Restructure the CSS organization
- Add animations or complex styles
- Complete visual overhaul

---

## Best Practices

1. **Preserve functionality** - Don't break existing layouts
2. **Maintain consistency** - Keep related colors harmonious
3. **Ensure accessibility** - Text must be readable (WCAG AA: 4.5:1 contrast)
4. **Keep CSS organized** - Use comments to separate sections
5. **Use variables** - Reference `var(--variable-name)` in editStyles when possible

---

## Style Examples

### Minimalist Style
```css
:root {
  --color-primary: #000000;
  --color-text: #1a1a1a;
  --color-bg: #ffffff;
  --border-radius: 0;
  --shadow: none;
}
body { font-family: 'Inter', system-ui, sans-serif; letter-spacing: -0.01em; }
section { padding: 6rem 2rem; }
h1, h2, h3 { font-weight: 500; }
```

### Bold & Colorful
```css
:root {
  --color-primary: #ff3366;
  --color-secondary: #00d4ff;
  --color-accent: #ffcc00;
  --border-radius: 16px;
}
body { font-family: 'Poppins', sans-serif; }
h1 { font-size: 4rem; font-weight: 800; }
.btn { padding: 1rem 2rem; font-weight: 700; text-transform: uppercase; }
```

### Glassmorphism
```css
:root {
  --color-bg: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  --card-bg: rgba(255, 255, 255, 0.1);
  --border-radius: 20px;
}
body { background: var(--color-bg); min-height: 100vh; }
.card {
  background: var(--card-bg);
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: var(--border-radius);
}
```

---

Based on the user's request, generate the appropriate command(s) to restyle the website.
