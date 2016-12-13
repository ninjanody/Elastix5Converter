# Elastix5Converter
Backup converter for Elastix 2.5/4.0 to Elastix 5, in PHP

Pre-requisites:
- Linux host
- Apache with PHP5 or PHP7
- SQLite3 extensions loaded
- Email sending configured

Installation:
Adjust the 3 paths from include/config.inc.php with 	
	$upload_dir = "uploads/";
	$download_dir = "conversions/";
	$logs_dir = "logs/";

Adjust the max number of converter threads allowed to run simultaneously
	define('MAX_CONVERSIONS_THREAD', 8); 
	
Add a cron every minute to run cron_converter.php