<?php
/**
 * AssetMetadataManager
 * 
 * Centralized management of asset metadata (assets_metadata.json).
 * Handles load/save, per-asset get/set/remove/rename, file info detection,
 * and extension-to-category resolution.
 * 
 * Used by: uploadAsset, deleteAsset, editAsset
 */
class AssetMetadataManager
{
    private string $metadataPath;
    private array $metadata = [];
    private array $extensionMap = [];

    /**
     * @param string $dataPath      Directory containing assets_metadata.json
     * @param string $extensionsConfigPath  Path to assetExtensions.php config
     */
    public function __construct(string $dataPath, string $extensionsConfigPath)
    {
        $this->metadataPath = rtrim($dataPath, '/\\') . '/assets_metadata.json';

        // Build reverse extension → category map
        $extensions = require $extensionsConfigPath;
        foreach ($extensions as $category => $exts) {
            foreach ($exts as $ext) {
                $this->extensionMap[strtolower($ext)] = $category;
            }
        }

        $this->load();
    }

    /**
     * Load metadata from disk.
     */
    public function load(): void
    {
        if (file_exists($this->metadataPath)) {
            $content = file_get_contents($this->metadataPath);
            $this->metadata = json_decode($content, true) ?: [];
        } else {
            $this->metadata = [];
        }
    }

    /**
     * Save metadata to disk.
     */
    public function save(): bool
    {
        $dir = dirname($this->metadataPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return file_put_contents(
            $this->metadataPath,
            json_encode($this->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    /**
     * Get metadata for a specific asset.
     */
    public function get(string $category, string $filename): ?array
    {
        $key = $category . '/' . $filename;
        return $this->metadata[$key] ?? null;
    }

    /**
     * Set/merge metadata for a specific asset.
     */
    public function set(string $category, string $filename, array $data): void
    {
        $key = $category . '/' . $filename;
        if (isset($this->metadata[$key])) {
            $this->metadata[$key] = array_merge($this->metadata[$key], $data);
        } else {
            $this->metadata[$key] = $data;
        }
    }

    /**
     * Remove metadata entry for a specific asset.
     */
    public function remove(string $category, string $filename): void
    {
        $key = $category . '/' . $filename;
        unset($this->metadata[$key]);
    }

    /**
     * Migrate metadata key from old filename to new filename.
     */
    public function rename(string $category, string $oldFilename, string $newFilename): void
    {
        $oldKey = $category . '/' . $oldFilename;
        $newKey = $category . '/' . $newFilename;

        if (isset($this->metadata[$oldKey])) {
            $this->metadata[$newKey] = $this->metadata[$oldKey];
            unset($this->metadata[$oldKey]);
        }
    }

    /**
     * Auto-detect file info: MIME type, size, and dimensions (for images).
     */
    public function detectFileInfo(string $filePath): array
    {
        $info = [];

        if (!file_exists($filePath)) {
            return $info;
        }

        $info['size'] = filesize($filePath);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        if ($mime) {
            $info['mime_type'] = $mime;
        }

        // Image dimensions
        if (isset($info['mime_type']) && str_starts_with($info['mime_type'], 'image/')) {
            $imageInfo = @getimagesize($filePath);
            if ($imageInfo !== false) {
                $info['width'] = $imageInfo[0];
                $info['height'] = $imageInfo[1];
                $info['dimensions'] = $imageInfo[0] . 'x' . $imageInfo[1];
            }
        }

        return $info;
    }

    /**
     * Resolve category from file extension.
     * Returns category string or null if extension not recognized.
     */
    public function resolveCategory(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return $this->extensionMap[$ext] ?? null;
    }

    /**
     * Get all known extensions as a flat array.
     */
    public function getAllExtensions(): array
    {
        return array_keys($this->extensionMap);
    }
}
