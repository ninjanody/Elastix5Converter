<?php
set_time_limit(3600); //upload may take long

//INIT --->
	require_once "include/config.inc.php"; 
	require_once "include/utilities.inc.php"; 
			
	$time=str_replace(".","",microtime(1)); //current time
	
	$e=""; //error
	$status="";
	
//<----

	//on form submit
	if(isset($_POST["submit"]))
	{
		//create a folder name timestamp-based for this upload
			$upload_dir.=$time;
			mkdir($upload_dir);
			
		ini_set("error_log", $upload_dir."/$time.log"); //store log in that folder
			
		log1("Backup submit from ".$_SERVER['REMOTE_ADDR']);
		log1(print_r($_POST,true));
		
		//validate form input
			//check email
				if(filter_var($_POST['email'], FILTER_VALIDATE_EMAIL))
					$email=$_POST['email'];
				else
				{
					$e.="Email is invalid.\r\n";
					log1($e);
				}
			
			//extension length
				if(is_numeric($_POST['extensions']) && $_POST['extensions']>=2 && $_POST['extensions'] <6)
					$ext_length=$_POST['extensions'];
				else
				{
					$e.="Extension length is invalid.\r\n";
					log1($e);
				}
			
			//check file field
				if(isset($_FILES['backupfile']['name'])) {
					if($_FILES['backupfile']['size'] > 1024*1024*1024) { //1 GB max
						$e.="File upload is too big, backup should be up to 1 GB.\r\n";
						log1($e);
					}
				}
				else
				{
					$e.="File was not specified.\r\n";
					log1($e);
				}
		
		
		//upload file
			$target_file = $upload_dir . "/".basename($_FILES["backupfile"]["name"]);
			
			$filetype = pathinfo($target_file,PATHINFO_EXTENSION);
			if($filetype != "tar") {
				$e.="File type should be *.tar.\r\n";
				log1($e);
			}

			
			if($e=="") //continue if no error
			{
				if (!move_uploaded_file($_FILES["backupfile"]["tmp_name"], $target_file)) {
					$e.="Sorry, there was an error uploading your file.\r\n";
					log1($e);
				}
			
				//store mail and ext_length
					$h=fopen($upload_dir."/EMAIL","w");
					fwrite($h,$email);
					fclose($h);
					
					$h=fopen($upload_dir."/EXT_LENGTH","w");
					fwrite($h,$ext_length);
					fclose($h);
			
				$status="Your file has been sent successfully for conversion.<br/>You will receive an email once conversion has been done.";
			}
		
	}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Convert Elastix 2.5/4.0 backups to Elastix 5.X</title>
<link href="https://fonts.googleapis.com/css?family=Open+Sans" rel="stylesheet">
<link rel="stylesheet" href="css.css" />
<style>
body {
	font-family: 'Open Sans', sans-serif;
	font-size: 13px;
}
#header {
	background-color: #333;
	width: 100%;
	text-align: center;
	height: 80px;
}
#status {
	width: 100%;
	text-align: center;
	height: 80px;
}
#bk_form {
	width: 500px;
	margin: 0 auto;
	position: relative;
	display: block;
	border-radius: 5px;
	background-color: #EDEDED;
	padding: 10px 20px;
	margin-top: 50px;
	box-shadow: #DFDFDF 0px 0px 5px 2px;
}
#footer {
	display: block;
	background-color: #F1F1F1;
	padding: 7px;
	text-align: center;
	position: absolute;
	bottom: 0;
}
.small {
	font-size: 10px;
}
#submit {
	background-color: #333;
	color: #FFF;
	padding: 5px 10px;
	border: none;
	cursor: pointer;
}
.error {
	border:1px solid #FF0000!important;
}
</style>
</head>

