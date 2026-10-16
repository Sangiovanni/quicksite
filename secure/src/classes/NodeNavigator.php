<?php
/**
 * NodeNavigator - Helper class for navigating and manipulating JSON structure trees
 * 
 * Provides utilities for:
 * - Adding node identifiers to structure trees
 * - Navigating to specific nodes by identifier
 * - Editing/deleting nodes by identifier
 * - Inserting nodes before, after or inside a node (first or last), or at the root
 * 
 * Node identifiers use 0-indexed dot notation: "0.2.1" means
 * root's 1st child → 3rd child → 2nd child
 */

// Which tags are void elements (TagRegistry::isVoidElement): an insert never puts children in one.
require_once __DIR__ . '/TagRegistry.php';

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
     * Insert a node at a structure's root: the one rule for every command that
     * takes targetNodeId "root".
     *
     * A root has one of two shapes. A LIST of top-level nodes (every page, menu and
     * footer) takes the new node as its first entry; one NODE with children (a
     * component) takes it as its first child. The new node's id is "0" in both. A
     * structure with no nodes is a list with no entries.
     *
     * Writing `children` into a list instead turns it into an object the renderer
     * cannot read: the page shows nothing and the insert is lost.
     *
     * A node root that cannot hold children (a component call, a text node, a void
     * element such as an img) is refused, as an insert inside one is.
     *
     * @param mixed $structure The whole structure, as read from its file
     * @param array $newNode   The node to insert
     * @param int[] $adopt     Indices of root entries to move into the new node first,
     *                         keeping their order: addNode's rule for an insert inside
     *                         a node that holds text. Used only when the new node has
     *                         children.
     * @return array ['success' => true, 'structure' => array, 'newNodeId' => '0'],
     *               or ['success' => false, 'error' => string]
     */
    public static function insertAtRoot($structure, array $newNode, array $adopt = []): array {
        if (!is_array($structure)) {
            return ['success' => false, 'error' => 'The structure is neither a list of nodes nor a node'];
        }
        $isList = !self::isAssociativeArray($structure);
        if (!$isList && (!isset($structure['tag']) || isset($structure['component']))) {
            return ['success' => false, 'error' => 'The structure root cannot hold children'];
        }
        if (!$isList && ($void = self::voidElementRefusal($structure, 'The structure root')) !== null) {
            return ['success' => false, 'error' => $void];
        }

        $entries = $isList ? $structure : (is_array($structure['children'] ?? null) ? $structure['children'] : []);

        $newNode = self::adopt($entries, $newNode, $adopt);
        array_unshift($entries, $newNode);
        if ($isList) {
            $structure = $entries;
        } else {
            $structure['children'] = $entries;
        }
        return ['success' => true, 'structure' => $structure, 'newNodeId' => '0'];
    }

    /**
     * Insert a node before, after or inside the node $targetNodeId names: the one
     * rule for every command that inserts next to a node or into one. The root has
     * its own, insertAtRoot().
     *
     * The target is found as getNode() finds it: in a LIST root (every page, menu
     * and footer) the first index picks an entry, in a NODE root (a component) a
     * child of the root.
     *
     *   before / after  the new node becomes the target's sibling, just before or
     *                   just after it.
     *   inside          the new node becomes the target's first child.
     *   inside-end      the new node becomes the target's last child (a move's drop
     *                   inside an element, which the editor appends).
     *
     * Inside or at the end, a target that cannot hold children is refused, as such a
     * root is: a component call, a text node, or a void element (img, input, br…).
     * The renderer reads no children there, so the insert would be written and
     * never shown.
     *
     * Several nodes land in order when each is inserted at the same target and
     * position, last one first.
     *
     * @param mixed  $structure    The whole structure, as read from its file
     * @param string $targetNodeId Dot-separated indices ("0.2.1")
     * @param array  $newNode      The node to insert
     * @param string $position     'before', 'after', 'inside' or 'inside-end'
     * @param int[]  $adopt        Inside or at the end: indices of the target's children to move
     *                             into the new node first, keeping their order (addNode's
     *                             rule for an insert inside a node that holds text). Used
     *                             only when the new node has children.
     * @return array ['success' => true, 'structure' => array, 'newNodeId' => string],
     *               or ['success' => false, 'error' => string]
     */
    public static function insertAt($structure, string $targetNodeId, array $newNode, string $position, array $adopt = []): array {
        if (!in_array($position, ['before', 'after', 'inside', 'inside-end'], true)) {
            return ['success' => false, 'error' => "Invalid position '{$position}'"];
        }
        if (preg_match('/^[0-9]+(\.[0-9]+)*$/D', $targetNodeId) !== 1) {
            return ['success' => false, 'error' => "Invalid node identifier '{$targetNodeId}'"];
        }
        if (!is_array($structure)) {
            return ['success' => false, 'error' => 'The structure is neither a list of nodes nor a node'];
        }
        $path = array_map('intval', explode('.', $targetNodeId));
        $notFound = ['success' => false, 'error' => "Target node not found: {$targetNodeId}"];

        // $siblings is the array that holds the target: the root list, or a node's children.
        if (self::isAssociativeArray($structure)) {
            if (!isset($structure['children']) || !is_array($structure['children'])) {
                return $notFound;
            }
            $siblings = &$structure['children'];
        } else {
            $siblings = &$structure;
        }
        $index = array_pop($path);
        foreach ($path as $step) {
            if (!is_array($siblings[$step] ?? null) || !is_array($siblings[$step]['children'] ?? null)) {
                return $notFound;
            }
            $siblings = &$siblings[$step]['children'];
        }
        if (!array_key_exists($index, $siblings)) {
            return $notFound;
        }

        if ($position === 'inside' || $position === 'inside-end') {
            $target = &$siblings[$index];
            if (!is_array($target) || !isset($target['tag']) || isset($target['component'])
                || (isset($target['children']) && !is_array($target['children']))) {
                return ['success' => false, 'error' => 'The target node cannot hold children'];
            }
            if (($void = self::voidElementRefusal($target, 'The target node')) !== null) {
                return ['success' => false, 'error' => $void];
            }
            $children = $target['children'] ?? [];
            $newNode = self::adopt($children, $newNode, $adopt);
            if ($position === 'inside') {
                array_unshift($children, $newNode);
                $at = 0;
            } else {
                $children[] = $newNode;
                $at = count($children) - 1;
            }
            $target['children'] = $children;
            unset($target, $siblings);
            $path[] = $index;
            $path[] = $at;
            return ['success' => true, 'structure' => $structure, 'newNodeId' => implode('.', $path)];
        }

        $at = $position === 'before' ? $index : $index + 1;
        array_splice($siblings, $at, 0, [$newNode]);
        unset($siblings);
        $path[] = $at;
        return ['success' => true, 'structure' => $structure, 'newNodeId' => implode('.', $path)];
    }

    /**
     * The refusal for a node that is a void element (TagRegistry::isVoidElement: img,
     * input, br…), which HTML gives no children, or null. $what names the node in the
     * sentence ("The target node", "The structure root").
     */
    private static function voidElementRefusal(array $node, string $what): ?string {
        $tag = $node['tag'] ?? null;
        if (!is_string($tag) || !TagRegistry::isVoidElement($tag)) {
            return null;
        }
        $tag = strtolower($tag);
        $article = in_array($tag[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
        return "{$what} is {$article} {$tag} element, which cannot hold children";
    }

    /**
     * Move the entries at $adopt (indices into $entries) into $newNode's children,
     * before its own, keeping their order. Nothing moves when $adopt is empty or the
     * new node has no children list.
     */
    private static function adopt(array &$entries, array $newNode, array $adopt): array {
        if ($adopt === [] || !isset($newNode['children']) || !is_array($newNode['children'])) {
            return $newNode;
        }
        rsort($adopt);
        $moved = [];
        foreach ($adopt as $index) {
            if (isset($entries[$index])) {
                array_unshift($moved, $entries[$index]);
                array_splice($entries, $index, 1);
            }
        }
        $newNode['children'] = array_merge($moved, $newNode['children']);
        return $newNode;
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
        if (!preg_match('/^[0-9]+(\.(slots\.[a-zA-Z0-9_-]+\.)?[0-9]+)*$/D', $nodeId)) {
            // Check for simpler format without slots
            if (!preg_match('/^[0-9]+(\.[0-9]+)*$/D', $nodeId)) {
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
        
        // Determine if root is an array of nodes (pages) or single object (components)
        $isRootArray = is_array($structure) && !self::isAssociativeArray($structure);
        
        if (empty($path)) {
            // Target is at root level
            if ($isRootArray) {
                // Root is an array of nodes (page structure)
                if (!isset($structure[$targetIndex])) {
                    return ['success' => false, 'error' => 'Node not found at index ' . $targetIndex];
                }
                if ($isDelete) {
                    array_splice($structure, $targetIndex, 1);
                } else {
                    $structure[$targetIndex] = $newNode;
                }
                return ['success' => true, 'structure' => $structure];
            } else {
                // Root is a single object (component structure) - target is in children
                if (!isset($structure['children'][$targetIndex])) {
                    return ['success' => false, 'error' => 'Node not found at index ' . $targetIndex];
                }
                if ($isDelete) {
                    array_splice($structure['children'], $targetIndex, 1);
                    $structure['children'] = array_values($structure['children']);
                } else {
                    $structure['children'][$targetIndex] = $newNode;
                }
                return ['success' => true, 'structure' => $structure];
            }
        }
        
        // Navigate to parent
        $parent = &$structure;
        
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
