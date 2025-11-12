<?php

function is_valid_relative_path(string $path): bool
{
    // 1. Basic cleaning and normalization
    $normalized_path = trim($path);
    
    // Convert all backslashes (common in Windows input) to forward slashes
    $normalized_path = str_replace('\\', '/', $normalized_path);
    
    // Remove duplicate slashes (e.g., 'a//b' becomes 'a/b')
    $normalized_path = preg_replace('/\/+/', '/', $normalized_path);

    // 2. Critical Security & Format Checks
    
    // A. Check for directory traversal attempts ('../', '/../', or '..')
    // A path should *never* contain '..' if it is meant to be relative and safe.
    if (strpos($normalized_path, '..') !== false) {
        // Log a security warning here if necessary
        return false;
    }
    
    // B. Check for absolute path indicators (leading slash)
    // A relative path should not start with a slash.
    if (str_starts_with($normalized_path, '/')) {
        return false;
    }
    
    // C. Check for invalid characters using a regular expression
    // This regex allows letters, numbers, hyphens, underscores, dots (for file extensions), and forward slashes.
    // Adjust this regex based on the strictest character set your file system allows.
    // We explicitly exclude null bytes (\x00) and other control characters for security.
    $valid_path_regex = '/^[a-zA-Z0-9_\-\.\/]+$/';
    if (!preg_match($valid_path_regex, $normalized_path)) {
        return false;
    }

    // 3. Optional: Check for trailing slash (optional, but good practice for folder paths)
    // If you expect 'some/path' not 'some/path/'
    if (str_ends_with($normalized_path, '/')) {
        return false;
    }

    // If all checks pass, the path is structurally valid and safe for directory creation
    return true;
}



/**
 * Recursively MOVES files and folders from source to destination using a two-pass approach:
 * 1. Pre-process to determine all moves and exclude folders.
 * 2. Execute moves.
 */
function recursive_move_template(string $source, string $destination, string $exclude_folder_name): bool
{
    if (!is_dir($source)) {
        return false;
    }

    // --- PASS 1: Pre-process and Collect Moves (No File System changes) ---
    $moves = [];
    $source_dirs_to_clean = [];
    $source_base = rtrim($source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            
            $item_name = $item->getFilename();
            
            // 1. Pruning/Exclusion Check
            if ($item->isDir() && $item_name === $exclude_folder_name) {
                // Prune the iterator to skip this directory and its contents
                $iterator->next(); 
                continue; 
            }
            
            // 2. Calculate Correct Relative Path (Fixes the 'test/test' issue)
            $full_item_path = $item->getPathName();
            // str_replace is safe here because $source_base is guaranteed to be at the start
            $relative_item_path = str_replace($source_base, '', $full_item_path); 

            // 3. Store the Move Instruction
            if ($item->isDir()) {
                // Collect the source path for cleanup *only if it's not the root itself*
                if ($full_item_path !== $source) {
                    $source_dirs_to_clean[] = $full_item_path;
                }
                $moves[] = ['type' => 'dir', 'target' => $destination . DIRECTORY_SEPARATOR . $relative_item_path];
            } else {
                $moves[] = [
                    'type' => 'file', 
                    'source' => $full_item_path, 
                    'target' => $destination . DIRECTORY_SEPARATOR . $relative_item_path
                ];
            }
        }

    } catch (Exception $e) {
        // Log error
        return false;
    }

    // --- PASS 2: Execution (File System changes) ---
    
    // 1. Create the top-level destination directory FIRST
    if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
        return false;
    }

    foreach ($moves as $move) {
        if ($move['type'] === 'dir') {
            // Create subdirectories in the destination
            if (!is_dir($move['target']) && !mkdir($move['target'], 0755)) {
                // If directory creation fails, fail the whole operation
                return false; 
            }
        } else {
            // Handle Files (Moving)
            
            // The parent directories *should* already exist from the 'dir' creation step, 
            // but we add a safety check (without recursion) just in case a file was 
            // listed before its parent directory due to iteration order.
            $target_dir = dirname($move['target']);
            if (!is_dir($target_dir) && !@mkdir($target_dir, 0755, true)) {
                return false; // Cannot create file's parent directory
            }

            // Execute the move
            if (!rename($move['source'], $move['target'])) {
                // Log the failure and return
                return false;
            }
        }
    }

    rsort($source_dirs_to_clean); 
    
    foreach ($source_dirs_to_clean as $dir) {
        // We only attempt to remove if the directory is empty.
        // If it fails (because it's not empty, e.g., due to an excluded file), we ignore it.
        // We use '@' to suppress warnings if rmdir fails (which is expected if the directory is not empty).
        @rmdir($dir);
    }
    
    // Finally, attempt to remove the top-level source directory itself, if it's now empty.
    @rmdir($source);

    return true;
}

/**
 * Reads a file, performs a string replacement, and writes the new content back.
 * * @param string $file_path The full path to the file to modify.
 * @param string $search The string to find.
 * @param string $replace The string to replace it with.
 * @return bool True on success, false on file read/write failure.
 */
function replace_in_file(string $file_path, string $search, string $replace): bool
{
    if (!file_exists($file_path)) {
        // Log: File not found
        return false;
    }

    $content = file_get_contents($file_path);
    if ($content === false) {
        // Log: Failed to read file
        return false;
    }

    $new_content = str_replace($search, $replace, $content);

    // If str_replace finds nothing, $new_content will be identical to $content.
    // We write it back regardless, but we check for write success.
    
    // Use FILE_TEXT for safety when writing back text files
    $write_result = file_put_contents($file_path, $new_content, LOCK_EX); 
    
    return $write_result !== false;
}


/**
 * Deletes empty directories, starting from $start_dir and working up 
 * to the root directory's parent. Stops when a directory is not empty 
 * or the path cannot be reduced further.
 * @param string $start_dir The deepest directory that should now be empty.
 * @param string $stop_dir The directory path where cleanup should stop (e.g., the shared root).
 */
function cleanup_empty_source_chain(string $start_dir, string $stop_dir): void
{
    // Normalize paths and ensure the stop directory is an ancestor
    $current_dir = rtrim($start_dir, DIRECTORY_SEPARATOR);
    $stop_dir = rtrim($stop_dir, DIRECTORY_SEPARATOR);

    // Loop until we reach the stopping point or the path cannot be reduced
    while ($current_dir && $current_dir !== $stop_dir && dirname($current_dir) !== $current_dir) {
        
        // Attempt to delete the current directory (only works if empty)
        $deleted = @rmdir($current_dir);

        // Move up to the parent directory
        $current_dir = dirname($current_dir);
    }
}