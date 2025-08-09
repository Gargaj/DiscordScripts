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

$secPerDay = 60 * 60 * 24;
foreach($CONFIG["monitors"] as $monitor)
{
  $days = (strtotime($monitor["date"]) - time()) / $secPerDay;
  printf("* %.2f days until %s\n",$days,$monitor["name"]);
  $days = (int)$days;
  if ($days <= 0)
  {
    continue;
  }
  foreach($CONFIG["frequencies"] as $frequency)
  {
    if ($days < $frequency["lessThan"])
    {
      printf("Checking %d...\n",$frequency["lessThan"]);
      if ($days % $frequency["frequency"] == 0)
      {
        $message = sprintf( "**T-%d** days until **%s**!",$days, $monitor["name"] );
        if($frequency["warning"])
        {
          $message .= " \xe2\x9a\xa0";
        }
        printf("Notifying (%d => %d)...\n",$frequency["lessThan"],$days % $frequency["frequency"]);
        $response = $discord->SendMessage(
          $monitor["discordGuild"] ?: $CONFIG["discordGuild"],
          $monitor["discordChannel"] ?: $CONFIG["discordChannel"],
          $message);
      }
      break;
    }
  }
}

printf( "* Saving state...\n");
file_put_contents($stateFile,json_encode($STATE,JSON_PRETTY_PRINT));
?>
