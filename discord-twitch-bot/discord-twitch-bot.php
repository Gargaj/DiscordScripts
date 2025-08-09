<?php
error_reporting(E_ALL & ~E_NOTICE);

include_once(dirname(__FILE__) . "/../sideload.inc.php");
include_once(dirname(__FILE__) . "/../discord.inc.php");
include_once(dirname(__FILE__) . "/../oauth.inc.php");

class TwitchOAuth extends OAuth2
{
  const ENDPOINT_TOKEN = "https://id.twitch.tv/oauth2/token";
  const ENDPOINT_AUTH = "https://id.twitch.tv/oauth2/token";
  const ENDPOINT_REQUEST = "https://id.twitch.tv/oauth2/token";
  const ENDPOINT_TOKENINFO = "";
  const ENDPOINT_RESOURCE = "https://api.twitch.tv/helix";
}

class TwitchOAuthStorage implements OAuthStorageInterface
{
  public $filename;
  public $data;
  public function __construct( $start = true )
  {
    $this->filename = dirname(__FILE__) . "/.twitchoauth.json";
    $this->data = json_decode(file_get_contents($this->filename),true);
  }
  public function Save()
  {
    file_put_contents($this->filename,json_encode($this->data,JSON_PRETTY_PRINT));
  }
  public function Reset()
  {
    $this->data = array();

    $this->Save();
  }
  public function Set( $key, $value )
  {
    if (!$this->data)
      $this->data = array();

    $this->data[$key] = $value;

    $this->Save();
  }
  public function Get( $key )
  {
    if (!@$this->data)
      return null;
    return @$this->data[$key];
  }
}

$configFile = dirname(__FILE__) . "/config.json";
$stateFile = dirname(__FILE__) . "/.state.json";

printf( "*** Starting check: %s\n",date("Y-m-d H:i:s"));
printf( "* Config: %s\n",$configFile);
printf( "* State: %s\n",$stateFile);

$discord = new Discord($configFile, dirname(__FILE__) . "/.discord.state.json");

$CONFIG = json_decode(file_get_contents($configFile),true);
$CONFIG["TWITCH_CLIENTID"] ?: die("TWITCH_CLIENTID missing");
$CONFIG["TWITCH_CLIENTSECRET"] ?: die("TWITCH_CLIENTID missing");

$STATE = json_decode(file_get_contents($stateFile),true) ?: array();

$twitchOauth = new TwitchOAuth(array(
  "clientID" => $CONFIG["TWITCH_CLIENTID"],
  "clientSecret" => $CONFIG["TWITCH_CLIENTSECRET"],
  "redirectURI" => "?",
));
$twitchOauth->SetStorage( new TwitchOAuthStorage() );

$sideload = new Sideload();

foreach($CONFIG["monitors"] as $monitor)
{
  printf( "* Checking %s...\n",$monitor["twitchUsername"]);
  $data = $twitchOauth->ResourceRequestRefresh("https://api.twitch.tv/helix/streams", "GET", array("user_login"=>$monitor["twitchUsername"]),array(
    "Client-ID" => $CONFIG["TWITCH_CLIENTID"],
  ));
  $json = $twitchOauth->UnpackFormat($data);
  
  $isStreaming = false;
  $shouldNotify = false;
  if ( @$json["error"] )
  {
    printf( "  ! Error getting Twitch status: %s\n", $data);
    printf( "  ! Resetting Twitch OAuth credentials...\n");
    $twitchOauth->Reset();
  }
  if ( @$json["data"] )
  {
    $isStreaming = true;

    $lastSeen = @$STATE["streaming"][$monitor["twitchUsername"]];
    $timeSinceLastSeenOnline = $lastSeen ? time() - $lastSeen : 999999;
    if ($timeSinceLastSeenOnline < 15 * 60)
    {
      printf( "  * Is streaming, but last notification was %d seconds ago\n", $timeSinceLastSeenOnline);
    }
    else
    {
      if (@$monitor["allowGames"])
      {
        printf( "  * Limited stream, checking we care about '%s'...\n", $json["data"][0]["game_name"]);
        $allowed = false;
        foreach($monitor["allowGames"] as $game)
        {
          if ($game == $json["data"][0]["game_name"])
          {
            $allowed = $json["data"][0]["game_name"];
            break;
          }
        }
        if ($allowed === false)
        {
          printf( "    * We don't care about '%s'...\n", $json["data"][0]["game_name"]);
          $STATE["streaming"][$monitor["twitchUsername"]] = false;
          break;
        }
        else
        {
          printf( "    * We do care about '%s'!\n", $allowed);
        }
      }
      
      printf( "  * Last notification was %d seconds ago, should notify\n", $timeSinceLastSeenOnline);
      $shouldNotify = true;
    }

    $STATE["streaming"][$monitor["twitchUsername"]] = time();
  }
  else
  {
    $isStreaming = false;
    printf( "  * Not streaming.\n");
  }
  
  if ($shouldNotify)
  {
    printf( "  * Started streaming, notify...\n");
    
    if (!@$STATE["twitchUserInfo"][$monitor["twitchUsername"]])
    {
      printf( "    * Getting additional user info...\n");
      $data = $twitchOauth->ResourceRequestRefresh("https://api.twitch.tv/helix/users", "GET", array("login"=>$monitor["twitchUsername"]),array(
        "Client-ID" => $CONFIG["TWITCH_CLIENTID"],
      ));
      $json2 = $twitchOauth->UnpackFormat($data);
      if ($json2["data"][0])
      {
        $STATE["twitchUserInfo"][$monitor["twitchUsername"]] = $json2["data"][0];
      }
    }

    $message = array(
      "content" => sprintf("**%s** has started streaming at https://www.twitch.tv/%s - _%s_", 
        $STATE["twitchUserInfo"][$monitor["twitchUsername"]]["display_name"], $monitor["twitchUsername"], $json["data"][0]["title"]),
      "embed" => array(
        "title" => $STATE["twitchUserInfo"][$monitor["twitchUsername"]]["display_name"],
        "description" => $json["data"][0]["title"],
        "color" => 0x9147FF,
        "url" => "https://www.twitch.tv/" . $monitor["twitchUsername"],
        "thumbnail" => array(
          "url" => $STATE["twitchUserInfo"][$monitor["twitchUsername"]]["profile_image_url"],
        ),
      )
    );
    
    $response = $discord->SendMessage(
      @$monitor["discordGuild"] ?: $CONFIG["discordGuild"],
      @$monitor["discordChannel"] ?: $CONFIG["discordChannel"],
      $message);
  }
  
}

printf( "* Saving state...\n");
file_put_contents($stateFile,json_encode($STATE,JSON_PRETTY_PRINT));
?>
