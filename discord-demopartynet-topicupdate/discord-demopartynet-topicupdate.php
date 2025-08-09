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
$sideload = new Sideload();

$xmlText = $sideload->Request("https://www.demoparty.net/demoparties.xml");
if (!$xmlText)
{
  printf( "! ERROR: RSS source returned empty\n");
  exit();
}
$xml = new SimpleXMLElement($xmlText);

if ($xml)
{
  $n = 0;
  
  $now = "";
  $upNext = "";
  // :desktop: Assembly Winter Online 2021 (Mar 08-28)
  // :desktop:  Lovebyte 2021 (Mar 12-14)
  // :desktop: Revision 2021 (Apr 2-5)
  foreach($xml->channel->item as $item)
  {
    if ($n >= 4)
    {
      break;
    }
    $ns_dpnet = $item->children('https://www.demoparty.net/info/ns.html');

    if ($ns_dpnet->eventStatus != "EventScheduled" && $ns_dpnet->eventStatus != "EventMovedOnline")
    {
      continue;
    } 
    
    $str = "";
    if ($ns_dpnet->eventAttendanceMode == "OnlineEventAttendanceMode")
    {
      $str .= ":desktop: ";
    } 
    else
    {
      $str .= ":flag_".$ns_dpnet->country.": ";
    }
    
    $startTime = strtotime($ns_dpnet->startDate);
    $endTime = strtotime($ns_dpnet->endDate);
    
    $str .= $item->title;
    $str .= sprintf(" (%s", date("M j",$startTime) );
    if ( date("Y-m-d",$startTime) == date("Y-m-d",$endTime) )
    {
      // single day party
    }
    else if ( date("M",$startTime) == date("M",$endTime) )
    {
      $str .= sprintf("-%s", date("j",$endTime) ); // same month
    }
    else
    {
      $str .= sprintf("-%s", date("M j",$endTime) ); // party crosses over months
    }
    if ($startTime - time() > 0)
    {
      $str .= ", <t:".$startTime.":R>";
    }
    $str .= ")";
    
    
    $str .= "\n";
    
    if ($startTime - time() > 0)
    {
      $upNext .= $str;
    }
    else
    {
      $now .= $str;
    }
    
    $n++;
  }
  
  $channelID = $discord->ResolveChannel($CONFIG["discordGuild"], $CONFIG["discordChannel"]);
  
  $topic = "";
  if ($now)
  {
    $topic .= "\n**NOW:**\n".$now;
  }
  if ($now && $upNext)
  {
    $topic .= "\n // ";
  }
  if ($upNext)
  {
    $topic .= "\n**UP NEXT:**\n".$upNext;
  }
  printf( "* Patching channel %s topic to '%s'\n",$channelID,addcslashes($topic,"\n"));
  
  $response = $discord->Request("channels/".$channelID, "PATCH", array("topic"=>trim($topic)));
  print_r($response);
}

printf( "* Saving state...\n");
file_put_contents($stateFile,json_encode($STATE,JSON_PRETTY_PRINT));
?>
