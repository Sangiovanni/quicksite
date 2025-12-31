# Quicksite (formerly Template Vitrine)

A modern, JSON-based PHP CMS for building multilingual websites with a powerful management API. File-based storage, no database required — perfect for landing pages, portfolios, and microsites.

> **🎯 Unique Niche**: While most CMS solutions require databases, Quicksite offers a fully API-managed, file-based architecture. Build and manage simple websites fast, without the complexity.

## 📖 Origin Story

This project started as a simple HTML template. Then I kept solving the same problems over and over: managing translations, updating content across pages, deploying to different environments. 

So I asked myself: *"What if I could manage all of this through an API?"*

That question turned a template into a CMS. The file-based architecture wasn't a limitation — it was a feature. No database setup, no migrations, just files you can version control, copy, and deploy anywhere.

**The name `public_template` and `secure_template` in the codebase?** That's history. You can rename them with the built-in commands (`renamePublicFolder`, `renameSecureFolder`) to whatever fits your project — we use `public/` and `secure/` now.

## ✨ Features

### Core Capabilities
- **JSON-Driven Templates**: Define page structures, menus, and components in JSON, compiled to optimized PHP
- **Multilingual Support**: Built-in translation system with language switching and validation
- **Production Builds**: One-command deployment with compilation, optimization, and ZIP packaging
- **RESTful Management API**: 55 endpoints for complete site management
- **File-Based Storage**: No database required - all configuration in JSON/PHP files
- **Flexible Architecture**: Separate public and secure folders for clean deployment
- **🔐 API Authentication**: Bearer token authentication with role-based permissions
- **🌐 CORS Support**: Built-in cross-origin support for external UI clients (Flutter, React, etc.)

### Management Features
- **Page Management**: Add, delete, and modify routes dynamically
- **Translation Management**: Multi-language support with validation and key extraction
- **Asset Management**: Upload, organize, and manage images, fonts, videos, scripts
- **Structure Editing**: Modify page structures, menus, footers, and components via API
- **Style Management**: SCSS editing with automatic dangerous pattern blocking
- **Build System**: Create production-ready deployments with custom folder names
- **Token Management**: Generate, list, and revoke API tokens programmatically

### Security
- **Bearer Token Authentication**: All API endpoints protected by default
- **Role-Based Permissions**: Granular access control (read/write/admin/command-specific)
- **Path Validation**: Comprehensive protection against path traversal attacks
- **File Locking**: Prevents concurrent operations and race conditions
- **MIME Type Validation**: Verifies actual file content, not just extensions
- **Input Sanitization**: All parameters validated with strict rules
- **Config Sanitization**: Database credentials automatically removed from builds

## 📋 Requirements

- **PHP**: 7.4 or higher (tested up to 8.4)
- **Apache**: mod_rewrite enabled
- **Extensions**: json, fileinfo, zip
- **Permissions**: Write access to public/secure folders

## 🚀 Quick Start

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/Sangiovanni/template_vitrinne.git
   cd template_vitrinne
   ```

2. **Configure Apache DocumentRoot**
   Point your virtual host to the `public/` folder:
   ```apache
   <VirtualHost *:80>
       ServerName yoursite.local
       DocumentRoot "C:/path/to/quicksite/public"
       
       <Directory "C:/path/to/quicksite/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

3. **Set Permissions**
   ```bash
   # Linux/Mac
   chmod -R 755 public app
   
   # Windows: Ensure IIS_IUSRS or Apache user has read/write access
   ```

4. **Configure Database** (if needed)
   Edit `secure/config.php` with your database credentials.

5. **Access Your Site**
   - **Public Site**: `http://yoursite.local/`
   - **Management API**: `http://yoursite.local/management/`

### ⚠️ First-Time Setup: Authentication

The API is protected by default. Before using any endpoint:

1. **Find the default token** in `secure/config/auth.php`:
   ```php
   'tvt_dev_default_change_me_in_production' => [...]
   ```

2. **Generate a new secure token** (recommended):
   ```bash
   curl -X POST http://yoursite.local/management/generateToken \
     -H "Authorization: Bearer tvt_dev_default_change_me_in_production" \
     -H "Content-Type: application/json" \
     -d '{"name": "My Admin Token", "permissions": ["*"]}'
   ```

3. **Save your new token** - it won't be shown again!

