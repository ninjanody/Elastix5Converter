<?php
require_once "include/utilities.inc.php";

function create_elastix5_backup($upload_dir,$ext_length,$system_extensions,$extensions,$trunks,$ivrs,$prompts_converted,$routes,$dialcodes,$blacklisted,$admin)
{
	log1("create_elastix5_backup function called");
	
	global $user_friendly_errors;
	global $download_dir;
	
	//init
	$xml_extensions="";
	$dn_list=""; $i=40; 
	$grp_members="";
	$ts=date("ymdHis");
	
	
	//browse extensions to create the relevant XML tags
	foreach($extensions as $num=>$params)
	{
		//load extension template
			$xml=file_get_contents("templates/extension.xml");
		
		//replace variables by the extension data
			$xml=str_replace("%%EXTNUM%%",$num,$xml);
			$xml=str_replace("%%EXTPWD%%",$params["password"],$xml);
			$xml=str_replace("%%EXTFN%%",$params["firstname"],$xml);
			$xml=str_replace("%%EXTLN%%",$params["lastname"],$xml);
			
			if(isset($params["ocid"])) //SIP ID may not be specified
				$xml=str_replace("%%EXTOCID%%",$params["ocid"],$xml);
			else
				$xml=str_replace("%%EXTOCID%%","",$xml);
			
			if(isset($params["sipid"])) //SIP ID may not be specified
				$xml=str_replace("%%EXTSIPID%%",$params["sipid"],$xml);
			else
				$xml=str_replace("%%EXTSIPID%%","",$xml);
			
			
			if(isset($params["email"])) //email may not be specified
				$xml=str_replace("%%EXTEMAIL%%",$params["email"],$xml);
			else
				$xml=str_replace("%%EXTEMAIL%%","",$xml);
			
			
		//generate random credentials per extension
			$xml=str_replace("%%VMPIN%%",generateRandomNum(4),$xml);
			$xml=str_replace("%%DESKPHONE_PASSWORD%%",generateRandomString(5),$xml);
			$xml=str_replace("%%SERVICES_ACCESS_PASSWORD%%",generateRandomString(6),$xml);
			$xml=str_replace("%%EXTPROVTS%%",$ts,$xml); //format expected: 161205083840
			$xml=str_replace("%%EXTGUID%%",generateRandomHex(8)."-".generateRandomHex(4)."-".generateRandomHex(4)."-".generateRandomHex(4)."-".generateRandomHex(12),$xml); //format expected: 4d8111a8-7618-4937-9bb7-76dc9c9a197e
		
		//add to the extensions XML output
		$xml_extensions.=$xml;		
		
		//fill the DN list for mapping table
			$dn_list.='        <DN No="'.$num.'" ID="'.$i.'" />'."\r\n";
			$i++;
		
		//add all ext. to the default group as user role
			$grp_members.='						<Member DN="'.$num.'"><role name="users"/></Member>'."\r\n";
		
	}
	
	//define first extension = operator extension
		reset($extensions); 
		$operator=key($extensions);
	
	//browse trunks
	$xml_trunks="";$xml_trunks2="";
	$xml1=file_get_contents("templates/provider.xml");
	$xml2=file_get_contents("templates/externalline.xml");
	$trunk_vnum=10000;
	foreach($trunks as $trunk)
	{
		$xml=$xml1;
		$xml=str_replace("%%TRUNK_NAME%%",$trunk['name'],$xml);
		$xml=str_replace("%%TRUNK_HOST%%",$trunk['host'],$xml);
		$xml=str_replace("%%TRUNK_PORT%%",$trunk['port'],$xml);
		$xml_trunks.=$xml;
		
		
		$xml=$xml2;
		$xml=str_replace("%%TRUNK_NAME%%",$trunk['name'],$xml);
		$xml=str_replace("%%TRUNK_DIDS%%",$trunk['num'],$xml);
		$xml=str_replace("%%TRUNK_MAXCALLS%%",$trunk['maxchan'],$xml);
		$xml=str_replace("%%TRUNK_OCID%%",$trunk['ocid'],$xml);
		$xml=str_replace("%%TRUNK_VNUM%%",$trunk_vnum,$xml);
		$xml=str_replace("%%TRUNK_AUTHID%%",$trunk['username'],$xml);
		$xml=str_replace("%%TRUNK_AUTHPWD%%",$trunk['secret'],$xml);
		$xml=str_replace("%%OPERATOR%%",$operator,$xml);
		$xml_trunks2.=$xml;
		
		$dn_list.='        <DN No="'.$trunk_vnum.'" ID="'.$i.'" />'."\r\n";
		$trunk_vnum++; //increment the virtual extension number for next trunk
		$i++; //increment mapping table ID
	}
	
	
	
	//load the main Db XML template
	$xml=file_get_contents("templates/backupDb.xml");
	
	//insert the extensions XML tags generated above
		$xml=str_replace("%%EXTXML%%",$xml_extensions,$xml);
		
	//insert the trunks XML tags generated above
		$xml=str_replace("%%EXTXML%%",$xml_extensions,$xml);
		$xml=str_replace("%%TRUNKSXML%%",$xml_trunks,$xml);
		$xml=str_replace("%%TRUNKS2XML%%",$xml_trunks2,$xml);
	
	//replace web admin credentials variables
		$xml=str_replace("%%WEBSERVERUSER%%","admin",$xml);
		$xml=str_replace("%%WEBSERVERPASS%%",$admin['password'],$xml);
	//generate some random credentials (fax extension password, tunnel password, provisioning subdirectory name)
		
		//system extensions
		$xml=str_replace("%%FAXOVEREMAILGATEWAY%%",$system_extensions['fax_ext'],$xml);
		$xml=str_replace("%%FAXPWD%%",generateRandomString(11),$xml);
		
		$xml=str_replace("%%PINPROTECT%%",$system_extensions['pin_ext'],$xml);
		
		$xml=str_replace("%%SPECIALMENU%%",$system_extensions['special_ext'],$xml);
		
		$xml=str_replace("%%CONFEXT%%",$system_extensions['conf_ext'],$xml);
		
		$xml=str_replace("%%TNL_CLIENT_PASSWORD%%",generateRandomStringCapitals(11),$xml);
		$xml=str_replace("%%PROVISIONING_FOLDER%%",generateRandomString(13),$xml);
	
	//set the first extension imported as the operator extension
		$xml=str_replace("%%OPERATOR%%",$operator,$xml);

	//write the extension length parameter
		$xml=str_replace("%%ENL%%",$ext_length,$xml);
		
		
	//put all users in the ELASTIX default group of extensions
		$xml=str_replace("%%GROUP_MEMBERS%%",$grp_members,$xml);
	
	//set the admin email for notifications
		$xml=str_replace("%%PBXERRORMAIL%%",$admin['email'],$xml);
		
	//set the dial codes
		$xml=str_replace("%%DIALCODE_DNDON%%",$dialcodes['dnd_on'],$xml);
		$xml=str_replace("%%DIALCODE_DNDOFF%%",$dialcodes['dnd_off'],$xml);
		$xml=str_replace("%%DIALCODE_VM%%",$dialcodes['dialvoicemail'],$xml);
		$xml=str_replace("%%DIALCODE_INTERCOM%%",$dialcodes['intercom-prefix'],$xml);
		$xml=str_replace("%%DIALCODE_UNPARK%%",$dialcodes['parkedcall'],$xml);
	
	//browse the outbound rules
		$xml_outboundrules="";
		$xml1=file_get_contents("templates/outboundrule.xml");
		
		foreach($routes as $route)
		{
			$xml2=$xml1;
			$xml2=str_replace("%%OUT_POS%%",$route['pos'],$xml2);
			$xml2=str_replace("%%OUT_PREFIX%%",$route['prefix'],$xml2);
			$xml2=str_replace("%%OUT_NAME%%",$route['name'],$xml2);
			$xml2=str_replace("%%OUT_TRUNK1%%",$trunks[ $route['trunk'][0] ]['name'],$xml2);
			
			
			//check if rule is emergency number or not
			if($route['emergency']==1)
			{
				//set the length to match exactly the prefix len
				$xml2=str_replace("%%OUT_LEN%%",strlen($route['prefix']),$xml2);
				//set the 5 routes to be route 1
				$xml2=str_replace("%%OUT_TRUNK1%%",$trunks[ $route['trunk'][0] ]['name'],$xml2);
				$xml2=str_replace("%%OUT_TRUNK2%%",$trunks[ $route['trunk'][0] ]['name'],$xml2);
				$xml2=str_replace("%%OUT_TRUNK3%%",$trunks[ $route['trunk'][0] ]['name'],$xml2);
				$xml2=str_replace("%%OUT_TRUNK4%%",$trunks[ $route['trunk'][0] ]['name'],$xml2);
				$xml2=str_replace("%%OUT_TRUNK5%%",$trunks[ $route['trunk'][0] ]['name'],$xml2);
				//no prepend setting for emergency number
				$xml2=str_replace("%%OUT_PREPEND1%%","",$xml2);
				$xml2=str_replace("%%OUT_PREPEND2%%","",$xml2);
				$xml2=str_replace("%%OUT_PREPEND3%%","",$xml2);
				$xml2=str_replace("%%OUT_PREPEND4%%","",$xml2);
				$xml2=str_replace("%%OUT_PREPEND5%%","",$xml2);
			}
			else
				$xml2=str_replace("%%OUT_LEN%%","",$xml2);
			
			
			$xml2=str_replace("%%OUT_PREPEND1%%",$route['prepend'],$xml2);
			
			
			//additional routes if set
			if(isset($route['trunk'][1]))
			{
				$xml2=str_replace("%%OUT_TRUNK2%%",$trunks[ $route['trunk'][1] ]['name'],$xml2);
				$xml2=str_replace("%%OUT_PREPEND2%%",$route['prepend'],$xml2);
			}
			if(isset($route['trunk'][2]))
			{
				$xml2=str_replace("%%OUT_TRUNK3%%",$trunks[ $route['trunk'][2] ]['name'],$xml2);
				$xml2=str_replace("%%OUT_PREPEND3%%",$route['prepend'],$xml2);
			}
			if(isset($route['trunk'][3]))
			{
				$xml2=str_replace("%%OUT_TRUNK4%%",$trunks[ $route['trunk'][3] ]['name'],$xml2);
				$xml2=str_replace("%%OUT_PREPEND4%%",$route['prepend'],$xml2);
			}
			if(isset($route['trunk'][4]))
			{
				$xml2=str_replace("%%OUT_TRUNK5%%",$trunks[ $route['trunk'][4] ]['name'],$xml2);
				$xml2=str_replace("%%OUT_PREPEND5%%",$route['prepend'],$xml2);
			}
			
			//default no extra routes
			$xml2=str_replace("%%OUT_TRUNK2%%","",$xml2);
			$xml2=str_replace("%%OUT_PREPEND2%%","",$xml2);
			$xml2=str_replace("%%OUT_TRUNK3%%","",$xml2);
			$xml2=str_replace("%%OUT_PREPEND3%%","",$xml2);
			$xml2=str_replace("%%OUT_TRUNK4%%","",$xml2);
			$xml2=str_replace("%%OUT_PREPEND4%%","",$xml2);
			$xml2=str_replace("%%OUT_TRUNK5%%","",$xml2);
			$xml2=str_replace("%%OUT_PREPEND5%%","",$xml2);
			
			
			$xml_outboundrules.=$xml2;
		}
		
		$xml=str_replace("%%OUTBOUNDRULESXML%%",$xml_outboundrules,$xml);
	
		
	//browse IVR
		$xml_ivrs="";
		$xml1=file_get_contents("templates/ivr.xml");
		$xml2=file_get_contents("templates/ivr_dtmf.xml");
		
		foreach($ivrs as $ivr)
		{
			$xml1_=$xml1;
			
			$xml1_=str_replace("%%IVR_TIMEOUT_TYPE%%",$ivr['timeout']['dest']['type'],$xml1_);
			$xml1_=str_replace("%%IVR_TIMEOUT_DEST%%",$ivr['timeout']['dest']['dest'],$xml1_);
			$xml1_=str_replace("%%IVR_TIMEOUT%%",$ivr['timeout']['delay'],$xml1_);
			$xml1_=str_replace("%%IVR_NAME%%",$ivr['name'],$xml1_);
			$xml1_=str_replace("%%IVR_VNUM%%",$ivr['virtual_ext'],$xml1_);
			$xml1_=str_replace("%%IVR_INVALID%%",$ivr['dest_invalid']['dest'],$xml1_);
			
			$xml_dtmf="";
			if(isset($ivr['dtmf']))
			{
				foreach($ivr['dtmf'] as $key=>$dtmf)
				{
					$xml2_=$xml2;
					$xml2_=str_replace("%%IVR_DEST_TYPE%%",$dtmf['type'],$xml2_);
					$xml2_=str_replace("%%IVR_DEST_NUM%%",$dtmf['dest'],$xml2_);
					$xml2_=str_replace("%%IVR_KEY%%",$key,$xml2_);
					
					$xml_dtmf.=$xml2_;
				}
			}	
			$xml1_=str_replace("%%IVRDTMFXML%%",$xml_dtmf,$xml1_);
			
			//prompt file
			$xml1_=str_replace("%%IVR_PROMPT%%",$ivr['prompt'],$xml1_);
			
			//english prompt set by default
			$xml1_=str_replace("%%IVR_PROMPTSETID%%","8210986B-9412-497f-AD77-3A554F4A9BDB",$xml1_);
			$xml1_=str_replace("%%IVR_PROMPTSETNAME%%","Standard English Prompts Set",$xml1_);
			
			$xml_ivrs.=$xml1_;
		}
		$xml=str_replace("%%IVRXML%%",$xml_ivrs,$xml);
	
	
	//browse blacklisted numbers
		$i=1;
		$xml1=file_get_contents("templates/blacklist.xml");
		$xml_blacklist="";
		foreach($blacklisted as $entry)
		{
			//blacklist entry index, 4 digits
				$j=$i;
				while(strlen($j)<4) $j="0$j";
			
			$xml2=$xml1;
			$xml2=str_replace("%%BLACKLIST_NAME%%",$entry['name'],$xml2);
			$xml2=str_replace("%%BLACKLIST_NUM%%",$entry['num'],$xml2);
			$xml2=str_replace("%%BLACKLIST_INDEX%%",$j,$xml2);
			
			$xml_blacklist.=$xml2;
			
			$i++; //increment index
		}
		$xml=str_replace("%%BLACKLISTXML%%",$xml_blacklist,$xml);
	
	
	
	//write Db XML
		$h=fopen($upload_dir."/".$ts."Db.xml","w");
		fwrite($h,$xml);
		fclose($h);
	
	//create the MappingTable XML
		$xml=file_get_contents("templates/MappingTable.xml");
		$xml=str_replace("%%DN_LIST%%",$dn_list,$xml);
		
	//write MappingTable XML
		$h=fopen($upload_dir."/MappingTable.xml","w");
		fwrite($h,$xml);
		fclose($h);
	
	
	//zip backup
		$zip = new ZipArchive();
		$filename = $ts."_".generateRandomStringCapitals(10).".zip";

		if ($zip->open($upload_dir."/".$filename, ZipArchive::CREATE)!==TRUE) {
			log1("cannot open <$filename>\n");
			return 0;
			
		}
		else
		{
			
			//add backup Db
			$zip->addFile($upload_dir."/".$ts."Db.xml",$ts."Db.xml");
			
			//add folder "OtherSettings"
			$zip->addEmptyDir("OtherSettings"); 
			//add mapping table to folder
			$zip->addFile($upload_dir."/MappingTable.xml","OtherSettings/MappingTable.xml");
			
			//if custom prompts found and converted, add them to zip
			if(count($prompts_converted)>0)
			{
				//add folder "httpprompts"
				$zip->addEmptyDir("httpprompts");
				
				foreach($prompts_converted as $file)
				{
					$zip->addFile($upload_dir."/backup/var.lib.asterisk.sounds.custom/conv/$file","httpprompts/$file");
				}
			}
			
			
			$zip->close();
			log1("zip completed");
			
			log1("deleting files zipped");
			unlink($upload_dir."/MappingTable.xml");
			unlink($upload_dir."/".$ts."Db.xml");
			
			
			log1("moving zip to download folder");
			rename($upload_dir."/".$filename,$download_dir."/".$filename);
		}
			
		return $filename;
	
}

function create_elastix5_csv($upload_dir,$filename,$dids)
{
	global $download_dir;
	
	log1("create_elastix5_csv called");
	
	$csv="PRIORITY,NAME,TYPE,MASK,PORTS,INOFFICE_DEST_TYPE,INOFFICE_DEST_NUMBER,SAME_DEST_AS_INOFFICE,SPECIFIC_HOURS,SPECIFIC_HOURS_TIME,INCLUDE_HOLIDAYS,OUTOFOFFICE_DEST_TYPE,OUTOFOFFICE_DEST_NUMBER,PLAY_HOLIDAY_PROMPT\r\n";

	foreach($dids as $k=>$did)
	{
		$prio=$k+2; //starts at 2
		$csv.="$prio,$did[name],$did[type],$did[num],[10000],$did[dest_type],$did[dest],0,0,,,$did[dest_type],$did[dest],0\r\n";
	}

	$h=fopen("$upload_dir/$filename","w");
	fwrite($h,$csv);
	fclose($h);
	
	log1("csv created");
	
	log1("moving csv to download folder");
	rename($upload_dir."/$filename",$download_dir."/$filename");
}
?>