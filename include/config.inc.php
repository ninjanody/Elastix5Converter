<?php
	define('DEBUG', true); 
	define('MAX_CONVERSIONS_THREAD', 8); 
	
	
	$upload_dir = "uploads/";
	$download_dir = "conversions/";
	$logs_dir = "logs/";
	
	//logging
		error_reporting(E_ALL); 
		if(DEBUG == true)
		{
			ini_set('display_errors', 1);
			ini_set("log_errors", 1);
		}
		else
		{
			ini_set('display_errors', 0);
			ini_set("log_errors", 1);
		}
		
?>