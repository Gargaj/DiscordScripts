<?php
error_reporting(E_ALL & ~E_NOTICE);

include_once(dirname(__FILE__) . "/../sideload.inc.php");
include_once(dirname(__FILE__) . "/../discord.inc.php");

$configFile = dirname(__FILE__) . "/config.json";
$stateFile = dirname(__FILE__) . "/.state.json";

printf( "*** Starting check: %s\n",date("Y-m-d H:i:s"));
printf( "* Config: %s\n",$configFile);
printf( "* State: %s\n",$stateFile);

$CONFIG = json_decode(file_get_contents($configFile),true);
$STATE = json_decode(file_get_contents($stateFile),true) ?: array();

$sideload = new Sideload();
$discord = new Discord($configFile, dirname(__FILE__) . "/.discord.state.json");

$checkables = array();

$prods = array();
foreach($CONFIG["monitors"] as $monitor)
{
  if ($monitor["groupID"])
  {
    printf( "* Checking groupID %d...\n",$monitor["groupID"]);
    if (!$STATE["groups"][$monitor["groupID"]])
    {
      printf( "  * Fetching prods...\n",$monitor["groupID"]);
      $data = $sideload->Request("https://api.pouet.net/v1/group/", "GET", array("id"=>$monitor["groupID"]));
      $json = json_decode($data, true);
      if ($json["group"] && $json["group"]["prods"])
      {
        foreach($json["group"]["prods"] as $v)
        {
          $STATE["groups"][$monitor["groupID"]][] = array(
            "id" => $v["id"],
            "name" => $v["name"],
          );
          $STATE["releaseDates"][$v["id"]] = $v["addedDate"];
        }
      }
    }
    foreach($STATE["groups"][$monitor["groupID"]] as $v)
    {
      $checkables[] = $v["id"];
    }
  }
  if ($monitor["prodID"])
  {
    $checkables[] = $monitor["prodID"];
  }
}

$latestTime = time();
$latestProd = 0;
foreach($checkables as $prodID)
{
  if ($STATE["prodsLastChecked"][$prodID] < $latestTime)
  {
    $latestTime = $STATE["prodsLastChecked"][$prodID];
    $latestProd = $prodID;
  }
}

// override for prods that are less than two weeks old
foreach($checkables as $prodID)
{
  $relTime = strtotime($STATE["releaseDates"][$prodID]);
  if (time() - $relTime < 7 * 24 * 60 * 60)
  {
    printf( "* Prod %d is newer than a week!", $prodID);
    $latestProd = $prodID;
  }
}

if ($latestProd)
{
  $STATE["prodsLastChecked"][$latestProd] = time();

  printf( "* Checking prod %d...\n",$latestProd);
  $data = $sideload->Request("https://api.pouet.net/v1/prod/comments/", "GET", array("id"=>$latestProd));
  $json = json_decode($data, true);

  printf( "  * Prod name is \"%s\"...\n",$json["prod"]["name"]);
  
  $latestComment = null;
  if ($json["prod"]["comments"])
  {
    foreach($json["prod"]["comments"] as $v)
    {
      if (!$latestComment || $latestComment["addedDate"] < $v["addedDate"])
      {
        $latestComment = $v;
      }
    }
  }

  printf( "  * Latest comment was \"%s\"\n",$latestComment["addedDate"]);
  
  if ($STATE["prodsLastComment"][$latestProd] < $latestComment["addedDate"])
  {
    printf( "  * New comment! (Last registered was %s)\n",$STATE["prodsLastComment"][$latestProd]);

    $STATE["prodsLastComment"][$latestProd] = $latestComment["addedDate"];

    $voteNames = array(
      -1 => "thumb down",
       0 => "comment",
       1 => "thumb up"
    );
    $emoji = array(
      -1 => "\xf0\x9f\x91\x8e",
       0 => "",
       1 => "\xf0\x9f\x91\x8d",
    );
    $colors = array(
      -1 => 0xF04747,
       0 => 0xFAA61A,
       1 => 0x43B581,
    );

    $date = new DateTime($latestComment["addedDate"],timezone_open("Europe/Budapest"));
    $date->setTimezone(timezone_open("Europe/Budapest"));
    
    // https://leovoel.github.io/embed-visualizer/
    $message = array(
      /*
      "content" => sprintf("**%s** got a new %s (https://www.pouet.net/prod.php?post=%d) by *%s*:\n%s", 
        $json["prod"]["name"], $voteNames[$latestComment["rating"]], $latestComment["id"], $latestComment["user"]["nickname"], "> ".str_replace("\n","\n> ",$latestComment["comment"])),
      */
      "content" => sprintf("New %s on %s by %s! %s", $voteNames[$latestComment["rating"]], $json["prod"]["name"], $latestComment["user"]["nickname"], $emoji[$latestComment["rating"]]),
      "embeds" => array(
        array(
          "title"       => $json["prod"]["name"],
          "description" => $latestComment["comment"],
          "color"       => $colors[$latestComment["rating"]],
          "url"         => sprintf("https://www.pouet.net/prod.php?post=%d",$latestComment["id"]),
          "timestamp"   => $date->format('c'),
          "thumbnail"   => array(
            "url" => $json["prod"]["screenshot"],
          ),
          "author"      => array(
            "name"     => $latestComment["user"]["nickname"],
            "icon_url" => "https://content.pouet.net/avatars/".$latestComment["user"]["avatar"],
          ),
        )
      )
    );
    
    $response = $discord->SendMessage(
      $monitor["discordGuild"] ?: $CONFIG["discordGuild"],
      $monitor["discordChannel"] ?: $CONFIG["discordChannel"],
      $message);
      
    var_dump($response);
    if (!$response)
    {
      exit();
    }
  }
}
  
printf( "* Saving state...\n");
file_put_contents($stateFile,json_encode($STATE,JSON_PRETTY_PRINT));
?>
