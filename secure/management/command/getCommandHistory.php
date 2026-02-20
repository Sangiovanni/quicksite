<?php
/**
 * getCommandHistory - Retrieves command execution history
 * 
 * @method GET
 * @url /management/getCommandHistory
 * @auth required
 * @permission read
 * 
 * Retrieves command execution history with optional filtering and pagination.
 * Useful for audit trails, debugging, and AI context.
 * 
 * Query Parameters (for HTTP) / Body Parameters (for internal):
 *   - start_date: Filter from date (YYYY-MM-DD), default: 7 days ago
 *   - end_date: Filter to date (YYYY-MM-DD), default: today
 *   - command: Filter by specific command name
 *   - status: Filter by result status (success, error)
 *   - token_name: Filter by token name (partial match)
 *   - page: Page number (default: 1)
 *   - limit: Entries per page (default: 100, max: 500)
 *   - dates_only: If true, only return list of available log dates
 */

require_once SECURE_FOLDER_PATH . '/src/functions/LoggingManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body/query parameters (filters)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_getCommandHistory(array $params = [], array $urlParams = []): ApiResponse {
    // Check if user only wants list of dates
    if (isset($params['dates_only']) && ($params['dates_only'] === 'true' || $params['dates_only'] === '1' || $params['dates_only'] === true)) {
        $dates = getLogDates();
        
        $totalEntries = array_sum(array_column($dates, 'entries'));
        $totalSize = array_sum(array_column($dates, 'size_bytes'));
        
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('Log dates retrieved successfully')
            ->withData([
                'dates' => $dates,
                'summary' => [
                    'total_days' => count($dates),
                    'total_entries' => $totalEntries,
                    'total_size_bytes' => $totalSize,
                    'total_size_kb' => round($totalSize / 1024, 2)
                ]
            ]);
    }

    // Build filters from params
    $filters = [
        'start_date' => $params['start_date'] ?? null,
        'end_date' => $params['end_date'] ?? null,
        'command' => $params['command'] ?? null,
        'status' => $params['status'] ?? null,
        'token_name' => $params['token_name'] ?? null,
        'page' => $params['page'] ?? 1,
        'limit' => $params['limit'] ?? 100
    ];

    // Remove null values
    $filters = array_filter($filters, fn($v) => $v !== null);

    // Validate date formats
    if (isset($filters['start_date']) && !RegexPatterns::match('date_iso', $filters['start_date'])) {
        return ApiResponse::create(400, 'validation.invalid_date')
            ->withMessage('Invalid start_date format')
            ->withErrors([RegexPatterns::validationError('date_iso', 'start_date', $filters['start_date'])]);
    }

    if (isset($filters['end_date']) && !RegexPatterns::match('date_iso', $filters['end_date'])) {
        return ApiResponse::create(400, 'validation.invalid_date')
            ->withMessage('Invalid end_date format')
            ->withErrors([RegexPatterns::validationError('date_iso', 'end_date', $filters['end_date'])]);
    }

    // Get command history
    $result = getCommandHistory($filters);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Command history retrieved successfully')
        ->withData([
            'entries' => $result['entries'],
            'pagination' => $result['pagination'],
            'filters_applied' => $filters
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    // Use $_GET for HTTP query parameters
    __command_getCommandHistory($_GET, [])->send();
}
