<?php
/**
 * Valid Asset Extensions Configuration
 * 
 * Maps each asset category to its allowed file extensions.
 * Used by: uploadAsset (validation), admin API (UI hints)
 */

return [
    'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
    'font'   => ['ttf', 'otf', 'woff', 'woff2'],
    'audio'  => ['mp3', 'wav', 'ogg'],
    'videos' => ['mp4', 'webm', 'ogv'],
];
