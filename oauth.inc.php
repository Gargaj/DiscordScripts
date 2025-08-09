<?php

class OAuthException extends Exception
{
  public function __construct($message, $code = 0, Exception $previous = null, $dataJSON = "")
  {
    $data = json_decode($dataJSON);
    if ($data && $data->error_description)
      $message .= ": " . $data->error_description;
    else
      $message .= ": \"" . $dataJSON . "\"";
    parent::__construct($message, $code, $previous);
  }
}

interface OAuthStorageInterface {
  public function Reset();
  public function Set( $key, $value );
  public function Get( $key );
}

class OAuthSessionStorage implements OAuthStorageInterface
{
  public function __construct( $start = true )
  {
    if ($start)
      @session_start();
  }
  public function Reset()
  {
    $_SESSION["OAuth"] = array();
  }
  public function Set( $key, $value )
  {
    if (!@$_SESSION["OAuth"])
      $_SESSION["OAuth"] = array();

    $_SESSION["OAuth"][$key] = $value;
  }
  public function Get( $key )
  {
    if (!@$_SESSION["OAuth"])
      return null;
    return @$_SESSION["OAuth"][$key];
  }
}

class OAuthBase
{
  protected $clientID = null;
  protected $clientSecret = null;
  protected $redirectURI = null;

  protected $format = "json";
  protected $storage = null;

  /**
   * Constructor
   * @param array $options The initializing parameters of the class
   * @return self
   *
   * The following parameters are required in $options:
   *   clientID     - OAuth2 client ID
   *   clientSecret - OAuth2 client secret
   *   redirectURI  - OAuth2 redirect/return URL
   */
  function __construct( $options = array() )
  {
    $mandatory = array("clientID","clientSecret","redirectURI");
    foreach($mandatory as $v)
    {
      if (!$options[$v])
        throw new Exception("'".$v."' invalid or missing from initializer array!");
      $this->$v = $options[$v];
    }

    $this->storage = new OAuthSessionStorage();
  }
  /**
   * Send HTTP request
   * @access protected
   * @param string $url The request target URL
   * @param string $method GET, POST, PUT, etc.
   * @param string $contentArray Key-value pairs to be sent in the request body
   * @param string $headerArray HTTP headers to be sent
   * @return string The URL contents
   */
  protected function RequestFGC( $url, $method = "GET", $contentArray = array(), $headerArray = array() )
  {
    $headerStrArray = array();
    foreach($headerArray as $k=>$v) $headerStrArray[] = $k.": ".$v;

    $getArray  = $method == "GET"  ? $contentArray : array();
    $postArray = $method == "POST" ? $contentArray : array();

    //$getArray["format"] = $this->format;

    if ($getArray)
    {
      $data = http_build_query($getArray);
      $url .= "?" . $data;
    }

    if ($postArray)
    {
      $data = http_build_query($contentArray);
    }

    $data = file_get_contents( $url, false, stream_context_create( array(
      'http'=>array(
        'method'=>$method,
        'header'=>implode("\r\n",$headerStrArray),
        'content'=>$data
      ),
      'ssl' => array(
        'verify_peer' => false,
      ),
    ) ) );

    return $data;
  }
  protected function RequestCURL( $url, $method = "GET", $contentArray = array(), $headerArray = array() )
  {
    //var_dump($contentArray,$headerArray);
    $ch = curl_init();

    $headerStrArray = array();
    foreach($headerArray as $k=>$v) $headerStrArray[] = $k.": ".$v;

    $getArray  = $method == "GET"  ? $contentArray : array();
    $postArray = $method == "POST" ? $contentArray : array();

    //$getArray["format"] = $this->format;

    if ($getArray)
    {
      $data = http_build_query($getArray);
      $url .= "?" . $data;
    }

    if ($postArray)
    {
      if (strstr(@$headerArray["Content-Type"]?:"","multipart") === false)
      {
        $data = http_build_query($contentArray);
      }
      else
      {
        $data = $contentArray;
      }
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    if ($method == "POST")
      curl_setopt($ch, CURLOPT_POST, true);

    //curl_setopt($ch, CURLOPT_STDERR, fopen('php://output', "w+"));
    //curl_setopt($ch, CURLOPT_VERBOSE, TRUE);
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerStrArray);
    //curl_setopt($ch, CURLINFO_HEADER_OUT, true);

    $data = curl_exec($ch);
    //var_dump(curl_getinfo($ch,CURLINFO_HEADER_OUT));
    
    curl_close($ch);

    return $data;
  }
  public function Request( $url, $method = "GET", $contentArray = array(), $headerArray = array() )
  {
    if (function_exists("curl_init"))
      return $this->RequestCURL( $url, $method, $contentArray, $headerArray );

    return $this->RequestFGC( $url, $method, $contentArray, $headerArray );
  }

