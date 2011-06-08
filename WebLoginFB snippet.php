<?php
# Created By Raymond Irving 2004
# Facebook parts added by B A Senior 2011
#::::::::::::::::::::::::::::::::::::::::
# Params:	
#
#	&loginhomeid 	- (Optional)
#		redirects the user to first authorized page in the list.
#		If no id was specified then the login home page id or 
#		the current document id will be used
#
#	&logouthomeid 	- (Optional)
#		document id to load when user logs out	
#
#	&pwdreqid 	- (Optional)
#		document id to load after the user has submited
#		a request for a new password
#
#	&pwdactid 	- (Optional)
#		document id to load when the after the user has activated
#		their new password
#
#	&logintext		- (Optional) 
#		Text to be displayed inside login button (for built-in form)
#
#	&logouttext 	- (Optional)
#		Text to be displayed inside logout link (for built-in form)
#	
#	&tpl			- (Optional)
#		Chunk name or document id to as a template
#				  
#	Note: Templats design:
#			section 1: login template
#			section 2: logout template 
#			section 3: password reminder template 
#
#			See weblogin.tpl for more information
#
# Examples:
#
#	[[WebLogin? &loginhomeid=`8` &logouthomeid=`1`]] 
#
#	[[WebLogin? &loginhomeid=`8,18,7,5` &tpl=`Login`]] 

//initialise variable
$output="";

# Set Snippet Paths 
$snipPath = $modx->config['base_path'] . "assets/snippets/";

# check if inside manager
if ($m = $modx->insideManager()) {
	return ''; # don't go any further when inside manager
}

# deprecated params - only for backward compatibility
if(isset($loginid)) $loginhomeid=$loginid;
if(isset($logoutid)) $logouthomeid = $logoutid;
if(isset($template)) $tpl = $template;

# Snippet customize settings
$liHomeId	= isset($loginhomeid)? explode(",",$loginhomeid):array($modx->config['login_home'],$modx->documentIdentifier);
$loHomeId	= isset($logouthomeid)? $logouthomeid:$modx->documentIdentifier;
$pwdReqId	= isset($pwdreqid)? $pwdreqid:0;
$pwdActId	= isset($pwdactid)? $pwdactid:0;
$loginText	= isset($logintext)? $logintext:'Sign in';
$logoutText	= isset($logouttext)? $logouttext:'Sign out';
$tpl		= isset($tpl)? $tpl:"";

# System settings
$webLoginMode = isset($_REQUEST['webloginmode'])? $_REQUEST['webloginmode']: '';
$isLogOut		= $webLoginMode=='lo' ? 1:0;
$isPWDActivate	= $webLoginMode=='actp' ? 1:0;
$isPostBack		= count($_POST) && (isset($_POST['cmdweblogin']) || isset($_POST['cmdweblogin_x']));
$txtPwdRem 		= isset($_REQUEST['txtpwdrem'])? $_REQUEST['txtpwdrem']: 0;
$isPWDReminder	= $isPostBack && $txtPwdRem=='1' ? 1:0;

$site_id = isset($site_id)? $site_id: '';
$cookieKey = substr(md5($site_id."Web-User"),0,15);

# Start processing
include_once $snipPath."weblogin/weblogin.common.inc.php";
include_once ($modx->config['base_path'] . "manager/includes/crypt.class.inc.php");
include_once $snipPath."weblogin/login.function.inc.php";


if ($isPWDActivate || $isPWDReminder || $isLogOut || $isPostBack) {
	# include the logger class
	include_once $modx->config['base_path'] . "manager/includes/log.class.inc.php";
	include_once $snipPath."weblogin/weblogin.processor.inc.php";
}

    //run weblogin as usual - comment this out if you don't want the old style Modx login option
	include_once $snipPath."weblogin/weblogin.inc.php";

//name of the modx user group
$usergroup="WebUsers";

//PASSWORDPOSTFIX: USER INTERVENTION REQUIRED HERE: - add in the postfix you want, make sure it's the same in WebLoginFB
$passwordpostfix="12345";  //make this change in the login function also

$email="pleaseenter@youremail.here";

//database variables
$table_prefix = $modx->dbConfig['table_prefix'];

