<?php
set_time_limit(3600);

	require_once "include/config.inc.php"; 
	
	//check for pending jobs
	
	//list upload folder
	$directories = glob($upload_dir.'*' , GLOB_ONLYDIR);
	
	//if jobs folder found
	if(count($directories)>0)
	{
		$nb_conversions_ongoing=0;
		$nb_new_conversions=0;
		
		foreach($directories as $k=>$dir)
		{
			//check if file "CONVERTED" exists then skip and go to next 
			//or check if "CONVERTING" exists then skip but count number of conversions ongoing
			if(!file_exists($dir."/CONVERTED"))
			{
				if(file_exists($dir."/CONVERTING") or file_exists($dir."/PENDING"))
				{
					$nb_conversions_ongoing++;
				}
			}
			else unset($directories[$k]);
		}
		
		if($nb_conversions_ongoing<MAX_CONVERSIONS_THREAD)		
		{
			foreach($directories as $dir)
			{
				if(!file_exists($dir."/CONVERTING") && !file_exists($dir."/PENDING") && ($nb_conversions_ongoing+$nb_new_conversions)<MAX_CONVERSIONS_THREAD)
				{
					$h=fopen($dir."/PENDING","w");
					fwrite($h,time());
					fclose($h);
					
					$nb_new_conversions++;
				}
			}
		}
		else
			cron_log("There are already $nb_conversions_ongoing conversions ongoing.");
		
		
		cron_log("There are $nb_conversions_ongoing conversions ongoing and $nb_new_conversions new conversions marked to start.");
		
	}
	else
	{
		cron_log("No pending job.");
		
		
		//clear log when there's no conversion pending, keep last 10000 lines
			$lines = file($logs_dir."cron_converter.log");
			$flipped = array_reverse($lines);
			$keep = array_slice($flipped,0, 10000);
			$keep=implode("",$keep);
			$h=fopen($logs_dir."cron_converter.log","w");
			fwrite($h,$keep);
			fclose($h);
		
		die();
	}
	
    // open up to MAX_CONVERSIONS_THREAD new processes
	for ($i=0; $i<$nb_new_conversions; $i++) {
		cron_log("open new converter process");
		$cmd = 'wget -qO- http://127.0.0.1/converter.php'; //run wget threads which will not wait for the output
		cron_log($cmd);
		exec($cmd,$pid);
		cron_log(print_r($pid,true)); //log output
		pclose(popen($cmd, 'r'));
	}
	

function cron_log($msg)
{
	global $logs_dir;
	error_log(date("d/m/y H:i:s")." | ".$msg."\r\n",3,$logs_dir."cron_converter.log");
	
}

?>