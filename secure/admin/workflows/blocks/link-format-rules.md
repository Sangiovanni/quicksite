## Link Format Rules

**⚠️ CRITICAL: Links must follow these formats:**

### Internal Links (Routes)
Use simple paths matching route names. **NO query strings (?), parameters (&=), or fragments (#) for page navigation.**

| Route Name | Link href |
|------------|----------|
| home | `/` or `/home` |
| about | `/about` |
| services | `/services` |
| docs/api | `/docs/api` |

```json
{ "tag": "a", "params": { "href": "/about" }, "children": [{ "textKey": "menu.about" }] }
```

For in-page anchors on a single page (e.g. landing pages), `href="#section-id"` is allowed.

### External Links
For links outside the site, use full URLs with `target="_blank"`:

```json
{ "tag": "a", "params": { "href": "https://github.com/user/repo", "target": "_blank" }, "children": [{ "textKey": "footer.github" }] }
```

**❌ NEVER use:** `href="?page=about"`, `href="index.php?route=x"`, `href="page.html"`
**✅ ALWAYS use:** `href="/route-name"` for pages, `href="#section"` for in-page anchors, `href="https://..."` with `target="_blank"` for external
