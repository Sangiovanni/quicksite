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
    // Preview mode - show what would be deleted
    $dates = getLogDates();
    $toDelete = array_filter($dates, fn($d) => $d['date'] < $body['before']);
    
    $totalEntries = array_sum(array_column($toDelete, 'entries'));
    $totalSize = array_sum(array_column($toDelete, 'size_bytes'));
    
    ApiResponse::create(200, 'operation.preview')
        ->withMessage('Preview: Add "confirm": true to execute deletion')
        ->withData([
            'would_delete' => [
                'files' => count($toDelete),
                'entries' => $totalEntries,
                'size_kb' => round($totalSize / 1024, 2)
            ],
            'dates_affected' => array_column($toDelete, 'date'),
            'before_date' => $body['before']
        ])
        ->send();
}

// Execute deletion
$result = clearCommandHistory($body['before']);

ApiResponse::create(200, 'operation.success')
    ->withMessage('Command history cleared successfully')
    ->withData([
        'deleted' => $result,
        'before_date' => $body['before']
    ])
    ->send();
