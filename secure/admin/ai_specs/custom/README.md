# Custom AI Specs

Place your custom AI specification files here.

## File Structure

Each custom spec requires:
1. `your-spec-name.json` - The spec definition following the schema
2. `your-spec-name.md` - The prompt template

## Example

```
custom/
├── my-custom-task.json
└── my-custom-task.md
```

## Guidelines

- Use kebab-case for filenames
- Follow the schema defined in `../schema.json`
- Test your specs before deploying

## Schema Validation

Custom specs should validate against the schema. Required fields:
- `id` (kebab-case)
- `version` (semver format: x.y.z)
- `meta` (with icon, titleKey, descriptionKey, category)
- `dataRequirements` (array of data to fetch)
- `relatedCommands` (commands the AI may use)
- `promptTemplate` (reference to .md file)