  /**
   * Sets a new storage handler
   * @param object $storage The new storage handler implementing OAuth2StorageInterface
   * @throws OAuthException Exception is thrown if the class doesn't implement OAuth2StorageInterface
   */
  function SetStorage( $storage )
  {
    if (!($storage instanceof OAuthStorageInterface))
      throw new OAuthException("Storage class must implement OAuth2StorageInterface");

    $this->storage = $storage;
  }

  /**
   * Sets the communication format
   * @param string $format The communication format - must be either "json" or "xml"
   * @throws OAuthException Throws exception when the format isn't one of the above.
   */
  function SetFormat( $format )
  {
    $format = strtolower($format);
    if (array_search($format,array("json","xml"))===false)
      throw new OAuthException("Format has to be either XML or JSON!");

    $this->format = $format;
  }

  /**
   * Unpack string data according to the given format
   * @param string $data The incoming data
   * @return The unpacked data array
   */
  function UnpackFormat( $data )
  {
    switch($this->format)
    {
      case 'json':
        return json_decode( $data, true );
      case 'xml':
        throw new Exception("Not implemented yet!");
    }
    return null;
  }
  public function PerformAuthRedirect() {}
  public function ProcessAuthResponse() {}
  public function ResourceRequest( $url = "", $method = "GET", $params = array(), $headers = array() ) {}

  /**
   * Resets the entire internal token storage
   */
  function Reset()
  {
    $this->storage->Reset();
  }
}

///////////////////////////////////////////////////////////////////////////////

class OAuth1 extends OAuthBase
{
  const ENDPOINT_REQUEST = "";
  const ENDPOINT_TOKEN = "";
  const ENDPOINT_RESOURCE = "";

  function GenerateNonce()
  {
    return sha1(time());
  }
  
  public function GenerateAuthHeader( $url, $tokenSecret = null, $params = array(), $method = "POST" )
  {
    $oauth = array(
      'oauth_consumer_key' => $this->clientID,
      'oauth_nonce' => $this->GenerateNonce(),
      'oauth_signature_method' => 'HMAC-SHA1',
      'oauth_timestamp' => time(),
      'oauth_version' => '1.0'
    );
    $oauth = array_merge( $oauth, $params );

    ksort($oauth);
    foreach($oauth as $key=>$value) $r[] = $key . "=" . rawurlencode($value);
 
    $baseString = $method . '&' . rawurlencode($url) . '&' . rawurlencode(implode('&', $r));
    $compositeKey = rawurlencode($this->clientSecret) . '&' . rawurlencode($tokenSecret);

    $oauth['oauth_signature'] = base64_encode( hash_hmac('sha1', $baseString, $compositeKey, true) );

    //if (strstr($url,"https://upload.twitter.com") !== false) // HAX
    {
      foreach($params as $k=>$v)
        if (substr($k,0,5)!="oauth")
          unset($oauth[$k]);
    }

    $a = array();
    foreach($oauth as $key=>$value) $a[] = $key . "=\"" . rawurlencode($value) . "\"";

    return "OAuth " . implode(", ",$a);
  }
  public function PerformAuthRedirect()
  {
    $headerArray = array();
    $headerArray["Authorization"] = $this->GenerateAuthHeader( static::ENDPOINT_REQUEST, "", array('oauth_callback' => $this->redirectURI) );
    
    $data = $this->Request( static::ENDPOINT_REQUEST, "POST", "", $headerArray );

    parse_str($data,$a);
    if (!$a["oauth_token"])
      return false;
    
    header("Location: " . static::ENDPOINT_AUTH . "?oauth_token=" . $a["oauth_token"]);
    exit();
  }
  public function ProcessAuthResponse( $token = "", $verifier = "" )
  {
    if (!$token)
      $token = $_GET["oauth_token"];
    if (!$token)
      return false;
      
    if (!$verifier)
      $verifier = $_GET["oauth_verifier"];
    if (!$verifier)
      return false;

    $headerArray = array();
    $headerArray["Authorization"] = $this->GenerateAuthHeader( static::ENDPOINT_TOKEN, "", array( "oauth_token" => $token ) );
    
    $data = $this->Request( static::ENDPOINT_TOKEN, "POST", array("oauth_verifier"=>$verifier), $headerArray );
    parse_str($data,$a);
    
    $this->storage->set("token",$a["oauth_token"]);
    $this->storage->set("token_secret",$a["oauth_token_secret"]);
  
    return true;
  }

