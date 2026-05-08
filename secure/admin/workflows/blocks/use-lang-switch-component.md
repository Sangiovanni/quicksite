## Language Switcher (REQUIRED for Multilingual)

**⚠️ MANDATORY:** You MUST include the `lang-switch` component in your **footer structure**.

The component will be auto-generated with this structure (for styling reference):
```json
{
  "tag": "div",
  "params": { "class": "lang-switch" },
  "children": [
    { "tag": "a", "params": { "href": "{{__current_page;lang=en}}", "class": "lang-btn", "data-lang": "en" }, "children": [{ "textKey": "__RAW__English" }] },
    { "tag": "a", "params": { "href": "{{__current_page;lang=fr}}", "class": "lang-btn", "data-lang": "fr" }, "children": [{ "textKey": "__RAW__Français" }] }
  ]
}
```

**To include it in your footer, add this child element:**
```json
{ "component": "lang-switch", "data": {} }
```

**Example footer with lang-switch:**
```json
{
  "tag": "footer",
  "children": [
    { "tag": "p", "children": [{ "textKey": "footer.copyright" }] },
    { "component": "lang-switch", "data": {} }
  ]
}
```

**DO NOT recreate this component.** Just include it using the component reference above.
