For a full restyle (CSS + variables):
```json
[
  {
    "command": "editStyles",
    "params": {
      "css": ":root { ... }\nbody { font-family: var(--font-family); ... }\n.main-nav { display: flex; ... }\n..."
    }
  },
  {
    "command": "setRootVariables",
    "params": {
      "variables": {
        "--color-primary": "#2563eb",
        "--color-bg": "#0f172a",
        "--color-text": "#e2e8f0"
      }
    }
  }
]
```