4. **Revoke the default token** (important for security):
   ```bash
   curl -X POST http://yoursite.local/management/revokeToken \
     -H "Authorization: Bearer your_new_token_here" \
     -H "Content-Type: application/json" \
     -d '{"token_preview": "tvt_dev_...tion"}'
   ```

### Basic Usage

#### Get Available Commands
```bash
curl -H "Authorization: Bearer your_token" \
  http://yoursite.local/management/help
```

#### Add a New Page
```bash
curl -X POST http://yoursite.local/management/addRoute \
  -H "Authorization: Bearer your_token" \
  -H "Content-Type: application/json" \
  -d '{"route": "about", "title": {"en": "About Us", "fr": "À Propos"}}'
```

#### Build for Production
```bash
curl -X POST http://yoursite.local/management/build \
  -H "Authorization: Bearer your_token" \
  -H "Content-Type: application/json" \
  -d '{"public": "public", "secure": "app"}'
```

## 📚 Documentation

### Authentication

All API endpoints require authentication via Bearer token:

```bash
curl -H "Authorization: Bearer your_token_here" \
  http://yoursite.local/management/help
```

**Permission Levels:**
| Permission | Access |
|------------|--------|
| `*` | Full access to all commands |
| `read` | Read-only: get*, list*, validate*, help |
| `write` | Modifications: edit*, add*, delete*, upload* |
| `admin` | Administrative: set*, rename*, build, tokens |
| `command:name` | Specific command only (e.g., `command:build`) |

**CORS Support:**
- Development mode automatically allows `localhost:*` origins
- Configure allowed origins in `secure/config/auth.php`
- Supports preflight OPTIONS requests

### API Endpoints

#### **Authentication & Tokens**
- `POST /management/generateToken` - Create new API token (admin)
- `GET /management/listTokens` - List all tokens with masked values (read)
- `POST /management/revokeToken` - Delete a token (admin)

#### **Folder Management**
- `POST /management/setPublicSpace` - Set URL space/prefix for public site
- `POST /management/renameSecureFolder` - Rename secure/backend folder
- `POST /management/renamePublicFolder` - Rename public folder

#### **Page Management**
- `POST /management/addRoute` - Create new page route
- `POST /management/deleteRoute` - Remove page route
- `GET /management/getRoutes` - List all routes

#### **Structure Management**
- `GET /management/getStructure/{type}/{name?}/{option?}` - Get page/menu/footer/component JSON
  - Options: `showIds` (add node identifiers), `summary` (tree overview), `{nodeId}` (specific node)
- `POST /management/editStructure` - Update structure JSON (full replacement or targeted nodeId edit)

#### **Translation Management**
- `GET /management/getTranslation/{lang}` - Get language translations
- `GET /management/getTranslations` - Get all translations
- `POST /management/setTranslationKeys` - Add/update translation keys (safe merge)
- `POST /management/deleteTranslationKeys` - Remove translation keys
- `GET /management/getTranslationKeys` - Extract all required translation keys
- `GET /management/validateTranslations/{lang?}` - Validate translation completeness
- `GET /management/getUnusedTranslationKeys/{lang?}` - Find orphaned keys not used in structures
- `GET /management/analyzeTranslations/{lang?}` - Full health check (missing + unused + recommendations)
- `GET /management/getLangList` - List supported languages
- `POST /management/setMultilingual` - Switch between multilingual and mono-language modes
- `POST /management/addLang` - Add new language (multilingual mode only)
- `DELETE /management/deleteLang` - Delete language (multilingual mode only)
- `PATCH /management/setDefaultLang` - Set default language (multilingual mode only)

#### **Asset Management**
- `POST /management/uploadAsset` - Upload file to assets (with optional description)
- `POST /management/deleteAsset` - Delete asset file
- `GET /management/listAssets/{category?}` - List assets by category (includes metadata)
- `POST /management/updateAssetMeta` - Update asset metadata (description, alt text)

#### **Customization**
- `POST /management/editFavicon` - Update favicon from assets
- `POST /management/editTitle` - Update page title for specific language

