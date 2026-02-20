<?php
require_once __DIR__ . '/../functions/String.php';

class TrimParametersManagement{
  private $command = '';
  private $additionnalCommand = [];
  private $params = [];

  public function __construct() {
    $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $folder = PUBLIC_FOLDER_SPACE;
    $request_uri = removePrefix($request_uri, $folder ? trim($folder, '/') . '/' : '');
    $parts = explode('/', $request_uri);
    //shift management folder
    array_shift($parts);
    if (count($parts) > 0 && $parts[0] !== '') {
      $this->command = array_shift($parts);
    }
    $this->additionnalCommand = $parts;

    // Start with GET parameters (query string)
    if (!empty($_GET)) {
        $this->params = $_GET;
    }

    // Check for standard POST data (form-urlencoded) - overrides GET
    if (!empty($_POST)) {
        $this->params = array_merge($this->params, $_POST);
    }
    else{
      // Check for JSON data - use pre-captured body if available (for logging)
      $json_data = defined('REQUEST_BODY_RAW') ? REQUEST_BODY_RAW : file_get_contents('php://input');
      $data = json_decode($json_data, true);
      if ($data !== null) {
          $this->params = array_merge($this->params, $data);
      }         
    }
  }

  public function command() {
    return $this->command;
  }
  
  public function params() {
    return $this->params;
  }
  public function additionalParams() {
      return $this->additionnalCommand;
  }
}