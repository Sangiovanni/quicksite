<?php
/**
 * clearCommandHistory.php
 * 
 * POST /management/clearCommandHistory
 * 
 * Deletes command log files older than a specified date.
 * Useful for maintenance and storage management.
 * 
 * Body Parameters:
 *   - before: (required) Delete logs before this date (YYYY-MM-DD)
 *   - confirm: (required) Must be true to execute deletion
 * 
 * @requires admin permission
 */

require_once SECURE_FOLDER_PATH . '/src/functions/LoggingManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

// C10 10.1b — clearing is PER-PROJECT. The project is the dispatcher's authorized
// URL marker (PROJECT_NAME), never a body parameter. Scoped by directory, so this
// deletion can only ever reach the caller's own project (F-C10-3).
$project = defined('PROJECT_NAME') ? (string)PROJECT_NAME : '';
if ($project === '') {
    ApiResponse::create(400, 'project.required')
        ->withMessage('This command is project-scoped. Target a project with /management/p/<projectId>/clearCommandHistory')
        ->send();
}

// Get request body (use pre-captured if available)
$rawBody = defined('REQUEST_BODY_RAW') ? REQUEST_BODY_RAW : file_get_contents('php://input');
$body = json_decode($rawBody, true) ?? [];

// Validate required parameters
if (empty($body['before'])) {
    ApiResponse::create(400, 'validation.missing_parameter')
        ->withMessage('Missing required parameter: before')
        ->withData([
            'required' => ['before', 'confirm'],
            'example' => [
                'before' => '2025-01-01',
                'confirm' => true
            ]
        ])
        ->send();
}

// Validate date format
if (!RegexPatterns::match('date_iso', $body['before'])) {
    ApiResponse::create(400, 'validation.invalid_date')
        ->withMessage('Invalid date format for "before" parameter')
        ->withErrors([RegexPatterns::validationError('date_iso', 'before', $body['before'])])
        ->send();
}

// Validate date is not in the future
$beforeDate = new DateTime($body['before']);
$today = new DateTime();
if ($beforeDate > $today) {
    ApiResponse::create(400, 'validation.invalid_date')
        ->withMessage('Cannot clear future logs')
        ->withData(['before' => $body['before'], 'today' => $today->format('Y-m-d')])
        ->send();
}

// Require confirmation
if (empty($body['confirm']) || $body['confirm'] !== true) {
    // Preview mode - show what would be deleted (this project only)
    $dates = getLogDates($project);
    $toDelete = array_filter($dates, fn($d) => $d['date'] < $body['before']);
    
    $totalEntries = array_sum(array_column($toDelete, 'entries'));
    $totalSize = array_sum(array_column($toDelete, 'size_bytes'));
    
    // ⚠ `stored_days` is every day the project HAS, not only the ones this call
    // would remove (beta.11 S6.6). Without it a caller who is told "0 files"
    // cannot tell whether they picked the wrong date or simply have nothing
    // older — and the answer matters, because deletion is DAY-GRANULAR: the day
    // comes from the FILE NAME, never from a record's own timestamp, so an
    // install whose only file is today's has nothing any valid date can reach.
    // Strictly less information than the history the caller can already read.
    ApiResponse::create(200, 'operation.preview')
        ->withMessage('Preview: Add "confirm": true to execute deletion')
        ->withData([
            'project' => $project,
            'would_delete' => [
                'files' => count($toDelete),
                'entries' => $totalEntries,
                'size_kb' => round($totalSize / 1024, 2)
            ],
            'dates_affected' => array_column($toDelete, 'date'),
            'stored_days' => array_values($dates),
            'before_date' => $body['before']
        ])
        ->send();
}

// Execute deletion — this project's log directory only
$result = clearCommandHistory($body['before'], $project);

ApiResponse::create(200, 'operation.success')
    ->withMessage('Command history cleared successfully')
    ->withData([
        'project' => $project,
        'deleted' => $result,
        'before_date' => $body['before']
    ])
    ->send();
