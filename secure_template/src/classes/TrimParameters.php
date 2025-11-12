<?php
require_once __DIR__ . '/../functions/String.php';

class TrimParameters{
  private $folder = null;
  private $lang = MULTILINGUAL_SUPPORT ? 'en' : null;
  private $page = ROUTES[0] ?? 'home';
  private $id = null;
  private $params = [];
  private static $supportedLangs = MULTILINGUAL_SUPPORT ?  ['fr', 'en'] : [];
  private static $supportedRoute = ROUTES;

  public function __construct() {
    $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $folder = PUBLIC_FOLDER_SPACE;
    $request_uri = removePrefix($request_uri, $folder ? trim($folder, '/') . '/' : '');
    $parts = explode('/', $request_uri);
    if (count($parts) > 0 && $parts[0] !== '') {
      if (in_array($parts[0], self::$supportedLangs)) {
        if(MULTILINGUAL_SUPPORT){
          $this->lang = array_shift($parts);
        }
      }
    }
    if (count($parts) > 0 && $parts[0] !== '') {
      if (in_array($parts[0], self::$supportedRoute)) {
        $this->page = array_shift($parts);
      }
      else{
        $this->page = '404';
      }
    }
    if(count($parts) > 0 && $parts[0] !== '') {
      $this->id = array_shift($parts);
    }
    $this->params = $parts;
  }

  public function page() {
    return $this->page;
  }
  public function lang() {
    return $this->lang;
  }
  public function id() {
    return $this->id;
  }
  public function params() {
    return $this->params;
  }

  public function samePageUrl($lang = null) {
    $currentLang = $lang ?? $this->lang;
    $url = BASE_URL ;
    if(MULTILINGUAL_SUPPORT){
        $url .= $currentLang;
    }
    if ($this->page !== 'home') {
      if(MULTILINGUAL_SUPPORT){
          $url .= '/'.$this->page;
      }
      else{
          $url .= $this->page;
      }
    }
    if ($this->id !== null) {
      $url .= '/' . $this->id;
    }
    if (!empty($this->params)) {
      $url .= '/' . implode('/', $this->params);
    }
    return $url;
  }
}