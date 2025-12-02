<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

ApiResponse::create(200, 'operation.success')
    ->withMessage('Routes retrieved successfully')
    ->withData([
        'routes' => ROUTES,
        'count' => count(ROUTES)
    ])
    ->send();