<?php

require_once 'token.php';
$worker = new GearmanWorker();
$worker->addServer();

function checkStatus($myid){
    echo "Checking ".$myid."\n";
    $curlOraclize = curl_init();

    curl_setopt($curlOraclize, CURLOPT_URL, 'https://api.oraclize.it/v1/contract/'.$myid.'/status');
    curl_setopt($curlOraclize, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curlOraclize, CURLOPT_USERAGENT, 'Oraclize Poll Bot');
    curl_setopt($curlOraclize, CURLOPT_HTTPHEADER, array('Content-type' => 'application/json'));

    $resp = json_decode(curl_exec($curlOraclize),true);
    curl_close($curlOraclize);
    return $resp["result"];
}

function resetOffset($offset){
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot".constant('BOT_TOKEN')."/getUpdates?offset=".$offset."&limit=1");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type' => 'application/json'));

    curl_exec($ch);

    curl_close($ch);
}

function sendMessage($text,$chatid){
	$ch = curl_init();

	$query = array(
		"text" => $text,
		"chat_id" => $chatid,
		"parse_mode" => "markdown"
	);

	$query = http_build_query($query);

    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot".constant('BOT_TOKEN')."/sendMessage?".$query);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type' => 'application/json'));

    curl_exec($ch);

    curl_close($ch);
}

function sendDocument($topost) {
  $ch = curl_init();

   curl_setopt($ch, CURLOPT_URL, 'https://api.telegram.org/bot'.constant("BOT_TOKEN").'/sendDocument');
   curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
   curl_setopt($ch, CURLOPT_POST, 1);
   curl_setopt($ch, CURLOPT_POSTFIELDS, $topost);
   curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type' => 'multipart/form-data'));
   $resp = json_decode(curl_exec($ch),true);

   curl_close($ch);
   return $resp["result"];
 }

$worker->addFunction("oraclizeCheckStatus", function(GearmanJob $job) {
    $workload = json_decode($job->workload());
    $myid = $workload->myid;
    $chatId = $workload->chatId;
    $thisPoll = $workload->thisPoll;
    $user_vote = json_decode(json_encode($workload->user_vote),true);
    $status_response = checkStatus($myid);
    $query_result = true;
    $proofList = array();
    while($query_result==true) {
	$status_response = checkStatus($myid);
	if(isset($status_response["checks"])){
	try {
	    $last_check = $status_response["checks"][count($status_response["checks"])-1];
      	    $query_result = $status_response["active"];
      	    if($query_result==false) $proofList = $last_check["proofs"];
	} catch (Exception $e) {
	    echo 'Caught exception: ',  $e->getMessage(), "\n";
	}
      }
      sleep(5);
    }
    resetOffset($workload->reset_offset);
        
    $file = tempnam("tmp","zip");
    $zip = new ZipArchive();
    $zip->open($file,ZipArchive::CREATE);

    $count = 0;
    foreach ($proofList as $value) {
      if(empty($value)){
        $proofContent = "";
      } else {
    	$value = hex2bin($value["value"]);
        $proofContent = $value;
      }
      $username = preg_replace("/[^a-zA-Z0-9]+/","",$user_vote[$count]["username"]);
      $filename = "vote_".$username."_".$user_vote[$count]["id"].".proof.pgsg";
      $zip->addFromString($filename,$proofContent);
      $count += 1;
    }
    $zip->close();
    sendMessage("📦 *Oraclize* has just generated for you the following archive.
🔐 It contains cryptographic proofs showing the authenticity of each vote.
✅ Keep it in a safe place for your records.",$chatId);
    $pollTitle = preg_replace("/[^a-zA-Z0-9]+/","",$thisPoll);
    sendDocument(["document"=>new \CurlFile($file,'archive/zip','poll'.$chatId.'_'.$pollTitle.'_'.$workload->poll_closed.'.zip'),"chat_id"=>$chatId]);
    unlink($file);
});

while ($worker->work());
