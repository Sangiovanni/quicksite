<?php
require_once __DIR__ . '/../functions/String.php';

class TrimParameters{
  private $folder = null;
  private $lang = MULTILINGUAL_SUPPORT ? 'en' : null;
  private $page = ROUTES[0] ?? 'home';
  private $id = null;
  private $params = [];
  private static $supportedLangs = MULTILINGUAL_SUPPORT ?  CONFIG['LANGUAGES_SUPPORTED'] : [];
  private static $supportedRoute = ROUTES;

  public function __construct() {
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $parsed_path = parse_url($request_uri, PHP_URL_PATH);
    
    // Handle malformed URLs or null results
    if ($parsed_path === null || $parsed_path === false) {
        // Redirect to home or set as 404
        $this->page = 'home';
        return;
    }
    
    $request_uri = trim($parsed_path, '/');
    $folder = PUBLIC_FOLDER_SPACE;
    $request_uri = removePrefix($request_uri, $folder ? trim($folder, '/') . '/' : '');
    
    // Filter out empty parts from multiple slashes
    $parts = array_filter(explode('/', $request_uri), function($part) {
        return $part !== '';
    });
    $parts = array_values($parts); // Re-index array
    
    if (count($parts) > 0) {
        if (in_array($parts[0], self::$supportedLangs)) {
            if(MULTILINGUAL_SUPPORT){
                $this->lang = array_shift($parts);
            }
        }
    }
    
    if (count($parts) > 0) {
        if (in_array($parts[0], self::$supportedRoute)) {
            $this->page = array_shift($parts);
        }
        else{
            $this->page = '404';
        }
    }
    
    if(count($parts) > 0) {
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
    } else {
      // For home page, add trailing slash
      if(MULTILINGUAL_SUPPORT){
          $url .= '/';
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