<body>
<div id="header_meta" class="container_wrap container_wrap_meta  av_icon_active_left av_extra_header_active av_secondary_right av_entry_id_1034 av_av_admin_bar_active">
		
			      <div class="container">
			      <ul class="noLightbox social_bookmarks icon_count_3"><li class="social_bookmarks_facebook av-social-link-facebook social_icon_1"><a target="_blank" href="https://www.facebook.com/elastix" aria-hidden="true" data-av_icon="" data-av_iconfont="entypo-fontello" title="Facebook"><span class="avia_hidden_link_text">Facebook</span></a></li><li class="social_bookmarks_twitter av-social-link-twitter social_icon_2"><a target="_blank" href="http://twitter.com/#elastix/" aria-hidden="true" data-av_icon="" data-av_iconfont="entypo-fontello" title="Twitter"><span class="avia_hidden_link_text">Twitter</span></a></li><li class="social_bookmarks_gplus av-social-link-gplus social_icon_3"><a target="_blank" href="https://plus.google.com/+elastix" aria-hidden="true" data-av_icon="" data-av_iconfont="entypo-fontello" title="Gplus"><span class="avia_hidden_link_text">Gplus</span></a></li></ul><nav class="sub_menu" role="navigation" itemscope="itemscope" itemtype="https://schema.org/SiteNavigationElement"><ul id="avia2-menu" class="menu"><li id="menu-item-23014" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-23014"><a href="http://www.elastix.org/contact/">CONTACT</a></li>
<li id="menu-item-1059" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-1059"><a href="http://www.elastix.org/community/">FORUM</a></li>
<li id="menu-item-23177" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-23177"><a href="https://sourceforge.net/projects/elastix/files/Elastix%20PBX%20Appliance%20Software/5.0.0/debian-8.6.0-amd64-netinst-Elastix-5-beta.iso/download">ISO</a></li>
<li id="menu-item-23178" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-23178"><a href="http://www.elastix.org/free-pbx-download/">Get Free Key</a></li>
<li id="menu-item-23180" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-23180"><a href="http://www.elastix.org/free-cloud-pbx/">Try on G Cloud</a></li>
<li id="menu-item-22659" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-22659"><a href="http://www.elastix.org/es/"><img src="http://www.elastix.org/wp-content/uploads/2016/12/es.png"></a></li>
</ul></nav>			      </div>
		</div>
        
<div id="header_main" class="container_wrap container_wrap_logo">
  <div class="container av-logo-container" style="height: 88px; line-height: 88px;">
    <div class="inner-container"><strong class="logo"><a href="http://www.elastix.org/" style="max-height: 88px;"><img height="100" width="300" src="http://www.elastix.org/wp-content/uploads/2016/01/elx-logo-w-300x138.png" alt="Elastix" style="max-height: 88px;"></a></strong><a id="advanced_menu_toggle" href="#" aria-hidden="true" data-av_icon="" data-av_iconfont="entypo-fontello"></a>
      <nav class="main_menu" data-selectname="Select a page" role="navigation" itemscope="itemscope" itemtype="https://schema.org/SiteNavigationElement">
        <div class="avia-menu av-main-nav-wrap">
          <ul id="avia-menu" class="menu av-main-nav">
          
 <li id="menu-item-10868" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children menu-item-mega-parent  menu-item-top-level menu-item-top-level-2 dropdown_ul_available" style="overflow: hidden;"><a href="http://www.elastix.org/pbx/small-business-phone-system/" itemprop="url" style="height: 88px; line-height: 88px;"><span class="avia-bullet"></span><span class="avia-menu-text">OVERVIEW</span><span class="avia-menu-fx"><span class="avia-arrow-wrap"><span class="avia-arrow"></span></span></span><span class="dropdown_available"></span></a>
            </li>
          
            <li id="menu-item-10868" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children menu-item-mega-parent  menu-item-top-level menu-item-top-level-2 dropdown_ul_available" style="overflow: hidden;"><a href="http://www.elastix.org/support/" itemprop="url" style="height: 88px; line-height: 88px;"><span class="avia-bullet"></span><span class="avia-menu-text">SUPPORT</span><span class="avia-menu-fx"><span class="avia-arrow-wrap"><span class="avia-arrow"></span></span></span><span class="dropdown_available"></span></a>
            </li>
            
 <li id="menu-item-10868" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children menu-item-mega-parent  menu-item-top-level menu-item-top-level-2 dropdown_ul_available" style="overflow: hidden;"><a href="http://www.elastix.org/community/" itemprop="url" style="height: 88px; line-height: 88px;"><span class="avia-bullet"></span><span class="avia-menu-text">COMMUNITY</span><span class="avia-menu-fx"><span class="avia-arrow-wrap"><span class="avia-arrow"></span></span></span><span class="dropdown_available"></span></a>
            </li>
            
 <li id="menu-item-7336" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-has-children menu-item-mega-parent  menu-item-top-level menu-item-top-level-4 dropdown_ul_available" style="overflow: hidden;"><a href="http://www.elastix.org/free-pbx-download/" itemprop="url" style="height: 88px; line-height: 88px;"><span class="avia-bullet"></span><span class="avia-menu-text">DOWNLOAD</span><span class="avia-menu-fx"><span class="avia-arrow-wrap"><span class="avia-arrow"></span></span></span><span class="dropdown_available"></span></a>
            </li>
            
            <li id="menu-item-10868" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children menu-item-mega-parent  menu-item-top-level menu-item-top-level-2 dropdown_ul_available" style="overflow: hidden;"><a href="http://www.elastix.org/blog/" itemprop="url" style="height: 88px; line-height: 88px;"><span class="avia-bullet"></span><span class="avia-menu-text">BLOG</span><span class="avia-menu-fx"><span class="avia-arrow-wrap"><span class="avia-arrow"></span></span></span><span class="dropdown_available"></span></a>
            </li>  
                       
            <li id="menu-item-10868" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children menu-item-mega-parent  menu-item-top-level menu-item-top-level-2 dropdown_ul_available" style="overflow: hidden;"><a href="http://www.elastix.org/docs/" itemprop="url" style="height: 88px; line-height: 88px;"><span class="avia-bullet"></span><span class="avia-menu-text">DOCS</span><span class="avia-menu-fx"><span class="avia-arrow-wrap"><span class="avia-arrow"></span></span></span><span class="dropdown_available"></span></a>
            </li>


          </ul>
        </div>
      </nav>
    </div>
  </div>
  <!-- end container_wrap--> 