  function ResourceRequest( $url = "", $method = "GET", $params = array(), $headerArray = array() )
  {
    if (!$url)
      $url = static::ENDPOINT_RESOURCE;

    $token = $this->storage->get("token");
    if (!$token)
      throw new OAuthException("Not authenticated!");    
    $token_secret = $this->storage->get("token_secret");

    $oauthParams = array("oauth_token" => $token);
    if (strstr($headerArray["Content-Type"],"multipart") === false)
      $oauthParams = array_merge( $params, $oauthParams );
      
    $headerArray["Authorization"] = $this->GenerateAuthHeader( $url, $token_secret, $oauthParams, $method );
    $data = $this->Request( $url, $method, $params, $headerArray );
    
    return $data;
  }
}

///////////////////////////////////////////////////////////////////////////////

class OAuth2 extends OAuthBase
{
  const ENDPOINT_TOKEN = "";
  const ENDPOINT_AUTH = "";
  const ENDPOINT_TOKENINFO = "";
  const ENDPOINT_RESOURCE = "";

  protected $scope = array();

  /**
   * Sets the request scope
   * @param array $scope The requested scopes
   */
  function SetScope( $scope )
  {
    if (is_string($scope))
      $scope = preg_split("/\s+/",$scope);

    $this->scope = $scope;
  }


  /**
   * Get access token via client credentials
   * @return boolean 'true' on success
   * @throws OAuthException Exception is thrown when the data returned
   *   by the endpoint is malformed or the authentication fails.
   *
   * The function authenticates with the OAuth22.0 endpoint using the
   * supplied credentials and stores the returning access token
   */
  function GetClientCredentialsToken()
  {
    $authString = "Basic " . base64_encode( $this->clientID . ":" . $this->clientSecret );

    $params = array(
      "grant_type"=>"client_credentials",
      "client_id"=>$this->clientID,
      "client_secret"=>$this->clientSecret,
    );
    if ($this->scope)
      $params["scope"] = implode(" ",$this->scope);

    $data = $this->Request( static::ENDPOINT_TOKEN, "POST", $params, array("Authorization"=>$authString) );

    $authTokens = json_decode( $data );

    if (!@$authTokens || !@$authTokens->access_token)
      throw new OAuthException("Authorization failed", 0, null, $data);

    $this->storage->set("accessToken",$authTokens->access_token);
    if (@$authTokens->refresh_token)
      $this->storage->set("refreshToken",$authTokens->refresh_token);

    return true;
  }

  /**
   * Generates "state" string
   * @return string The "state" string
   */
  function GenerateState()
  {
    return rand(0,0x7FFFFFFF);
  }

  /**
   * Retrieves authentication endpoint URL and parameters
   * @return string The authentication URL and query string
   */
  function GetAuthURL()
  {
    $params = array(
      "client_id"     => $this->clientID,
      "redirect_uri"  => $this->redirectURI,
      "response_type" => "code",
    );
    if ($this->storage)
    {
      $state = $this->GenerateState();
      $this->storage->set("state",$state);
      $params["state"] = $state;
    }
    if ($this->scope)
      $params["scope"] = implode(" ",$this->scope);

    return static::ENDPOINT_AUTH . "?" . http_build_query($params);
  }

  /**
   * Sends redirect header and stops execution
   */
  function PerformAuthRedirect()
  {
    header( "Location: " . $this->GetAuthURL() );
    exit();
  }

