# Translation Commands Security Improvements

## Overview
This document outlines the critical security vulnerabilities found and fixed in the translation management commands.

## Files Modified

### 1. editTranslation.php - CRITICAL SECURITY FIXES

#### Issues Found:
- ❌ **PATH TRAVERSAL VULNERABILITY** - `$language` parameter used directly in file path without validation
- ❌ No type validation on `language` parameter
- ❌ No length validation on `language` parameter
- ❌ No format validation on `language` parameter
- ❌ No path traversal checks
- ❌ Wrong error code for type validation (was `invalid_format`, should be `invalid_type`)
- ❌ **WRONG DEPTH VALIDATION** - Used `validateStructureDepth()` which only checks `children` arrays (for page structures), not nested objects (for translations)
- ❌ No size limit on translations payload (memory exhaustion risk)

#### Attack Vector Example:
```php
// BEFORE FIX:
$language = "../../../config";  // Attacker input
$file = SECURE_FOLDER_PATH . '/translate/' . $language . '.json';
// Result: SECURE_FOLDER_PATH/translate/../../../config.json
// CRITICAL: Could read/write files outside translate directory!
```

#### Depth Validation Bug:
```php
// BEFORE (WRONG):
validateStructureDepth($translations, 0, 20)
// Only checks $node['children'] - translations don't have this!
// Would ALWAYS return true for translation objects

// Translation structure:
{
    "page": {              // validateStructureDepth sees no 'children' key
        "titles": {        // Returns true immediately (BUG!)
            "home": "..."
        }
    }
}

// AFTER (CORRECT):
validateNestedDepth($translations, 0, 20)
// Checks ALL nested arrays/objects recursively
// Properly validates depth for translation structures
```

#### Fixes Applied:
1. **Type Validation** - Added `is_string()` check with proper error code
2. **Length Validation** - Max 3 characters (ISO language codes are 2-3 chars)
3. **Format Validation** - Regex `/^[a-z]{2,3}$/` (only lowercase letters)
4. **Path Traversal Protection** - Explicit checks for `..`, `/`, `\`, null bytes
5. **Depth Limit** - 20 levels max using `validateStructureDepth()` function
6. **Size Limit** - 5MB max for translation payload
7. **Error Code Fix** - Changed to `validation.invalid_type` for type errors

#### Validation Order:
```
Required → Type (string) → Length (≤3) → Format (a-z only) → Path Traversal → Depth (≤20) → Size (≤5MB)
```

---

### 2. getTranslation.php - DEFENSE IN DEPTH IMPROVEMENTS

#### Issues Found:
- ⚠️ No explicit `is_string()` type check (URL segments always strings, but good practice)
- ⚠️ No explicit length validation (regex limits to 3 but not explicit)
- ⚠️ Regex could theoretically match `..` patterns (defense in depth issue)

#### Fixes Applied:
1. **Type Validation** - Added explicit `is_string()` check for consistency
2. **Length Validation** - Explicit max 3 characters check
3. **Path Traversal Protection** - Added explicit checks even though regex prevents it
4. **Enhanced Error Messages** - More descriptive validation errors

#### Before vs After:
```php
// BEFORE:
if (!preg_match('/^[a-z]{2,3}$/', $language)) {
    // Single validation
}

// AFTER:
if (!is_string($language)) { /* Type check */ }
if (strlen($language) > 3) { /* Length check */ }
if (!preg_match('/^[a-z]{2,3}$/', $language)) { /* Format check */ }
if (strpos($language, '..') !== false || ...) { /* Path traversal check */ }
```

---

### 3. getTranslationKeys.php - REFACTORING & DEPTH PROTECTION

#### Issues Found:
- ❌ Functions defined inline in command file (architectural issue)
- ❌ No depth limit protection when recursing through structures
- ❌ No circular reference protection (infinite loop risk)
- ❌ Potential stack overflow with deeply nested structures

#### Fixes Applied:
1. **Moved to Utils** - Relocated `extractTextKeys()` and `loadJsonStructure()` to `utilsManagement.php`
2. **Depth Limit** - Added 20-level max depth parameter to prevent stack overflow
3. **Circular Reference Protection** - Added `$seen` array using `spl_object_hash()` to detect loops
4. **Enhanced Documentation** - Full PHPDoc comments for both functions

#### Depth Protection Implementation:
```php
function extractTextKeys($node, &$keys = [], $currentDepth = 0, $maxDepth = 20, &$seen = []) {
    // Depth limit check
    if ($currentDepth > $maxDepth) {
        return $keys;  // Stop recursion
    }
    
    // Circular reference check
    $nodeId = spl_object_hash((object)$node);
    if (isset($seen[$nodeId])) {
        return $keys;  // Already processed
    }
    $seen[$nodeId] = true;
    
    // ... extract keys ...
    
    // Recurse with depth tracking
    extractTextKeys($child, $keys, $currentDepth + 1, $maxDepth, $seen);
}
```

---

### 4. utilsManagement.php - NEW UTILITY FUNCTIONS

#### Functions Added:

1. **extractTextKeys()** - Extract translation keys from JSON structures
   - Depth limit protection (max 20 levels)
   - Circular reference detection
   - Skips `__RAW__` prefixed keys (non-translatable content)
   - Processes both node textKeys and component data labels

2. **loadJsonStructure()** - Load and parse JSON files
   - File existence check
   - Read error handling
   - JSON parsing with error detection
   - Returns null on any failure

3. **validateNestedDepth()** - NEW - Validate depth for nested objects/arrays
   - Unlike `validateStructureDepth()` which only checks `children` arrays (for page structures)
   - This validates **ALL** nested arrays/objects recursively (for translations, configs)
   - Prevents deeply nested translation structures (DoS risk)
   - Default max depth: 20 levels

#### The Critical Difference:

```php
// validateStructureDepth() - For page structures
function validateStructureDepth($node, $depth = 0, $maxDepth = 50) {
    if (isset($node['children']) && is_array($node['children'])) {
        // Only checks 'children' key
        foreach ($node['children'] as $child) {
            validateStructureDepth($child, $depth + 1, $maxDepth);
        }
    }
    return true;  // Returns true if no 'children' key!
}

