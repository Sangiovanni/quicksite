<?php
/**
 * What a backup holds, and how a backup or a restore copies, removes and
 * measures it. backupProject, restoreBackup (and its pre-restore copy) and
 * listBackups read the one list below, so a backup and a restore always agree on
 * what a project is.
 *
 * A backup holds what an export carries: the project's settings and routes, its
 * config folder, its structures, translations, data, snippets and public files.
 * It never holds `config/members.json` or its lock: a backup does not carry who
 * may use the project, and a restore never changes it. Builds, exports and
 * earlier backups are never part of one.
 *
 * A link is never followed (FileSystem.php): an item that is itself a link is
 * left out, as one inside an item is.
 */

require_once __DIR__ . '/FileSystem.php'; // qs_copy_tree, qs_delete_entry, qs_tree_measure

/** The top-level entries of a project a backup holds, in the order they are copied and restored. */
const QS_BACKUP_ITEMS = ['config.php', 'routes.php', 'config', 'templates', 'translate', 'data', 'snippets', 'public'];

/** The entries of `config/` a backup never holds and a restore never touches. */
const QS_BACKUP_CONFIG_KEPT = ['members.json', 'members.json.lock'];

/** Whether an item is in `$root` and is not a link: what a backup copies, or a restore brings back. */
function qs_backup_item_present(string $root, string $item): bool {
    $path = $root . '/' . $item;
    if (!file_exists($path) && !is_link($path)) {
        return false;
    }
    return !qs_delete_tree_leads_elsewhere($root, $item, $path);
}

/** The items present in `$root`, in QS_BACKUP_ITEMS order. */
function qs_backup_items_in(string $root): array {
    return array_values(array_filter(QS_BACKUP_ITEMS, fn(string $item): bool => qs_backup_item_present($root, $item)));
}

/**
 * Bytes a backup of `$root` holds, by the one measure (plain files; a link not
 * counted): `config/` without what it never holds.
 *
 * @param string[]|null $items Only these items (default: all of QS_BACKUP_ITEMS)
 */
function qs_backup_measure(string $root, ?array $items = null): int {
    $bytes = 0;
    foreach ($items ?? QS_BACKUP_ITEMS as $item) {
        if (!qs_backup_item_present($root, $item)) {
            continue;
        }
        $path = $root . '/' . $item;
        if (!is_dir($path)) {
            $bytes += (int) @filesize($path);
            continue;
        }
        $bytes += qs_tree_measure($path)['bytes'];
        if ($item === 'config') {
            foreach (QS_BACKUP_CONFIG_KEPT as $kept) {
                if (is_file("$path/$kept") && !is_link("$path/$kept")) {
                    $bytes -= (int) @filesize("$path/$kept");
                }
            }
        }
    }
    return max(0, $bytes);
}

/**
 * Copy one item from one project-shaped folder into another, keeping going past a
 * failure. It never writes through a link: an item that is a link where it would
 * land is not copied, and is named.
 *
 * @return array{ok: bool, files: int, failed: string[]} `failed` names paths
 *         relative to the folders, starting with the item (at most 50).
 */
function qs_backup_copy_item(string $fromRoot, string $toRoot, string $item): array {
    $from = $fromRoot . '/' . $item;
    $to = $toRoot . '/' . $item;
    if ((file_exists($to) || is_link($to)) && qs_delete_tree_leads_elsewhere($toRoot, $item, $to)) {
        return ['ok' => false, 'files' => 0, 'failed' => [$item]];
    }
    if (!is_dir($from)) {
        $ok = @copy($from, $to);
        return ['ok' => $ok, 'files' => $ok ? 1 : 0, 'failed' => $ok ? [] : [$item]];
    }
    $copy = qs_copy_tree($from, $to, $item === 'config' ? QS_BACKUP_CONFIG_KEPT : []);
    return [
        'ok' => $copy['ok'],
        'files' => $copy['files'],
        'failed' => array_map(static fn(string $rel): string => $rel === '.' ? $item : $item . '/' . $rel, $copy['failed']),
    ];
}

/**
 * Copy everything a backup holds from `$from` (a project) into `$to` (a new
 * backup folder). Keeps going past a failure and names it.
 *
 * @return array{copied: string[], failed: string[], errors: string[]}
 *         `copied` and `failed` are items; `errors` says, per failed item, which
 *         paths could not be copied, relative to the project.
 */
function qs_backup_copy(string $from, string $to): array {
    $report = ['copied' => [], 'failed' => [], 'errors' => []];
    foreach (qs_backup_items_in($from) as $item) {
        $copy = qs_backup_copy_item($from, $to, $item);
        if ($copy['ok']) {
            $report['copied'][] = $item;
            continue;
        }
        $report['failed'][] = $item;
        $report['errors'][] = $copy['failed'] === [$item]
            ? "Could not copy $item"
            : "Could not copy all of $item: " . implode(', ', $copy['failed']);
    }
    return $report;
}

/**
 * Remove a project's current item before a restore brings the backup's back, by
 * the one delete's rules (a link removed and never followed, a read-only file
 * removed). `config/` keeps what a backup never holds: its other entries are
 * removed one by one, and a `config` that is a link is left alone and reported.
 *
 * @return array{ok: bool, survived: string[]} paths relative to the project
 */
function qs_backup_remove_item(string $projectPath, string $item): array {
    if ($item !== 'config') {
        $removal = qs_delete_entry($projectPath, $item);
        return ['ok' => $removal['ok'], 'survived' => $removal['survived']];
    }
    $config = $projectPath . '/config';
    if (!file_exists($config) && !is_link($config)) {
        return ['ok' => true, 'survived' => []];
    }
    if (!is_dir($config) || qs_delete_tree_leads_elsewhere($projectPath, 'config', $config)) {
        return ['ok' => false, 'survived' => ['config']];
    }
    $report = ['ok' => true, 'survived' => []];
    foreach (@scandir($config) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, QS_BACKUP_CONFIG_KEPT, true)) {
            continue;
        }
        $removal = qs_delete_entry($config, $entry);
        if (!$removal['ok']) {
            $report['ok'] = false;
            foreach ($removal['survived'] as $rel) {
                $report['survived'][] = 'config/' . $rel;
            }
        }
    }
    return $report;
}

/**
 * The backups to remove once one more is made, so that `$maxBackups` holds: the
 * oldest, by their folders' modification time. Chosen before the new backup is
 * written, so the storage quota is checked against what the backup removes and a
 * new backup is never one of them. 0 keeps every backup.
 *
 * @return string[] backup names, oldest first
 */
function qs_backup_prune_plan(string $backupsDir, int $maxBackups): array {
    if ($maxBackups <= 0) {
        return [];
    }
    $created = [];
    foreach (glob($backupsDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $created[basename($dir)] = (int) @filemtime($dir);
    }
    asort($created);
    $excess = count($created) + 1 - $maxBackups;
    return $excess > 0 ? array_slice(array_keys($created), 0, $excess) : [];
}
