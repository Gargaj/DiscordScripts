<?php
/*

  NOTE: FOR THIS TO WORK YOU NEED A GITHUB WEBHOOK THAT DUMPS THE PAYLOAD INTO A JSON IN THIS DIR

  e.g. something like this:
    file_put_contents(sprintf("discord-github/blob.%s.%s.json",$action,$hash),$inputRaw);

*/
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

function do_send($msg,$embeds)
{
  global $discord, $CONFIG, $STATE;
  
  $msg = trim($msg);
  
  $data = array();
  if ($msg) $data["content"] = $msg;
  if ($embeds) $data["embeds"] = $embeds;
  
  $response = null;
  if ($data)
  {
    $response = $discord->SendMessage( $CONFIG["discordGuild"], $CONFIG["discordChannel"], $data);
  }
  return $response;
}

$jsonz = glob(dirname(__FILE__)."/blob.*.json");
$msg = "";
$embeds = array();
$toDelete = array();
foreach($jsonz as $json)
{
  $data = @json_decode(file_get_contents($json), true);

  preg_match("/blob\.(.*)\.([a-z0-9]*)\.json/",$json,$m);
  $action = $m[1];
  $hash = $m[2];

  printf( "* Parsing %s: %s...\n",$action,$json);

  switch($action)
  {
    case "issues":
      {
        switch($data["action"])
        {
          case "opened":
            {
              if ($data["issue"]["body"])
              {
                $msg .= sprintf("New Github issue to **%s** by %s (%s):\n**%s**\n```%s```\n",
                    $data["repository"]["name"],
                    $data["issue"]["user"]["login"],
                    $data["issue"]["html_url"],
                    $data["issue"]["title"],
                    $data["issue"]["body"]);
              }
              else
              {
                $msg .= sprintf("New Github issue to **%s** by %s (%s):\n**%s**\n",
                    $data["repository"]["name"],
                    $data["issue"]["user"]["login"],
                    $data["issue"]["html_url"],
                    $data["issue"]["title"]);
              }
            }
            break;
        }
        $toDelete[] = $json;
      }
      break;
    case "push":
      {
        if ($data["commits"])
        {
          $ref = "master";
          if (preg_match("/refs\/heads\/(.*)/",$data["ref"],$m))
          {
            $ref = $m[1];
          }
          foreach($data["commits"] as $commit)
          {
            $msg .= sprintf("New Github commit to **%s** by %s (%s):\n```%s```\n",
              $data["repository"]["name"] . ( ($ref!="master" && $ref!="main") ? " (".$ref.")" : "" ),
              $commit["author"]["name"],
              $commit["url"],
              $commit["message"]);
          }
        }

        $toDelete[] = $json;
      }
      break;
    case "build-artifact":
      {
        // https://leovoel.github.io/embed-visualizer/
        $refSize = (int)$data["referenceSize"];
        $embed = array(
          "title"       => sprintf("Workflow **%s/%s** has produced an artifact successfully!",$data["github"]["workflow"], $data["github"]["job"]),
          "description" => "",
          "color"       => 0x1A560D,
          "url"         => $data["run_url"],
          "timestamp"   => date('c'),
          "fields" => array(
            array(
              "name"   => "Artifact",
              "value"  => "[".$data["artifactName"]."](".$data["artifact"]["artifact-url"].")",
              "inline" => true
            ),
            array(
              "name"   => "Archon version used",
              "value"  => $data["archonVersion"],
              "inline" => true
            ),
          ),
        );
        if ($data["github"] && $data["github"]["event"] && $data["github"]["event"]["commits"])
        {
          $commits = "";
          foreach($data["github"]["event"]["commits"] as $commit)
          {
            $commits .= sprintf("[`%s`](%s) %s\n",substr($commit["id"],0,7),$commit["url"],$commit["message"]);
          }
          $embed["fields"][] =  array(
            "name"   => "Commits involved",
            "value"  => $commits,
          );
        }
        $thumbPath = "images/workflow-thumbnails/".$data["github"]["workflow"].".png";
        if (file_exists(dirname(__FILE__)."/../../public_html/".$thumbPath))
        {
          $embed["thumbnail"] = array("url"=>"https://conspiracy.hu/".$thumbPath);
        }
        if ($data["stats"])
        {
          foreach($data["stats"] as $name=>$size)
          {
            if ((int)$data["stats"]["kkrunchyDelta"] > 0)
            {
              $embed["description"] .= "\u{1F6A8} ";
            }
            $embed["description"] .= sprintf("**%s** = %d bytes (%.2fKB)\n",$name,$size,$size/1024.0);
          }
        }
        $embeds[] = $embed;
        
        $toDelete[] = $json;
      }
      break;
    case "build-failure":
      {
        $ref = "master";
        if (preg_match("/refs\/heads\/(.*)/",$data["github"]["ref"],$m))
        {
          $ref = $m[1];
        }
        $msg .= sprintf("\u{274C} Github action failure on **%s** in job **%s/%s**: %s\n",
          $data["github"]["repository"] . ( ($ref!="master" && $ref!="main") ? " (".$ref.")" : "" ),
          $data["github"]["workflow"],
          $data["github"]["job"],
          $data["run_url"]);
        if ($data["errors"])
        {
          $msg .= "```accesslog\n".$data["errors"]."\n```\n";
        }

        $toDelete[] = $json;
      }
      break;
    case "build-size-report":
      {
        $ref = "master";
        if (preg_match("/refs\/heads\/(.*)/",$data["github"]["ref"],$m))
        {
          $ref = $m[1];
        }
        if ($data["steps"] && $data["steps"]["sizes"] && $data["steps"]["sizes"]["outputs"])
        {
          $msg .= sprintf("\u{1F9EE} Github action size report for **%s** (%s):\n",
            $data["github"]["repository"] . ( ($ref!="master" && $ref!="main") ? " (".$ref.")" : "" ),
            $data["github"]["event"]["compare"]);
          foreach($data["steps"]["sizes"]["outputs"] as $name=>$size)
          {
            $msg .= sprintf("**%s** = %d bytes (%.2fKB)\n",$name,$size,$size/1024.0);
          }
        }

        $toDelete[] = $json;
      }
      break;
    case "release":
      {
        if ($data["action"]=="published")
        {
          $msg .= sprintf("New **%s** release **%s (%s)**: %s",
            $data["repository"]["name"],
            $data["release"]["tag_name"],
            $data["release"]["name"],
            $data["release"]["html_url"]);

          if ($data["release"]["body"])
          {
            $msg .= sprintf("\n```%s```",$data["release"]["body"]);
          }
          $msg .= "\n";
        }
        $toDelete[] = $json;
      }
      break;
  }
  if (strlen($msg) > 1500 || count($embeds) > 5)
  {
    $response = do_send($msg,$embeds);
    $embeds = array();
    $msg = "";
    if ($response && $response["id"])
    {
      foreach($toDelete as $v) unlink($v);
      $toDelete = array();
    }
    else
    {
      echo "*** ERROR:\n";
      print_r($response);
    }
  }
}

if ($msg || $embeds)
{
  $response = do_send($msg,$embeds);
  if ($response && $response["id"])
  {
    foreach($toDelete as $v) unlink($v);
  }
  else
  {
    echo "*** ERROR:\n";
    print_r($response);
  }
}

printf( "* Saving state...\n");
file_put_contents($stateFile,json_encode($STATE,JSON_PRETTY_PRINT));
?>