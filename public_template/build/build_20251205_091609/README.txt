=======================================================
PRODUCTION BUILD - DEPLOYMENT INSTRUCTIONS
=======================================================

This build was generated on: 2025-12-05 09:16:09

FOLDER STRUCTURE:
- public_template/  -> Deploy to your web root (public_html, www, etc.)
- secure_template/  -> Deploy OUTSIDE web root (one level up from public)

DEPLOYMENT STEPS:

1. Configure Database (REQUIRED):
   Edit secure_template/config.php and set:
   - DB_HOST (e.g., 'localhost')
   - DB_NAME (your database name)
   - DB_USER (database username)
   - DB_PASS (database password)

2. Upload Files:
   - Upload public_template/ contents to your web root
   - Upload secure_template/ to parent directory of web root

3. Update init.php (if needed):
   - Edit public_template/init.php
   - Verify SECURE_FOLDER_PATH points to correct location

4. Set Permissions:
   - Directories: 755
   - Files: 644
   - Ensure PHP can read secure_template/ folder

5. Test:
   - Visit your domain
   - Check all pages work
   - Test language switching (if multilingual)

NOTES:
- This is a production build (no management API)
- Database credentials are intentionally blank for security
- All pages are pre-compiled for performance
- Translation files are included for runtime language switching

COMPILED PAGES: 404, home, privacy, terms

=======================================================