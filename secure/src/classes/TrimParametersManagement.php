<?php
require_once __DIR__ . '/../functions/String.php';

class TrimParametersManagement{
  private $command = '';
  private $additionnalCommand = [];
  private $params = [];
  private $project = null; // per-request projectId peeled from '/management/p/<id>/...'

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
    // Needed by param routes ('test/:slug', '/auth/magic/:key'): without
    // this defensive decode every route-by-URL-path command would 404 on
    // any param route. (Note: $_GET is auto-decoded by PHP, so query-string
    // params don't need this — only the path segments do.)
    $parts = array_map('rawurldecode', $parts);
    // A value that is not valid UTF-8 is a malformed request, answered 400 here,
    // before any command reads it: a command that names the value in its answer
    // could not encode it and would answer 500 instead. One check reads every part
    // a value arrives in: the address's segments, the query string and a form body
    // (refuseNonUtf8()). (The site's router answers the same bytes in a page address
    // 404: a page that does not exist. A JSON body cannot carry them: json_decode()
    // refuses it.)
    self::refuseNonUtf8($parts, 'A segment of the address is not valid UTF-8');
    //shift management folder
    array_shift($parts);
    // Peel the optional project marker: '/management/p/<projectId>/<command>'.
    // The literal 'p' segment (mirrors surface-B '/p/<id>/') unambiguously marks a
    // project-scoped request; without it the request is a GLOBAL command
    // ('/management/<command>'). No projectId-vs-command ambiguity because 'p' is a
    // reserved marker, never a command name. The projectId is a path input —
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
        self::refuseNonUtf8($_GET, 'A value of the query string is not valid UTF-8');
        $this->params = $_GET;
    }

    // Check for standard POST data (form-urlencoded) - overrides GET
    if (!empty($_POST)) {
        self::refuseNonUtf8($_POST, 'A value of the request body is not valid UTF-8');
        $this->params = array_merge($this->params, $_POST);
    }
    else{
      // Check for JSON data - use pre-captured body if available (for logging)
      $json_data = defined('REQUEST_BODY_RAW') ? REQUEST_BODY_RAW : file_get_contents('php://input');
      $data = json_decode($json_data, true);
      // is_array, NOT !== null: a JSON SCALAR body ('5', '"s"', 'true', '1.5')
      // decodes to a non-null NON-array, and array_merge is typed for arrays, so
      // it would raise a TypeError → 500 on every command, unauthenticated on the
      // public ones. A scalar body carries no parameters,
      // so ignoring it is the correct handling, not merely the safe one.
      if (is_array($data)) {
          $this->params = array_merge($this->params, $data);
      }
    }
  }

  /**
   * Answer 400 when a string in $values, a key or a value at any depth, is not valid
   * UTF-8. $message names the part of the request it came in.
   */
  private static function refuseNonUtf8(array $values, string $message): void {
    if (self::isUtf8($values)) {
      return;
    }
    require_once __DIR__ . '/ApiResponse.php';
    ApiResponse::create(400, 'validation.invalid_format')
      ->withMessage($message)
      ->send();
  }

  /** Whether every string in $value, keys included, at any depth, is valid UTF-8. */
  private static function isUtf8($value): bool {
    if (is_string($value)) {
      return preg_match('//u', $value) === 1;
    }
    if (!is_array($value)) {
      return true;
    }
    foreach ($value as $key => $item) {
      if ((is_string($key) && preg_match('//u', $key) !== 1) || !self::isUtf8($item)) {
        return false;
      }
    }
    return true;
  }

  public function command() {
    return $this->command;
  }

  /**
   * The per-request projectId peeled from '/management/p/<projectId>/...', or
   * null for a global command ('/management/<command>'). UNVALIDATED — the
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