//if logged in then send to home page
if ($modx->getLoginUserType() == 'web')
{
    $modx->sendForward(1);
}else
{
    include_once ('./facebook/facebook.php');
    // Create our Application instance (replace this with your appId and secret).
    $facebook = new Facebook(array(
      'appId'  => '[YOUR_APP_ID]',
      'secret' => '[YOUR_FB_SECRET]',
      'cookie' => true,
    ));
    // If we get a session here, it means we found a correctly signed session using
    // the Application Secret only Facebook and the Application know. We dont know
    // if it is still valid until we make an API call using the session. A session
    // can become invalid if it has already expired (should not be getting the
    // session back in this case) or if the user logged out of Facebook.
    $session = $facebook->getSession();
    
    $me = null;
    // Session based API call.
    if ($session)
    {
      try
      {
        $user_id = $facebook->getUser();
        $me = $facebook->api('/me');
        /* This for debug - don't forget to add a return $output */
        //DEBUG   $output.="user ID: ".$user_id;
        //DEBUG $output.="<img src=\"https://graph.facebook.com/".$user_id."/picture\">";
        $fullname=$me['name'];
        //DEBUG $output.="name:".$fullname;
        //create the modx username, password and add to DB
        $username=$user_id;
        $pass = md5($user_id.$passwordpostfix);
        //does the record exist?
        $result = $modx->db->select('username',$modx->getFullTableName('web_users')," username = '".$user_id."'");
        if($modx->db->getRecordCount($result) != 1)
        {
            //no it doesn't - so create the user account
            //DEBUG $output.="user ".$user_id." doesn't exist in the database";
            $sql = "INSERT INTO ".$modx->getFullTableName('web_users')." (username,password) VALUES ('".$user_id."', '".$pass."')";
            $rs = $modx->db->query($sql);
            if(!$rs)
            {
                $output.="<p>ERROR: unable to write to database table ".$modx->getFullTableName('web_users');
                $output.="<br /> Please report this error to the site admin</p>";
            }else
            {
                $uid = $modx->db->getInsertId();
                //DEBUG $output.="<br />UID: ".$uid." Fullname: ".$fullname." email: ".$email;
                //$sql = "INSERT INTO ".$modx->getFullTableName('web_user_attributes')." (internalKey,fullname,email) VALUES (".$uid.",'".$fullname"','".$email."');";
                $sql = "INSERT INTO ".$modx->getFullTableName('web_user_attributes')." (internalKey,fullname,email) VALUES (".$uid.",'".$fullname."','".$email."');";
                //DEBUG $output.="<br />SQL: ".$sql;
                
                   $rs = $modx->db->query($sql);
                   if(!$rs) {
                              $modx->db->delete($modx->getFullTableName('web_users'),"id = ".$uid);
                              $output.="<p>ERROR: unable to write to database table ".$modx->getFullTableName('web_user_attributes');
                              $output.="<br /> Please report this error to the site admin</p>";
                    } else
                    {
                        // add user to the group
                              $result = $modx->db->select('id',$modx->getFullTableName('webgroup_names'),"name = '".$usergroup."'");
                              if($modx->db->getRecordCount($result) == 1) {
                                        $gid = $modx->db->getValue($result);
                                        $sql = "INSERT INTO ".$modx->getFullTableName('web_groups')." (webgroup, webuser) VALUES (".$gid.",".$uid.")";
                                        $result = $modx->db->query($sql);
                                        if (!$result)
                                        {
                                            $output.="<p>ERROR: unable to write to database table ".$modx->getFullTableName('web_groups');
                                            $output.="<br /> Please report this error to the site admin</p>";
                                        }else
                                        {
                                          //DEBUG  $output.="<br />All data written to DB ok";
                                        }
                               }else
                               {
                                    $output.="<p>ERROR: unable to find user group ".$usergroup." in database table ".$modx->getFullTableName('web_groups');
                                    $output.="<br /> Please report this error to the site admin</p>";
                               }
                    }
            }       
        }
        
      } catch (FacebookApiException $e)
      {
        error_log($e);
        //DEBUG $output.="Error: ".$e;
      }
      login($user_id);   

    }
	
    //if logged in then send to home page
    if ($modx->getLoginUserType() == 'web')
    {
	$modx->sendForward(1);
    } 

return $output;
}
?>