# Custom Workflows

Place your custom workflow files here.

## Workflow Types

### AI Workflows (with promptTemplate)
Require:
1. `your-workflow-name.json` - The workflow definition
2. `your-workflow-name.md` - The prompt template for AI

### Manual Workflows (with steps)
Require:
1. `your-workflow-name.json` - The workflow definition with steps array

## Example Structure

```
custom/
├── my-ai-workflow.json       # AI workflow
├── my-ai-workflow.md         # Prompt template
└── my-manual-workflow.json   # Manual workflow (no .md needed)
```

## Guidelines

- Use kebab-case for filenames
- Follow the schema defined in `../schema.json`
- Test your workflows before deploying

## Schema Validation

Workflows should validate against the schema. Required fields:
- `id` (kebab-case)
- `version` (semver format: x.y.z)
- `meta` (with icon, titleKey, descriptionKey, category)

For AI workflows add:
- `promptTemplate` (reference to .md file)
- `dataRequirements` (array of data to fetch)
- `relatedCommands` (commands the AI may use)

For manual workflows add:
- `steps` (array of commands to execute)