</div>
<?php 
	if($e!="" or $status!="") 
	{
		echo '<div id="status">';
		if($e!="") echo "<br/><br/>Errors occured: ".$e; 
		if($status!="") echo "<br/><br/>".$status; 
		echo '</div>';
	}
?>
<div id="bk_form">
  <form method="post" enctype="multipart/form-data" name="convert_backup" id="convert_backup" action="index.php">
    <table width="100%" border="0" cellspacing="0" cellpadding="0">
      <tbody>
        <tr>
          <td height="40" colspan="3" align="center"><h2> Elastix 2.5/4 to 5.X Backup Converter</h2>
            <hr></td>
        </tr>
        <tr>
          <td width="48%" height="45" align="right"><strong>Email:</strong><br>
            <div class="small">It will be used to send you the converted backup</div></td>
          <td width="2%" height="45">&nbsp;</td>
          <td width="50%" height="45"><input type="text" name="email" id="email"></td>
        </tr>
        <tr>
          <td height="45" align="right"><strong>
            <label for="extensions">Extension Length:<br>
            </label>
            </strong>
            <div class="small">Please specify the extension length number</div>
            <strong>
            <label for="extensions"> </label>
            </strong></td>
          <td height="45">&nbsp;</td>
          <td height="45"><select name="extensions" id="extensions">
              <option value="2" >2</option>
              <option value="3" selected>3</option>
              <option value="4">4</option>
              <option value="5">5</option>
            </select></td>
        </tr>
        <tr>
          <td height="45" align="right"><strong>Backup File:<br>
            </strong>
            <div class="small">Please make sure that the file is in TAR format</div>
            <strong> </strong></td>
          <td height="45">&nbsp;</td>
          <td height="45"><input type="file" name="backupfile" id="backupfile"></td>
        </tr>
        <tr>
          <td height="40" align="right">&nbsp;</td>
          <td height="40">&nbsp;</td>
          <td height="40"><input type="submit" name="submit" id="submit" value="Submit"></td>
        </tr>
      </tbody>
    </table>
    <hr>
    <p style="text-align:center;">Maximum file size - 1GB, If you have backups larger than 1 Gb send an email to <a href="mailto:admin@elastix.org">admin@elastix.org</a> and we will get back to you.</p>
  </form>
</div>
<div id="footer">Elastix.org, 2016</div>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.1.1/jquery.min.js"></script>
<script type="text/javascript">
$("#convert_backup").submit(function() { 
var email = $("#email").val();
var backupfile = $("#backupfile").val();
var result = true;
if ( null === $("#email").val().match(/^[a-zA-Z0-9-]+(\.?[a-zA-Z0-9])*([_a-zA-Z0-9-]*)@[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*(\.[a-zA-Z]{2,24})$/) )  {
       $("#email").addClass('error');
       return false;
 } else {
       $("#email").removeClass('error')
} 

if (backupfile == "") {
	alert("Please select a backup file to upload");
	return false;
}


 });
</script>
</body>
</html>
