# QuickSite Add Language Specification

You are adding a new language to an existing multilingual website. Generate a JSON command sequence.

## Output Format
```json
[
  { "command": "commandName", "params": { "key": "value" } }
]
```

---

## Current Website Info

**Existing Languages:** {{languages}}
**Default Language:** {{defaultLang}}
**lang-switch Component:** {{#if langSwitchComponent.structure}}✅ Exists{{else}}❌ Not found{{/if}}
{{#if components}}

### Available Components
{{#each components}}
- `{{this.name}}`
{{/each}}
{{/if}}

---

## CRITICAL: Command Order

1. **addLang** - Add the new language code FIRST
2. **editStructure** - Update the `lang-switch` component to include the new language
3. **setTranslationKeys** - Provide ALL translations for the new language

---

## The lang-switch Component

QuickSite uses a `lang-switch` component for language switching. Each language link follows this pattern:
```json
{ "tag": "a", "params": { "href": "{{__current_page;lang=XX}}", "class": "lang-link" }, "children": [{ "textKey": "__RAW__LanguageName" }] }
```

- Replace `XX` with the language code (e.g., `es`, `de`, `it`)
- Replace `LanguageName` with the native name (e.g., `Español`, `Deutsch`, `Italiano`)
- Use `__RAW__` prefix so the name displays directly without translation lookup

{{#if langSwitchComponent.structure}}
### Current lang-switch Component

The project has a `lang-switch` component. Here's its current structure:

```json
{{json langSwitchComponent.structure}}
```

**To add the new language, update the component by adding a new link for the target language.**

{{else}}
### Create the lang-switch Component

The project doesn't have a `lang-switch` component yet. Create one like this:

```json
{
  "command": "editStructure",
  "params": {
    "type": "component",
    "name": "lang-switch",
    "structure": {
      "tag": "div",
      "params": { "class": "lang-switch" },
      "children": [
        { "tag": "a", "params": { "href": "{{__current_page;lang=en}}", "class": "lang-link" }, "children": [{ "textKey": "__RAW__English" }] },
        { "tag": "a", "params": { "href": "{{__current_page;lang={{param.targetLanguage}}}}", "class": "lang-link" }, "children": [{ "textKey": "__RAW__{{param.languageName}}" }] }
      ]
    }
  }
}
```

Then add the component to the footer or menu using `editStructure` with `action: "appendChild"`.
{{/if}}

---

## Source Translations ({{defaultLang}})

These are the translation keys you need to translate. Keep the EXACT same structure:

```json
{{json sourceTranslations}}
```

---

## Command Reference

### addLang
{{#if helpAddLang}}
{{formatCommand helpAddLang}}
{{else}}
Adds a new language to the site.
```json
{ "command": "addLang", "params": { "language": "xx" } }
```
{{/if}}

---

### editStructure
{{#if helpEditStructure}}
{{formatCommand helpEditStructure}}
{{else}}
Updates JSON structures (pages, components, menu, footer).
{{/if}}

---

### setTranslationKeys
{{#if helpSetTranslationKeys}}
{{formatCommand helpSetTranslationKeys}}
{{else}}
Sets translation keys for a specific language.
```json
{ "command": "setTranslationKeys", "params": { "language": "xx", "translations": {...} } }
```
{{/if}}

---

## Example: Adding {{param.languageName}} ({{param.targetLanguage}})

```json
[
  {
    "command": "addLang",
    "params": { "code": "{{param.targetLanguage}}", "name": "{{param.languageName}}" }
  },
  {
    "command": "editStructure",
    "params": {
      "type": "component",
      "name": "lang-switch",
      "structure": {
        "tag": "div",
        "params": { "class": "lang-switch" },
        "children": [
          // ... existing language links ...
          { "tag": "a", "params": { "href": "{{__current_page;lang={{param.targetLanguage}}}}", "class": "lang-link" }, "children": [{ "textKey": "__RAW__{{param.languageName}}" }] }
        ]
      }
    }
  },
  {
    "command": "setTranslationKeys",
    "params": {
      "language": "{{param.targetLanguage}}",
      "translations": {
        // Translate all keys from the source above
      }
    }
  }
]
```

---

## Your Task

Add **{{param.languageName}}** (`{{param.targetLanguage}}`) to this website. Include:
1. The `addLang` command with the new language code
2. Language switcher updates (modify the `lang-switch` component to add the new language link)
3. Complete translations matching the source structure above

Translate all text naturally and accurately for {{param.languageName}} speakers.
