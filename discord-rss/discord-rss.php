<?php
error_reporting(E_ALL & ~E_NOTICE);

include_once(dirname(__FILE__) . "/../discord.inc.php");

$configFile = dirname(__FILE__) . "/config.json";
$stateFile = dirname(__FILE__) . "/.state.json";

printf( "*** Starting check: %s\n",date("Y-m-d H:i:s"));
printf( "* Config: %s\n",$configFile);
printf( "* State: %s\n",$stateFile);

$CONFIG = json_decode(file_get_contents($configFile),true);
$STATE = json_decode(file_get_contents($stateFile),true) ?: array();

$discord = new Discord($configFile, dirname(__FILE__) . "/.discord.state.json");

foreach($CONFIG["feeds"] as $feed)
{
  $data = @file_get_contents($feed["url"]);
  if (!$data)
  {
    printf("! Failed to load URL: '%s'\n",$feed["url"]);
    continue;
  }
  $xml = simplexml_load_string($data);
  if (!$xml)
  {
    printf("! Failed to parse data to XML\n");
    continue;
  }
  
  foreach($xml->channel->item as $item)
  {
    $time = strtotime($item->pubDate);
    if ($time > $STATE[$feed["url"]])
    {
      $STATE[$feed["url"]] = $time;

      $message = array(
        "content" => sprintf("**%s** >> %s (%s)", $feed["name"], $item->title, $item->link),
        /*
        "embed" => array(
          "title" => $item->title,
          "url" => $item->link
        ),
        */
      );

      $response = $discord->SendMessage(
        $feed["discordGuild"] ?: $CONFIG["discordGuild"],
        $feed["discordChannel"] ?: $CONFIG["discordChannel"],
        $message);
    }
  }
}

printf( "* Saving state...\n");
file_put_contents($stateFile,json_encode($STATE,JSON_PRETTY_PRINT));
?>
