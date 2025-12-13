# Documentation

Technical documentation for the **Quicksite** project (formerly Template Vitrine).

## 📚 Contents

### API Reference
- **[COMMAND_API_DOCUMENTATION.md](COMMAND_API_DOCUMENTATION.md)** - Complete API reference
  - All 45 management commands
  - Parameters, validation rules, examples
  - Error codes and responses
  - Authentication & CORS configuration

### Security Documentation

These documents serve as an **audit trail** — showing what vulnerabilities were identified, how they were fixed, and why certain patterns exist in the codebase.

- **[ADDROUTE_SECURITY_IMPROVEMENTS.md](ADDROUTE_SECURITY_IMPROVEMENTS.md)** - Security fixes in route management
  - Type validation
  - Length validation
  - Safe routes file generation with `var_export()`
  
- **[TRANSLATION_SECURITY_IMPROVEMENTS.md](TRANSLATION_SECURITY_IMPROVEMENTS.md)** - Critical security fixes in translation commands
  - Path traversal vulnerability fixes
  - Proper depth validation for nested objects
  - Memory exhaustion protection
  - Type and format validation

## 🔐 Why These Documents Exist

| Purpose | Value |
|---------|-------|
| **Audit Trail** | Shows what vulnerabilities were identified and fixed |
| **Educational** | Demonstrates proper security practices for PHP APIs |
| **Context** | Explains why certain validation patterns exist |
| **Transparency** | Open source projects benefit from security diligence |
| **Compliance** | Useful for security reviews and audits |

## 📖 Reading Order

**For new contributors:**
1. Read the main [README.md](../README.md) for project overview
2. Explore [COMMAND_API_DOCUMENTATION.md](COMMAND_API_DOCUMENTATION.md) to understand the API
3. Review security docs to understand validation patterns
4. Use `/management/help` endpoint for always-up-to-date API reference

**For security reviewers:**
1. Start with security improvement documents
2. Cross-reference with actual code in `secure/management/command/`
3. Check `secure/src/functions/` for validation utilities

## 🤝 Contributing to Documentation

When adding or updating docs:
1. Keep security docs as audit trails (don't delete history)
2. Update COMMAND_API_DOCUMENTATION.md when adding new endpoints
3. Document attack vectors being prevented in security fixes
4. Use clear before/after code examples

---

*Documentation is part of the codebase. Treat it with the same care.*

**Note**: These documents reflect the security-first approach taken throughout this project.