#### **CSS/Style Management**
- `GET /management/getStyles` - Get CSS stylesheet content
- `POST /management/editStyles` - Update CSS (with backup)
- `GET /management/getRootVariables` - Get CSS custom properties from :root
- `POST /management/setRootVariables` - Add/update CSS variables in :root
- `GET /management/listStyleRules` - List all CSS selectors
- `GET /management/getStyleRule/{selector}/{mediaQuery?}` - Get styles for specific selector
- `POST /management/setStyleRule` - Add/update CSS rule
- `POST /management/deleteStyleRule` - Remove CSS rule
- `GET /management/getKeyframes` - Get all @keyframes animations
- `POST /management/setKeyframes` - Add/update @keyframes animation
- `POST /management/deleteKeyframes` - Remove @keyframes animation

#### **Structure Discovery**
- `GET /management/listComponents` - List all available components
- `GET /management/listPages` - List all pages with metadata

#### **URL Aliases**
- `GET /management/listAliases` - List all URL aliases
- `POST /management/createAlias` - Create route alias
- `POST /management/deleteAlias` - Remove route alias

#### **Build & Deploy**
- `POST /management/build` - Create production build with optional folder renaming
- `GET /management/listBuilds` - List all available builds with metadata
- `GET /management/getBuild/{name}` - Get detailed info for specific build
- `POST /management/deleteBuild` - Delete a specific build (folder + ZIP)
- `POST /management/cleanBuilds` - Bulk delete builds older than timestamp
- `POST /management/deployBuild` - Deploy build to production paths
- `GET /management/downloadBuild/{name}` - Get download URL for build ZIP

#### **Project Management**
- `GET /management/listProjects` - List all projects with metadata (routes, pages, languages, size)
- `GET /management/getActiveProject` - Get current active project info
- `PATCH /management/switchProject` - Switch to different project (copies public files)
- `POST /management/createProject` - Create new project from template
- `DELETE /management/deleteProject` - Delete project (requires confirmation)
- `GET /management/exportProject` - Export project as downloadable ZIP
- `POST /management/importProject` - Import project from uploaded ZIP
- `GET /management/downloadExport` - Download previously exported ZIP

#### **Command History**
- `GET /management/getCommandHistory` - Get command execution history with filtering
- `POST /management/clearCommandHistory` - Delete old command logs

#### **Documentation**
- `GET /management/help/{command?}` - API documentation (all commands or specific)

For complete API documentation with parameters, validation rules, and examples:
```bash
curl http://yoursite.local/management/help
```

### File Structure

The QuickSite Template uses a **multi-project architecture** that separates the core system from individual projects:

```
quicksite/                    # Root installation
├── public/                   # Public web root (shared entry point)
│   ├── index.php            # Main entry point - loads active project
│   ├── init.php             # Bootstrap - defines paths & loads target.php
│   ├── .htaccess            # URL rewriting rules
│   ├── admin/               # Admin panel interface
│   └── build/               # Built/compiled output (generated)
│
├── secure/                  # Backend (core system + projects)
│   ├── admin/               # Admin panel backend
│   │   ├── ai_specs/        # AI specification files for commands
│   │   ├── functions/       # Admin helper functions
│   │   └── templates/       # Admin panel templates
│   │
│   ├── config/              # Global configuration
│   │   └── target.php       # Active project selector
│   │
│   ├── exports/             # Project export files (.zip)
│   ├── logs/                # System logs
│   │
│   ├── management/          # Core Management API (shared)
│   │   ├── command/         # API command handlers (63+ commands)
│   │   ├── classes/         # ApiResponse, CssParser, JsonCompiler, etc.
│   │   ├── functions/       # PathManagement, AuthManagement, etc.
│   │   └── routes.php       # Management route definitions
│   │
│   ├── projects/            # Individual project data
│   │   ├── quicksite/       # Example project (default)
│   │   │   ├── config/      # Project-specific config (auth.php, aliases.json)
│   │   │   ├── data/        # Config data (aliases.json, assets_metadata.json)
│   │   │   ├── templates/   # Page templates & JSON structures
│   │   │   │   ├── model/   # Component templates (json/, php/, css/)
│   │   │   │   └── pages/   # Page JSON definitions
│   │   │   ├── translate/   # Translation files (default.json, en.json, fr.json)
│   │   │   └── routes.php   # Public route definitions
│   │   │
│   │   └── [other-project]/ # Additional projects follow same structure
│   │
│   └── src/                 # Shared utilities (legacy location)
│
├── docs/                    # Documentation & notebooks
├── tests/                   # Test suite (gitignored in production)
├── LICENSE                  # MIT License
├── NOTES.txt                # Development notes & future ideas
└── README.md               # This file
```

