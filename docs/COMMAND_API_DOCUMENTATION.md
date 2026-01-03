# Quicksite - Command API Documentation

> ⚠️ **NOTICE (January 2025)**: This static documentation is **OUTDATED**. The API now has **72 commands** (this doc only covers ~20 legacy commands).
>
> **Key changes since this doc was written:**
> - `movePublicRoot` → `setPublicSpace`
> - `moveSecureRoot` → `renameSecureFolder`  
> - `changeFavicon` → `editFavicon`
> - `modifyTitle` → `editTitle`
> - `removeLang` → `deleteLang`
> - `editTranslation` → `setTranslationKeys` (merged with delete)
> - **New commands**: `renamePublicFolder`, `getSiteMap`, `checkStructureMulti`, `backupProject`, `listBackups`, `restoreBackup`, `deleteBackup`, `getSizeInfo`, `clearExports`, and many more
>
> **👉 For accurate, up-to-date documentation, use the live API:**
> ```bash
> curl -H "Authorization: Bearer your_token" http://yoursite.local/management/help
> ```
> The `/management/help` endpoint provides real-time documentation with all current command names, parameters, examples, and validation rules.

Complete reference for all management commands with parameters, validation rules, and responses.

## Authentication

All endpoints require Bearer token authentication:

```bash
curl -H "Authorization: Bearer your_token_here" \
  http://yoursite.local/management/help
```

See the main [README.md](../README.md) for authentication setup instructions.

## Table of Contents (Legacy Names)