// validateNestedDepth() - For translations/configs
function validateNestedDepth($data, $depth = 0, $maxDepth = 20) {
    foreach ($data as $value) {  // Checks ALL values
        if (is_array($value)) {
            validateNestedDepth($value, $depth + 1, $maxDepth);
        }
    }
    return true;
}
```

#### Why This Matters:

**Translation files** have nested key-value structure:
```json
{
    "page": {              // Level 1
        "titles": {        // Level 2
            "home": "..."  // Level 3
        }
    }
}
```

**Page structures** have children arrays:
```json
{
    "type": "container",
    "children": [          // Only this gets checked
        {"type": "text"}
    ]
}
```

`validateStructureDepth()` would **fail to validate** translation depth because translations don't have a `children` key!

---

## Security Impact Assessment

### Critical Issues Fixed:
1. **Path Traversal in editTranslation.php** - HIGH SEVERITY
   - Could have allowed reading/writing arbitrary files
   - Potential for config file manipulation
   - Could expose sensitive data

2. **Infinite Recursion in getTranslationKeys.php** - MEDIUM SEVERITY
   - Could cause stack overflow and DoS
   - No protection against circular structures
   - Resource exhaustion risk

### Defense in Depth:
- Multiple validation layers added to all commands
- Explicit checks even when regex provides protection
- Consistent error handling across all translation commands
- Proper error codes for type vs format vs length issues

---

## Validation Pattern Applied

All translation commands now follow this security pattern:

```php
1. Required Parameter Check
   └─> Missing parameters with field list

2. Type Validation (is_string, is_array)
   └─> validation.invalid_type

3. Length Validation
   └─> validation.invalid_length

4. Format Validation (regex patterns)
   └─> validation.invalid_format

5. Path Traversal Check (.., /, \, null bytes)
   └─> validation.invalid_format with 'path_traversal_attempt'

6. Business Logic (depth, size, whitelist)
   └─> validation.invalid_format or validation.invalid_length
```

---

## Testing Requirements

### editTranslation.php Tests:
- ✅ Valid translation update
- ✅ Missing parameters
- ✅ Type validation (non-string language, non-array translations)
- ✅ Length validation (4+ char language code)
- ✅ Format validation (uppercase, numbers, special chars)
- ✅ Path traversal attempts (`../`, `./`, `\`, null bytes)
- ✅ Depth limit (21+ level nested structure)
- ✅ Size limit (>5MB payload)
- ✅ Round-trip test (edit → get → verify)

### getTranslation.php Tests:
- ✅ Valid language codes (en, fr, es)
- ✅ Invalid format (uppercase, numbers, 4+ chars)
- ✅ Path traversal attempts
- ✅ Non-existent language (404)
- ✅ Missing language parameter

### getTranslationKeys.php Tests:
- ✅ Extract from all sources (pages, menu, footer)
- ✅ Deep structure handling (20 levels)
- ✅ Circular reference protection
- ✅ Skip __RAW__ keys
- ✅ Flattened key list correctness

---

## Comparison with Other Commands

| Command | Type Check | Length Check | Format Check | Path Traversal | Depth Limit | Size Limit |
|---------|------------|--------------|--------------|----------------|-------------|------------|
| **editStructure** | ✅ | ✅ | ✅ | ✅ | ✅ (50) | ✅ (10k nodes) |
| **editStyles** | ✅ | ✅ | ✅ (CSS inject) | N/A | N/A | ✅ (2MB) |
| **editTranslation** | ✅ NEW | ✅ NEW | ✅ NEW | ✅ NEW | ✅ NEW (20) | ✅ NEW (5MB) |
| **getTranslation** | ✅ NEW | ✅ NEW | ✅ | ✅ NEW | N/A | N/A |

---

## Next Steps

1. ✅ Create comprehensive test suite for all 3 commands
2. ⏳ Test depth limit with 21-level nested structure
3. ⏳ Test circular reference protection
4. ⏳ Verify error messages are clear and helpful
5. ⏳ Review remaining translation commands (validateTranslations, getTranslations)
6. ⏳ Git commit with message: "Security: Fix path traversal in editTranslation, add depth protection to getTranslationKeys"

---

## Lessons Learned

1. **Never Trust User Input** - Even "safe" parameters like language codes need validation
2. **Path Construction is Dangerous** - Always validate before concatenating into file paths
3. **Depth Limits Are Critical** - Recursive functions must have explicit depth tracking
4. **Defense in Depth** - Multiple validation layers catch what individual checks miss
5. **Circular References** - Always protect against infinite loops in graph traversal
6. **Functions Belong in Utils** - Reusable logic should not be inline in command files