**Key Concepts:**
- **Active Project**: Defined in `secure/config/target.php` - determines which project is loaded
- **Shared Core**: The `secure/management/` directory contains the API that works with any project
- **Project Isolation**: Each project in `secure/projects/` has its own templates, translations, and routes
- **Export/Import**: Projects can be exported as `.zip` files and imported to other installations

## 🔧 Advanced Usage

### Custom Folder Structure

Use `setPublicSpace` to create subdirectories for multi-site hosting:

```bash
# Move all public files into "web" subdirectory
curl -X POST http://yoursite.local/management/setPublicSpace \
  -H "Content-Type: application/json" \
  -d '{"destination": "web"}'

# Site now accessible at: http://yoursite.local/web/
# Management at: http://yoursite.local/web/management/

# Restore to root with empty destination
curl -X POST http://yoursite.local/web/management/setPublicSpace \
  -H "Content-Type: application/json" \
  -d '{"destination": ""}'
```

### Production Builds

The build system creates optimized deployments:

```bash
curl -X POST http://yoursite.local/management/build \
  -H "Content-Type: application/json" \
  -d '{
    "public": "www/public",
    "secure": "app",
    "space": "site"
  }'
```

This creates:
- **Compiled PHP**: JSON templates converted to optimized PHP files
- **Clean Structure**: Management system and development files removed
- **Sanitized Config**: Database credentials stripped from config
- **ZIP Archive**: Complete deployment package
- **Custom Names**: Folders renamed as specified

The `space` parameter creates a subdirectory inside the public folder, useful for shared hosting where you need specific URL paths.

### Translation Workflow

1. **Analyze Translation Health** (recommended starting point)
   ```bash
   curl http://yoursite.local/management/analyzeTranslations
   # Returns: missing keys, unused keys, health_status, recommendations
   ```

2. **Find Missing Translations Only**
   ```bash
   curl http://yoursite.local/management/validateTranslations/fr
   ```

3. **Find Unused/Orphaned Keys**
   ```bash
   curl http://yoursite.local/management/getUnusedTranslationKeys
   ```

