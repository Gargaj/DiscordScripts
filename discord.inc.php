<?php
include_once(dirname(__FILE__) . "/sideload.inc.php");

class Discord
{
  public $stateFile;
  public $configFile;
  public $sideload;
  public $state;
  public $config;
  function __construct( $configPath = null, $statePath = null )
  {
    $this->stateFile  = $statePath  ?? dirname(__FILE__) . "/.discord.state.json";
    $this->configFile = $configPath ?? dirname(__FILE__) . "/config.json";
    $this->sideload = new Sideload();
    
    $this->Load();
  }
  function __destruct()
  {
    $this->Save();
  }
  function Load()
  {
    printf( "[discord] Loading config and state...\n");
    $this->config = json_decode(file_get_contents($this->configFile),true) ?: array();
    $this->state = json_decode(file_get_contents($this->stateFile),true) ?: array();
    
    if (!$this->config["DISCORD_TOKEN"])
    {
      die("[discord] ERROR: Failed loading DISCORD_TOKEN");
    }
  }  
  function Save()
  {
    printf( "[discord] Saving state...\n");
    file_put_contents($this->stateFile,json_encode($this->state,JSON_PRETTY_PRINT));
  }
  
  function Request($url, $method, $data)
  {
    $data = $this->sideload->Request("https://discord.com/api/".$url, $method, json_encode($data), array(
      "Authorization" => "Bot " . $this->config["DISCORD_TOKEN"],
      "Content-type" => "application/json",
    ));
    if (!$this->sideload->WasSuccessful())
    {
      printf( "[discord] !!! Error sending message: %s\n",$data);
      return null;
    }
    $json = json_decode( $data, true );
    if (!$json)
    {
      printf( "[discord] !!! Error decoding message: %s\n", $data);
      return null;
    }
    return $json;
  }

  // https://discord.com/developers/docs/resources/guild#get-guild-channels
  function ResolveChannel($serverID, $discordChannelText)
  {
    $guild = $serverID;
    if (!$this->state["guilds"][$guild]["channels"][$discordChannelText])
    {
      printf( "[discord] Getting more recent channel list for guild %s ...\n",$guild);
      $json = $this->Request("guilds/".$guild."/channels", "GET", array());
      if ($json && $this->sideload->WasSuccessful())
      {
        foreach($json as $channel)
        {
          $this->state["guilds"][$guild]["channels"][$channel["name"]] = $channel["id"];
        }
      }
    }
    $discordChannel = $this->state["guilds"][$guild]["channels"][$discordChannelText];
    printf( "[discord] Resolved '%s' to %d\n",$discordChannelText,$discordChannel);
    return $discordChannel;
  }
  
  // https://discord.com/developers/docs/resources/channel#create-message
  function SendMessage($serverID, $channelName, $message)
  {
    if (is_string($message))
    {
      $message = array("content"=>$message);
    }
    printf( "[discord] Sending message to %s...\n",$channelName);
    $discordChannelText = $channelName;
    $discordChannel = $discordChannelText;
    if (!is_numeric($discordChannel))
    {
      $discordChannel = $this->ResolveChannel($serverID, $discordChannel);
    }
    if (!is_numeric($discordChannel))
    {
      printf( "[discord] !!! Failed to resolve '%s'\n",$channelName);
      return null;
    }
    
    return $this->Request("channels/".$discordChannel."/messages", "POST", $message );
  }
}
?>