  /**
   * Process the second step of authentication
   * @param string $code The authentication code from the query string
   * @param string $state The "state" parameter
   * @return boolean "true" if successful
   * @throws OAuthException Exception is thrown if the authorization code
   *    is not found
   * @throws OAuthException Exception is thrown if the state mismatches
   * @throws OAuthException Exception is thrown if authentication fails
   */
  function ProcessAuthResponse( $code = null, $state = null )
  {
    if (!$code)
      $code = $_GET["code"];
    if (!$code)
      return false;

    if (!$state)
      $state = $_GET["state"];

    if ($this->storage)
    {
      if ( $this->storage->get("state") != $state )
        throw new OAuthException("State mismatch!");
    }

    $authString = "Basic " . base64_encode( $this->clientID . ":" . $this->clientSecret );

    $params = array(
      "client_id"    => $this->clientID,
      "grant_type"   => "authorization_code",
      "code"         => $code,
      "redirect_uri" => $this->redirectURI,
    );

    $data = $this->Request( static::ENDPOINT_TOKEN, "POST", $params, array("Authorization"=>$authString) );

    $authTokens = json_decode( $data );

    if (!$authTokens || !$authTokens->access_token)
      throw new OAuthException("Authorization failed", 0, null, $data);

    $this->storage->set("accessToken",$authTokens->access_token);
    if ($authTokens->refresh_token)
      $this->storage->set("refreshToken",$authTokens->refresh_token);

    return true;
  }

  /**
   */
  function RefreshToken()
  {
    $refreshToken = $this->storage->get("refreshToken");

    if (!$refreshToken)
      throw new OAuthException("Not authenticated!");

    $authString = "Basic " . base64_encode( $this->clientID . ":" . $this->clientSecret );

    $params = array(
      "grant_type"    => "refresh_token",
      "refresh_token" => $refreshToken,
    );

    $data = $this->Request( static::ENDPOINT_TOKEN, "POST", $params, array("Authorization"=>$authString) );

    $authTokens = json_decode( $data );

    if (!$authTokens || !$authTokens->access_token)
      throw new OAuthException("Authorization failed", 0, null, $data);

    $this->storage->set("accessToken",$authTokens->access_token);
    if ($authTokens->refresh_token)
      $this->storage->set("refreshToken",$authTokens->refresh_token);

    return true;
  }

  /**
   * Send authenticated request to URL
   * @param string $url The endpoint URL
   * @param string $method GET, POST, PUT, etc.
   * @param string $params Key-value pair of POST data
   * @return string The request response
   * @throws OAuthException Exception is thrown if the class isn't
   *    authenticated yet
   */
  function ResourceRequest( $url = "", $method = "GET", $params = array(), $headers = array() )
  {
    if (!$url)
      $url = static::ENDPOINT_RESOURCE;

    $accessToken = $this->storage->get("accessToken");

    if (!$accessToken)
      throw new OAuthException("Not authenticated!");

    $auth2 = "Bearer ".$accessToken;
    $headers["Authorization"] = $auth2;
    $data = $this->Request( $url, $method, $params, $headers );

    return $data;
  }

  /**
   * Verify the incoming token that it belongs to us
   * @return bool true on success
   * @throws OAuthException Exception is thrown if the token
   *    belongs to a different application
   */
  function VerifyToken()
  {
    if (!static::ENDPOINT_TOKENINFO) // in case we don't provide one
      throw new OAuthException("No token info endpoint available!");

    $data = $this->ResourceRequest( static::ENDPOINT_TOKENINFO );
    $info = json_decode($data);
    if (!$info)
      throw new OAuthException("Invalid token!");

    if ($info->client_id != $this->clientID)
      throw new OAuthException("This token belongs to a different client!");

    return true;
  }

  /**
   * Attempt resource request, but refresh token if fails
   * @param string $url The endpoint URL
   * @param string $method GET, POST, PUT, etc.
   * @param string $params Key-value pair of POST data
   * @return string The request response
   */
  function ResourceRequestRefresh( $url = "", $method = "GET", $params = array(), $headers = array() )
  {
    if (!$url)
      $url = static::ENDPOINT_RESOURCE;

    if (!$this->IsAuthenticated())
    {
      $this->GetClientCredentialsToken();
    }

    $data = $this->ResourceRequest( $url, $method, $params, $headers );
    $error = json_decode($data);
    if ($error && @$error->error == "invalid_token")
    {
      $this->RefreshToken();
      $data = $this->ResourceRequest( $url, $method, $params, $headers );
    }
    return $data;
  }

  /**
   * Tests whether the instance is authenticated
   * @return bool True if the instance has a valid access token.
   */
  function IsAuthenticated()
  {
    return !!$this->storage->get("accessToken");
  }

  /**
   * Resets the entire internal token storage
   */
  function Reset()
  {
    $this->storage->Reset();
  }
}

?>