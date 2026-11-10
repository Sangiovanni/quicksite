# QuickSite Fit Cookie Banner Specification

QuickSite generated the cookie banner and its popup below. They do not follow this site's style yet: make them follow it.

{{> output-json-only}}

---

## The elements

{{> consent-structure}}

---

## The site's style

{{#if param.coloursOnly === true}}
The site's style is in these CSS variables. Follow them: use `var(--name)`, never a copied colour.

```json
{{json rootVariables}}
```
{{else}}
The site's style is this stylesheet. Follow it: its colours, fonts, corners and shadows, and above all its buttons, as a button here should look like a button there. Use its `var(--name)` variables where it has them.

A rule in it that names `qs-consent-` comes from an earlier run of this workflow: it is not the site's style, and your answer replaces it.

```css
{{#if styles.css}}{{styles.css}}{{else}}{{styles.content}}{{/if}}
```
{{/if}}

---

## Their look today

These elements' current look is not in the site's stylesheet. It is QuickSite's defaults, below. A default you do not override stays, so override every colour in them that belongs to the elements above.

```css
{{> consent-default-styles}}
```

---

## How to answer

- **Begin every selector with `#qs-consent-banner` or `#qs-consent-popup`.** Without that, the defaults win and your rule does nothing. Style what is inside them, never what follows them: no `+` or `~` straight after. A rule that begins otherwise is left out.
- **Never set `display`, `visibility`, `position`, `z-index` or `all`.** QuickSite shows and hides these elements and places them on the page, and `all` would undo that. A rule that sets one is left out.
- Keep every text readable on its background, at a contrast of at least 4.5 to 1, hover states included.
- Answer with `setStyleRule` only, one command per rule. Any other command is left out.

---

## Available Command

{{> command.setStyleRule}}

---

## Example Output

```json
[
  { "command": "setStyleRule", "params": { "selector": "#qs-consent-banner", "styles": { "background": "var(--color-bg)", "color": "var(--color-text)" } } },
  { "command": "setStyleRule", "params": { "selector": "#qs-consent-banner .qs-consent-btn--primary", "styles": { "background": "var(--color-primary)", "color": "var(--color-bg)", "border-radius": "6px" } } },
  { "command": "setStyleRule", "params": { "selector": "#qs-consent-popup .qs-consent-popup__dialog", "styles": { "background": "var(--color-bg)", "color": "var(--color-text)" } } }
]
```

**Your entire response must be a JSON array like the example above. No text before or after the JSON.**