4. **Add Missing Translations** (safe merge - won't delete existing)
   ```bash
   curl -X POST http://yoursite.local/management/setTranslationKeys \
     -H "Content-Type: application/json" \
     -d '{
       "language": "fr",
       "translations": {
         "menu": {"newpage": "Nouvelle Page"},
         "footer": {"copyright": "Tous droits réservés"}
       }
     }'
   ```

5. **Clean Up Unused Keys** (optional)
   ```bash
   curl -X POST http://yoursite.local/management/deleteTranslationKeys \
     -H "Content-Type: application/json" \
     -d '{
       "language": "fr",
       "keys": ["old.unused.key", "legacy.section.title"]
     }'
   ```

## 🏗️ Architecture

### JSON to PHP Compilation

Page structures are defined in JSON and compiled to PHP for optimal performance:

**JSON Structure** (`secure/templates/pages/home.json`):
```json
{
  "tag": "div",
  "class": "container",
  "children": [
    {
      "tag": "h1",
      "textKey": "home.title"
    },
    {
      "tag": "p",
      "textKey": "home.welcomeMessage"
    }
  ]
}
```

**Compiled PHP** (build output):
```php
echo '<div class="container">';
echo '<h1>' . htmlspecialchars($translator->translate('home.title')) . '</h1>';
echo '<p>' . htmlspecialchars($translator->translate('home.welcomeMessage')) . '</p>';
echo '</div>';
```

### System Variables

The compiler generates helper variables:
- `$__lang` - Current language code
- `$__current_page` - Current route (cleaned)
- `$__base_url` - Site base URL
- `processUrl($url, $lang)` - URL processor with language prefix
- `buildLanguageSwitchUrl($targetLang)` - Language switcher URLs

## 🔐 Security Features

- **Path Traversal Protection**: All path parameters validated against directory traversal
- **File Type Validation**: MIME type checking, not just extension
- **Concurrent Operation Safety**: File locking prevents race conditions
- **Input Validation**: Strict validation rules for all parameters (max length, depth, allowed chars)
- **Config Sanitization**: Credentials removed from production builds
- **SCSS Safety**: Dangerous patterns blocked in style editing

## 🗺️ Roadmap & Vision

Template Vitrine follows a **file-based, zero-database philosophy** - but that doesn't mean it can't grow!

### Current Version (v1.5.0)
- ✅ Complete file-based CMS with JSON templates
- ✅ RESTful API with 55 commands
- ✅ Bearer token authentication with RBAC
- ✅ CORS support for external UIs
- ✅ Production build system
- ✅ Comprehensive Guides page with practical tutorials
- ✅ **Mono-Language Mode**: Switch between multilingual and single-language sites with `setMultilingual`
- ✅ **Structure Audit**: `checkStructureMulti` command to find lang-specific content before switching modes
- ✅ **Build Management**: List, get, delete, clean, deploy, and download builds via API
- ✅ **Command Logging**: Audit trail with getCommandHistory and clearCommandHistory

### Upcoming Releases
| Version | Feature | Description |
|---------|---------|-------------|
| **v1.6** | Admin Panel | Built-in API Explorer with command selector, dynamic forms, and persistent auth |

### Future Vision
| Feature | Status | Description |
|---------|--------|-------------|
| **Flutter UI** | 🔜 Planned | Cross-platform admin interface with drag-and-drop editing |
| **Database Module** | 💡 Considering | Optional SQLite/MySQL module for dynamic content |
| **User System** | 💡 Future | Multi-user authentication (building on current token system) |
| **Plugin System** | 💡 Future | Extensible architecture for custom commands |

### Philosophy
> Keep the core **file-based and simple**. Database features will be **optional modules**, never requirements. This keeps Template Vitrine perfect for its niche: fast, portable, database-free websites.

**Want to contribute to the roadmap?** Open an issue with your ideas!

## 🤝 Contributing

Contributions are welcome! This project is open source under the MIT License.

### Review Policy

All contributions go through code review to ensure:
- **Security**: No vulnerabilities introduced
- **Quality**: Clean, readable, well-documented code
- **Consistency**: Follows existing patterns and conventions
- **Testing**: New features include appropriate tests

### How to Contribute

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

**First-time contributors welcome!** Look for issues labeled `good first issue`.

## 📝 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

**TL;DR**: Free to use for any purpose (personal, commercial, etc.), just keep the copyright notice. No warranty provided.

## 🙏 Acknowledgments

This project was built using a **hybrid human-AI development approach**:

| Aspect | Approach |
|--------|----------|
| **Architecture & Design** | Human-designed system architecture and feature requirements |
| **Implementation** | Developed with assistance from GitHub Copilot (Claude) |
| **Testing & Validation** | Comprehensive human-led testing and quality assurance |
| **Code Review** | All AI-generated code reviewed and validated by humans |

The combination of human creativity and AI-assisted coding enabled rapid development while maintaining code quality, security, and maintainability.

**Why mention this?** Transparency matters. AI-assisted development is a powerful tool when combined with human oversight and expertise. Every line of code in this project has been reviewed, tested, and validated.

**Development philosophy:**
- 🔒 Security-first design
- 🛠️ Developer experience
- 🚀 Deployment flexibility
- 📁 Zero-database architecture

## 📞 Support

For questions, issues, or feature requests:
- **Issues**: [GitHub Issues](https://github.com/Sangiovanni/quicksite/issues)
- **Documentation**: See `/management/help` endpoint for complete API reference
- **Security Docs**: See [docs/](docs/) folder for security improvements and best practices

## ☕ Support the Project

If this project saves you time or helps your business, consider supporting its development:

<a href="https://buymeacoffee.com/sangio" target="_blank">
  <img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy Me A Coffee" height="40">
</a>

Your support helps fund:
- 🔧 Ongoing maintenance and bug fixes
- 🔐 Security updates and audits
- ✨ New features (Flutter UI is next!)
- 📚 Better documentation

## 📚 Additional Documentation

- **[docs/README.md](docs/README.md)** - Documentation overview and reading guide
- **[docs/COMMAND_API_DOCUMENTATION.md](docs/COMMAND_API_DOCUMENTATION.md)** - Complete API reference
- **[docs/ADDROUTE_SECURITY_IMPROVEMENTS.md](docs/ADDROUTE_SECURITY_IMPROVEMENTS.md)** - Security fixes in route management
- **[docs/TRANSLATION_SECURITY_IMPROVEMENTS.md](docs/TRANSLATION_SECURITY_IMPROVEMENTS.md)** - Security fixes in translation system

---

**Made with ❤️ by [Sangio](https://github.com/Sangiovanni) for the open source community**
