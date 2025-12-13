<?php
/**
 * NodeNavigator - Helper class for navigating and manipulating JSON structure trees
 * 
 * Provides utilities for:
 * - Adding node identifiers to structure trees
 * - Navigating to specific nodes by identifier
 * - Editing/deleting nodes by identifier
 * - Inserting nodes before/after specific positions
 * 
 * Node identifiers use 0-indexed dot notation: "0.2.1" means
 * root's 1st child → 3rd child → 2nd child
 */

class NodeNavigator {
    
    /**
     * Add _nodeId to every node in the structure
     * 
     * @param mixed $structure The structure (array or object)
     * @param string $prefix Current node ID prefix
     * @return mixed Structure with _nodeId added to each node
     */
    public static function addNodeIds($structure, string $prefix = ''): mixed {
        // Handle array of root nodes
        if (is_array($structure) && !self::isAssociativeArray($structure)) {
            $result = [];
            foreach ($structure as $index => $node) {
                $nodeId = $prefix === '' ? (string)$index : "$prefix.$index";
                $result[] = self::addNodeIdToNode($node, $nodeId);
            }
            return $result;
        }
        
        // Single root node
        return self::addNodeIdToNode($structure, $prefix === '' ? '0' : $prefix);
    }
    
    /**
     * Add _nodeId to a single node and its children
     */
    private static function addNodeIdToNode($node, string $nodeId): mixed {
        if (!is_array($node)) {
            return $node;
        }
        
        // Add nodeId to this node
        $result = ['_nodeId' => $nodeId];
        
        foreach ($node as $key => $value) {
            if ($key === 'children' && is_array($value)) {
                // Process children array
                $result['children'] = [];
                foreach ($value as $childIndex => $child) {
                    $childNodeId = "$nodeId.$childIndex";
                    $result['children'][] = self::addNodeIdToNode($child, $childNodeId);
                }
            } elseif ($key === 'slots' && is_array($value)) {
                // Process component slots - each slot can have children
                $result['slots'] = [];
                foreach ($value as $slotName => $slotContent) {
                    if (is_array($slotContent)) {
                        // Slot content is typically an array of nodes
                        if (!self::isAssociativeArray($slotContent)) {
                            $result['slots'][$slotName] = [];
                            foreach ($slotContent as $slotIndex => $slotNode) {
                                $slotNodeId = "$nodeId.slots.$slotName.$slotIndex";
                                $result['slots'][$slotName][] = self::addNodeIdToNode($slotNode, $slotNodeId);
                            }
                        } else {
                            // Single node in slot
                            $result['slots'][$slotName] = self::addNodeIdToNode($slotContent, "$nodeId.slots.$slotName");
                        }
                    } else {
                        $result['slots'][$slotName] = $slotContent;
                    }
                }
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Get a specific node by its identifier
     * 
     * @param mixed $structure The structure to search
     * @param string $nodeId The node identifier (e.g., "0.2.1")
     * @return array|null The node if found, null otherwise
     */
    public static function getNode($structure, string $nodeId): ?array {
        $path = self::parseNodeId($nodeId);
        
        if ($path === null) {
            return null;
        }
        
        return self::navigateToNode($structure, $path);
    }
    
    /**
     * Update a specific node by its identifier
     * 
     * @param mixed $structure The structure to modify
     * @param string $nodeId The node identifier
     * @param array $newNode The new node data (null or empty to delete)
     * @return array ['success' => bool, 'structure' => modified structure, 'action' => 'updated'|'deleted']
     */
    public static function updateNode($structure, string $nodeId, ?array $newNode): array {
        $path = self::parseNodeId($nodeId);
        
        if ($path === null) {
            return ['success' => false, 'error' => 'Invalid node identifier format'];
        }
        
        if (empty($path)) {
            // Replacing root - handle array of roots vs single root
            if ($newNode === null || $newNode === []) {
                return ['success' => false, 'error' => 'Cannot delete root node'];
            }
            return ['success' => true, 'structure' => $newNode, 'action' => 'updated'];
        }
        
        $isDelete = $newNode === null || $newNode === [];
        $result = self::modifyNodeAtPath($structure, $path, $newNode, $isDelete);
        
        if ($result['success']) {
            $result['action'] = $isDelete ? 'deleted' : 'updated';
        }
        
        return $result;
    }
    
    /**
     * Insert a node before or after a specific position
     * 
     * @param mixed $structure The structure to modify
     * @param string $nodeId The reference node identifier
     * @param array $newNode The node to insert
     * @param string $position 'before' or 'after'
     * @return array ['success' => bool, 'structure' => modified structure]
     */
    public static function insertNode($structure, string $nodeId, array $newNode, string $position = 'after'): array {
        $path = self::parseNodeId($nodeId);
        
        if ($path === null || empty($path)) {
            return ['success' => false, 'error' => 'Invalid node identifier for insertion'];
        }
        
        return self::insertNodeAtPath($structure, $path, $newNode, $position);
    }
    
    /**
     * Parse a node identifier string into path array
     * 
     * @param string $nodeId e.g., "0.2.1" or "0.slots.main.0"
     * @return array|null Array of path segments or null if invalid
     */
    private static function parseNodeId(string $nodeId): ?array {
        if ($nodeId === '') {
            return [];
        }
        
        // Validate format: should be numbers separated by dots, optionally with "slots.name" segments
        if (!preg_match('/^[0-9]+(\.(slots\.[a-zA-Z0-9_-]+\.)?[0-9]+)*$/', $nodeId)) {
            // Check for simpler format without slots
            if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', $nodeId)) {
                return null;
            }
        }
        
        return explode('.', $nodeId);
    }
    
    /**
     * Navigate to a node following a path
     */
    private static function navigateToNode($structure, array $path): ?array {
        $current = $structure;
        
        foreach ($path as $i => $segment) {
            // Handle 'slots' keyword
            if ($segment === 'slots') {
                if (!isset($current['slots'])) {
                    return null;
                }
                // Next segment is slot name
                $slotName = $path[$i + 1] ?? null;
                if ($slotName === null || !isset($current['slots'][$slotName])) {
                    return null;
                }
                $current = $current['slots'][$slotName];
                continue;
            }
            
            // Skip slot names (already handled)
            if ($i > 0 && ($path[$i - 1] ?? '') === 'slots') {
                continue;
            }
            
            $index = (int)$segment;
            
            // Check if we're at root level (array of nodes)
            if (is_array($current) && !self::isAssociativeArray($current)) {
                if (!isset($current[$index])) {
                    return null;
                }
                $current = $current[$index];
            } 
            // Check children
            elseif (isset($current['children'][$index])) {
                $current = $current['children'][$index];
            }
            else {
                return null;
            }
        }
        
        return is_array($current) ? $current : null;
    }
    
    /**
     * Modify a node at a specific path
     */
    private static function modifyNodeAtPath($structure, array $path, ?array $newNode, bool $isDelete): array {
        // Work with a copy
        $structure = json_decode(json_encode($structure), true);
        
        // Navigate to parent and get target index
        $targetIndex = (int)array_pop($path);
        
        if (empty($path)) {
            // Target is at root level
            if (is_array($structure) && !self::isAssociativeArray($structure)) {
                if (!isset($structure[$targetIndex])) {
                    return ['success' => false, 'error' => 'Node not found'];
                }
                if ($isDelete) {
                    array_splice($structure, $targetIndex, 1);
                } else {
                    $structure[$targetIndex] = $newNode;
                }
                return ['success' => true, 'structure' => $structure];
            }
        }
        
        // Navigate to parent
        $parent = &$structure;
        $isRootArray = is_array($structure) && !self::isAssociativeArray($structure);
        
        foreach ($path as $i => $segment) {
            if ($segment === 'slots') {
                $slotName = $path[$i + 1] ?? null;
                if (!isset($parent['slots'][$slotName])) {
                    return ['success' => false, 'error' => 'Slot not found'];
                }
                $parent = &$parent['slots'][$slotName];
                continue;
            }
            
            if ($i > 0 && ($path[$i - 1] ?? '') === 'slots') {
                continue;
            }
            
            $index = (int)$segment;
            
            if ($isRootArray && $i === 0) {
                if (!isset($parent[$index])) {
                    return ['success' => false, 'error' => 'Node not found'];
                }
                $parent = &$parent[$index];
                $isRootArray = false;
            } elseif (isset($parent['children'][$index])) {
                $parent = &$parent['children'][$index];
            } else {
                return ['success' => false, 'error' => 'Node not found at path'];
            }
        }
        
        // Now modify the target in parent's children
        if (!isset($parent['children'][$targetIndex])) {
            return ['success' => false, 'error' => 'Target node not found'];
        }
        
        if ($isDelete) {
            array_splice($parent['children'], $targetIndex, 1);
            // Reindex array
            $parent['children'] = array_values($parent['children']);
        } else {
            $parent['children'][$targetIndex] = $newNode;
        }
        
        return ['success' => true, 'structure' => $structure];
    }
    
    /**
     * Insert a node at a specific path
     */
    private static function insertNodeAtPath($structure, array $path, array $newNode, string $position): array {
        $structure = json_decode(json_encode($structure), true);
        
        $targetIndex = (int)array_pop($path);
        $insertIndex = $position === 'after' ? $targetIndex + 1 : $targetIndex;
        
        if (empty($path)) {
            // Insert at root level
            if (is_array($structure) && !self::isAssociativeArray($structure)) {
                if ($targetIndex < 0 || $targetIndex >= count($structure)) {
                    return ['success' => false, 'error' => 'Invalid target index'];
                }
                array_splice($structure, $insertIndex, 0, [$newNode]);
                return ['success' => true, 'structure' => $structure, 'insertedAt' => $insertIndex];
            }
        }
        
        // Navigate to parent
        $parent = &$structure;
        $isRootArray = is_array($structure) && !self::isAssociativeArray($structure);
        
        foreach ($path as $i => $segment) {
            if ($segment === 'slots') {
                $slotName = $path[$i + 1] ?? null;
                if (!isset($parent['slots'][$slotName])) {
                    return ['success' => false, 'error' => 'Slot not found'];
                }
                $parent = &$parent['slots'][$slotName];
                continue;
            }
            
            if ($i > 0 && ($path[$i - 1] ?? '') === 'slots') {
                continue;
            }
            
            $index = (int)$segment;
            
            if ($isRootArray && $i === 0) {
                if (!isset($parent[$index])) {
                    return ['success' => false, 'error' => 'Node not found'];
                }
                $parent = &$parent[$index];
                $isRootArray = false;
            } elseif (isset($parent['children'][$index])) {
                $parent = &$parent['children'][$index];
            } else {
                return ['success' => false, 'error' => 'Node not found at path'];
            }
        }
        
        // Insert into parent's children
        if (!isset($parent['children']) || !is_array($parent['children'])) {
            return ['success' => false, 'error' => 'Parent has no children array'];
        }
        
        if ($targetIndex < 0 || $targetIndex >= count($parent['children'])) {
            return ['success' => false, 'error' => 'Invalid target index'];
        }
        
        array_splice($parent['children'], $insertIndex, 0, [$newNode]);
        
        return ['success' => true, 'structure' => $structure, 'insertedAt' => $insertIndex];
    }
    
    /**
     * Check if array is associative (has string keys)
     */
    private static function isAssociativeArray($arr): bool {
        if (!is_array($arr) || empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
    
    /**
     * Generate a tree summary of the structure with node IDs
     * Useful for quickly seeing the structure hierarchy
     * 
     * @param mixed $structure The structure
     * @param int $maxDepth Maximum depth to display
     * @return array Summary tree
     */
    public static function getSummary($structure, int $maxDepth = 10): array {
        return self::summarizeNode($structure, '', 0, $maxDepth);
    }
    
    private static function summarizeNode($node, string $nodeId, int $depth, int $maxDepth): array {
        if ($depth > $maxDepth) {
            return ['_truncated' => true, '_nodeId' => $nodeId];
        }
        
        // Handle array of root nodes
        if (is_array($node) && !self::isAssociativeArray($node)) {
            $result = [];
            foreach ($node as $index => $child) {
                $childId = $nodeId === '' ? (string)$index : "$nodeId.$index";
                $result[] = self::summarizeNode($child, $childId, $depth, $maxDepth);
            }
            return $result;
        }
        
        if (!is_array($node)) {
            return ['_nodeId' => $nodeId, '_type' => 'text'];
        }
        
        $summary = [
            '_nodeId' => $nodeId ?: '0'
        ];
        
        // Identify node type
        if (isset($node['tag'])) {
            $summary['tag'] = $node['tag'];
            if (isset($node['params']['class'])) {
                $summary['class'] = $node['params']['class'];
            }
            if (isset($node['params']['id'])) {
                $summary['id'] = $node['params']['id'];
            }
        }
        if (isset($node['component'])) {
            $summary['component'] = $node['component'];
        }
        if (isset($node['textKey'])) {
            $summary['textKey'] = substr($node['textKey'], 0, 50) . (strlen($node['textKey']) > 50 ? '...' : '');
        }
        
        // Process children
        if (isset($node['children']) && is_array($node['children'])) {
            $summary['children'] = [];
            $currentId = $nodeId ?: '0';
            foreach ($node['children'] as $childIndex => $child) {
                $summary['children'][] = self::summarizeNode($child, "$currentId.$childIndex", $depth + 1, $maxDepth);
            }
        }
        
        // Process slots
        if (isset($node['slots']) && is_array($node['slots'])) {
            $summary['slots'] = [];
            $currentId = $nodeId ?: '0';
            foreach ($node['slots'] as $slotName => $slotContent) {
                $slotId = "$currentId.slots.$slotName";
                if (is_array($slotContent) && !self::isAssociativeArray($slotContent)) {
                    $summary['slots'][$slotName] = [];
                    foreach ($slotContent as $slotIndex => $slotNode) {
                        $summary['slots'][$slotName][] = self::summarizeNode($slotNode, "$slotId.$slotIndex", $depth + 1, $maxDepth);
                    }
                } else {
                    $summary['slots'][$slotName] = self::summarizeNode($slotContent, $slotId, $depth + 1, $maxDepth);
                }
            }
        }
        
        return $summary;
    }
}
