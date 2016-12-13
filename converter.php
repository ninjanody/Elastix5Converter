<?php
set_time_limit(3600);

	require_once "include/config.inc.php"; 
	require_once "include/random.inc.php"; 
	require_once "include/utilities.inc.php"; 
	require_once "include/elastix5.inc.php"; 
	
	//check for pending jobs
	
	//list folders
	$directories = glob($upload_dir.'*' , GLOB_ONLYDIR);
	
	//if jobs folder found
	if(count($directories)>0)
	{		
		foreach($directories as $dir)
		{
			//check if there are jobs marked for conversion by scheduler, if one is found take this one for this thread
			if(file_exists($dir."/PENDING"))
			{
				$h=fopen($dir."/CONVERTING","w");
				fwrite($h,getmypid());
				fclose($h);
				
				unlink($dir."/PENDING");
				
				$current_job_time=basename($dir);
				
				break;
			}
			
		}
	}
	
	if(!isset($current_job_time)) die("No pending job.");
	
	print($current_job_time);
	
	
	$upload_dir=$upload_dir.$current_job_time;
	//logging
	ini_set("error_log", $upload_dir."/$current_job_time.log"); //log file per job
	
	$to=file_get_contents($upload_dir."/EMAIL");
	$ext_length=file_get_contents($upload_dir."/EXT_LENGTH");
	
	
	log1("Job started");
	$nb_obj_converted=0;
	$user_friendly_errors=""; //errors gathered for email sending
	$nb_errors=0;
	
	$files = glob($upload_dir."/*.{tar}", GLOB_BRACE);
	if(!isset($files[0])) 
	{
		$e="Tar file not found.";
		log1($e);
	
		mark_job_completed($dir);
		
		die();
	}
		
	$tar_file=$files[0];
	
	//EXTRACT TAR BACKUP FILE
	try {
		$phar = new PharData("$tar_file");
		$phar->extractTo($upload_dir);
		} catch (Exception $e) {
			$e="File extract failed.";
			log1($e);
			$user_friendly_errors.="- $e\r\n";
			$nb_errors++;
			send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
			mark_job_completed($dir);
			die();
		}
	
	//define system extensions from beginning
	$system_extensions=array();
	if($ext_length==2) { 
		$system_extensions['fax_ext']=88;
		$system_extensions['pin_ext']=77;
		$system_extensions['special_ext']=99;
		$system_extensions['conf_ext']=70;
	}
	if($ext_length==3) { 
		$system_extensions['fax_ext']=888;
		$system_extensions['pin_ext']=777;
		$system_extensions['special_ext']=999;
		$system_extensions['conf_ext']=700;
	}
	if($ext_length==4) { 
		$system_extensions['fax_ext']=8888;
		$system_extensions['pin_ext']=7777; 
		$system_extensions['special_ext']=9999;
		$system_extensions['conf_ext']=7000;
	}
	if($ext_length==5) {
		$system_extensions['fax_ext']=88888;
		$system_extensions['pin_ext']=77777; 
		$system_extensions['special_ext']=99999;
		$system_extensions['conf_ext']=70000; 
	}
	
	//check if the asterisk settings were included
	if(file_exists("$upload_dir/backup/etc.asterisk.tgz"))
	{
		//decompress from gz				
		$p = new PharData("$upload_dir/backup/etc.asterisk.tgz");
		$p->decompress(); 

		//unarchive from the tar
		$phar = new PharData("$upload_dir/backup/etc.asterisk.tgz");
		mkdir($upload_dir."/backup/etc.asterisk");
		$phar->extractTo("$upload_dir/backup/etc.asterisk");
		
		//Read SIP extensions data from sip_additional.conf
		$extensions=array();
		if(file_exists("$upload_dir/backup/etc.asterisk/asterisk/sip_additional.conf"))
		{
			//read as ini
			$sip_additional=parse_ini_file("$upload_dir/backup/etc.asterisk/asterisk/sip_additional.conf",true);
			
			$keys=array_keys($sip_additional);
			
			foreach($keys as $key)
			{
				//extensions have an encryption field while trunks haven't so it's a trick to dissociate them as they are also listed in the ini file
				if(isset($sip_additional[$key]['encryption']))
				{
					//get extensions data
					
						$name=$sip_additional[$key]['callerid'];
						//remove ext. num from caller id
						$name=str_replace(" <$key>","",$name);
						//check if 1 space in the callerid, if so split as first name/last name
						if(substr_count($name," ")==1)
						{
							$tmp=explode(" ",$name);
							$firstname=$tmp[0];
							$lastname=$tmp[1];
						}
						else
						{
							$firstname=$name;
							$lastname="";
						}
												
						$extensions[$key]=array(
							"password"=>$sip_additional[$key]['secret'],
							"firstname"=>$firstname,
							"lastname"=>$lastname
						);
						$nb_obj_converted++;
				}
				else
				{
					if(isset($sip_additional[$key]['host']))
					{
						if(isset($sip_additional[$key]['port'])) $port=$sip_additional[$key]['port']; else $port=5060; 
						
						//trunks data
						$trunks_outboundid[$key]=array(
							"host"=>$sip_additional[$key]['host'],
							"port"=>$port,
							"username"=>$sip_additional[$key]['username'],
							"secret"=>$sip_additional[$key]['secret']
						);
					}
				}
				
				
			}
		}
		else 
		{
			$e="File etc.asterisk.tgz/sip_additional.conf is missing from backup, please redo a backup which includes Asterisk settings.";
			log1($e);
			$user_friendly_errors.="- $e\r\n";
			$nb_errors++;
			send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
			mark_job_completed($dir);
			die($e);
		}
		
		
		//Read IAX extensions data from iax_additional.conf
		if(file_exists("$upload_dir/backup/etc.asterisk/asterisk/iax_additional.conf"))
		{
			//first we need to fix ini syntax, searching for "setvar=REALCALLERIDNUM=105" entries and removing the second equal sign
			$ini=file_get_contents("$upload_dir/backup/etc.asterisk/asterisk/iax_additional.conf");
			$ini=str_replace("REALCALLERIDNUM=","REALCALLERIDNUM",$ini);
			$h=fopen("$upload_dir/backup/etc.asterisk/asterisk/iax_additional.conf","w");
			fwrite($h,$ini);
			fclose($h);
			
			//read as ini
			$iax_additional=parse_ini_file("$upload_dir/backup/etc.asterisk/asterisk/iax_additional.conf",true);
			
			$keys=array_keys($iax_additional);
			foreach($keys as $key)
			{
				if(isset($iax_additional[$key]['callerid'])) //trick to dissociate IAX extensions from IAX trunks, no callerid field for trunks
				{
						
					$name=$iax_additional[$key]['callerid'];
					//remove ext. num from caller id
					$name=str_replace(" <$key>","",$name);
					//check if 1 space in the callerid, if so split as first name/last name
					if(substr_count($name," ")==1)
					{
						$tmp=explode(" ",$name);
						$firstname=$tmp[0];
						$lastname=$tmp[1];
					}
					else
					{
						$firstname=$name;
						$lastname="";
					}
											
					$extensions[$key]=array(
						"password"=>$iax_additional[$key]['secret'],
						"firstname"=>$firstname,
						"lastname"=>$lastname
					);
					
					$nb_obj_converted++;
				}					
				
			}
		}
		else 
		{
			$e="File etc.asterisk.tgz/iax_additional.conf is missing from backup, please redo a backup which includes Asterisk settings.";
			log1($e);
			$user_friendly_errors.="- $e\r\n";
			$nb_errors++;
			send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
			mark_job_completed($dir);
			die($e);
		}
		
		//check extensions numbers
		if(count($extensions)>0)
		{
			//check extensions length
				foreach($extensions as $num=>$data)
				{
					if(strlen($num)>$ext_length) //if longer than dial plan skip this extension
					{
						unset($extensions[$num]); 
						$e="Extension $num was not imported due to length longer than dial plan size (".strlen($num)." vs $ext_length). Recreate it manually or run the tool again and modify options.";
						$user_friendly_errors.="- $e\r\n";
						$nb_errors++;
						log1($e);
					}
					if(strlen($num)<$ext_length) //if smaller than dial plan, fill with zeros infront
					{
						$new_num=$num;
						while(strlen($new_num)<$ext_length)
						{
							$new_num="0$new_num";
						}
						$extensions[$new_num]=$extensions[$num];
						unset($extensions[$num]);
						$nb_obj_converted--;
					}
				}
				ksort($extensions); //resort by num
				
			//check if an imported extension has system extension reserved number, then skip it
				if(isset($extensions[ $system_extensions['fax_ext'] ])) { 
					unset($extensions[ $system_extensions['fax_ext'] ]);
					$nb_obj_converted--;
					$e="Extension ".$system_extensions['fax_ext']." was not imported due to conflicting with default Fax extension number.";
					$user_friendly_errors.="- $e\r\n";
					$nb_errors++;
					log1($e);
				}
				if(isset($extensions[ $system_extensions['pin_ext'] ])) { 
					unset($extensions[ $system_extensions['pin_ext'] ]);
					$nb_obj_converted--;
					$e="Extension ".$system_extensions['pin_ext']." was not imported due to conflicting with default PIN Protect extension number.";
					$user_friendly_errors.="- $e\r\n";
					$nb_errors++;
					log1($e);
				}
				if(isset($extensions[ $system_extensions['special_ext'] ])) { 
					unset($extensions[ $system_extensions['special_ext'] ]);
					$nb_obj_converted--;
					$e="Extension ".$system_extensions['special_ext']." was not imported due to conflicting with default Special Menu extension number.";
					$user_friendly_errors.="- $e\r\n";
					$nb_errors++;
					log1($e);
				}
				if(isset($extensions[ $system_extensions['conf_ext'] ])) { 
					unset($extensions[ $system_extensions['conf_ext'] ]);
					$nb_obj_converted--;
					$e="Extension ".$system_extensions['conf_ext']." was not imported due to conflicting with default Conference Menu extension number.";
					$user_friendly_errors.="- $e\r\n";
					$nb_errors++;
					log1($e);
				}

		}
		
		
		//read web password from manager.conf
		$manager=array();$admin=array();
		if(file_exists("$upload_dir/backup/etc.asterisk/asterisk/manager.conf"))
		{
			
			$manager=parse_ini_file("$upload_dir/backup/etc.asterisk/asterisk/manager.conf");
			
			$keys=array_keys($manager);
			if(isset($manager['secret']))
			{
				$admin['password']=$manager['secret'];
			
				$nb_obj_converted++;
			}
		}
		else 
		{
			$e="File etc.asterisk.tgz/manager.conf is missing from backup, please redo a backup which includes Asterisk settings.";
			log1($e);
			$user_friendly_errors.="- $e\r\n";
			$nb_errors++;
			send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
			mark_job_completed($dir);
			die($e);
		}
		unset($manager);
		
		
		//read extensions emails from voicemail.conf
		if(file_exists("$upload_dir/backup/etc.asterisk/asterisk/voicemail.conf"))
		{
			//read file
			$tmp=file_get_contents("$upload_dir/backup/etc.asterisk/asterisk/voicemail.conf");
			//format expected: 2000 => ,Test,xx@3cx.com,,attach=no|saycid=no|envelope=no|delete=no
			
			//regex to gather extension/email pairs
			preg_match_all("#([0-9]+) \=\> (.*),((.*)@(.*)),#",$tmp,$split);
			
			if(count($split)>0) //if emails were found
			{
				/*format expected: 
[0] => Array
	(
		[0] => 100 => ,test1,xx@3cx.com,,
	)
[1] => Array
	(
		[0] => 100
	)
[3] => Array
	(
		[0] => xx@3cx.com,
	)
				*/
				foreach($split[0] as $k=>$v)
				{
					$ext=$split[1][$k];
					$email=substr($split[3][$k],0,-1);
					
					if(isset($extensions[$ext])) //first check if an extension array exists (this will mean that it is either a SIP or IAX extension, others types are ignored)
						$extensions[$ext]['email']=$email;
				}
			}
			
		}
		else 
		{
			$e="File etc.asterisk.tgz/voicemail.conf is missing from backup, please redo a backup which includes Asterisk settings.";
			log1($e);
			$user_friendly_errors.="- $e\r\n";
			$nb_errors++;
			send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
			mark_job_completed($dir);
			die($e);
		}
		
	
	
		//read extensions SIP ID, outbound routes, and IVR from extensions_additional.conf
		if(file_exists("$upload_dir/backup/etc.asterisk/asterisk/extensions_additional.conf"))
		{
			//read file
			$tmp=file_get_contents("$upload_dir/backup/etc.asterisk/asterisk/extensions_additional.conf",true);
			
			//extensions SIP ID
			if(count($extensions)>0)
			foreach($extensions as $ext=>$v)
			{
				//regex to gather extension/email pairs
				//format expected: "exten => 007123456,1,Goto(from-internal,100,1)"
				preg_match_all("#=> (.*),1,Goto\(from-internal,$ext,#",$tmp,$split);
				
				if(count($split[0])>0) //if emails were found
				{
					$extensions[$ext]['sipid']=$split[1][0];
					/*format expected: 
[0] => Array
	(
		[0] =>  => 007123456,1,Goto(from-internal,100,
	)
[1] => Array
	(
		[0] => => 007123456
	)
					*/
				}
			}
			
			//get outbound routes
			
			/*
[outbound-allroutes]
include => outbound-allroutes-custom
include => outrt-2 ; out-12345
include => outrt-3 ; out-67
include => outrt-4 ; out-89
include => outrt-5 ; out-0
include => outrt-1 ; 9_outside
exten => foo,1,Noop(bar)

;--== end of [outbound-allroutes] ==--;
			*/
			
			$routes=array();
			//check if outbound route section is present
				$pos1=strpos($tmp,"[outbound-allroutes]");
				$pos2=strpos($tmp,";--== end of [outbound-allroutes]");
				if($pos1!==FALSE and $pos2!==FALSE)
				{
					//get section
					$routes_section=substr($tmp,$pos1,$pos2-$pos1);
					
					//search for routes names and position
					preg_match_all("/include \=\> outrt\-([0-9]+) ; (.*)/",$routes_section,$routes_match);
					
					foreach($routes_match[0] as $k=>$match)
					{
						$routes[$k]=array(
							"id"=>$routes_match[1][$k],
							"name"=>$routes_match[2][$k],
							"pos"=>$k+1 //routes will have priority starting from 1 so that emergency numbers have priority number 0 and are above.
						);
						$nb_obj_converted++;
					}
					
				}
				
			
			//search for the detailled section of each outbound route
				if(count($routes)>0)
				foreach($routes as $k=>$route)
				{
					$pos1=strpos($tmp,"[outrt-".$route['id']."] ; ".$route['name']);
					$pos2=strpos($tmp,";--== end of [outrt-".$route['id']."] ==--;");
					if($pos1!==FALSE and $pos2!==FALSE)
					{
						
						//get section
						$route_section=substr($tmp,$pos1,$pos2-$pos1);
/*							
[outrt-2] ; out-12345
include => outrt-2-custom
exten => _[12-5],1,Macro(user-callerid,LIMIT,EXTERNAL,)
exten => _[12-5],n,Set(MOHCLASS=${IF($["${MOHCLASS}"=""]?default:${MOHCLASS})})
exten => _[12-5],n,Set(_NODEST=)
exten => _[12-5],n,Gosub(sub-record-check,s,1(out,${EXTEN},))
exten => _[12-5],n,Macro(dialout-trunk,5,${EXTEN},,off)
exten => _[12-5],n,Macro(outisbusy,)


[outrt-5] ; out-0
...
exten => 0,n,Macro(dialout-trunk,4,${EXTEN},,off)


exten => _123[89],n,Macro(dialout-trunk,6,007${EXTEN:3},,off)
*/
//((\:[0-9]+)|)
						//search for route prefix, prepend1, prepend2, and trunk number
						preg_match_all("/exten \=\> (.*),n,Macro\(dialout\-trunk,([0-9]+),([0-9]+|)\\\$\{EXTEN(\:([0-9]+)|)/",$route_section,$matches);
						
						//gather all trunks routes for this rule
						foreach($matches[2] as $trunk)
						{
							$routes[$k]['trunk'][]=$trunk;
						}
						$routes[$k]['prepend']=$matches[3][0];
						$routes[$k]['prefix']=$matches[1][0];
						$routes[$k]['prepend2_length']=$matches[5][0];
						
						
						//check if prefix has complex expression (- or letters), if so drop route, it won't be converted
						if(
							strpos($routes[$k]['prefix'],".")!==FALSE or
							strpos($routes[$k]['prefix'],"-")!==FALSE or
							strpos($routes[$k]['prefix'],"X")!==FALSE or
							strpos($routes[$k]['prefix'],"Z")!==FALSE or
							strpos($routes[$k]['prefix'],"N")!==FALSE 
						)
							unset($routes[$k]);
						else
						{
							//remove brackets and underscore
							$routes[$k]['prefix']=str_replace("_","",$routes[$k]['prefix']);
							$routes[$k]['prefix']=str_replace("[","",$routes[$k]['prefix']);
							$routes[$k]['prefix']=str_replace("]","",$routes[$k]['prefix']);
							
							
							//check if there is a "prepend 2" field defined, this is done by looking at the {EXTEN:X where X is a number, if X is there then it means that the first X digits of the prefix are actually a "prepend 2" value
							
							if($routes[$k]['prepend2_length']!="")
								if($routes[$k]['prepend2_length']>0)
								{
									$routes[$k]['prepend'].=substr($routes[$k]['prefix'],0,$routes[$k]['prepend2_length']);
									$routes[$k]['prefix']=substr($routes[$k]['prefix'],$routes[$k]['prepend2_length']);
								}
								
							//check if route is an emergency number
							//exten => 911,n,Set(EMERGENCYROUTE=YES) - format expected
							$pos=strpos($route_section,"EMERGENCYROUTE");
							if($pos!==FALSE)
							{
								$routes[$k]['emergency']=1;
								$routes[$k]['pos']=0; //top priority for emergency numbers
								$routes[$k]['name']="EM_No_".$routes[$k]['name']; //add "EM_No_" prefix to emergency numbers rule name
							}
							else
								$routes[$k]['emergency']=0;
						}
						
					}
				}
			
			//get a list of all IVRs
			preg_match_all("/\[(ivr-([0-9]+))\] \; (.*)/",$tmp,$matches);
			/*
[0] => Array
	(
		[0] => [ivr-4] ; ivrtest1
		[1] => [ivr-5] ; ivr_name
		[2] => [ivr-3] ; Unnamed
	)

[1] => Array
	(
		[0] => ivr-4
		[1] => ivr-5
		[2] => ivr-3
	)

[2] => Array
	(
		[0] => 4
		[1] => 5
		[2] => 3
	)

[3] => Array
	(
		[0] => ivrtest1
		[1] => ivr_name
		[2] => Unnamed
	)
*/
			$ivrs=array();
			if(count($matches[1])>0)
			{
				foreach($matches[1] as $k=>$match)
				{
					$ivrs[ $matches[1][$k] ]=array('name'=>$matches[3][$k]);
				}
				
				//virtual extension number start range for IVRs
				if($ext_length==2) $i=80;
				if($ext_length==3) $i=800;
				if($ext_length==4) $i=8000;
				if($ext_length==5) $i=80000;
				
				foreach($ivrs as $k=>$ivr)
				{
					//check if ext. exists, then increment virtual ext num, repeat until it finds a free num
					while(isset($extensions[$i]))
					{
						$i++;
					}
					
					$ivrs[ $k ]['virtual_ext']=$i;
					$nb_obj_converted++;
					$i++;
				}
				
				
				$ivr_section="";
				foreach($ivrs as $id=>$ivr)
				{
					$pos1=strpos($tmp,"[".$id."] ; ".$ivr['name']);
					$pos2=strpos($tmp,";--== end of [".$id."] ==--;");
					if($pos1!==FALSE and $pos2!==FALSE)
					{
						$ivr_section=substr($tmp,$pos1,$pos2-$pos1);
						$invalid=array();
						//get section
/*
[ivr-5] ; ivr_name
include => ivr-5-custom
exten => s,1,Set(TIMEOUT_LOOPCOUNT=0)
exten => s,n,Set(INVALID_LOOPCOUNT=0)
exten => s,n,Set(_IVR_CONTEXT_${CONTEXT}=${IVR_CONTEXT})
exten => s,n,Set(_IVR_CONTEXT=${CONTEXT})
exten => s,n,Set(__IVR_RETVM=)
exten => s,n,GotoIf($["${CDR(disposition)}" = "ANSWERED"]?skip)
exten => s,n,Answer
exten => s,n,Wait(1)
exten => s,n(skip),Set(IVR_MSG=)
exten => s,n(start),Set(TIMEOUT(digit)=3)
exten => s,n,ExecIf($["${IVR_MSG}" != ""]?Background(${IVR_MSG}))
exten => s,n,WaitExten(123,)

exten => 1,1(ivrsel-1),Goto(from-did-direct,100,1)

exten => 2,1(ivrsel-2),Goto(ivr-4,s,1)

exten => 3,1(ivrsel-3),Goto(ext-group,1500,1)

exten => 4,1(ivrsel-4),Goto(ext-queues,1000,1)

exten => 5,1(ivrsel-5),Goto(app-blackhole,hangup,1)

exten => 6,1(ivrsel-6),Goto(ext-local,vmb100,1)

exten => i,1,Set(INVALID_LOOPCOUNT=$[${INVALID_LOOPCOUNT}+1])
exten => i,n,GotoIf($[${INVALID_LOOPCOUNT} > 3]?final)
exten => i,n,Set(IVR_MSG=no-valid-responce-pls-try-again)
exten => i,n,Goto(s,start)
exten => i,n(final),Playback(no-valid-responce-transfering)
exten => i,n,Goto(ivr-3,s,1)

exten => t,1,Set(TIMEOUT_LOOPCOUNT=$[${TIMEOUT_LOOPCOUNT}+1])
exten => t,n,GotoIf($[${TIMEOUT_LOOPCOUNT} > 3]?final)
exten => t,n,Set(IVR_MSG=no-valid-responce-pls-try-again)
exten => t,n,Goto(s,start)
exten => t,n(final),Playback(no-valid-responce-transfering)
exten => t,n,Goto(from-did-direct,101,1)

exten => return,1,Set(_IVR_CONTEXT=${CONTEXT})
exten => return,n,Set(_IVR_CONTEXT_${CONTEXT}=${IVR_CONTEXT_${CONTEXT}})
exten => return,n,Set(IVR_MSG=)
exten => return,n,Goto(s,start)

exten => h,1,Hangup

exten => hang,1,Playback(vm-goodbye)
exten => hang,n,Hangup

;--== end of [ivr-5] ==--;
*/
					//timeout delay
						preg_match("/WaitExten\(([0-9]+),\)/",$ivr_section,$matches);
						if(isset($matches[1]))
							$ivrs[ $id ]['timeout']=array('delay'=>$matches[1]);
					
					//invalid input destination
						$matches=array();
						$pos=strpos($ivr_section,"INVALID_LOOPCOUNT=\$");
						$subsection=substr($ivr_section,$pos);
						$pos2=strpos($subsection,"Goto\(");
						$subsection=substr($subsection,$pos2);
						
						preg_match_all("/Goto\((.*)\)/",$subsection,$matches);
						$dest_invalid=$matches[1][1];
						
						//check if the destination of invalid input can be handled
						$invalid=parse_IVR_dest($dest_invalid);
						//vm or terminate call are not allowed for invalid dest, then replace by Repeat prompt
						if($invalid['type']=="VoiceMail" or $invalid['type']=="EndCall")
						{
							$invalid['type']="Repeat";
							$invalid['dest']="";
						}
												
						$ivrs[$id]['dest_invalid']=$invalid;
												
					//timeout destination
						$matches=array();
						$pos=strpos($ivr_section,"TIMEOUT_LOOPCOUNT=\$");
						$subsection=substr($ivr_section,$pos);
						$pos2=strpos($subsection,"Goto\(");
						$subsection=substr($subsection,$pos2);
						
						preg_match_all("/Goto\((.*)\)/",$subsection,$matches);
						$dest_timeout=$matches[1][1];
						
						$timeout=parse_IVR_dest($dest_timeout);
						
						$ivrs[$id]['timeout']['dest']=$timeout;
						
					//DTMF destinations
						$matches=array();
						
						//e.g exten => 1,1(ivrsel-1),Goto(from-did-direct,100,1)
						preg_match_all("/ivrsel-([0-9])\),Goto\((.*)\)/",$ivr_section,$matches);
						
						//if DTMF found
						if(count($matches[0])>0)
						{
							$ivrs[$id]['dtmf']=array();
							
							foreach($matches[0] as $k=>$match)
							{
								$dtmf=$matches[1][$k];
								$dest=parse_IVR_dest($matches[2][$k]);
								
								$ivrs[$id]['dtmf'][ $dtmf ]=$dest;
							}
						}
					
					//prompt
					//e.g exten => s,n(skip),Set(IVR_MSG=custom/OfficeClosed)
						$ivrs[$id]['prompt']="";
						preg_match("/exten \=\> s,n\(skip\),Set\(IVR_MSG\=custom\/(.*)\)/",$ivr_section,$matches);
						
						//if a custom prompt is associated to this IVR, store its name
						if(isset($matches[1]))
							if($matches[1]!="")
							{
								$ivrs[$id]['prompt']=$matches[1].".wav";
							}
							
					}
				}
			}	
			
		}
		else 
		{
			$e="File etc.asterisk.tgz/extensions_additional.conf is missing from backup, please redo a backup which includes Asterisk settings.";
			log1($e);
			$user_friendly_errors.="- $e\r\n";
			$nb_errors++;
			send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
			mark_job_completed($dir);
			die($e);
		}
		
	}
	else 
	{
		$e="File etc.asterisk.tgz is missing from backup, please redo a backup which includes Asterisk settings.";
		log1($e);
		$user_friendly_errors.="- $e\r\n";
		$nb_errors++;
		send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
		mark_job_completed($dir);
		die($e);
	}
	
	//mount SQLite3 acl.db file to get the admin email address
	if(file_exists("$upload_dir/backup/acl.db"))
	{
		$db = new SQLite3("$upload_dir/backup/acl.db");

		$result = $db->query("SELECT `value` FROM acl_profile_properties WHERE property='login' and id_profile='1';");
		$mail=$result->fetchArray();
		if(isset($mail['value']))
		{
			$admin['email']=$mail['value'];
			$nb_obj_converted++;
		}
		
		$db->close();
		
	}
	else 
	{
		$e="File acl.db is missing from backup, please redo a backup which includes the Menus and Permissions settings.";
		log1($e);
		$user_friendly_errors.="- $e\r\n";
		$nb_errors++;
		send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
		mark_job_completed($dir);
		die($e);
	}
	
	//mount SQLite3 astdb.sqlite3 file to get the outbound caller IDs and blacklisted numbers
	if(file_exists("$upload_dir/backup/astdb.sqlite3"))
	{
		$db = new SQLite3("$upload_dir/backup/astdb.sqlite3");

		//get extension outbound caller ids
		foreach($extensions as $ext=>$data)
		{
			$result = $db->query("SELECT `value` FROM astdb WHERE key='/AMPUSER/$ext/outboundcid';");
			$ocid=$result->fetchArray();
			if(isset($ocid['value']))
				if(isset($extensions[$ext])) //first check if an extension array exists (this will mean that it is either a SIP or IAX extension, others types are ignored)
					$extensions[$ext]['ocid']=$ocid['value'];
			
		}
		
		//get blacklisted numbers
		$blacklisted=array();
		$result = $db->query("SELECT key,value FROM astdb WHERE key LIKE '/blacklist/%';");
		while($bl=$result->fetchArray())
		{
			$num=str_replace("/blacklist/","",$bl['key']);
			if(is_numeric($num))
			{
				$blacklisted[]=array(
					"name"=>$bl['value'],
					"num"=>$num
				);
				$nb_obj_converted++;
			}
		}
		
		$db->close();
		
	}
	else 
	{
		$e="File astdb.sqlite3 is missing from backup, please redo a backup which includes Asterisk settings.";
		log1($e);
		$user_friendly_errors.="- $e\r\n";
		$nb_errors++;
		send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
		mark_job_completed($dir);
		die($e);
	}
	
	
	
	
	//check if the mysql database was included
	if(file_exists("$upload_dir/backup/mysqldb_asterisk.tgz"))
	{
		//decompress from gz				
		$p = new PharData("$upload_dir/backup/mysqldb_asterisk.tgz");
		$p->decompress(); 

		//unarchive from the tar
		$phar = new PharData("$upload_dir/backup/mysqldb_asterisk.tgz");
		mkdir($upload_dir."/backup/mysqldb_asterisk");
		$phar->extractTo("$upload_dir/backup/mysqldb_asterisk");
		
		//search file asterisk.sql
		if(file_exists("$upload_dir/backup/mysqldb_asterisk/mysqldb_asterisk/asterisk.sql"))
		{
			$tmp=file_get_contents("$upload_dir/backup/mysqldb_asterisk/mysqldb_asterisk/asterisk.sql");
				
			//if we find the sql query creating trunks, then proceed
			$trunks=array();
			if(strpos($tmp,"INSERT INTO `trunks`")!==FALSE)
			{
				require_once("include/PHP-SQL-Parser/src/PHPSQLParser.php");
				
				$pos=strpos($tmp,"INSERT INTO `trunks`");
				$rest=substr($tmp,$pos);
				$sql=substr($rest,0,strpos($rest,"\n"));
				
				//$sql="INSERT INTO `trunks` (`trunkid`, `name`, `tech`, `outcid`, `keepcid`, `maxchans`, `failscript`, `dialoutprefix`, `channelid`, `usercontext`, `provider`, `disabled`, `continue`) VALUES (1,'','dahdi','','','','','','g0','',NULL,'off','off'),(2,'VoipProv','sip','22222222','off','10','','','TestVoipProv','22222222','','off','off'),(3,'test2voip','sip','333333333','off','','','','test3','3333333','','off','off'),(4,'VoipProv1','sip','5555555','off','','','','VoipProv1','555555','','off','off'),(5,'VoipProv2','sip','6666666','off','','','','VoipProv2','22222333','','off','off'),(6,'VoipProv3','sip','7777777','off','','','','VoipProv3','22222444','','off','off'),(7,'IaXTrunk','iax2','88888888','off','5','','','IaXTrunk','88888888','','off','off');";
				
				$parser = new PHPSQLParser($sql, true);
				
				foreach($parser->parsed['VALUES'] as $trunk)
				{
					//[base_expr] => (2,'VoipProv','sip','22222222','off','10','','','TestVoipProv','22222222','','off','off')
					
					//check if SIP trunk
					if($trunk['data'][2]['base_expr']=="'sip'")
					{
						//trunk ID
							$trunk_tmp['id']=str_replace("'","",$trunk['data'][0]['base_expr']);
						//trunk name
							$trunk_tmp['name']=str_replace("'","",$trunk['data'][1]['base_expr']);
						//trunk Outbound Caller ID
							$trunk_tmp['ocid']=str_replace("'","",$trunk['data'][3]['base_expr']);
						//trunk outbound name
							$trunk_tmp['outbound_name']=str_replace("'","",$trunk['data'][8]['base_expr']);
						//trunk main number
							$trunk_tmp['num']=str_replace("'","",$trunk['data'][9]['base_expr']);
						//trunk outbound name
							$trunk_tmp['maxchan']=str_replace("'","",$trunk['data'][5]['base_expr']);
							
						if($trunk_tmp['maxchan']=="") $trunk_tmp['maxchan']=1; //must be at least 1 SC
							
						if(isset($trunks_outboundid[ $trunk_tmp['outbound_name'] ])) //filter out the trunks that are disabled in Elastix
						{
							$trunk_tmp=array_merge( $trunk_tmp, $trunks_outboundid[ $trunk_tmp['outbound_name'] ] );
							
							$trunks[ $trunk_tmp['id'] ]=$trunk_tmp;
							$nb_obj_converted++;
						}
					}
					
				}
			}
	
			unset($trunks_outboundid);
			
			//get all custom prompts
			$prompts=array();
			if(strpos($tmp,"INSERT INTO `recordings`")!==FALSE)
			{
			
				require_once("include/PHP-SQL-Parser/src/PHPSQLParser.php");
				
				$pos=strpos($tmp,"INSERT INTO `recordings`");
				$rest=substr($tmp,$pos);
				$sql=substr($rest,0,strpos($rest,"\n"));
			
				//INSERT INTO `recordings` (`id`, `displayname`, `filename`, `description`, `fcode`, `fcode_pass`) VALUES (1,'__invalid','install done','',0,NULL),(3,'OfficeClosed','custom/OfficeClosed','No long description available',0,NULL);
				
				$parser = new PHPSQLParser($sql, true);
				
				foreach($parser->parsed['VALUES'] as $prompt)
				{
					/*
Array
(
...
		[data] => Array
		....
		[2] => Array
			(
				[expr_type] => const
				[base_expr] => 'custom/OfficeClosed'
				[sub_tree] => 
				[position] => 163
				*/
					if(strpos($prompt['data'][2]['base_expr'],"custom/")!==FALSE)
					{
						$prompts[]=str_replace("custom/","",$prompt['data'][2]['base_expr']);
					}
				}
				
				//search for the prompt audio files
				$prompts_converted=array();
				if(count($prompts)>0)
				{
					if(file_exists("$upload_dir/backup/var.lib.asterisk.sounds.custom.tgz"))
					{
						//decompress from gz
						$p = new PharData("$upload_dir/backup/var.lib.asterisk.sounds.custom.tgz");
						$p->decompress(); 

						//unarchive from the tar
						$phar = new PharData("$upload_dir/backup/var.lib.asterisk.sounds.custom.tgz");
						$tar_folder=$upload_dir."/backup/var.lib.asterisk.sounds.custom";
						mkdir($tar_folder);
						$phar->extractTo($tar_folder);
						
						$wav_folder=$tar_folder."/custom";
						if(file_exists($wav_folder))
						{
							mkdir($tar_folder."/conv"); //create folder for converted prompts
							
							//run ffmpeg converter for each WAV file of this folder to ensure the file format is the one expected by PBX
							$files=scandir($wav_folder);
							foreach($files as $file)
							{
								if($file!="." && $file!=".." && substr($file,-4)==".wav")
								{
									if(preg_match("/^[\w\-\_\.\s]+$/",$file))
									{
										$cmd="avconv -i $wav_folder/$file -acodec pcm_s16le -ac 1 -ar 8000 $tar_folder/conv/$file -y 2>&1";
										log1($cmd);
										$ffmpeg_output=shell_exec($cmd);
										
										log1($ffmpeg_output);
										
										$prompts_converted[]=$file;
										$nb_obj_converted++;
									}
									else
									{
										//file name invalid, non blocking but skipping this file then
										$e="Custom prompt file $file was not converted, its file name is invalid.";
										log1($e);
										$user_friendly_errors.="- $e\r\n";
										$nb_errors++;
									}
								}
							}
						}
						else
						{
							$e="Folder custom is missing from backup's var.lib.asterisk.sounds.custom.tgz content, please redo a backup which includes Asterisk settings.";
							log1($e);
							$user_friendly_errors.="- $e\r\n";
							$nb_errors++;
							send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
							mark_job_completed($dir);
							die($e);
						}
					}
					else
					{
						$e="File var.lib.asterisk.sounds.custom.tgz is missing from backup, please redo a backup which includes Asterisk settings.";
						log1($e);
						$user_friendly_errors.="- $e\r\n";
						$nb_errors++;
						send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
						mark_job_completed($dir);
						die($e);
					}
				}
			}
			
			
			//search for DIDs
			$dids=array();
			if(strpos($tmp,"INSERT INTO `incoming`")!==FALSE)
			{
			
				require_once("include/PHP-SQL-Parser/src/PHPSQLParser.php");
				
				$pos=strpos($tmp,"INSERT INTO `incoming`");
				$rest=substr($tmp,$pos);
				$sql=substr($rest,0,strpos($rest,"\n"));
			
			//INSERT INTO `incoming` (`cidnum`, `extension`, `destination`, `faxexten`, `faxemail`, `answer`, `wait`, `privacyman`, `alertinfo`, `ringing`, `mohclass`, `description`, `grppre`, `delay_answer`, `pricid`, `pmmaxretries`, `pmminlength`) VALUES ('','0123456788','ext-local,vmb100,1',NULL,NULL,NULL,NULL,0,'','','default','DIDvm','',0,'','3','10'),('','0123456789','from-did-direct,100,1',NULL,NULL,NULL,NULL,0,'','','default','DIDext','',0,'','3','10'),('','0123456787','ivr-6,s,1',NULL,NULL,NULL,NULL,0,'','','default','DIDivr','',0,'','3','10'),('0044*','','from-did-direct,100,1',NULL,NULL,NULL,NULL,0,'','','default','CID','',0,'','3','10');
			
				
				$parser = new PHPSQLParser($sql, true);
				
				//for each DID, gather data and format them for CSV import
				foreach($parser->parsed['VALUES'] as $rule)
				{
					$dest=str_replace("'","",$rule['data'][2]['base_expr']);
					$dest=parse_IVR_dest($dest);
					
					if($dest['type']=="EndCall") $dest_type=0;
					if($dest['type']=="VoiceMail") $dest_type=1;
					if($dest['type']=="Extension") $dest_type=2;
					if($dest['type']=="IVR") $dest_type=5;
					$dest=$dest['dest'];
					
					$cid=str_replace("'","",$rule['data'][0]['base_expr']);
					$did=str_replace("'","",$rule['data'][1]['base_expr']);
					if($cid!="") { $type=2; $num=$cid; }
					if($did!="") { $type=1; $num=$did; }
					
					$dids[]=array(
						"name"=>str_replace("'","",$rule['data'][12]['base_expr']),
						"type"=>$type,
						"num"=>$num,
						"dest_type"=>$dest_type,
						"dest"=>$dest,
					);
					$nb_obj_converted++;
				}
				
				//ensure DIDs are 4 digits at least otherwise do not import
					foreach($dids as $k=>$did)
					{
						if($did['type']==1 && strlen($did['num'])<4)
						{
							unset($dids[$k]);
							$nb_obj_converted--;
							$e="DID ".$did['num']." was not imported because of less than 4 digits. You will need to recreate it manually.";
							$user_friendly_errors.="- $e\r\n";
							$nb_errors++;
							log1($e);
						}
					}
			}
			
			
			//search for dial codes
			$dialcodes=array();
			if(strpos($tmp,"INSERT INTO `featurecodes`")!==FALSE)
			{
				
				require_once("include/PHP-SQL-Parser/src/PHPSQLParser.php");
				
				$pos=strpos($tmp,"INSERT INTO `featurecodes`");
				$rest=substr($tmp,$pos);
				$sql=substr($rest,0,strpos($rest,"\n"));
				
				/*INSERT INTO `featurecodes` (`modulename`, `featurename`, `description`, `defaultcode`, `customcode`, `enabled`, `providedest`) VALUES ('core','userlogon','User Logon','*11',NULL,1,0),('core','userlogoff','User Logoff','*12',NULL,1,0),('core','zapbarge','ZapBarge','888',NULL,1,1),('core','simu_pstn','Simulate Incoming Call','7777',NULL,1,1),('fax','simu_fax','Dial System FAX','666',NULL,1,1),('core','chanspy','ChanSpy','555',NULL,1,1),('core','pickup','Directed Call Pickup','**',NULL,1,0),('core','pickupexten','Asterisk General Call Pickup','*8',NULL,1,0),('core','blindxfer','In-Call Asterisk Blind Transfer','##',NULL,1,0),('core','atxfer','In-Call Asterisk Attended Transfer','*2',NULL,1,0),('core','automon','In-Call Asterisk Toggle Call Recording','*1',NULL,1,0),('core','disconnect','In-Call Asterisk Disconnect Code','**',NULL,1,0),('queues','que_pause_toggle','Queue Pause Toggle','*46',NULL,1,0),('infoservices','calltrace','Call Trace','*69',NULL,1,0),('infoservices','echotest','Echo Test','*43',NULL,1,1),('infoservices','speakingclock','Speaking Clock','*60',NULL,1,1),('infoservices','speakextennum','Speak Your Exten Number','*65',NULL,1,0),('voicemail','myvoicemail','My Voicemail','*97',NULL,1,0),('voicemail','dialvoicemail','Dial Voicemail','*98',NULL,1,1),('recordings','record_save','Save Recording','*77',NULL,1,0),('recordings','record_check','Check Recording','*99',NULL,1,0),('callforward','cfon','Call Forward All Activate','*72',NULL,1,0),('callforward','cfoff','Call Forward All Deactivate','*73',NULL,1,0),('callforward','cfoff_any','Call Forward All Prompting Deactivate','*74',NULL,1,0),('callforward','cfbon','Call Forward Busy Activate','*90',NULL,1,0),('callforward','cfboff','Call Forward Busy Deactivate','*91',NULL,1,0),('callforward','cfboff_any','Call Forward Busy Prompting Deactivate','*92',NULL,1,0),('callforward','cfuon','Call Forward No Answer/Unavailable Activate','*52',NULL,1,0),('callforward','cfuoff','Call Forward No Answer/Unavailable Deactivate','*53',NULL,1,0),('callwaiting','cwon','Call Waiting - Activate','*70',NULL,1,0),('callwaiting','cwoff','Call Waiting - Deactivate','*71',NULL,1,0),('dictate','dodictate','Perform dictation','*34',NULL,1,0),('dictate','senddictate','Email completed dictation','*35',NULL,1,0),('donotdisturb','dnd_on','DND Activate','*78',NULL,1,0),('donotdisturb','dnd_off','DND Deactivate','*79',NULL,1,0),('donotdisturb','dnd_toggle','DND Toggle','*76',NULL,1,0),('findmefollow','fmf_toggle','Findme Follow Toggle','*21',NULL,1,0),('paging','intercom-prefix','Intercom prefix','*80',NULL,0,0),('paging','intercom-on','User Intercom Allow','*54',NULL,0,0),('paging','intercom-off','User Intercom Disallow','*55',NULL,0,0),('pbdirectory','app-pbdirectory','Phonebook dial-by-name directory','411',NULL,1,1),('blacklist','blacklist_add','Blacklist a number','*30',NULL,1,1),('blacklist','blacklist_remove','Remove a number from the blacklist','*31',NULL,1,1),('blacklist','blacklist_last','Blacklist the last caller','*32',NULL,1,0),('speeddial','callspeeddial','Speeddial prefix','*0',NULL,1,0),('speeddial','setspeeddial','Set user speed dial','*75',NULL,1,0),('gabcast','gabdial','Connect to Gabcast','*422',NULL,1,0),('queues','que_toggle','Queue Toggle','*45',NULL,1,0),('callforward','cf_toggle','Call Forward Toggle','*740',NULL,1,0),('parking','parkedcall','Pickup ParkedCall Prefix','*85',NULL,1,1),('voicemail','directdialvoicemail','Direct Dial Prefix','*',NULL,1,0),('callforward','cfpon','Call Forward All Prompting Activate','*720',NULL,1,0),('callforward','cfbpon','Call Forward Busy Prompting Activate','*900',NULL,1,0),('callforward','cfupon','Call Forward No Answer/Unavailable Prompting Activate','*520',NULL,1,0),('conferences','conf_status','Conference Status','*87',NULL,1,0),('daynight','toggle-mode-all','All: Call Flow Toggle','*28',NULL,1,0),('queues','que_callers','Queue Callers','*47',NULL,1,0),('timeconditions','toggle-mode-all','All: Time Condition Override','*27',NULL,1,0),('timeconditions','toggle-mode-1','1: test','*271',NULL,1,1),('timeconditions','toggle-mode-2','2: hol','*272',NULL,1,1),('timeconditions','toggle-mode-3','3: chr','*273',NULL,1,1),('timeconditions','toggle-mode-4','4: sat-sun-close','*274',NULL,1,1),('timeconditions','toggle-mode-5','5: testweek1','*275',NULL,1,1);*/
				
				$parser = new PHPSQLParser($sql, true);
				
				foreach($parser->parsed['VALUES'] as $dialcode)
				{
					//get voicemail dial code
					if(strpos($dialcode['base_expr'],"'dialvoicemail'")!==FALSE)
					{
						$dialcodes['dialvoicemail']=str_replace("'","",$dialcode['data'][3]['base_expr']);
						$nb_obj_converted++;
					}
					
					//get pick parked call dial code
					if(strpos($dialcode['base_expr'],"'parkedcall'")!==FALSE)
					{
						$dialcodes['parkedcall']=str_replace("'","",$dialcode['data'][3]['base_expr']);
						$nb_obj_converted++;
					}
					//get intercom prefix dial code
					if(strpos($dialcode['base_expr'],"'intercom-prefix'")!==FALSE)
					{
						$dialcodes['intercom-prefix']=str_replace("'","",$dialcode['data'][3]['base_expr']);
						$nb_obj_converted++;
					}
					//get DND OFF dial code
					if(strpos($dialcode['base_expr'],"'dnd_off'")!==FALSE)
					{
						$dialcodes['dnd_off']=str_replace("'","",$dialcode['data'][3]['base_expr']);
						$nb_obj_converted++;
					}
					
					//get DND ON dial code
					if(strpos($dialcode['base_expr'],"'dnd_on'")!==FALSE)
					{
						$dialcodes['dnd_on']=str_replace("'","",$dialcode['data'][3]['base_expr']);
						$nb_obj_converted++;
						
					}
					
				}
				
				
			}
		}
		else 
		{
			$e="File asterisk.sql is missing from backup, please redo a backup which includes Mysql Database.";
			log1($e);
			$user_friendly_errors.="- $e\r\n";
			$nb_errors++;
			send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
			mark_job_completed($dir);
			die($e);
		}
	}	
	else 
	{
		$e="File mysqldb_asterisk.tgz is missing from backup, please redo a backup which includes Mysql Database.";
		log1($e);
		$user_friendly_errors.="- $e\r\n";
		$nb_errors++;
		send_email($to,0,$nb_errors,$user_friendly_errors,0,null,null,0,null);
		mark_job_completed($dir);
		die($e);
	}
	

	
	//create the Elastix5 backup
	$bkp=create_elastix5_backup($upload_dir,$ext_length,$system_extensions,$extensions,$trunks,$ivrs,$prompts_converted,$routes,$dialcodes,$blacklisted,$admin);
	
	if($bkp!=0) $backup_status=1; else $backup_status=0;
	
	//create DIDs CSV
	$has_dids=0;$csv="";
	if($backup_status==1 && count($dids)>0)
	{
		$csv=basename($bkp, ".zip")."_inbound_rules.csv";
		create_elastix5_csv($upload_dir,$csv,$dids);
		$has_dids=1;
	}
	
	log1("send email");
	send_email($to,$backup_status,$nb_errors,$user_friendly_errors,$nb_obj_converted,$current_job_time,$bkp,$has_dids,$csv);
	
	
	//cleanup task - delete files extracted and upload once done
	log1("cleaning up");
	
		//delete backup folder
		$it = new RecursiveDirectoryIterator($dir."/backup", RecursiveDirectoryIterator::SKIP_DOTS);
			$files = new RecursiveIteratorIterator($it,
						 RecursiveIteratorIterator::CHILD_FIRST);
			foreach($files as $file) {
				if ($file->isDir()){
					rmdir($file->getRealPath());
				} else {
					unlink($file->getRealPath());
				}
			}
			rmdir($dir."/backup");
			
	//delete TAR file
		unlink($tar_file);
	
	
	//remove marker of conversion in progress, and mark completed
	$h=fopen($dir."/CONVERTED","w");
	fwrite($h,time());
	fclose($h);
	unlink($dir."/CONVERTING");
	
	function send_email($to,$backup_status,$nb_errors,$user_friendly_errors,$nb_obj_converted,$current_job_time,$bkp,$has_dids,$csv)
	{
		$email_body="";
		
		if($backup_status)
		{
			$email_subject="Your Elastix backup has been converted successfully";
			
			$email_body.="Please download your backup for Elastix 5 from the following link:\r\nhttps://converter.elastix.org/conversions/$bkp\r\n\r\nNote that this link will remain live for the next 24 hours only.\r\n\r\n";
			
			if($has_dids)
				$email_body.="A CSV was also generated with your inbound rules, and is available at:\r\nhttps://converter.elastix.org/conversions/$csv\r\n\r\nNote that this link will remain live for the next 24 hours only.\r\n\r\n-------\r\n";
		}
		else
			$email_subject="Your backup conversion has failed";
		
		$email_body.="Converted $nb_obj_converted objects successfully.\r\n\r\n";
			
		if($user_friendly_errors!="")
			$email_body.="Encountered $nb_errors errors:\r\n".$user_friendly_errors;
		
				
		mail($to,$email_subject,$email_body,"From: admin@elastix.org" . "\r\n");
		
		log1("sent email to $to\r\nSubject:$email_subject\r\n$email_body");
			
	}
	
	function parse_IVR_dest($dest)
	{
		global $ivrs;
		$dest_parsed=array();
		
		//goes to an extension
			if(strpos($dest,"from-did-direct")!==FALSE)
			{
				//e.g from-did-direct,101,1
				$tmp2=explode(",",$dest);
				$dest_parsed['dest']=$tmp2[1];
				$dest_parsed['type']="Extension";
			}
		//goes to another IVR
			if(strpos($dest,"ivr-")!==FALSE)
			{
				//e.g ivr-3,s,1
				$tmp2=explode(",",$dest);
				
				//get the IVR id
				$dest=$tmp2[0];
				
				//get its number
				if(isset($ivrs[ $dest ]['virtual_ext']))
				{
					$dest_parsed['dest']=$ivrs[ $dest ]['virtual_ext'];
					$dest_parsed['type']="IVR";
				}
			}
		//goes to a ring group
			if(strpos($dest,"ext-group")!==FALSE)
			{
				//TODO
			}
		//goes to a queue
			if(strpos($dest,"ext-queues")!==FALSE)
			{
				//TODO
			}
		//ends call
			/*if(strpos($dest,"app-blackhole")!==FALSE or strpos($dest,"hang")!==FALSE)
			{
				$dest_parsed['dest']="EndCall";
			}*/
		//goes to voicemail of an extesion
			if(strpos($dest,"ext-local,vmb")!==FALSE)
			{
				//e.g ext-local,vmb100,1
				$tmp2=explode(",",$dest);
				$dest_parsed['dest']=str_replace("vmb","",$tmp2[1]);
				$dest_parsed['type']="VoiceMail";
			}
						
		//if dest not handled above then change it to end call
		if(!isset($dest_parsed['dest'])) 
		{
			$dest_parsed['dest']="";
			$dest_parsed['type']="EndCall";
		}
			
		return $dest_parsed;
	}
	
	function mark_job_completed($dir)
	{
		//remove marker of conversion in progress, and mark completed
		$h=fopen($dir."/CONVERTED","w");
		fwrite($h,time());
		fclose($h);
		unlink($dir."/CONVERTING");
	}
?>