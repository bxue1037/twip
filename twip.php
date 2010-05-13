<?php
/*
 * The contents of this file are subject to the Mozilla Public License
 * Version 1.1 (the "License"); you may not use this file except in
 * compliance with the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS"
 * basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See the
 * License for the specific language governing rights and limitations
 * under the License.
 *
 * The Original Code is Twip.
 *
 * The Initial Developer of the Original Code is yegle <http://yegle.net> and 
 * xmxsuperstar <http://www.xmxsuperstar.com/>. All Rights Reserved.
 *
 * Contributor(s): bronco <http://heybronco.net>
 */


require_once('include/twitteroauth.php');
require_once('include/utility.php');
require_once('oauth_config.php');
session_start();

class twip{
    const DEBUG = false;
    const DOLOG = true;
    const WEBROOT = 'twip';
    const PARENT_API = 'http://twitter.com';
    const PARENT_SEARCH_API = 'http://search.twitter.com';
    const ERR_LOGFILE = 'err.txt';
    const LOGFILE = 'log.txt';
    const LOGTIMEZONE = 'Etc/GMT-8';
    const CGI_WORKAROUND = false;


    public function twip ( $options = null ){
		$this->issearch = false;
		$this->url = '';
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->debug = !!$options['debug'] || self::DEBUG;
        $this->dolog = !!$options['dolog'] & self::DOLOG;
        $this->webroot = !empty($options['webroot']) ? $this->mytrim($options['webroot']) : self::WEBROOT;
        $this->parent_api = !empty($options['parent_api']) ? $this->mytrim($options['parent_api']) : self::PARENT_API;
        $this->parent_search_api = !empty($options['parent_search_api']) ? $this->mytrim($options['parent_search_api']) : self::PARENT_SEARCH_API;
        $this->err_logfile = !empty($options['err_logfile']) ? $options['err_logfile'] : self::ERR_LOGFILE;
        $this->logfile = !empty($options['logfile']) ? $options['logfile'] : self::LOGFILE;
        $this->log_timezone = !empty($options['log_timezone']) ? $options['log_timezone'] : self::LOGTIMEZONE;
        $this->replace_shorturl = !!$options['replace_shorturl'];
        $this->docompress = !!$options['docompress'];
        $this->cgi_workaround = ($options['cgi_workaround']==="YES I DO NEED THE WORKAROUND!") ? true : self::CGI_WORKAROUND;
        $this->private_api = !!$options['private_api'][0];
        if($this->private_api){
            $this->allowed_users = explode(',',$options['private_api']['allowed_users']);
            foreach($this->allowed_users as $key=>$value){
                $this->allowed_users[$key] = strtolower($value);
            }
        }
		$this->enable_oauth = !!$options['enable_oauth'];

		$this->check_server();

        $this->pre_request();
        $this->dorequest();
        $this->post_request();
    }


    private function pre_request(){
        if(strlen($this->webroot) == 0){//use "/" as webroot
            $this->request_api = strval(substr($_SERVER['REQUEST_URI'],1));
        }else{
            if(stripos($_SERVER['REQUEST_URI'],$this->webroot) !== false){
                $this->request_api =strval(substr($_SERVER['REQUEST_URI'],strlen($this->webroot) + 2));
            }else{
                $this->err();
            }
        }

        if($this->request_api =='' || strpos($this->request_api,'index.php')!==false){
            $this->err();
        }

        $this->request_api = $this->mytrim($this->request_api);
        if($this->method == 'POST'){
            $this->post_data = $this->ProcessPostData((bool)stripos($this->request_api,'/update.'),$this->enable_oauth);
        }

		if( strpos($this->request_api,'api/') === 0 ){//workaround for twhirl
            $this->request_api = substr($this->request_api,4);
        }
        if( strpos($this->request_api,'search')===0 || strpos($this->request_api,'trends')===0){
            $this->url = $this->parent_search_api.'/'.$this->request_api;
			$this->issearch = true;
        }
        else{
            $this->url = $this->parent_api.'/'.$this->request_api;
			$this->issearch = false;
        }
    }


