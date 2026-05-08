**Styles ordering rule (matters!):**

- `editStyles` → MUST come BEFORE `setRootVariables`
- `setRootVariables` → AFTER `editStyles` (optional helper)

**Why:** `editStyles` can reset CSS variables, so `setRootVariables` must apply after.
