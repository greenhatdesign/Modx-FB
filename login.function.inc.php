<?php
function login($user) {
    # process login - this function from http://modxcms.com/forums/index.php?topic=32390.20
    # modified BAS (greenhatdesign/net) June 2011
    
    /* add in required WebAuth etc functions */
    # Set Snippet Paths 
    $snipPath = $modx->config['base_path'] . "assets/snippets/";
    include_once $snipPath."weblogin/weblogin.common.inc.php";
    include_once ($modx->config['base_path'] . "manager/includes/crypt.class.inc.php");
    global $modx;
    
    defined('IN_PARSER_MODE') or die();
    
    $dbase = $modx->dbConfig['dbase'];
    $table_prefix = $modx->dbConfig['table_prefix'];
    
    //PASSWORDPOSTFIX: USER INTERVENTION REQUIRED HERE: - add in the postfix you want, make sure it's the same in WebLoginFB
    $passwordpostfix="12345"; //make this change in the WebLoginFB snippet also
    
    $logindetails = array();
    $logindetails['username'] = $user;
    $logindetails['givenPass'] = $user.$passwordpostfix; //needs to match that set in the main body
    $rememberme=FALSE;

    $username = $modx->db->escape(strip_tags($logindetails['username']));
    $givenPassword = $modx->db->escape($logindetails['givenPass']);

    // invoke OnBeforeWebLogin event
    $modx->invokeEvent("OnBeforeWebLogin",
                            array(
                                "username"        => $username,
                                "userpassword"    => $givenPassword,
                                "rememberme"    => $rememberme
                            ));

    $sql = "SELECT $dbase.`".$table_prefix."web_users`.*, $dbase.`".$table_prefix."web_user_attributes`.* FROM $dbase.`".$table_prefix."web_users`, $dbase.`".$table_prefix."web_user_attributes` WHERE BINARY $dbase.`".$table_prefix."web_users`.username = '".$username."' and $dbase.`".$table_prefix."web_user_attributes`.internalKey=$dbase.`".$table_prefix."web_users`.id;";
    $ds = $modx->db->query($sql);
    $limit = $modx->db->getRecordCount($ds);

    if($limit==0 || $limit>1) {
        $output = webLoginAlert("Incorrect username or password entered!");
        return;
    }

    $row = $modx->db->getRow($ds);

    $internalKey             = $row['internalKey'];
    $dbasePassword             = $row['password'];
    $failedlogins             = $row['failedlogincount'];
    $blocked                 = $row['blocked'];
    $blockeduntildate        = $row['blockeduntil'];
    $blockedafterdate        = $row['blockedafter'];
    $registeredsessionid    = $row['sessionid'];
    $role                    = $row['role'];
    $lastlogin                = $row['lastlogin'];
    $nrlogins                = $row['logincount'];
    $fullname                = $row['fullname'];
    //$sessionRegistered         = checkSession();
    $email                     = $row['email'];

    // load user settings
    if($internalKey){
        $result = $modx->db->query("SELECT setting_name, setting_value FROM ".$dbase.".`".$table_prefix."web_user_settings` WHERE webuser='$internalKey'");
        while ($row = $modx->fetchRow($result, 'both')) $modx->config[$row[0]] = $row[1];
    }

    if($failedlogins>=$modx->config['failed_login_attempts'] && $blockeduntildate>time()) {    // blocked due to number of login errors.
        session_destroy();
        session_unset();
        $output = webLoginAlert("Due to too many failed logins, you have been blocked!");
        return;
    }

    if($failedlogins>=$modx->config['failed_login_attempts'] && $blockeduntildate<time()) {    // blocked due to number of login errors, but get to try again
        $sql = "UPDATE $dbase.`".$table_prefix."web_user_attributes` SET failedlogincount='0', blockeduntil='".(time()-1)."' where internalKey=$internalKey";
        $ds = $modx->db->query($sql);
    }

    if($blocked=="1") { // this user has been blocked by an admin, so no way he's loggin in!
        session_destroy();
        session_unset();
        $output = webLoginAlert("You are blocked and cannot log in!");
        return;
    }

    // blockuntil
    if($blockeduntildate>time()) { // this user has a block until date
        session_destroy();
        session_unset();
        $output = webLoginAlert("You are blocked and cannot log in! Please try again later.");
        return;
    }

    // blockafter
    if($blockedafterdate>0 && $blockedafterdate<time()) { // this user has a block after date
        session_destroy();
        session_unset();
        $output = webLoginAlert("You are blocked and cannot log in! Please try again later.");
        return;
    }

    // allowed ip
    if (isset($modx->config['allowed_ip'])) {
        if (strpos($modx->config['allowed_ip'],$_SERVER['REMOTE_ADDR'])===false) {
            $output = webLoginAlert("You are not allowed to login from this location.");
            return;
        }
    }

    // allowed days
    if (isset($modx->config['allowed_days'])) {
        $date = getdate();
        $day = $date['wday']+1;
        if (strpos($modx->config['allowed_days'],"$day")===false) {
            $output = webLoginAlert("You are not allowed to login at this time. Please try again later.");
            return;
        }
    }

    // invoke OnWebAuthentication event
    $rt = $modx->invokeEvent("OnWebAuthentication",
                            array(
                                "userid"        => $internalKey,
                                "username"      => $username,
                                "userpassword"  => $givenPassword,
                                "savedpassword" => $dbasePassword,
                                "rememberme"    => $rememberme
                            ));
    // check if plugin authenticated the user

    if (!$rt||(is_array($rt) && !in_array(TRUE,$rt))) {
        // check user password - local authentication
        if($dbasePassword != md5($givenPassword)) {
            $output = webLoginAlert("Incorrect username or password entered!");
            $newloginerror = 1;
        }
    }

    if(isset($modx->config['use_captcha']) && $modx->config['use_captcha']==1) {
        if($_SESSION['veriword']!=$captcha_code) {
            $output = webLoginAlert("The security code you entered didn't validate! Please try to login again!");
            $newloginerror = 1;
        }
    }

    if(isset($newloginerror) && $newloginerror==1) {
        $failedlogins += $newloginerror;
        if($failedlogins>=$modx->config['failed_login_attempts']) { //increment the failed login counter, and block!
            $sql = "update $dbase.`".$table_prefix."web_user_attributes` SET failedlogincount='$failedlogins', blockeduntil='".(time()+($modx->config['blocked_minutes']*60))."' where internalKey=$internalKey";
            $ds = $modx->db->query($sql);
        } else { //increment the failed login counter
            $sql = "update $dbase.`".$table_prefix."web_user_attributes` SET failedlogincount='$failedlogins' where internalKey=$internalKey";
            $ds = $modx->db->query($sql);
        }
        session_destroy();
        session_unset();
        return;
    }

    $currentsessionid = session_id();

    if(!isset($_SESSION['webValidated'])) {
        $sql = "update $dbase.`".$table_prefix."web_user_attributes` SET failedlogincount=0, logincount=logincount+1, lastlogin=thislogin, thislogin=".time().", sessionid='$currentsessionid' where internalKey=$internalKey";
        $ds = $modx->db->query($sql);
    }

    $_SESSION['webShortname']=$username;
    $_SESSION['webFullname']=$fullname;
    $_SESSION['webEmail']=$email;
    $_SESSION['webValidated']=1;
    $_SESSION['webInternalKey']=$internalKey;
    $_SESSION['webValid']=base64_encode($givenPassword);
    $_SESSION['webUser']=base64_encode($username);
    $_SESSION['webFailedlogins']=$failedlogins;
    $_SESSION['webLastlogin']=$lastlogin;
    $_SESSION['webnrlogins']=$nrlogins;
    $_SESSION['webUserGroupNames'] = ''; // reset user group names

    // get user's document groups
    $dg='';$i=0;
    $tblug = $dbase.".`".$table_prefix."web_groups`";
    $tbluga = $dbase.".`".$table_prefix."webgroup_access`";
    $sql = "SELECT uga.documentgroup
            FROM $tblug ug
            INNER JOIN $tbluga uga ON uga.webgroup=ug.webgroup
            WHERE ug.webuser =".$internalKey;
    $ds = $modx->db->query($sql);
    while ($row = $modx->db->getRow($ds,'num')) $dg[$i++]=$row[0];
    $_SESSION['webDocgroups'] = $dg;

    if($rememberme) {
        $_SESSION['modx.web.session.cookie.lifetime']= intval($modx->config['session.cookie.lifetime']);
    } else {
        $_SESSION['modx.web.session.cookie.lifetime']= 0;
    }
    // update active users list if redirectinq to another page
    if($id!=$modx->documentIdentifier) {
        if (getenv("HTTP_CLIENT_IP")) $ip = getenv("HTTP_CLIENT_IP");else if(getenv("HTTP_X_FORWARDED_FOR")) $ip = getenv("HTTP_X_FORWARDED_FOR");else if(getenv("REMOTE_ADDR")) $ip = getenv("REMOTE_ADDR");else $ip = "UNKNOWN";$_SESSION['ip'] = $ip;
        $itemid = isset($_REQUEST['id']) ? $_REQUEST['id'] : 'NULL' ;$lasthittime = time();$a = 998;

        if($a!=1) {
            // web users are stored with negative id
            $sql = "REPLACE INTO $dbase.`".$table_prefix."active_users` (internalKey, username, lasthit, action, id, ip) values(-".$_SESSION['webInternalKey'].", '".$_SESSION['webShortname']."', '".$lasthittime."', '".$a."', ".$itemid.", '$ip')";
            if(!$ds = $modx->db->query($sql)) {
                $output = "error replacing into active users! SQL: ".$sql;
                return;
            }
        }
    }

    // invoke OnWebLogin event
    $modx->invokeEvent("OnWebLogin",
                            array(
                                "userid"        => $internalKey,
                                "username"        => $username,
                                "userpassword"    => $givenPassword,
                                "rememberme"    => $rememberme
                            ));

    // redirect
    if(isset($_REQUEST['refurl']) && !empty($_REQUEST['refurl'])) {
        // last accessed page
        $targetPageId= urldecode($_REQUEST['refurl']);
        if (strpos($targetPageId, 'q=') !== false) {
            $urlPos = strpos($targetPageId, 'q=')+2;
            $alias = substr($targetPageId, $urlPos);
            $aliasLength = (strpos($alias, '&'))? strpos($alias, '&'): strlen($alias);
            $alias = substr($alias, 0, $aliasLength);
            $url = $modx->config['base_url'] . $alias;
        } elseif (intval($targetPageId)) {
            $url = $modx->makeUrl($targetPageId);
        } else {
            $url = urldecode($_REQUEST['refurl']);
        }
        $modx->sendRedirect($url);
    }
    return;
}
?>