    private function dorequest(){
        $this->pwd = $this->user_pw();
		list($this->username, $this->password) = explode(':', $this->pwd);
        if( !$this->issearch && $this->private_api && !in_array($this->username,$this->allowed_users)){
            header("HTTP/1.1 403 Forbidden");
            exit();
        }
		//==============================================OAuth=================================================
		if( !$this->issearch && $this->enable_oauth ) {
			if (empty($_SESSION['access_token'])) {
				if( !file_exists( OAUTH_DIR.$this->username.'.oauth' )) {
					header("HTTP/1.1 403 Forbidden");
					exit();
				}
				$filecontents = file_get_contents(OAUTH_DIR.$this->username.'.oauth');
				list($crypted,$md5pwd,$token) = explode(',',$filecontents);
				if(md5(md5($this->password).SECURE_KEY) != $md5pwd)
				{
					header("HTTP/1.1 401 Unauthorized");
					exit();
				}

				if ((bool)$crypted ){
					if (!function_exists('mcrypt_module_open'))
						$this->err("mcrypt is needed!");
					$access_token = unserialize(decrypt($token,$this->password.SECURE_KEY));
				} else {
					$access_token = unserialize($token);
				}
				$_SESSION['access_token'] = $access_token;

			}else{
				$access_token = $_SESSION['access_token'];
			}

			$_SESSION['user'] = $this->username;
			$connection = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, $access_token['oauth_token'], $access_token['oauth_token_secret']);
			$connection->useragent = $_SERVER['HTTP_USER_AGENT'];
			list( $oauthurl, $args ) = explode( '.', $this->request_api );
			$connection->format = $args;
			if( $this->method == 'POST' ){
				$this->ret = $connection->post($oauthurl, $this->post_data);
			}
			else{
				$args = explode( '&',$args );
				$arr = array();
				foreach( $args as $arg ){
					list( $key, $value ) = explode( '=', $arg );
					$arr[$key] = $value;
				}
				$this->ret = $connection->get($oauthurl);
			}
			return;
		}
		//====================================================================================================
        $ch = curl_init($this->url);
        $curl_opt = array();
        if($this->method == 'POST'){
            $curl_opt[CURLOPT_POST] = true;
            $curl_opt[CURLOPT_POSTFIELDS] = $this->post_data;
        }
        $curl_opt[CURLOPT_USERAGENT] = $_SERVER['HTTP_USER_AGENT'];
        $curl_opt[CURLOPT_RETURNTRANSFER] = true;
		if ( !$this->issearch )
			$curl_opt[CURLOPT_USERPWD] = $this->pwd;
        $curl_opt[CURLOPT_HEADERFUNCTION] = create_function('$ch,$str','if(strpos($str,\'Content-Length:\') === false ) { header($str); } return strlen($str);');
        $curl_opt[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1 ;//avoid the "Expect: 100-continue" error
        curl_setopt_array($ch,$curl_opt);
        $this->ret = curl_exec($ch);
        curl_close($ch);
    }

    private function post_request(){
        if($this->replace_shorturl){
            $this->replace_shorturl();
        }

        if($this->docompress && Extension_Loaded('zlib')) {
            if(!Ob_Start('ob_gzhandler')){
                Ob_Start();
            }
        } else {
            header('Content-Length: '.strlen($this->ret));
        }

        echo $this->ret;

        if($this->docompress && Extension_Loaded('zlib')) {
            Ob_End_Flush();
        }
        if($this->dolog){
            $this->dolog();
        }
    }