1. [movePublicRoot](#1-movepublicroot)
2. [moveSecureRoot](#2-movesecureroot)
3. [addRoute](#3-addroute)
4. [deleteRoute](#4-deleteroute)
5. [build](#5-build)
6. [changeFavicon](#6-changefavicon)
7. [modifyTitle](#7-modifytitle)
8. [getRoutes](#8-getroutes)
9. [getStructure](#9-getstructure)
10. [editStructure](#10-editstructure)
11. [getTranslation](#11-gettranslation)
12. [editTranslation](#12-edittranslation)
13. [getLangList](#13-getlanglist)
14. [addLang](#14-addlang)
15. [removeLang](#15-removelang)
16. [uploadAsset](#16-uploadasset)
17. [deleteAsset](#17-deleteasset)
18. [listAssets](#18-listassets)
19. [getStyles](#19-getstyles)
20. [editStyles](#20-editstyles)

---

## 1. movePublicRoot

**Description:** Moves the public template folder to a new location, supporting nested directory structures up to 5 levels deep.

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| destination | true | string | Relative path for new public folder location (can be empty for root) | `"app/v1/public"` or `""` | Max 255 chars, max 5 levels deep, alphanumeric/hyphen/underscore/slash only, empty allowed |

**Validation Rules:**
- Type must be string
- Maximum length: 255 characters
- Maximum depth: 5 directory levels (e.g., `app/v1/api/public/assets`)
- Allowed characters: a-z, A-Z, 0-9, hyphen, underscore, forward slash
- Empty string is allowed (moves to web root)
- Path is normalized and validated with `is_valid_relative_path()`

**Success Response (200):**
```json
{
  "status": 200,
  "code": "operation.success",
  "message": "Public root successfully moved",
  "data": {
    "old_path": "/path/to/old",
    "new_path": "/path/to/new",
    "destination": "app/public",
    "init_file_updated": "/path/to/init.php"
  }
}
```

**Error Responses:**
- `400 validation.required` - destination parameter missing
- `400 validation.invalid_type` - destination not a string
- `400 validation.invalid_format` - Invalid path format or exceeds depth limit
- `409 conflict.operation_in_progress` - Another move operation in progress (file lock)
- `500 server.internal_error` - Source folder doesn't exist
- `500 server.file_write_failed` - Failed to copy/write files

**Important Notes:**
- Uses file locking to prevent concurrent operations
- Automatically creates/updates .htaccess files with correct FallbackResource
- Updates PUBLIC_FOLDER_SPACE constant in init.php
- Cleans up empty source directories after successful move
- Sends response and exits immediately to prevent path issues

---

## 2. moveSecureRoot

**Description:** Moves the secure template folder to a new location. Restricted to single folder name (no nesting).

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| destination | true | string | Single folder name for new secure folder location | `"backend"` | Max 255 chars, single level only, alphanumeric/hyphen/underscore, cannot be empty |

**Validation Rules:**
- Type must be string
- Maximum length: 255 characters
- Maximum depth: 1 (single folder name only, no subdirectories)
- Allowed characters: a-z, A-Z, 0-9, hyphen, underscore
- Empty string NOT allowed
- No forward slashes allowed (enforced by max_depth=1)

**Success Response (200):**
```json
{
  "status": 200,
  "code": "operation.success",
  "message": "Secure root successfully moved",
  "data": {
    "old_path": "/path/to/old",
    "new_path": "/path/to/new",
    "old_name": "secure_template",
    "new_name": "backend",
    "init_file_updated": "/path/to/init.php"
  }
}
```

**Error Responses:**
- `400 validation.missing_field` - destination parameter missing
- `400 validation.invalid_type` - destination not a string
- `400 validation.invalid_format` - Empty string or nested path (exceeds depth limit)
- `409 conflict.operation_in_progress` - Another move operation in progress
- `409 conflict.duplicate` - Folder with this name already exists
- `500 server.internal_error` - Source folder doesn't exist
- `500 server.file_write_failed` - Failed to move/write files

**Important Notes:**
- Uses file locking to prevent concurrent operations
- Updates SECURE_FOLDER_NAME constant in public init.php
- Restricted to single level for init.php path resolution compatibility
- Cleans up empty source directories after successful move
- Sends response and exits immediately to prevent path issues

---

## 3. addRoute

**Description:** Creates a new route with associated PHP template and JSON structure files.

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| route | true | string | Route name (URL slug) | `"about-us"` | 1-100 chars, lowercase letters/numbers/hyphens only |

**Validation Rules:**
- Type must be string
- Length: 1-100 characters
- Format: lowercase letters, numbers, and hyphens only (validated by `is_valid_route_name()`)
- Pattern: `/^[a-z0-9-]+$/`
- Cannot already exist in ROUTES array

**Success Response (201):**
```json
{
  "status": 201,
  "code": "route.created",
  "message": "Route 'about-us' successfully created and registered",
  "data": {
    "route": "about-us",
    "php_file": "/path/to/templates/pages/about-us.php",
    "json_file": "/path/to/templates/model/json/pages/about-us.json",
    "routes_updated": "/path/to/routes.php"
  }
}
```

**Error Responses:**
- `400 validation.required` - route parameter missing
- `400 validation.invalid_type` - route not a string
- `400 validation.invalid_length` - route length invalid (not 1-100 chars)
- `400 route.invalid_name` - Invalid route name format
- `400 route.already_exists` - Route already exists
- `500 server.directory_create_failed` - Failed to create directories
- `500 server.file_write_failed` - Failed to write PHP/JSON files or update routes.php

**Important Notes:**
- Creates both PHP template and JSON structure files
- Automatically registers route in routes.php
- Uses `var_export()` for safe array generation
- Invalidates opcache for routes.php
- Performs cleanup (deletes created files) if any step fails

---

## 4. deleteRoute

**Description:** Deletes an existing route and its associated files.

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| route | true | string | Route name to delete | `"about-us"` | 1-100 chars, lowercase letters/numbers/hyphens only |

**Validation Rules:**
- Type must be string
- Length: 1-100 characters
- Format: lowercase letters, numbers, and hyphens only
- Must exist in ROUTES array

**Success Response (200):**
```json
{
  "status": 200,
  "code": "route.deleted",
  "message": "Route 'about-us' successfully deleted",
  "data": {
    "route": "about-us",
    "deleted_files": {
      "php": "/path/to/pages/about-us.php",
      "json": null
    },
    "routes_updated": "/path/to/routes.php"
  }
}
```

**Error Responses:**
- `400 validation.required` - route parameter missing
- `400 validation.invalid_type` - route not a string
- `400 validation.invalid_length` - route length invalid
- `400 route.invalid_name` - Invalid route name format
- `404 route.not_found` - Route doesn't exist
- `404 file.not_found` - PHP template file or routes.php not found
- `500 server.file_write_failed` - Failed to delete file or update routes.php

**Important Notes:**
- Deletes both PHP template and JSON structure files
- Updates routes.php to remove route
- JSON deletion is non-fatal (logs warning if fails)
- Invalidates opcache for routes.php
- Array is re-indexed after removal

---

## 5. build

**Description:** Creates production-ready deployment with compiled pages, sanitized config, and optional folder renaming.

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| public | false | string | Custom public folder name/path | `"www/v1/public"` | Max 255 chars, max 5 levels deep, alphanumeric/hyphen/underscore/slash |
| secure | false | string | Custom secure folder name | `"backend"` | Max 255 chars, single level only, alphanumeric/hyphen/underscore |
| space | false | string | URL path prefix (subdirectory inside public) | `"app"` or `""` | Max 255 chars, max 5 levels, alphanumeric/hyphen/underscore/slash, empty allowed |

**Validation Rules:**
- All parameters are optional (defaults to current folder names)
- **public**: Max 5 levels deep, cannot be empty if provided
- **secure**: Max 1 level (single folder name), cannot be empty if provided
- **space**: Max 5 levels, can be empty (creates subdirectory for public files)
- **Security**: public and secure cannot share same root directory
- Build size must not exceed MAX_BUILD_SIZE_MB (default 500MB)

**Success Response (201):**
```json
{
  "status": 201,
  "code": "operation.success",
  "message": "Production build completed successfully",
  "data": {
    "build_path": "/path/to/build_20231208_143022",
    "zip_path": "/path/to/build_20231208_143022.zip",
    "zip_filename": "build_20231208_143022.zip",
    "zip_size_mb": 2.45,
    "original_size_mb": 8.12,
    "compression_ratio": "69.8%",
    "compiled_pages": ["home", "about", "contact", "404"],
    "total_pages": 4,
    "public_folder_name": "www",
    "secure_folder_name": "backend",
    "public_folder_space": "app",
    "config_sanitized": true,
    "menu_compiled": true,
    "footer_compiled": true,
    "build_date": "2023-12-08 14:30:22",
    "readme_created": true,
    "download_url": "http://site.com/build/build_20231208_143022.zip"
  }
}
```

**Error Responses:**
- `400 validation.invalid_type` - Parameter not a string
- `400 validation.invalid_format` - Invalid path format or depth
- `400 validation.shared_parent_folder` - Public and secure share same root directory
- `413 validation.size_limit_exceeded` - Build exceeds MAX_BUILD_SIZE_MB
- `409 conflict.operation_in_progress` - Another build in progress
- `500 server.internal_error` - Source folders don't exist
- `500 server.directory_create_failed` - Failed to create build directories
- `500 server.file_write_failed` - Failed to copy/compile files

**Important Notes:**
- Uses file locking to prevent concurrent builds
- Compiles all JSON structures to PHP for performance
- Sanitizes config.php (removes DB credentials)
- Creates timestamped build folder
- Generates ZIP archive with compression
- Updates .htaccess files with correct paths
- Updates init.php constants if folder names changed
- Creates README.txt with deployment instructions
- Validates build size before creating ZIP

---

## 6. changeFavicon

**Description:** Updates the site favicon from an uploaded image in assets/images.

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| imageName | true | string | Name of image file in assets/images | `"logo-icon.png"` | Max 100 chars, PNG format only, alphanumeric/hyphen/underscore |

**Validation Rules:**
- Type must be string
- Maximum length: 100 characters
- Format: alphanumeric, hyphens, underscores, must end with .png
- Pattern: `/^[a-z0-9_-]+\.png$/i`
- Extension must be .png (lowercase check)
- File must exist in /assets/images/
- File must be valid PNG image (verified with getimagesize)
- Must be actual PNG format, not just .png extension

**Success Response (200):**
```json
{
  "status": 200,
  "code": "success.favicon_updated",
  "message": "Favicon updated successfully",
  "data": {
    "new_favicon": "favicon.png",
    "source_image": "logo-icon.png",
    "backup_created": "favicon_backup_20231208_143022.png",
    "favicon_url": "/assets/images/favicon.png"
  }
}
```

**Error Responses:**
- `400 validation.missing_field` - imageName parameter missing
- `400 validation.invalid_type` - imageName not a string
- `400 validation.invalid_length` - Filename exceeds 100 characters
- `400 validation.invalid_format` - Invalid filename format or not .png extension
- `400 validation.invalid_file` - File is not a valid image
- `404 file.not_found` - Image not found in assets/images
- `500 server.file_operation_failed` - Failed to backup or update favicon

**Important Notes:**
- Only PNG format supported
- Creates timestamped backup of existing favicon
- Uses basename() to prevent path traversal
- Validates image format with getimagesize()
- Restores backup if copy fails

---

## 7. modifyTitle

**Description:** Updates the page title for a specific route in a specific language.

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| route | true | string | Route name | `"about-us"` | Max 100 chars, alphanumeric/hyphen/underscore, must exist |
| lang | true | string | Language code | `"en"` or `"en-US"` | Max 10 chars, ISO 639/BCP 47 format, must be supported |
| title | true | string | New page title | `"About Our Company"` | Max 200 chars, non-empty |

**Validation Rules:**
- **route**: Alphanumeric/hyphen/underscore, max 100 chars, must exist in ROUTES
- **lang**: ISO 639 (2-3 letters) or BCP 47 format (e.g., en-US), max 10 chars, must be in LANGUAGES_SUPPORTED
- **title**: String, 1-200 characters
- No path traversal characters allowed in route or lang

**Success Response (200):**
```json
{
  "status": 200,
  "code": "success.title_updated",
  "message": "Page title updated successfully",
  "data": {
    "route": "about-us",
    "language": "en",
    "title": "About Our Company",
    "translation_key": "page.titles.about-us"
  }
}
```

**Error Responses:**
- `400 validation.missing_field` - Required parameter missing
- `400 validation.invalid_type` - Parameter not a string
- `400 validation.invalid_format` - Invalid format or path traversal attempt
- `400 validation.invalid_length` - Parameter too long
- `400 validation.unsupported_language` - Language not supported
- `404 validation.invalid_route` - Route doesn't exist
- `404 file.not_found` - Translation file not found
- `500 server.file_read_failed` - Failed to read translation file
- `500 server.invalid_json` - Translation file has invalid JSON
- `500 server.file_write_failed` - Failed to write translation file

**Important Notes:**
- Updates page.titles.{route} key in translation file
- Creates page.titles structure if it doesn't exist
- Uses JSON_PRETTY_PRINT for readable output
- Validates route exists before updating
- Checks for path traversal in both route and lang

---

## 8. getRoutes

**Description:** Retrieves the list of all registered routes.

**HTTP Method:** GET

**Parameters:** None

**Success Response (200):**
```json
{
  "status": 200,
  "code": "operation.success",
  "message": "Routes retrieved successfully",
  "data": {
    "routes": ["home", "about", "contact", "services"],
    "count": 4
  }
}
```

**Error Responses:** None (always succeeds if system is properly configured)

**Important Notes:**
- No authentication required (read-only operation)
- Returns routes from ROUTES constant
- Simple endpoint with no validation needed

---

## 9. getStructure

**Description:** Retrieves JSON structure for menu, footer, page, or component.

**HTTP Method:** GET

**URL Format:** 
- Menu/Footer: `GET /management/getStructure/{type}`
- Page/Component: `GET /management/getStructure/{type}/{name}`

**Parameters (URL segments):**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| type | true | string | Structure type | `"menu"`, `"footer"`, `"page"`, `"component"` | Must be one of allowed types |
| name | conditional | string | Page/component name (required for page/component types) | `"about-us"` | Max 100 chars, alphanumeric/hyphen/underscore |

**Validation Rules:**
- **type**: Must be one of: menu, footer, page, component
- **name**: Required for page/component types, alphanumeric/hyphen/underscore, max 100 chars
- **name**: No path traversal characters (../, \, null byte)
- **page**: Name must exist in ROUTES array
- **component**: No existence check (can retrieve any component)

**Success Response (200):**
```json
{
  "status": 200,
  "code": "operation.success",
  "message": "Structure retrieved successfully",
  "data": {
    "type": "page",
    "name": "about-us",
    "structure": [
      {
        "element": "section",
        "children": ["..."]
      }
    ],
    "file": "/path/to/json/pages/about-us.json"
  }
}
```

**Error Responses:**
- `400 validation.required` - type parameter missing from URL
- `400 validation.invalid_type` - Parameter not a string
- `400 validation.invalid_length` - Parameter too long
- `400 validation.invalid_value` - Invalid type value
- `400 validation.invalid_format` - Invalid name format or path traversal
- `404 route.not_found` - Page doesn't exist (for type=page)
- `404 file.not_found` - Structure file not found
- `500 server.file_write_failed` - Failed to read file
- `500 server.internal_error` - Invalid JSON in file

**Important Notes:**
- Uses URL segments instead of query parameters
- Validates page existence only for type=page
- Components can be retrieved even if they don't exist yet
- Returns full JSON structure

---

## 10. editStructure

**Description:** Updates or creates JSON structure for menu, footer, page, or component.

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| type | true | string | Structure type | `"menu"`, `"footer"`, `"page"`, `"component"` | Must be one of allowed types |
| name | conditional | string | Page/component name (required for page/component) | `"about-us"` | Max 100 chars, alphanumeric/hyphen/underscore |
| structure | true | array/object | JSON structure data | `[{...}]` or `{...}` | Max 10,000 nodes, max 50 levels deep |

**Validation Rules:**
- **type**: Must be one of: menu, footer, page, component
- **name**: Required for page/component, max 100 chars, no path traversal
- **structure**: Must be array or object
- **structure size**: Max 10,000 nodes (prevents memory exhaustion)
- **structure depth**: Max 50 levels (prevents stack overflow)
- **page**: Name must exist in ROUTES (for type=page)
- **component**: Can create new components (no existence check)
- **component deletion**: Empty array for component deletes the file

**Success Response (200):**
```json
{
  "status": 200,
  "code": "operation.success",
  "message": "Structure updated successfully",
  "data": {
    "type": "page",
    "name": "about-us",
    "file": "/path/to/json/pages/about-us.json",
    "structure_size": 15,
    "node_count": 42,
    "created": false
  }
}
```

**Error Responses:**
- `400 validation.required` - Required parameter missing
- `400 validation.invalid_type` - Parameter type mismatch
- `400 validation.invalid_length` - Parameter too long
- `400 validation.invalid_value` - Invalid type value
- `400 validation.invalid_format` - Invalid format, path traversal, or structure too large/deep
- `404 route.not_found` - Page doesn't exist (for type=page)
- `404 file.not_found` - Structure file not found (except for new components)
- `500 server.directory_create_failed` - Failed to create components directory
- `500 server.internal_error` - Failed to encode JSON
- `500 server.file_write_failed` - Failed to write file
- `500 server.file_delete_failed` - Failed to delete component

**Important Notes:**
- Uses JSON_PRETTY_PRINT for readable output
- Empty array for component triggers deletion
- Creates components directory if needed
- Validates structure depth and size for security
- Uses utility functions: `countNodes()`, `validateStructureDepth()`

---

## 11. getTranslation

**Description:** Retrieves all translations for a specific language.

**HTTP Method:** GET

**URL Format:** `GET /management/getTranslation/{lang}`

**Parameters (URL segment):**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| lang | true | string | Language code | `"en"` or `"en-US"` | Max 10 chars, ISO 639/BCP 47 format |

**Validation Rules:**
- Type must be string
- Maximum length: 10 characters
- Format: ISO 639 (2-3 lowercase letters) or BCP 47 (e.g., en-US, zh-Hans)
- Pattern: `/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/`
- No path traversal characters allowed

**Success Response (200):**
```json
{
  "status": 200,
  "code": "operation.success",
  "message": "Translation retrieved successfully",
  "data": {
    "language": "en",
    "translations": {
      "page": {
        "titles": {
          "home": "Home",
          "about": "About Us"
        }
      },
      "common": {
        "welcome": "Welcome"
      }
    },
    "file": "/path/to/translate/en.json"
  }
}
```

**Error Responses:**
- `400 validation.required` - Language parameter missing from URL
- `400 validation.invalid_type` - Parameter not a string
- `400 validation.invalid_length` - Language code too long
- `400 validation.invalid_format` - Invalid format or path traversal attempt
- `404 file.not_found` - Translation file not found
- `500 server.file_write_failed` - Failed to read file
- `500 server.internal_error` - Invalid JSON in file

**Important Notes:**
- Uses URL segment instead of query parameter
- Supports both ISO 639 (en, fr) and BCP 47 (en-US, zh-Hans) formats
- Path traversal check happens before other validations
- Returns complete translation structure

---

## 12. editTranslation

**Description:** Updates all translations for a specific language.

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| language | true | string | Language code | `"en"` or `"en-US"` | Max 10 chars, ISO 639/BCP 47 format |
| translations | true | object/array | Complete translation structure | `{...}` | Max 5MB, max 20 levels deep |

**Validation Rules:**
- **language**: ISO 639/BCP 47 format, max 10 chars, no path traversal
- **translations**: Must be array/object
- **translations size**: Max 5MB (prevents huge payloads)
- **translations depth**: Max 20 levels (prevents deeply nested structures)

**Success Response (200):**
```json
{
  "status": 200,
  "code": "operation.success",
  "message": "Translations updated successfully",
  "data": {
    "language": "en",
    "file": "/path/to/translate/en.json"
  }
}
```

**Error Responses:**
- `400 validation.required` - Required parameter missing
- `400 validation.invalid_type` - Parameter type mismatch
- `400 validation.invalid_length` - Language code too long or data too large
- `400 validation.invalid_format` - Invalid format, path traversal, or structure too deep
- `500 server.internal_error` - Failed to encode JSON
- `500 server.file_write_failed` - Failed to write file

**Important Notes:**
- Uses JSON_PRETTY_PRINT and JSON_UNESCAPED_UNICODE
- Validates structure depth with `validateNestedDepth()`
- Size limit prevents memory exhaustion
- Creates file if it doesn't exist
- Uses LOCK_EX for atomic writes

---

## 13. getLangList

**Description:** Retrieves list of supported languages and multilingual configuration.

**HTTP Method:** GET

**Parameters:** None

**Success Response (200):**
```json
{
  "status": 200,
  "code": "operation.success",
  "message": "Language list retrieved successfully",
  "data": {
    "multilingual_enabled": true,
    "languages": ["en", "fr", "es"],
    "default_language": "en",
    "language_names": {
      "en": "English",
      "fr": "Français",
      "es": "Español"
    }
  }
}
```

**Error Responses:**
- `500 server.internal_error` - Configuration not loaded

**Important Notes:**
- No authentication required (read-only)
- Returns data from CONFIG constant
- Simple endpoint with no parameters

---

## 14. addLang

**Description:** Adds a new supported language with translation file.

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| code | true | string | Language code | `"es"` | 2-3 lowercase letters |
| name | true | string | Language display name | `"Español"` | 1-100 chars, letters/spaces/hyphens/apostrophes |

**Validation Rules:**
- **code**: Must be 2-3 lowercase letters only
- **code pattern**: `/^[a-z]{2,3}$/`
- **name**: 1-100 characters, letters, spaces, hyphens, apostrophes only
- **name pattern**: `/^[a-zA-Z\s\-']+$/`
- Language code must not already exist

**Success Response (201):**
```json
{
  "status": 201,
  "code": "operation.success",
  "message": "Language added successfully",
  "data": {
    "code": "es",
    "name": "Español",
    "config_updated": "/path/to/config.php",
    "translation_file": "/path/to/translate/es.json",
    "copied_from": "en"
  }
}
```

**Error Responses:**
- `400 validation.required` - Required parameter missing
- `400 validation.invalid_format` - Invalid format or type
- `409 conflict.duplicate` - Language already exists
- `500 file.not_found` - Config file not found
- `500 server.internal_error` - Failed to parse config
- `500 server.file_write_failed` - Failed to write config or translation file

**Important Notes:**
- Updates config.php with new language
- Creates translation file by copying default language
- Uses `var_export()` for safe config generation
- Invalidates opcache after config update
- Falls back to empty translations if default language missing

---

## 15. removeLang

**Description:** Removes a language from supported languages and deletes translation file.

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| code | true | string | Language code to remove | `"es"` | 2-3 lowercase letters, must exist, cannot be default |

**Validation Rules:**
- **code**: Must be 2-3 lowercase letters
- **code pattern**: `/^[a-z]{2,3}$/`
- Language must exist in LANGUAGES_SUPPORTED
- Cannot remove default language
- Cannot remove last remaining language

**Success Response (200):**
```json
{
  "status": 200,
  "code": "operation.success",
  "message": "Language removed successfully",
  "data": {
    "code": "es",
    "config_updated": "/path/to/config.php",
    "translation_file_deleted": true,
    "remaining_languages": ["en", "fr"]
  }
}
```

**Error Responses:**
- `400 validation.required` - code parameter missing
- `400 validation.invalid_format` - Invalid format or is default/last language
- `404 route.not_found` - Language not found
- `500 server.internal_error` - Failed to parse config
- `500 server.file_write_failed` - Failed to delete file or write config

**Important Notes:**
- Deletes translation file before updating config (safer order)
- Uses `var_export()` for safe config generation
- Invalidates opcache after config update
- Re-indexes language array after removal
- Prevents removing default or last language

---

## 16. uploadAsset

**Description:** Uploads files to asset categories with category-specific validation.

**HTTP Method:** POST (multipart/form-data)

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| category | true | string (form field) | Asset category | `"images"` | Must be in whitelist |
| file | true | file upload | File to upload | (binary) | Size, MIME type, extension validated per category |

**Validation Rules:**

**Category:**
- Must be one of: images, scripts, font, audio, videos
- Whitelist loaded from `assetCategories.php`

**File Size Limits:**
- images: 5MB
- scripts: 1MB
- font: 2MB
- audio: 10MB
- videos: 50MB

**Allowed MIME Types:**
- images: image/jpeg, image/png, image/gif, image/webp, image/svg+xml
- scripts: application/javascript, text/javascript, text/plain
- font: font/ttf, font/otf, font/woff, font/woff2, application/octet-stream
- audio: audio/mpeg, audio/mp3, audio/wav, audio/ogg
- videos: video/mp4, video/webm, video/ogg

**Allowed Extensions:**
- images: jpg, jpeg, png, gif, webp, svg
- scripts: js
- font: ttf, otf, woff, woff2
- audio: mp3, wav, ogg
- videos: mp4, webm, ogv

**Filename:**
- Max 100 characters (including extension)
- Sanitized to alphanumeric, hyphens, underscores only
- Dangerous extensions blocked: php, phtml, php3, php4, php5, phar, exe, sh, bat, cmd, com, htaccess
- Auto-generates unique name if file exists (appends _1, _2, etc.)

**Success Response (201):**
```json
{
  "status": 201,
  "code": "operation.success",
  "message": "File uploaded successfully",
  "data": {
    "filename": "logo.png",
    "category": "images",
    "path": "/assets/images/logo.png",
    "size": 45678,
    "mime_type": "image/png"
  }
}
```

**Error Responses:**
- `400 validation.missing_field` - category or file missing
- `400 validation.invalid_type` - category not a string
- `400 validation.invalid_length` - Category or filename too long
- `400 validation.invalid_value` - Invalid category
- `400 validation.invalid_file` - Invalid/missing filename or not uploaded file
- `400 validation.invalid_extension` - File extension not allowed
- `400 validation.invalid_mime_type` - MIME type not allowed
- `400 validation.forbidden_extension` - Dangerous file type
- `400 asset.upload_failed` - PHP upload error (with specific message)
- `400 asset.invalid_upload` - File not uploaded via HTTP POST
- `400 asset.file_too_large` - Exceeds category size limit
- `500 server.directory_not_found` - Target directory doesn't exist
- `500 server.permission_denied` - Target directory not writable
- `500 server.file_move_failed` - Failed to move uploaded file
- `500 server.file_verification_failed` - File not found after move
- `500 server.file_corrupted` - Size mismatch after upload
- `500 server.too_many_duplicates` - Unable to generate unique filename

**Important Notes:**
- Uses `finfo_file()` for server-side MIME detection (not client-provided)
- Uses `is_uploaded_file()` and `move_uploaded_file()` for security
- Sanitizes filename to prevent path traversal
- Blocks executable extensions regardless of category
- Verifies file size after upload (corruption check)
- Auto-generates unique filenames (max 1000 attempts)
- Deletes file if corruption detected

---

## 17. deleteAsset

**Description:** Deletes a file from an asset category.

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| category | true | string | Asset category | `"images"` | Must be in whitelist |
| filename | true | string | Filename to delete | `"logo.png"` | Max 100 chars, alphanumeric/hyphen/underscore/dot |

**Validation Rules:**
- **category**: Must be one of: images, scripts, font, audio, videos
- **filename**: Max 100 chars, no path traversal characters
- **filename format**: Alphanumeric, hyphens, underscores, and dot for extension
- **filename pattern**: `/^[a-zA-Z0-9_-]+\.[a-zA-Z0-9]+$/`
- File must exist and be a regular file (not directory)

**Success Response (204):**
```json
{
  "status": 204,
  "code": "operation.success",
  "message": "File deleted successfully",
  "data": {
    "filename": "logo.png",
    "category": "images"
  }
}
```

**Error Responses:**
- `400 validation.missing_field` - Required parameter missing
- `400 validation.invalid_type` - Parameter not a string
- `400 validation.invalid_length` - Parameter too long
- `400 validation.invalid_value` - Invalid category
- `400 validation.invalid_format` - Invalid filename format or path traversal
- `400 asset.invalid_filename` - Path is not a file
- `404 asset.not_found` - File not found
- `500 asset.delete_failed` - Failed to delete file

**Important Notes:**
- Uses basename() to sanitize filename
- Validates filename is not empty after sanitization
- Checks for path traversal before and after sanitization
- Validates file exists and is regular file
- Returns 204 (No Content) on success per REST conventions

---

## 18. listAssets

**Description:** Lists all files in one or all asset categories.

**HTTP Method:** GET or POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| category | false | string | Asset category (optional, lists all if omitted) | `"images"` | Must be in whitelist if provided |

**Validation Rules:**
- **category**: Optional parameter
- If provided, must be one of: images, scripts, font, audio, videos
- Type must be string if provided

**Success Response (200):**
```json
{
  "status": 200,
  "code": "operation.success",
  "message": "All assets retrieved",
  "data": {
    "assets": {
      "images": [
        {
          "filename": "logo.png",
          "size": 45678,
          "modified": "2023-12-08 14:30:22",
          "path": "/assets/images/logo.png"
        }
      ],
      "scripts": [
        {
          "filename": "app.js",
          "size": 12345,
          "modified": "2023-12-07 10:15:30",
          "path": "/assets/scripts/app.js"
        }
      ]
    },
    "total_categories": 2,
    "total_files": 2
  }
}
```

**Error Responses:**
- `400 validation.invalid_type` - category not a string
- `400 validation.invalid_length` - category too long
- `400 validation.invalid_value` - Invalid category

**Important Notes:**
- Works with GET, POST, or JSON parameters
- If no category specified, lists all categories
- Excludes directories and index.php files
- Sorts files by filename
- Returns empty array for categories with no files
- Skips non-existent category directories

---

## 19. getStyles

**Description:** Retrieves the content of the main CSS stylesheet.

**HTTP Method:** GET

**Parameters:** None

**Success Response (200):**
```json
{
  "status": 200,
  "code": "operation.success",
  "message": "Style file retrieved successfully",
  "data": {
    "content": "/* SCSS content here */\nbody { margin: 0; }",
    "file": "/path/to/style/style.css",
    "size": 1234,
    "modified": "2023-12-08 14:30:22"
  }
}
```

**Error Responses:**
- `404 file.not_found` - Style file not found
- `500 server.file_write_failed` - Failed to read file

**Important Notes:**
- Returns raw SCSS content
- Includes file metadata (size, modified date)
- Simple read-only operation
- No authentication required

---

## 20. editStyles

**Description:** Updates the main CSS stylesheet content.

**HTTP Method:** POST

**Parameters:**

| Name | Required | Type | Description | Example | Validation |
|------|----------|------|-------------|---------|------------|
| content | true | string | New SCSS content | `"body { margin: 0; }"` | Max 2MB, cannot be empty, no dangerous CSS patterns |

**Validation Rules:**
- Type must be string
- Cannot be empty
- Maximum size: 2MB
- **Dangerous patterns blocked**:
  - `javascript:` - JavaScript protocol
  - `expression(` - CSS expression (IE)
  - `behavior:` - CSS behavior (IE)
  - `vbscript:` - VBScript protocol
  - `-moz-binding:` - XBL binding (Firefox)
  - `@import url("/` - Absolute path import
  - `data:text/html` - Data URI with HTML

**Success Response (200):**
```json
{
  "status": 200,
  "code": "operation.success",
  "message": "Style file updated successfully",
  "data": {
    "file": "/path/to/style/style.css",
    "new_size": 1500,
    "old_size": 1234,
    "backup_content": "/* old content */",
    "modified": "2023-12-08 14:35:00"
  }
}
```

**Error Responses:**
- `400 validation.required` - content parameter missing
- `400 validation.invalid_type` - content not a string
- `400 validation.invalid_length` - Content empty or exceeds 2MB
- `400 validation.invalid_format` - Contains dangerous CSS pattern
- `404 file.not_found` - Style file not found
- `500 server.file_write_failed` - Failed to read or write file

**Important Notes:**
- Uses LOCK_EX for atomic writes
- Returns old content for manual rollback
- Validates dangerous CSS patterns (basic protection)
- SCSS is compiled server-side (not user-facing)
- Size limit prevents resource exhaustion

---

## Common Error Response Structure

All error responses follow this structure:

```json
{
  "status": 400,
  "code": "validation.invalid_type",
  "message": "The route parameter must be a string.",
  "errors": [
    {
      "field": "route",
      "reason": "invalid_type",
      "expected": "string"
    }
  ],
  "data": {
    "additional": "context"
  }
}
```

## Common Status Codes

- `200 OK` - Successful operation
- `201 Created` - Resource created successfully
- `204 No Content` - Successful deletion
- `400 Bad Request` - Validation error
- `404 Not Found` - Resource not found
- `409 Conflict` - Resource conflict (duplicate, operation in progress)
- `413 Payload Too Large` - Build/upload exceeds size limit
- `500 Internal Server Error` - Server-side error

## Common Response Codes

### Validation
- `validation.required` - Missing required parameter
- `validation.invalid_type` - Wrong parameter type
- `validation.invalid_format` - Invalid format/pattern
- `validation.invalid_length` - Length constraint violated
- `validation.invalid_value` - Value not in allowed set
- `validation.size_limit_exceeded` - Size limit exceeded
- `validation.missing_field` - Required field missing
- `validation.unsupported_language` - Language not supported

### Operations
- `operation.success` - Operation completed successfully
- `operation.in_progress` - Another operation in progress

### Conflicts
- `conflict.duplicate` - Resource already exists
- `conflict.operation_in_progress` - Concurrent operation prevented by file lock

### Routes
- `route.created` - Route created
- `route.deleted` - Route deleted
- `route.not_found` - Route doesn't exist
- `route.already_exists` - Route already exists
- `route.invalid_name` - Invalid route name format

### Files
- `file.not_found` - File not found
- `server.file_write_failed` - File write failed
- `server.file_read_failed` - File read failed
- `server.file_operation_failed` - File operation failed
- `server.file_delete_failed` - File deletion failed
- `server.file_move_failed` - File move failed
- `server.file_corrupted` - File corruption detected
- `server.file_verification_failed` - File verification failed

### Assets
- `asset.upload_failed` - Upload failed
- `asset.invalid_upload` - Invalid upload
- `asset.file_too_large` - File too large
- `asset.not_found` - Asset not found
- `asset.delete_failed` - Deletion failed
- `asset.invalid_filename` - Invalid filename

### Server
- `server.internal_error` - Internal server error
- `server.directory_create_failed` - Directory creation failed
- `server.directory_not_found` - Directory not found
- `server.permission_denied` - Permission denied
- `server.invalid_json` - Invalid JSON
- `server.too_many_duplicates` - Too many duplicate files

## Security Features

### Path Traversal Protection
- All file path parameters validated with `basename()`
- Explicit checks for `..`, `/`, `\`, and null bytes
- Path validation before file operations

### File Upload Security
- Server-side MIME type detection (not client-provided)
- Extension whitelist per category
- Dangerous extensions blocked globally
- File size limits per category
- Post-upload verification (size check)
- `is_uploaded_file()` validation

### Input Validation
- Type checking for all parameters
- Length limits on all string inputs
- Format validation with regex patterns
- Whitelist validation for categories/types

### Concurrency Protection
- File locking for critical operations (build, movePublicRoot, moveSecureRoot)
- Prevents race conditions and concurrent modifications
- Lock timeout and cleanup

### Injection Prevention
- `var_export()` for PHP config generation
- JSON encoding with proper flags
- SQL-free architecture (file-based storage)
- Sanitized filenames (alphanumeric only)

### Structure Validation
- Node count limits (max 10,000 for structures)
- Depth limits (max 50 for structures, max 20 for translations)
- Size limits (2MB for styles, 5MB for translations, 500MB for builds)

### Configuration Security
- Database credentials stripped in builds
- Opcache invalidation after config updates
- Atomic file writes with LOCK_EX
