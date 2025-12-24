<?php
/**
 * Admin Tutorial API Endpoint
 * 
 * Handles tutorial/onboarding AJAX requests from the admin panel.
 * This is separate from the management API since tutorial is admin-panel only.
 * 
 * Actions:
 * - get: Get current tutorial state
 * - update: Update tutorial progress
 */

require_once '../init.php';
require_once SECURE_FOLDER_PATH . '/admin/AdminRouter.php';
require_once SECURE_FOLDER_PATH . '/admin/functions/AdminTutorial.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON response header
header('Content-Type: application/json');

// Check authentication
$token = $_SESSION['admin_token'] ?? null;
if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Validate token exists in auth.php
$authConfigPath = SECURE_FOLDER_PATH . '/config/auth.php';
$authConfig = include $authConfigPath;
if (!isset($authConfig['authentication']['tokens'][$token])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

// Get action from request
$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

// Get JSON body for POST requests
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?? [];
}

// Initialize tutorial with current token
$tutorial = AdminTutorial::getInstance();
$tutorial->setCurrentToken($token);

switch ($action) {
    case 'get':
        $status = $tutorial->getOnboardingStatus();
        echo json_encode([
            'success' => true,
            'data' => $status
        ]);
        break;
        
    case 'update':
        $step = $input['step'] ?? null;
        $substep = $input['substep'] ?? null;
        $status = $input['status'] ?? null;
        
        // Validate step
        if ($step !== null && (!is_numeric($step) || $step < 1 || $step > 10)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Step must be between 1 and 10']);
            exit;
        }
        
        // Validate substep
        if ($substep !== null && (!is_numeric($substep) || $substep < 1 || $substep > 15)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Substep must be between 1 and 15']);
            exit;
        }
        
        // Validate status
        $validStatuses = ['pending', 'active', 'paused', 'skipped', 'completed'];
        if ($status !== null && !in_array($status, $validStatuses)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid status']);
            exit;
        }
        
        $result = $tutorial->updateProgress($step, $substep, $status);
        
        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'data' => $tutorial->getOnboardingStatus()
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