    private function user_pw(){
        if(!empty($_SERVER['PHP_AUTH_USER'])){
            $name = strtolower($_SERVER['PHP_AUTH_USER']);
            return $name.':'.$_SERVER['PHP_AUTH_PW'];
        }
        else if(!empty($_SERVER['HTTP_AUTHORIZATION'])||!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])){
            $auth = empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION']:$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            $a = base64_decode( substr($auth,6)) ;
            list($name, $password) = explode(':', $a);
            $name = strtolower($name);
            return $name.':'.$password;
        }
        else if($this->cgi_workaround){
            $pattern = '/^([^:]*):([^\/]*)[\/]+(.*)$/';
            if(preg_match($pattern,$this->request_api,$matches)){
                $this->request_api = $matches[3];
                $name = strtolower($matches[1]);
                return $name.':'.$matches[2];
            }
        }

		if ( $this->issearch )
			return "nobody:nobody";

		
		if($this->private_api && empty($this->username))
		{
			header("WWW-Authenticate: Basic realm=\"Twip Info\"");
			header("HTTP/1.0 401 Unauthorized"); 
			exit();
		}
    }

    private function mytrim($str){
        return trim($str,'/');
    }

    private function check_server(){
        if(!function_exists('curl_init') &&
        !function_exists('curl_setopt_array') &&
        !function_exists('curl_exec') &&
        !function_exists('curl_close')){
            $this->err("curl functions doesn't exists!");
        }
        else if(!function_exists('file_get_contents') && !function_exists('file_put_contents')){
            $this->err("PHP 5 is needed!");
        }
    }

    private function err($str=null){
        if(!empty($str)){
            $this->errlog($str);
			$info = '<p style="color:#FF0000">'.$str.'</p>';
        }
		else
		{
			if(!isset($_SESSION['access_token']) && !isset($_SESSION["dologin"]))
			{
				$info = '<p style="color:#48AC1D">Seems everyting works fine.</p><p><u>';
				if ($this->enable_oauth) {
					$info .= 'Using OAuth Authentication.</u>';
					if (function_exists('mcrypt_module_open'))
						$info .= ' <span style="color:#48AC1D">The access token will been crypted.</span>';
					else
						$info .= ' <span style="color:#FF0000">The access token will NOT been crypted.</span>';
				}
				else
					$info .= 'Using Basic Authentication.</u>';
				$info .= '</p>';
				if ($this->private_api)
					$info .= '<p style="color:#FF0000"><strong>WARNING! PRIVATE_PROXY IS ENABLED,UNAUTHORIZED USERS WILL NOT BE ABLE TO OBTAIN ANY INFORMATION.</strong></p>';
			}
		}
		$enable_oauth = $this->enable_oauth;
		include('home.inc');
		exit();
    }

    private function errlog($str){
		date_default_timezone_set($this->log_timezone);		//set timezone
		$postinfo = is_array($this->post_data)?serialize($this->post_data):$this->post_data;
		$msg = date('Y-m-d H:i:s').' '.$this->request_api.' '.$postinfo.' '.$this->username.' '.$str."\n";
		file_put_contents($this->err_logfile,$msg,FILE_APPEND);
    }

    private function replace_shorturl(){
        $url_pattern = "/http:\/\/(?:j\.mp|bit\.ly|ff\.im|is\.gd|tinyurl\.com)\/[\w|\-]+/";

        if(preg_match_all($url_pattern,$this->ret,$matches)){
            $query_arr = array();
            foreach($matches[0] as $shorturl){
                $query_arr[] = "q=".$shorturl;
            }
            $offset = 0;
            $query_count = 5;
            $replace_arr = array();
            do{
                $tmp_arr = array_slice($query_arr,$offset,$query_count);
                $query_str = implode("&",$tmp_arr);
                $json_str = @file_get_contents("http://www.longurlplease.com/api/v1.1?".$query_str);
                if( $json_str !==FALSE ){
                    $json_arr = json_decode($json_str,true);
                    $replace_arr = array_merge($json_arr,$replace_arr);
                }
                $offset+=$query_count;
            }while( count($tmp_arr)===$query_count );//split the queries to avoid a too long query string.:
            foreach($replace_arr as $key=>$value){
                $replace_arr[$key] = str_replace('&#039;', '&apos;', htmlspecialchars($value, ENT_QUOTES));
            }
           	$this->ret = str_replace(array_keys($replace_arr),array_values($replace_arr),$this->ret);
        }
    }

	private function ProcessPostData($autoCut=false,$returnAsArray=false)
	{
		if(empty($_POST)) return '';
		if(!is_array($_POST)) return $_POST;

		$c = 0;$out = '';
		try{
			foreach($_POST as $name => $value) {
				if($c++ != 0) $out .= '&';
				$out .= urlencode($name).'=';
				
				if(is_array($value)){
					$out .= urlencode(serialize($value));
				}else{
					if(get_magic_quotes_gpc())
						$value = stripslashes($value);

					if($autoCut && $name == 'status')
						$value = $this->sysSubStr($value,140,true);

					$_POST[$name] = $value;
					$out .= urlencode($value);
				}
			}
		}  catch (Exception $e) {
			$out = @file_get_contents('php://input');
			$this->errlog($e->message);
		}
		if ($returnAsArray) return $_POST;
		return $out;
	}

	/**
    * Return part of a string(Enhance the function substr())
    *
    * @author                  Chunsheng Wang <wwccss@263.net>
	* @modifier                bronco
    * @param  string  $String  the string to cut.
    * @param  int     $Length  the length of returned string.
    * @param  booble  $Append  whether append "...": false|true
    * @return string           the cutted string.
    */
    private function sysSubStr($String,$Length,$Append = false)
    {
        if (mb_strlen($String,'UTF-8') <= $Length ){
            return $String;
        }
        else
        {
            $I = 0;
            $Count = 0;

            while ($Count < $Length)
            {
                $StringTMP = substr($String,$I,1);
                if ( ord($StringTMP) >=224 )
                {
                    $StringTMP = substr($String,$I,3);
                    $I = $I + 3;
                }
                elseif( ord($StringTMP) >=192 )
                {
                    $StringTMP = substr($String,$I,2);
                    $I = $I + 2;
                }
                else
                {
                    $I = $I + 1;
                }
                $Count ++;
                $StringLast[] = $StringTMP;
            }
            if($Append)
                array_pop($StringLast);
            $StringLast = implode("",$StringLast);
            if($Append && $String != $StringLast)
                $StringLast .= urldecode("%E2%80%A6"); //utf-8 code of "..." as a character
            return $StringLast;
        }
    }


    private function dolog(){
        date_default_timezone_set($this->log_timezone);		//set timezone
        $msg = date('Y-m-d H:i:s').' '.$this->request_api.' '.$this->username.' compress: '.(!!$this->docompress?'yes':'no')."\n";
        file_put_contents($this->logfile,$msg,FILE_APPEND);
    }
}
?>
