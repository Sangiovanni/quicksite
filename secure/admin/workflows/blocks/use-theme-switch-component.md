## Light / Dark Switch (REQUIRED with dark mode)

**⚠️ MANDATORY:** The site has a dark mode. Place the `theme-switch` component **once**, where a visitor looks for it: in the menu, the header or the footer — your choice.

The component already exists, with this structure (for styling reference):
```json
{
  "tag": "button",
  "params": { "class": "theme-switch", "data-theme-toggle": "true", "type": "button", "aria-label": "themeSwitch.toggle", "aria-pressed": "false" },
  "children": [
    { "tag": "span", "params": { "class": "theme-switch-icon", "aria-hidden": "true" }, "children": [{ "textKey": "__RAW__🌙" }] },
    { "tag": "span", "params": { "class": "theme-switch-label" }, "children": [{ "textKey": "themeSwitch.dark" }] }
  ]
}
```

Its two words, `themeSwitch.toggle` (the button's name) and `themeSwitch.dark` (its label), are already written in every language of the site: leave them as they are.

**To place it, add this child element where you want it:**
```json
{ "component": "theme-switch", "data": {} }
```

Give `.theme-switch`, `.theme-switch-icon` and `.theme-switch-label` rules in your stylesheet, so the button fits where you put it.

**DO NOT recreate this component, and do not switch the dark mode on yourself:** QuickSite does it once your commands have run.
