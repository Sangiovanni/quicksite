<?php
/**
 * Valid Asset Categories Configuration
 * 
 * Defines the allowed asset categories for the management system.
 * Used by: uploadAsset, deleteAsset, listAssets
 * 
 * To add a new category:
 * 1. Add the category name to this array
 * 2. Create the corresponding folder: public/assets/{category}/
 * 3. Add an index.php file in that folder for directory protection
 */

return [
    'images',
    'scripts',
    'font',
    'audio',
    'videos'
];
