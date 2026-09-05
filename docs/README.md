# QuickSite Documentation

## References

- [ARCHITECTURE.md](ARCHITECTURE.md) — Three-layer model (Project / Management / Admin), JSON-to-HTML pipeline, translation system, request lifecycle, multi-project model, security boundary, interactions, style management, build and deploy.
- [ADMIN_PANEL.md](ADMIN_PANEL.md) — Admin panel internals: boot flow, page modules, storage keys, visual editor, preview subsystem, and the `data-*` attribute reference.
- [COMMAND_API.md](COMMAND_API.md) — Management API surface: endpoint shape, response envelope, authentication, and the full command catalogue grouped by category. `GET /management/help` returns the same catalogue live, with the count this installation actually routes.
- [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) — On-disk layout.
- [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) — Workflow engine reference: JSON spec format, markdown templates, condition syntax.
- [DESIGN_DECISIONS.md](DESIGN_DECISIONS.md) — Locked design decisions: what was chosen, why, and what was rejected.

## Images

PNG assets live in [`images/`](images/). They are referenced by the QuickSite v2 template workflow (`secure/admin/workflows/core/build-quicksite-v2.json`) via raw GitHub URLs (`https://raw.githubusercontent.com/Sangiovanni/quicksite/main/docs/images/<file>.png`). If you rename or move them, update those URLs in the workflow in the same commit so the build doesn't 404.
