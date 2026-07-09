<?php
require_once __DIR__ . '/../functions/String.php';

class TrimParametersManagement{
  private $command = '';
  private $additionnalCommand = [];
  private $params = [];
  private $project = null; // C7: per-request projectId peeled from '/management/p/<id>/...'

  public function __construct() {
    $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $folder = PUBLIC_FOLDER_SPACE;
    $request_uri = removePrefix($request_uri, $folder ? trim($folder, '/') . '/' : '');
    $parts = explode('/', $request_uri);
    // parse_url(PHP_URL_PATH) returns the path WITHOUT percent-decoding, so a
    // segment like ':slug' that the client URL-encoded as '%3Aslug' arrives
    // here still encoded. Decoding per-segment (NOT before explode — that would
    // turn an encoded '/' into a separator) restores the original value so
    // commands like getPageEvents / editPageEvent / setRouteLayout that look
    // up a route by name find the matching entry under ROUTES. rawurldecode
    // (not urldecode) because '+' is a literal '+' in URL paths — only the
    // form-urlencoded body convention treats '+' as space.
    // Surfaced by beta.8 A1 param routes ('test/:slug', '/auth/magic/:key'):
    // before this defensive decode every route-by-URL-path command 404'd on
    // any param route. (Note: $_GET is auto-decoded by PHP, so query-string
    // params don't need this — only the path segments do.)
    $parts = array_map('rawurldecode', $parts);
    //shift management folder
    array_shift($parts);
    // C7 — peel the optional project marker: '/management/p/<projectId>/<command>'.
    // The literal 'p' segment (mirrors surface-B '/p/<id>/') unambiguously marks a
    // project-scoped request; without it the request is a GLOBAL command
    // ('/management/<command>'). No projectId-vs-command ambiguity because 'p' is a
    // reserved marker, never a command name. The projectId is a NEW F1 path input —
    // the dispatcher validates it (is_valid_project_name) and membership-checks it
    // before it is ever used to build a path; it is NOT trusted here.
    if (count($parts) >= 2 && $parts[0] === 'p' && $parts[1] !== '') {
      array_shift($parts);                   // drop the 'p' marker
      $this->project = array_shift($parts);  // <projectId>
    }
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

  /**
   * The per-request projectId peeled from '/management/p/<projectId>/...', or
   * null for a global command ('/management/<command>'). C7. UNVALIDATED — the
   * dispatcher runs is_valid_project_name + a membership check before use.
   */
  public function project() {
    return $this->project;
  }
  
  public function params() {
    return $this->params;
  }
  public function additionalParams() {
      return $this->additionnalCommand;
  }
}