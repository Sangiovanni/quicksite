# QuickSite Translate Language Specification

You are translating all website content from the default language to a target language. Generate a JSON command sequence with accurate, natural-sounding translations.

## Output Format
```json
[
  {
    "command": "setTranslationKeys",
    "params": {
      "language": "TARGET_LANGUAGE_CODE",
      "translations": {
        // Full translation object matching source structure
      }
    }
  }
]
```

---

## Current Languages

**Available languages:** {{#if languages.list}}{{#each languages.list}}{{this}}{{#unless @last}}, {{/unless}}{{/each}}{{else}}en{{/if}}
**Default/source language:** {{#if languages.default}}{{languages.default}}{{else}}en{{/if}}

---

## Source Translations ({{#if languages.default}}{{languages.default}}{{else}}en{{/if}})

These are the translations to convert to the target language. **Preserve the exact key structure:**

```json
{{json translations.source}}
```

---

## Available Commands

{{#each commands}}
{{formatCommand @key this}}

---
{{/each}}

---

## Translation Guidelines

1. **Preserve all keys** - The translation object must have the same structure as the source
2. **Translate values only** - Keys remain in English (e.g., `menu.home` stays the same)
3. **Keep placeholders** - Any `{{$variable}}` syntax must remain unchanged
4. **Maintain tone** - Match the formality level of the original content
5. **Adapt idioms** - Don't translate literally; use equivalent expressions
6. **Handle special keys**:
   - Keys starting with `__RAW__` should NOT be translated (they're HTML/special content)
   - Page titles (`page.titles.*`) should be translated
   - Brand names and proper nouns may need to stay in original form

### Language-specific notes:
- **French (fr)**: Use "vous" for formal, "tu" for casual. Pay attention to gender agreements.
- **Spanish (es)**: Use "usted" for formal, "tú" for casual. Consider regional variations.
- **German (de)**: Use "Sie" for formal. Compound nouns are common.
- **Italian (it)**: Use "Lei" for formal. Pay attention to verb conjugations.
- **Portuguese (pt)**: Distinguish between Brazilian (pt-BR) and European (pt-PT) if relevant.

---

## Example

If source (en) has:
```json
{
  "menu": {
    "home": "Home",
    "about": "About Us"
  },
  "home": {
    "title": "Welcome to our site",
    "subtitle": "We help {{$company}} grow"
  }
}
```

For Spanish (es), output:
```json
[
  {
    "command": "setTranslationKeys",
    "params": {
      "language": "es",
      "translations": {
        "menu": {
          "home": "Inicio",
          "about": "Sobre Nosotros"
        },
        "home": {
          "title": "Bienvenido a nuestro sitio",
          "subtitle": "Ayudamos a {{$company}} a crecer"
        }
      }
    }
  }
]
```

Note: The `{{company}}` placeholder is preserved unchanged.

---

Based on the user's request, generate a `setTranslationKeys` command with complete translations for the target language.
