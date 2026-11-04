# QuickSite Translate Site Specification

You are translating every text of an existing website from `{{param.from}}` into `{{param.language}}` (language codes as ISO 639-1).

{{> output-json-only}}

---

## The texts to translate

These are the site's texts in `{{param.from}}`, by group (the menu, the footer, the page titles, each page…):

```json
{{json sourceTexts}}
```

---

## How to answer

- **One `setTranslationKeys` command per top-level group** of the texts above (`menu`, `footer`, `page`, `404`, each page's group…), each with `"language": "{{param.language}}"` and that group alone under `translations`. Never `replace`: the groups are merged into the language's texts.
- **Keep every key exactly** — the same names, the same nesting. Translate the values only.
- Keep unchanged anything that is not prose: `{{…}}` placeholders, URLs, e-mail addresses, phone numbers, prices, HTML entities, emoji, and brand or product names (unless a brand is commonly written differently in `{{param.language}}`).
- Translate naturally for a website (menus short, buttons as actions), not word for word.
- If a user request follows, it describes the site: use it for context and tone only — translate, do not rewrite.

---

## Available Command

{{> command.setTranslationKeys}}

---

## Example Output

Texts in `en` with the groups `menu`, `page` and `home`, translated into `fr`:

```json
[
  {
    "command": "setTranslationKeys",
    "params": { "language": "fr", "translations": { "menu": { "home": "Accueil", "contact": "Contact" } } }
  },
  {
    "command": "setTranslationKeys",
    "params": { "language": "fr", "translations": { "page": { "titles": { "home": "Accueil | Ma Boulangerie" } } } }
  },
  {
    "command": "setTranslationKeys",
    "params": { "language": "fr", "translations": { "home": { "hero": { "title": "Du pain frais chaque matin", "cta": "Commander" } } } }
  }
]
```

---

## 🚀 NOW TRANSLATE

Translate every group above into `{{param.language}}`, one `setTranslationKeys` per group, in the order shown.

**⚠️ Your entire response must be a JSON array like the example above. No text before or after the JSON.**
