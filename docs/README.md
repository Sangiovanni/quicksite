# QuickSite Documentation

_Last updated: 2026-04-23._

## References

- [ARCHITECTURE.md](ARCHITECTURE.md) — Three-layer model (Project / Management / Admin), JSON-to-HTML pipeline, request lifecycle, multi-project model, security boundary.
- [ADMIN_PANEL.md](ADMIN_PANEL.md) — Admin panel internals: boot flow, page modules, visual editor, preview subsystem.
- [COMMAND_API.md](COMMAND_API.md) — Management API surface and command catalogue (122 commands).
- [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) — On-disk layout.
- [WORKFLOW_SYSTEM.md](WORKFLOW_SYSTEM.md) — Workflow engine reference: JSON spec format, markdown templates, condition syntax, creating custom workflows.

## Images

PNG assets live in [`images/`](images/). They are referenced by the QuickSite v2 template workflow (`secure/admin/workflows/core/build-quicksite-v2.json`) via raw GitHub URLs (`https://raw.githubusercontent.com/Sangiovanni/quicksite/main/docs/images/<file>.png`). If you rename or move them, update those URLs in the workflow in the same commit so the build doesn't 404.
