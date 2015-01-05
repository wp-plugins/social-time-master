<?php

class STMPlugin {

	public function __construct() {
		global $wp_scripts;
		if (is_admin()) {
			add_action('admin_menu', array($this, 'adminMenu'));
			add_action('wp_ajax_stmjs', array($this, 'stmajax'));
			add_action('admin_enqueue_scripts', array($this, 'stmjs'));
			add_action('edit_form_advanced', array($this, 'editPost'));
			add_action('edit_page_form', array($this, 'editPost'));
			add_action('edit_post', array($this, 'savePost'));
			add_action('publish_post', array($this, 'savePost'));
			add_action('save_post', array($this, 'savePost'));
			add_action('edit_page_form', array($this, 'savePost'));
		}
		else {
			add_action('init', array($this, 'Cron'));
			add_action('wp_head', array($this, 'PostMeta'));
		}
		//add_filter('default_title', array($this,'defTitle') );
	}

	function DoPost($info, $dodelete=1) {
		global $wpdb;
		//return 'http://done.com';
		foreach ($info as $k=>$v) $info[$k] = stripslashes(stripslashes($v));
		$tm = time();
		$stub = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_accounts WHERE (id=%d) AND (auth3<>'');", $info['accountid']));
		if (!count($stub)) return '';
		$account = (array)$stub[0];
		if ($account['paused']) return '';
		$url = $info['url'];
		$bitly = $this->BitLyURL($url);
		if (!$bitly || !strpos(' '.$bitly, 'http')) $bitly = $url;
		if (strpos(' '.$info['content'], '[url]')) $info['content'] = str_replace('[url]', $bitly, $info['content']);
		else $info['content'] .= ' '.$bitly;
		$postcontent = $info['content'];
		$atype = $account['atype'];
		if ($account['atype'] == 'twitter') {
			//$postcontent = $this->LimitLength($info['content'], 120);
			$postcontent = $this->LimitLength($info['title'], 120);
			if (strpos(' '.$postcontent, '[url]')) $postcontent = str_replace('[url]', $bitly, $postcontent);
			else $postcontent .= ' '.$bitly;
			$nonce = time();
			$params = array(
				'include_entities'=>'true',
				'oauth_consumer_key'=>$account['auth1'],
				'oauth_nonce'=>$nonce,
				'oauth_signature_method'=>'HMAC-SHA1',
				'oauth_timestamp'=>$tm,
				'oauth_token'=>$account['auth3'],
				'oauth_version'=>'1.0',
				'status'=>$postcontent
			);
			$baseString = $this->buildBaseString('https://api.twitter.com/1.1/statuses/update.json', $params);
			$compositeKey = $this->getCompositeKey($account['auth2'], $account['auth4']);
			$signature = base64_encode(hash_hmac('sha1', $baseString, $compositeKey, true));
			$params['oauth_signature'] = $signature;
			$ret = $this->HTTPPost('https://api.twitter.com/1.1/statuses/update.json', $params);
			$ret = json_decode($ret, 1);
			if (isset($_GET['d'])) {
				echo 'ret: ';
				print_r($ret);
				echo '<br />';
			}
			//Array ( [errors] => Array ( [0] => Array ( [message] => Bad Authentication data [code] => 215 ) ) ) 
			if (isset($ret['errors'])) {
				if ($ret['errors'][0]['code'] == 215) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET auth3='' WHERE id=%d;", $info['accountid']));
				return 'err-'.$ret['errors'][0]['message'];
			}
			$userid = $ret['user']['screen_name'];
			$postid = $ret['id'];
			$posturl = "https://twitter.com/$userid/status/$postid";
		}
		if ($account['atype'] == 'facebook') {
			$postcontent = "URL: $bitly<br />Image: $info[imgurl]<br />Content: $info[content]";
			if ($account['info'] == 'page') {
				$post_args = array(
					'privacy'=>json_encode(array('value'=>'EVERYONE', 'description'=>'This is shared to all')),
					'access_token' => $account['auth4'],
					'message' => $info['content'],
					'link' => $bitly,
					'picture' => $info['imgurl']
				);
				$ret = $this->HTTPPost("https://graph.facebook.com/$account[auth3]/feed", $post_args);
				$ret = json_decode($ret, 1);
				if (isset($_GET['d'])) {
					echo 'ret: ';
					print_r($ret);
					echo '<br />';
				}
				$atype = 'fbpage';
			}
			elseif ($account['info'] == 'group') {
				$post_args = array(
					'privacy'=>json_encode(array('value'=>'EVERYONE', 'description'=>'This is shared to all')),
					'access_token' => $account['auth4'],
					'message' => $info['content'],
					'link' => $bitly,
					'picture' => $info['imgurl']
				);
				$ret = $this->HTTPPost("https://graph.facebook.com/$account[auth3]/feed", $post_args);
				$ret = json_decode($ret, 1);
				if (isset($_GET['d'])) {
					echo 'ret: ';
					print_r($ret);
					echo '<br />';
				}
				$atype = 'fbgroup';
			}
			else {
				$post_args = array(
					'privacy'=>json_encode(array('value'=>'EVERYONE', 'description'=>'This is shared to all')),
					'access_token' => $account['auth4'],
					'message' => $info['content'],
					'link' => $bitly,
					'picture' => $info['imgurl']
				);
				$ret = $this->HTTPPost("https://graph.facebook.com/$account[auth3]/feed", $post_args);
				$ret = json_decode($ret, 1);
				if (isset($_GET['d'])) {
					echo 'ret: ';
					print_r($ret);
					echo '<br />';
				}
				$atype = 'fbacc';
			}
			if (isset($ret['error'])) {
				if ($ret['error']['type'] == 'OAuthException') $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET auth3='' WHERE id=%d;", $info['accountid']));
				return 'err-'.$ret['error']['message'];
			}
			//ret: Array ( [error] => Array ( [message] => (#803) Some of the aliases you requested do not exist: a469946106440103 [type] => OAuthException [code] => 803 ) ) 
			$stub = explode('_', $ret['id']);
			$userid = $stub[0];
			$postid = $stub[1];
			$posturl = "https://www.facebook.com/$userid/posts/$postid";
		}
		if ($account['atype'] == 'linkedin') {
			$postcontent = "Title: $info[title]<br />URL: $bitly<br />Image: $info[imgurl]<br />Description: $info[content]";
			$share = "<"."?xml version='1.0' encoding='UTF-8'?".">
				<share>
				  <comment>$info[content]</comment>
				  <content>
					<title>$info[title]</title>
					<description>$info[content]</description>
					<submitted-url>$bitly</submitted-url>
					<submitted-image-url>$info[imgurl]</submitted-image-url> 
				  </content>
				  <visibility><code>anyone</code></visibility>
				</share>";
			$response = $this->HTTPPost("https://api.linkedin.com/v1/people/~/shares?oauth2_access_token=$account[auth3]", $share, 1);
			//<update><update-key>UPDATE-24966616-5936241053718835200</update-key><update-url>https://www.linkedin.com/updates?discuss=&amp;scope=24966616&amp;stype=M&amp;topic=5936241053718835200&amp;type=U&amp;a=LeKn</update-url></update>
			if (isset($_GET['d'])) {
				echo 'ret: ';
				print_r($response);
				echo '<br />';
			}
			if (strpos(' '.$response, '<error>')) {
				$err = $this->GetBetweenTags($response, '<error>', '</error>');
				$status = $this->GetBetweenTags($err, '<status>', '</status>');
				if ($status == 401) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET auth3='' WHERE id=%d;", $info['accountid']));
				$msg = $this->GetBetweenTags($err, '<message>', '</message>');
				return 'err-'.$msg;
			}
			$key = $this->GetBetweenTags($response, '<update-key>', '</update-key>');
			$key = explode('-', $key);
			$userid = $key[1]; //https://www.linkedin.com/profile/view?id=$userid
			$postid = $key[2];
			$posturl = "https://www.linkedin.com/updates?discuss=&scope=$userid&topic=$postid";
		}
		if ($account['atype'] == 'tumblr') {
			require_once(STM_DIR.'/tumblroauth/tumblroauth.php');
			$post_URI = 'http://api.tumblr.com/v2/blog/'.$account['info'].'.tumblr.com/post';
			$tum_oauth = new TumblrOAuth($account['auth1'], $account['auth2'], $account['auth3'], $account['auth4']);
			$parameters = array();
			//link
			$parameters['type'] = 'link';
			//$parameters['tags'] = 'tag1,tag2';
			$parameters['title'] = $info['title'];
			$parameters['url'] = $bitly;
			$parameters['description'] = "$info[content]<br /><a href='$bitly' target='_blank'><img src='$info[imgurl]' style='width: 100%;' /></a>";
			$postcontent = "Title: $info[title]<br />URL: $bitly<br />Description: $parameters[description]";
			$post = $tum_oauth->post($post_URI, $parameters);
			if (isset($_GET['d'])) {
				echo 'ret: ';
				print_r($post);
				echo '<br />';
			}
			//ret: stdClass Object ( [meta] => stdClass Object ( [status] => 401 [msg] => Not Authorized ) [response] => Array ( ) ) 
			if (isset($post->meta->status) && ($post->meta->status == 401)) {
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET auth3='' WHERE id=%d;", $info['accountid']));
				return 'err-'.$post->meta->msg;
			}
			if (isset($post->meta->status) && ($post->meta->status != 201)) return 'err-'.$post->meta->msg;
			$userid = $account['info'];
			$postid = $post->response->id;
			$posturl = "http://$userid.tumblr.com/post/$postid";
		}
		if ($posturl) {
			$postcontent = addslashes($postcontent);
			$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_postlog (variationid, postid, atype, aname, userid, rpostid, url, bitly, posturl, ptime, content, numvar) VALUES (%d, %d, %s, %s, %d, %d, %s, %s, %s, %d, %s, %d);", $info['variationid'], $info['postid'], $atype, $account['username'], $userid, $postid, $url, $bitly, $posturl, $tm, $postcontent, $info['numvar']));
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_urls SET numshares=numshares+1 WHERE bitly=%s;", $bitly));
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_schedule SET lastposttime=%d, repdone=repdone+1 WHERE id=%d;", $tm, $info['scheduleid']));
			$wpdb->query("DELETE FROM {$wpdb->prefix}stm_schedule WHERE (id=$info[scheduleid]) AND (NOT repcount);"); //delete if there are no repeats
			$wpdb->query("DELETE FROM {$wpdb->prefix}stm_schedule WHERE (id=$info[scheduleid]) AND (repcount<>0) AND (repcount=repdone);"); //delete if it is repeats and all done
		}
		if ($dodelete) $wpdb->query("DELETE FROM {$wpdb->prefix}stm_timeline WHERE id=$info[tlid];");
		return $posturl;
	}


	function Cron() {
		global $wpdb;
		//return '';
		$tm = time();
		$stmlastcron = get_option('stmlastcron');
		if (!$stmlastcron) $stmlastcron = $tm-70;
		if ($stmlastcron > $tm-60) return ''; //every minute
		if (isset($_GET['d'])) echo 'cron started...<br />';
		$accids = array();
		$tm2 = $tm-1800; //post only these from the last half an hour... older which are missed to be posted will be skipped to prevent spamming
		$data = $wpdb->get_results($wpdb->prepare("SELECT t.*, v.*, t.id as tlid FROM {$wpdb->prefix}stm_timeline as t LEFT JOIN {$wpdb->prefix}stm_variations as v ON t.variationid=v.id WHERE (posttime<=%d) && (posttime>%d);", $tm, $tm2));
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			if (in_array($info['accountid'], $accids)) continue;
			$ret = $this->DoPost($info);
			if (!strpos(' '.$ret, 'err-')) array_push($accids, $info['accountid']);
		}
		$stmlastvercheck = get_option('stmlastvercheck');
		if (!$stmlastvercheck) $stmlastvercheck = $tm-43201;
		if ($stmlastvercheck > $tm-43200) { //12 hours
			$lastver = file_get_contents('http://wiziva.com/?lastver='.STM_WIZIVA_ID);
			if (strlen($lastver) == 4) {
				$curver = str_replace('.', '', STM_PLUGIN_VERSION);
				$curver = $curver . str_repeat('0', 4-strlen($curver));
				if ($curver < $lastver) update_option('stmhasnewver', 1);
			}
			update_option('stmlastvercheck', $tm);
		}
		update_option('stmlastcron', $tm);
	}



	function PostMeta() {
		global $post, $wpdb;
		$nocards = get_option('stmnocards');
		$noog = get_option('stmnoog');
		if ($nocards && $noog) return '';
		$postid = $post->ID;
		if (!isset($_GET['stmv'])) $_GET['stmv'] = 0;
		$title = '';
		$data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_variations WHERE (postid=%d) AND (numvar=%d)", $postid, $_GET['stmv']));
		if (count($data)) {
			$info = (array)$data[0];
			$title = $info['title'];
			$imgurl = $info['imgurl'];
			$content = stripslashes($info['content']);
		}
		if (!$title) {
			$imgurl = get_the_post_thumbnail($postid, 'large');
			if ($imgurl) $imgurl = $this->GetBetweenTags($imgurl, 'src="', '"');
			$title = get_the_title($postid);
			$content = get_the_excerpt();
			if (!$content) $content = get_the_content();
			if ($content) $content = $this->LimitLength($content, 120);
			else $content = $title;
		}
		$url = $this->PostVarURL($postid, $_GET['stmv']);
		$content = strip_tags($content);
		$content = str_replace("\r", '', $content);
		$content = str_replace("\n", '', $content);
		//https://cards-dev.twitter.com/validator
		//https://developers.facebook.com/tools/debug/og/object/
		if (!$nocards) {
			$stmtwiuser = get_option('stmtwiuser');
			$stmtwisite = get_option('stmtwisite');
			$stmcardformat = get_option('stmcardformat');
			if (!$stmcardformat) $stmcardformat = 1;
			if ($stmcardformat == 1) { //Summary with Large Image
				echo "
					<meta name='twitter:card' content='summary_large_image' />
					<meta name='twitter:site' content='@$stmtwisite' />
					<meta name='twitter:creator' content='@$stmtwiuser' />
					<meta name='twitter:title' content=\"$title\" />
					<meta name='twitter:description' content=\"$content\" />
					<meta name='twitter:image:src' content=\"$imgurl\" />
				";
			}
			if ($stmcardformat == 2) { //Summary
				echo "
					<meta name='twitter:card' content='summary' />
					<meta name='twitter:site' content='@$stmtwisite' />
					<meta name='twitter:title' content=\"$title\" />
					<meta name='twitter:description' content=\"$content\" />
					<meta name='twitter:image' content=\"$imgurl\" />
					<meta name='twitter:url' content=\"$url\" />
				";
			}
			if ($stmcardformat == 3) { //Photo
				echo "
					<meta name='twitter:card' content='photo' />
					<meta name='twitter:site' content='@$stmtwisite' />
					<meta name='twitter:title' content=\"$title\" />
					<meta name='twitter:description' content=\"$content\" />
					<meta name='twitter:image' content=\"$imgurl\" />
					<meta name='twitter:url' content=\"$url\" />
				";
			}
		}
		if (!$noog) {
			$blogname = get_bloginfo('name');
			echo "
				<meta property='og:type' content='blog' />
				<meta property='og:site_name' content=\"$blogname\" />
				<meta property='og:url' content=\"$url\" />
				<meta property='og:title' content=\"$title\" />
				<meta property='og:image' content=\"$imgurl\" />
				<meta property='og:description' content=\"$content\" />
			";
		}
	}

	function LimitLength($str, $numchars=300) {
		if (strlen($str) > $numchars) {
			$numchars = strpos($str, ' ', $numchars-10);
			return substr ($str, 0, $numchars). " ...";
		}
		else return $str;
	}

	function BitLyURL($url) {
		global $wpdb;
		$bitly = $wpdb->get_var($wpdb->prepare("SELECT bitly FROM {$wpdb->prefix}stm_urls WHERE url=%s", $url));
		if ($bitly && strpos(' '.$bitly, 'http')) return $bitly;
		$urle = urlencode($url);
		$api = get_option('stmbitly');
		if (!$api) return $url;
		$stub = json_decode(file_get_contents("https://api-ssl.bitly.com/v3/user/link_save?access_token=$api&longUrl=$urle"));
		$bitly = $stub->data->link_save->link;
		if ($bitly && strpos(' '.$bitly, 'http')) {
			$id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_urls WHERE url=%s;", $url));
			if ($id) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_urls SET bitly=%s;", $bitly));
			else $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_urls (url, bitly) VALUES (%s, %s);", $url, $bitly));
		}
		return $bitly;
	}

	function BitLyClicks($url, $doupdate=0) {
		global $wpdb;
		if ($doupdate) {
			$api = get_option('stmbitly');
			$urle = urlencode($url);
			$clicks = file_get_contents("https://api-ssl.bitly.com/v3/link/clicks?access_token=$api&link=$urle&format=txt");
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_urls SET clickcount=%d WHERE bitly=%s;", $clicks, $url));
		}
		else $clicks = $wpdb->get_var($wpdb->prepare("SELECT clickcount FROM {$wpdb->prefix}stm_urls WHERE bitly=%s", $url));
		return $clicks;
	}

	function adminMenu() {
		add_menu_page('Social Time Master - Timeline', 'Social Time Master', 'manage_options', STM_PLUGIN_SLUG, array($this, 'Timeline'), STM_URL.'images/logo.png');
		add_submenu_page(STM_PLUGIN_SLUG, 'Social Time Master - Timeline', 'Timeline', 'manage_options', STM_PLUGIN_SLUG, array($this, 'Timeline'));
		add_submenu_page(STM_PLUGIN_SLUG, 'Social Time Master - Scheduled Posts', 'Scheduled Posts', 'manage_options', STM_PLUGIN_SLUG.'-scheduled', array($this, 'ScheduledPostings'));
		add_submenu_page(STM_PLUGIN_SLUG, 'Social Time Master - Post Log', 'Post Log', 'manage_options', STM_PLUGIN_SLUG.'-postlog', array($this, 'PostLogAll'));
		add_submenu_page(STM_PLUGIN_SLUG, 'Social Time Master - Accounts', 'Social Accounts', 'manage_options', STM_PLUGIN_SLUG.'-accounts', array($this, 'SocialAccounts'));
		add_submenu_page(STM_PLUGIN_SLUG, 'Social Time Master - Schedule Templates', 'Schedule Templates', 'manage_options', STM_PLUGIN_SLUG.'-templates', array($this, 'ScheduleTemplates'));
		add_submenu_page(STM_PLUGIN_SLUG, 'Social Time Master - Settings', 'Settings', 'manage_options', STM_PLUGIN_SLUG.'-settings', array($this, 'Settings'));
	}


	function stmjs() {
		wp_enqueue_script('ajax-script', STM_URL.'stm.js', array('jquery') );
		wp_localize_script('ajax-script', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-core');
		wp_enqueue_script('jquery-ui-dialog');
		wp_enqueue_script('jquery-ui-datepicker');
		wp_enqueue_script('jquery-ui-position');
		wp_register_script('smtimepicker', STM_URL.'jquery.ui.timepicker.js');
		wp_enqueue_script('smtimepicker');
		wp_enqueue_style('jquery-ui-smoothness', STM_URL.'jquery-ui.min.css');
		wp_register_style('socialtimemaster', STM_URL.'stm.css');
		wp_enqueue_style('socialtimemaster');
	}


	function stmajax() {
		if ($_POST['ajsub']=='main') $this->Ajax();
		if ($_POST['ajsub']=='page') $this->AjaxPage();
		if ($_POST['ajsub']=='submit') $this->AjaxSubmit();
	}


	function DefaultVariation($postid, $numvar) {
		global $wpdb;
		$title = get_the_title($postid);
		$content = $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->prefix}posts WHERE ID=%d", $postid));
		$content = strip_tags($content);
		if (!$content) $content = $title;
		$imgurl = get_the_post_thumbnail($postid, 'large');
		if (strpos($imgurl, 'src="')) $imgurl = $this->GetBetweenTags($imgurl, 'src="', '"');
		else $imgurl = '';
		$url = $this->PostVarURL($postid, $numvar);
		$title = addslashes($title);
		$content = $this->LimitLength($content, 300);
		$content = addslashes($content);
		$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_variations (postid, numvar, title, content, imgurl, url) VALUES (%d, %d, %s, %s, %s, %s);", $postid, $numvar, $title, $content, $imgurl, $url));
		return $wpdb->insert_id;
	}


	function AjaxSubmit() {
		global $moreops, $wpdb;
		$tblid = isset($_GET['subid'])?$_GET['subid']:'';
		$msg = '';
		$moreops = '';
		$dialbuts = '';
		switch($_GET['sub']) {
			case 'tlsettings':
				update_option('tlheight', $_POST['tlheight']);
				$noanim = isset($_POST['noanim'])?1:0;
				update_option('stmnoanim', $noanim);
				$moreops .= "tlheight = $_POST[tlheight]; globalnoanim = $noanim;";
				if ($_POST['tlheight']) $moreops .= "document.getElementById('timelinebox').style.height='$_POST[tlheight]px';";
				else $moreops .= "ResizeTLBox();";
				$moreops .= "jQuery('#dialog-main').dialog('close'); AjaxLoadedSP();";
				die($moreops);
			break;
			case 'edittimeline':
				$id = $_GET['id'];
				$err = '';
				if (!trim($_POST['title']) && !trim($_POST['content'])) $err = 'Please enter Title or/and Content!';
				elseif (!trim($_POST['url'])) $err = 'Please enter URL!';
				else {
					$variationid = $wpdb->get_var($wpdb->prepare("SELECT variationid FROM {$wpdb->prefix}stm_timeline WHERE id=%d", $id));
					$postid = $wpdb->get_var($wpdb->prepare("SELECT postid FROM {$wpdb->prefix}stm_variations WHERE id=%d", $variationid));
					if (!$_POST['content']) $_POST['content'] = $_POST['title'];
					if (!$_POST['title']) $_POST['title'] = $this->LimitLength($_POST['content'], 120);
					$_POST['title'] = addslashes(stripslashes($_POST['title']));
					$_POST['content'] = addslashes(stripslashes($_POST['content']));
					$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_variations SET title=%s, content=%s, imgurl=%s, url=%s WHERE id=%d;", $_POST['title'], $_POST['content'], $_POST['imgurl'], $_POST['url'], $variationid));
					$t = '';
					if ($postid) $t = "<a href='post.php?post=$postid&action=edit' target='_blank'>".get_the_title($postid).'</a>';
					if (!$_POST['title']) $_POST['title'] = $_POST['content'];
					if ($t) $t .= ' - ';
					$t .= $this->LimitLength($_POST['title'], 100);
					$this->AjaxContent($t);
					$moreops .= "document.getElementById('titlebox_$id').innerHTML=\"$t\"; jQuery('#dialog-main').dialog('close'); AjaxLoadedSP();";
					die($moreops);
				}
				$err = $this->Msg_Err($err);
				$this->AjaxContent($err);
				$moreops .= "document.getElementById('poperr').innerHTML=\"$err\"; document.getElementById('poperr').style.display='block'; setTimeout('HideErr()', 5000); AjaxLoadedSP();";
				die($moreops);
			break;
			case 'setpreftime':
				$prefstart = $_POST['prefstart'];
				$prefend = $_POST['prefend'];
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET prefstart=%s, prefend=%s WHERE id=%d;", $prefstart, $prefend, $_GET['accountid']));
				$content = '';
				$prefh = 0;
				$height = 1440;
				$pxpersec = 60;
				$zdif = $_GET['zoom']-5;
				if ($zdif > 0) {
					$height = 1440*pow(2, $zdif);
					$pxpersec = 60*pow(2, $zdif);
				}
				if ($zdif < 0) {
					$zdif = -$zdif;
					$height = 1440/pow(2, $zdif);
					$pxpersec = 60/pow(2, $zdif);
				}
				$stub = explode(':', $prefstart);
				$startsec = (3600*(int)$stub[0] + 60*(int)$stub[1])/3600;
				for ($i=1; $i<=$_GET['numdays']; $i++) {
					$preft = ($i-1)*$height + round($startsec*$pxpersec);
					if (!$prefh) {
						$stub = explode(':', $prefend);
						$secs = (3600*(int)$stub[0] + 60*(int)$stub[1])/3600;
						$prefh = round($secs*$pxpersec) - $preft;
						$prefh .= 'px';
					}
					$preft .= 'px';
					$content .= "<div class='lineprefered' id='pref_$i' onmouseover='ShowLinetime(event, 1);' onmouseout='ShowLinetime(event, 0);' onmousemove='LinetimePos(event);' ondblclick=\"AjaxPopSP('addtotimeline');\" style='top: $preft; height: $prefh;'></div>";
				}
				$this->AjaxContent($content);
				$moreops .= "document.getElementById('prefsbox').innerHTML=\"$content\"; InPreferredAll(); jQuery('#dialog-main').dialog('close'); AjaxLoadedSP();";
				die($moreops);
			break;
			case 'distribute':
				if ($_POST['distrtype'] == 1) {
					if (!isset($_POST['prefonly'])) $_POST['prefonly'] = 0;
					if (!isset($_POST['perday'])) $_POST['perday'] = 0;
					$moreops .= "jQuery('#dialog-main').dialog('close'); AjaxLoadedSP(); DistrRandom($_POST[dtype], $_POST[deviation], $_POST[intfrom], $_POST[intto], $_POST[prefonly]);";
				}
				if ($_POST['distrtype'] == 2) {
					if (!isset($_POST['prefonly2'])) $_POST['prefonly2'] = 0;
					if (!isset($_POST['perday'])) $_POST['perday'] = 0;
					$moreops .= "jQuery('#dialog-main').dialog('close'); AjaxLoadedSP(); DistrEvenly($_POST[prefonly2], $_POST[perday]);";
				}
				die($moreops);
			break;
			case 'addaccount':
				$err = '';
				if (!$_POST['atype']) $err = 'Please select Account Type!';
				else {
					$arr = array('facebook'=>'fb', 'twitter'=>'tw', 'linkedin'=>'li', 'tumblr'=>'tu');
					$abbr = $arr[$_POST['atype']];
					if (!$_POST[$abbr.'username'] || !$_POST[$abbr.'auth1'] || !$_POST[$abbr.'auth2']) $err = 'Please fill in all the fields!';
					else {
						$username = $_POST[$abbr.'username'];
						$auth1 = $_POST[$abbr.'auth1'];
						$auth2 = $_POST[$abbr.'auth2'];
						if ($_POST['id']) {
							$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET username=%s, auth1=%s, auth2=%s, auth3='', auth4='', info='', prefstart=%s, prefend=%s WHERE id=%d;", $username, $auth1, $auth2, $_POST['prefstart'], $_POST['prefend'], $_POST['id']));
							$id = $_POST['id'];
						}
						else {
							$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_accounts (atype, username, auth1, auth2, prefstart, prefend) VALUES (%s, %s, %s, %s, %s, %s);", $_POST['atype'], $username, $auth1, $auth2, $_POST['prefstart'], $_POST['prefend']));
							$id = $wpdb->insert_id;
						}
						$info = '';
						if (($abbr=='fb') && (isset($_POST['imppages']))) $info .= ',imppages';
						if (($abbr=='fb') && (isset($_POST['impgroups']))) $info .= ',impgroups';
						if (($abbr=='fb') && (isset($_POST['admingroups']))) $info .= ',admingroups';
						if ($info) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET info=%s WHERE id=%d;", $info, $id));
					}
				}
				if ($err) {
					$err = $this->Msg_Err($err);
					$this->AjaxContent($err);
					$moreops .= "jQuery('#errbox').html(\"$err\"); AjaxLoadedSP();";
					die($moreops);
				}
				$content = $this->ListAccounts();
				$this->AjaxContent($content);
				$moreops .= "jQuery('#acclistbox').html(\"$content\");";
				$moreops .= "jQuery('#dialog-main').dialog('close'); AjaxLoadedSP();";
				die($moreops);
			break;
		}
		die("AjaxLoadedSP();");
	}


	function AjaxPage() {
		global $moreops, $dialbuttons, $wpdb;
		$tm = time();
		$html = ''; $moreops = ''; $dialbuttons = '';
		$g = '';
		foreach($_GET as $k=>$v) if (($k != 'pg') && ($k != 's')) $g .= "&$k=$v";
		switch($_GET['pg']) {
			case 'addtotimeline':
				$_GET['w'] = 500; $_GET['h'] = 400;
				$title = 'Adding Post to the Timeline...';
				$content = "
					You can add posts directly on the timeline only in the Pro version of the plugin.
					To unlock this and lot more great features and to get a premium support please get the Pro version of the plugin here:<br /><br />
					<center><a href='http://wiziva.com/stm/pro.php' target='_blank'>Social Time Master Pro<br />[discounted offer]</a></center>
				";
				$dialbuttons = "'Close': function() { jQuery(this).dialog('close'); }";
			break;
			case 'savetimelineall':
				$_GET['w'] = 500; $_GET['h'] = 400;
				$title = 'Saving Timeline Changes...';
				$content = "
					With the free version of the plugin you can use the Timeline only to preview the postings and You can not save the changes you make here.<br />
					To unlock the saving functionality and get lot more great features please get the Pro version of the plugin here:<br /><br />
					<center><a href='http://wiziva.com/stm/pro.php' target='_blank'>Social Time Master Pro<br />[discounted offer]</a></center>
				";
				$dialbuttons = "'Close': function() { jQuery(this).dialog('close'); }";
			break;
			case 'tlsettings':
				$_GET['w'] = 600; $_GET['h'] = 400;
				$title = 'Timeline Settings';
				$tlheight = get_option('tlheight');
				$noanim = get_option('stmnoanim');
				$ch = $noanim?' checked':'';
				if (!$tlheight) $tlheight = 0;
				$content = "
					The timeline container should be resized by the script automatically to fill the entire free space on the screen.<br />
					If there are any problems with the automatic resizing then here you have the option to set it's height manually.<br />
					If it is working fine for you, leave the setting to 0, which means it will be resized automatically.<br /><br />
					<form mehtod='post' id='frm$_GET[pg]'>
						Timeline Height: <input type='text' name='tlheight' id='tlheight' style='width: 50px;' value='$tlheight' /> px<br />
						<small>Set to 0 for automatic resizing</small><br /><br />
						<input type='checkbox' name='noanim' id='noanim' value='1'$ch /> <label for='noanim'>Do not animate the Zoom</label>
					</form>					
				";
			break;
			case 'edittimeline':
				$_GET['w'] = 760; $_GET['h'] = 480;
				$title = 'Edit Posting';
				$id = $_GET['id'];
				$info = $wpdb->get_results($wpdb->prepare("SELECT accountid, posttime, variationid FROM {$wpdb->prefix}stm_timeline WHERE id=%d;", $id));
				$info = (array)$info[0];
				$ainfo = $wpdb->get_results($wpdb->prepare("SELECT atype, username FROM {$wpdb->prefix}stm_accounts WHERE id=%d;", $info['accountid']));
				$ainfo = (array)$ainfo[0];
				$account = ucfirst($ainfo['atype']).' - '.$ainfo['username'];
				$vinfo = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_variations WHERE id=%d;", $info['variationid']));
				$vinfo = (array)$vinfo[0];
				foreach ($vinfo as $k=>$v) $vinfo[$k] = stripslashes($v);
				$info['posttime'] = date(get_option('date_format'), $info['posttime']).', '.date(get_option('time_format'), $info['posttime']);
				$img = $vinfo['imgurl']?"<img src='$vinfo[imgurl]' style='max-width: 140px; max-height: 140px;' />":'';
				$len = strlen($vinfo['content']);
				$content = "
					<form mehtod='post' id='frm$_GET[pg]'>
					<table class='frm'>
						<tr><td valign='top'>Post Time:</td><td>$info[posttime]</td></tr>
						<tr><td valign='top'>Account:</td><td>$account</td></tr>
						<tr>
							<td>Title:</td><td><input type='text' name='title' id='title' style='width: 440px;' value=\"$vinfo[title]\" /></td>
							<td rowspan='4'><div id='previewbox' style='width: 140px; height: 140px; overflow: hidden; padding: 5px;'>$img</div></td>
						<tr>
						<tr><td valign='top'>Content:<br /><br /><span class='tacount' id='count'>$len</span></td><td><textarea name='content' id='content' style='width: 440px; height: 60px;' onkeyup=\"jQuery('#count').html(jQuery(this).val().length);\">$vinfo[content]</textarea><br /><small>put [url] where you'd like the URL to appear and <a href='admin.php?page=stm-settings' target='_blank'>integrate bit.ly</a> to be able to track click stats</small></td></tr>
						<tr><td nowrap>Image URL:</td><td><input type='text' name='imgurl' id='imgurl' style='width: 440px;' value='$vinfo[imgurl]' onkeyup=\"clearTimeout(prevtm);prevtm=setTimeout('PreviewImg()', 1000);\" /></td><tr>
						<tr><td>URL:</td><td><input type='text' name='url' id='url' style='width: 440px;' value='$vinfo[url]' /></td><tr>
					</table>
					</form>
					<div id='poperr'></div>
				";
			break;
			case 'setpreftime':
				$_GET['w'] = 320; $_GET['h'] = 220;
				$title = 'Set Prefered Posting Time';
				$ainfo = $wpdb->get_results($wpdb->prepare("SELECT atype, username, prefstart, prefend FROM {$wpdb->prefix}stm_accounts WHERE id=%d;", $_GET['accountid']));
				$ainfo = (array)$ainfo[0];
				$ainfo['atype'] = ucfirst($ainfo['atype']);
				$content = "
					<form mehtod='post' id='frm$_GET[pg]'>
						Account: <strong>$ainfo[atype] - $ainfo[username]</strong><br /><br />
						From: <input type='text' name='prefstart' id='prefstart' style='width: 70px;' value='$ainfo[prefstart]' />
						To: <input type='text' name='prefend' id='prefend' style='width: 70px;' value='$ainfo[prefend]' />
					</form>
				";
				$moreops .= "jQuery('#prefstart').blur();jQuery('#prefend').blur();jQuery('#prefstart').timepicker(); jQuery('#prefend').timepicker();";
			break;
			case 'distribute':
				$_GET['w'] = 600; $_GET['h'] = 380;
				$title = 'Distribute Evenly';
				if ($_GET['numdays'] > 1) $perday = "<input type='checkbox' name='perday' id='perday' value='1' /> <label for='perday'>Keep the scheduled date</label>";
				else $perday = '';
				$content = "
					<form mehtod='post' id='frm$_GET[pg]'>
						<input type='radio' name='distrtype' id='distrtype1' value='1' checked onclick=\"document.getElementById('distr1').style.display='block';document.getElementById('distr2').style.display='none';\" /> <label for='distrtype1'>Distribute Randomly</label>&nbsp;&nbsp;
						<input type='radio' name='distrtype' id='distrtype2' value='2' onclick=\"document.getElementById('distr1').style.display='none';document.getElementById('distr2').style.display='block';\" /> <label for='distrtype2'>Distribute Evenly</label><br /><br />
						<div id='distr1' style='padding-left: 20px;'>
							<input type='radio' name='dtype' id='dtype1' value='1' checked onclick=\"document.getElementById('opt1').style.display='block';document.getElementById('opt2').style.display='none';\" /> <label for='dtype1'>Automatic (spread across the entire timeline)</label>
							<div id='opt1' style='display: block; padding-left: 40px;'>
								Deviation (10-50): <input type='text' name='deviation' id='deviation' style='width: 50px;' value='30' /> %
							</div><br />
							<input type='radio' name='dtype' id='dtype2' value='2' onclick=\"document.getElementById('opt1').style.display='none';document.getElementById('opt2').style.display='block';\" /> <label for='dtype2'>Manually Set Interval</label>
							<div id='opt2' style='display: none; padding-left: 40px;'>
								Interval from: <input type='text' name='intfrom' id='intfrom' style='width: 50px;' value='10' /> to <input type='text' name='intto' id='intto' style='width: 50px;' value='20' /> min<br />
								<small>Please Note: Depending on the number of posts and the interval you set this option may move the posts to the beginning of the timeline or spread it beyond the visible timeline.</small>
							</div>
							<br /><br />
							<input type='checkbox' name='prefonly' id='prefonly' value='1' /> <label for='prefonly'>Post only in the preferred time</label><br />
						</div>
						<div id='distr2' style='padding-left: 20px; display: none;'>
							<input type='checkbox' name='prefonly2' id='prefonly2' value='1' /> <label for='prefonly2'>Post only in the preferred time</label><br />
							$perday
						</div>
					</form>
				";
			break;
			case 'postlogdet':
				$_GET['w'] = 600; $_GET['h'] = 300;
				$title = 'Post Details';
				$content = $wpdb->get_var($wpdb->prepare("SELECT content FROM {$wpdb->prefix}stm_postlog WHERE id=%d", $_GET['id']));
				if (!$content) $content = 'n/a/';
			break;
			case 'sharenow':
				$_GET['w'] = 600; $_GET['h'] = 300;
				$title = 'Share Post';
				$dialbuttons = "'Close': function() { jQuery(this).dialog('close'); }";
				$data = $wpdb->get_results($wpdb->prepare("SELECT t.*, v.*, t.id as tlid FROM {$wpdb->prefix}stm_timeline as t LEFT JOIN {$wpdb->prefix}stm_variations as v ON t.variationid=v.id WHERE t.id=%d;", $_GET['id']));
				if (!count($data)) $content = "<span style='color:red'>There's nothing to post!</span><br />";
				else {
					$info = (array)$data[0];
					$purl = $this->DoPost($info, 0);
					if (substr($purl, 0, 4)=='err-') {
						$purl = substr($purl, 4);
						$content = "<span style='color:red'>Posting failed</span> with error:<br />$purl";
					}
					else $content = "The posting completed successfully.<br />You can see the social update here: <a href='$purl' target='_blank'>$purl</a><br />";
				}
			break;
			case 'addaccount':
				$_GET['w'] = 500; $_GET['h'] = 560;
				$dispfacebook = 'none';
				$disptwitter = 'none';
				$displinkedin = 'none';
				$disptumblr = 'none';
				$disp = 'none';
				if (isset($_GET['id'])) {
					$id = $_GET['id'];
					$title = 'Edit Social Account';
					$data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_accounts WHERE id=%d", $_GET['id']));
					if (count($data)) $info = (array)$data[0];
					$fld = 'disp'.$info['atype'];
					$$fld = 'block';
					$sel = "<input type='hidden' name='atype' id='atype' value='$info[atype]' />";
					$disp = 'block';
				}
				else {
					$id = 0;
					$sel = "Account Type: ".$this->SelSocialSites('atype')."<br />";
					$title = 'Add New Social Account';
					$info = array('auth1'=>'', 'auth2'=>'', 'username'=>'', 'prefstart'=>'18:00', 'prefend'=>'21:00');
				}
				$content = "
					<form mehtod='post' id='frm$_GET[pg]'>
						$sel
						<div id='subboxfacebook' style='display: $dispfacebook;'>
							<input type='hidden' name='id' id='id' value='$id' />
							<table class='frm'>
								<tr><td colspan='2'><img src='".STM_URL."images/facebook.png' align='absmiddle' style='margin-right: 15px;' /> <a href='http://wiziva.com/user/pluginhelp.html?id=".STM_WIZIVA_ID."&sub=17&wpu=".STM_URL_Encoded."' target='_blank'>Read here how to create a Facebook App</a></td></tr>
								<tr><td>Account Name:</td><td><input type='text' name='fbusername' id='fbusername' style='width: 160px;' value='$info[username]' onkeyup='ClearErr();' /></td></tr>
								<tr><td>App ID:</td><td><input type='text' name='fbauth1' id='fbauth1' style='width: 300px;' value='$info[auth1]' onkeyup='ClearErr();' /></td></tr>
								<tr><td>App Secret:</td><td><input type='text' name='fbauth2' id='fbauth2' style='width: 300px;' value='$info[auth2]' onkeyup='ClearErr();' /></td></tr>
								<tr><td colspan='2'><input type='checkbox' name='imppages' id='imppages' value='0' onclick=\"AjaxCB(this, 1)\" /> <label for='imppages'>Import Facebook Pages upon Authentication</label></td></tr>
								<tr><td colspan='2'>
									<input type='checkbox' name='impgroups' id='impgroups' value='0' onclick=\"AjaxCB(this, 1)\" /> <label for='impgroups'>Import Groups upon Authentication</label><br />
									&nbsp;&nbsp;&nbsp;<input type='checkbox' name='admingroups' id='admingroups' value='1' onclick=\"AjaxCB(this, 1)\" checked /> <label for='admingroups'>Only the Groups where I'm an Admin</label><br />
									<small><span style='color: red;'>Important Note:</span> This free version of the plugin will import only the first 3 pages and the first 3 groups from your list.<br />You can import all the pages and groups with the Pro version of the plugin <a href='http://wiziva.com/stm/pro.php' target='_blank'>available here</a>.</small><br />
								</td></tr>
							</table>
						</div>
						<div id='subboxtwitter' style='display: $disptwitter;'>
							<table class='frm'>
								<tr><td colspan='2'><img src='".STM_URL."images/twitter.png' align='absmiddle' style='margin-right: 15px;' /> <a href='http://wiziva.com/user/pluginhelp.html?id=".STM_WIZIVA_ID."&sub=18&wpu=".STM_URL_Encoded."' target='_blank'>Read here how to create a Twitter App</a></td></tr>
								<tr><td>Account Name:</td><td><input type='text' name='twusername' id='twusername' style='width: 160px;' value='$info[username]' onkeyup='ClearErr();' /></td></tr>
								<tr><td>Consumer Key:</td><td><input type='text' name='twauth1' id='twauth1' style='width: 300px;' value='$info[auth1]' onkeyup='ClearErr();' /></td></tr>
								<tr><td>Consumer Secret:</td><td><input type='text' name='twauth2' id='twauth2' style='width: 300px;' value='$info[auth2]' onkeyup='ClearErr();' /></td></tr>
							</table>
						</div>
						<div id='subboxlinkedin' style='display: $displinkedin;'>
							<table class='frm'>
								<tr><td colspan='2'><img src='".STM_URL."images/linkedin.png' align='absmiddle' style='margin-right: 15px;' /> <a href='http://wiziva.com/user/pluginhelp.html?id=".STM_WIZIVA_ID."&sub=19&wpu=".STM_URL_Encoded."' target='_blank'>Read here how to create a LinkedIn App</a></td></tr>
								<tr><td>Account Name:</td><td><input type='text' name='liusername' id='liusername' style='width: 160px;' value='$info[username]' onkeyup='ClearErr();' /></td></tr>
								<tr><td>API Key:</td><td><input type='text' name='liauth1' id='liauth1' style='width: 300px;' value='$info[auth1]' onkeyup='ClearErr();' /></td></tr>
								<tr><td>API Secret:</td><td><input type='text' name='liauth2' id='liauth2' style='width: 300px;' value='$info[auth2]' onkeyup='ClearErr();' /></td></tr>
							</table>
						</div>
						<div id='subboxtumblr' style='display: $disptumblr;'>
							<table class='frm'>
								<tr><td colspan='2'><img src='".STM_URL."images/tumblr.png' align='absmiddle' style='margin-right: 15px;' /> <a href='http://wiziva.com/user/pluginhelp.html?id=".STM_WIZIVA_ID."&sub=20&wpu=".STM_URL_Encoded."' target='_blank'>Read here how to create a Tumblr App</a></td></tr>
								<tr><td>Account Name:</td><td><input type='text' name='tuusername' id='tuusername' style='width: 160px;' value='$info[username]' onkeyup='ClearErr();' /></td></tr>
								<tr><td>Consumer Key:</td><td><input type='text' name='tuauth1' id='tuauth1' style='width: 300px;' value='$info[auth1]' onkeyup='ClearErr();' /></td></tr>
								<tr><td>Consumer Secret:</td><td><input type='text' name='tuauth2' id='tuauth2' style='width: 300px;' value='$info[auth2]' onkeyup='ClearErr();' /></td></tr>
							</table>
						</div>
						<div style='display: $disp; padding-left: 5px;' id='prefbox'>
							Preferred posting time: <input type='text' name='prefstart' id='prefstart' style='width: 70px;' value='$info[prefstart]' /> to <input type='text' name='prefend' id='prefend' style='width: 70px;' value='$info[prefend]' />
						</div>
					</form>
					<div id='errbox'></div>
				";
				$moreops .= "jQuery('#prefstart').timepicker(); jQuery('#prefend').timepicker();";
			break;
			case 'pophelp':
				if ($_GET['sub'] == 'about') {
					$_GET['w'] = 680; $_GET['h'] = 500;
					$title = 'Overview';
					$content = "
						<strong>Social Time Master</strong> is a social autoposter that makes it easy for you to schedule social posts, based on templates.<br />
						You can create multiple sharing details for a single post and schedule unlimited shares accross multiple social accounts.<br />
						Here're some of the features:<br />
						<ul class='help'>
							<li>- <strong>Unlimited social accounts</strong> and multiple posting options - Twitter status, Facebook wall update, Facebook page update, Facebook group update, LinkedIn update, Tumblr post</li>
							<li>- One click import of <strong>all your Facebook Pages and Groups</strong></li>
							<li>- <strong>Multiple sharing details</strong> (title, content, image, url) for every blog post</li>
							<li>- <strong>Twitter Cards and Open Graph meta tags integrated</strong> (no need to use aditional plugins for that purpose)</li>
							<li>- <strong>Fast and easy scheduling</strong>, using templates</li>
							<li>- <strong>Flexible scheduling</strong> - you have the option to manually modify the posting time for every single post</li>
							<li>- <strong>Full control over the scheduled posts</strong> - cancel, delete, manually post, etc.</li>
							<li>- <strong>Posting history and click tracking</strong> (if you configure bit.ly in the settings)</li>
							<li>- <strong>Live links to the social posts</strong>, so you can preview, edit and delete them</li>
						</ul><br /><br />
						<h2>How To Start (First Steps with Social Time Master)</h2>
						We recommend the following flow when you start using the plugin for the first time:<br />
						<ul class='help'>
							<li>1. Configure the Settings and send a request to Twitter to approve your domain for Twitter Cards</li>
							<li>2. Add the social accounts you would like to post to</li>
							<li>3. Authenticate the social accounts</li>
							<li>4. Create a scheduling template</li>
							<li>5. When you add new (or edit existing) blog post use the created templates to schedule social posts, or add individual social posts manually</li>
							<li>6. Add more external posts, using the bulk importers (RSS, Bulk URLs, etc.)</li>
							<li>7. Review and manage the scheduled posts on the timeline</li>
							<li>8. And after the first few social posts are completed - check the post log for posting details and click stats</li>
						</ul>
						For every of the above steps please refer to the corresponding help page, available through the help tab that you see on the right side of the page.<br />
					";
				}
				if ($_GET['sub'] == 'timeline') {
					$_GET['w'] = 680; $_GET['h'] = 500;
					$title = 'The Timeline';
					$content = "<a href='http://wiziva.com/user/pluginhelp.html?id=264&sub=26' target='_blank'>Click here</a> to see our online help guide and a demo video about the Timeline.";
				}
				if ($_GET['sub'] == 'accounts') {
					$_GET['w'] = 680; $_GET['h'] = 500;
					$title = 'Managing Social Accounts';
					$content = "
						<p><strong>Social Account Inputs</strong></p>
						<p>Currently, Social Time Master plugin can access the following 4 social accounts - <strong>Twitter, Facebook, LinkedIn, Tumblr</strong>.</p>
						<p>When adding a Facebook account you also have the option to import all the FB groups and pages that you manage.</p>
						<p>To add a new social account go to &quot;Social Accounts&quot; page under the main &quot;Social Time Master&quot; admin menu link and click on &quot;Add Account&quot; button. This will open a popup window where you can choose the type of account you would like to add.<br />
						For every account type you need to add some app details and there are help pages explaining how you create these apps (see the links at the bottom).<br />
						But you have 2 fields common for all the account types:<br /><br />
						-&nbsp;<strong>Account Name</strong> - you can put whatever name you like to recognize this account within the plugin. Generally you can use your (FB/Twitter/LinkedIn/Tumblr) username here.<br /><br />
						-&nbsp;<strong>Preferred posting time</strong> - you can set a preferred time for posting. This feature is useful if you prefer to use a particular social media site in a certain timeframe each day and you will have the option to schedule your automated posts to go out in that timeframe only, (which will seem very natural and will suggest to your followers that you&#39;re not using automated posts).</p>
						<p>Click on the links below to see how to create and configure the required apps and what the settings are specific to each account type:</p>
						<p><a href='http://wiziva.com/user/pluginhelp.html?id=264&amp;sub=17' target='_blank'><img align='absmiddle' height='16' src='http://wiziva.com/images/facebook.png' width='16' /> Facebook</a></p>
						<p><a href='http://wiziva.com/user/pluginhelp.html?id=264&amp;sub=18' target='_blank'><img align='absmiddle' height='16' src='http://wiziva.com/images/twitter.png' width='16' /> Twitter</a></p>
						<p><a href='http://wiziva.com/user/pluginhelp.html?id=264&amp;sub=19' target='_blank'><img align='absmiddle' height='16' src='http://wiziva.com/images/linkedin.png' width='16' /> LinkedIn</a></p>
						<p><a href='http://wiziva.com/user/pluginhelp.html?id=264&amp;sub=20' target='_blank'><img align='absmiddle' height='16' src='http://wiziva.com/images/tumblr.png' width='16' /> Tumblr</a></p>
					";
				}
				if ($_GET['sub'] == 'templates') {
					$_GET['w'] = 680; $_GET['h'] = 500;
					$title = 'Managing Schedule Templates';
					$content = "
						<p>You can use our unique and powerful Social Time Master templates to make scheduling your social sharing campaigns quick and easy.<br />
						To create a new template click on &quot;Social Time Master&quot;, then &quot;Schedule Templates&quot; and on the page click on the &quot;Add Template&quot; button. You will see the following page:</p>
						<p><img src='http://wiziva.com/helpimg/264/stm1.jpg' style='width: 100%;' /></p><br />
						<p>Before you start editing the schedule details you should enter the number of variations and the number of schedules you would like to have for each blog post. Give the template a title, which you will use to identify it within Social Time Master. Click &quot;Update Template&quot; and then you&#39;re ready to edit the Scheduling Template.</p>
						<p>But first let me explain:</p>
						<h3>What are &quot;Variations&quot;?</h3>
						<p>You have the option to create multiple sharing variations for every blog post. The variation includes a title, content and image. Every variation will have it&#39;s own unique URL. There is one variation called &quot;Original&quot; and it will point to the regular blog post URL. Whith these variations it will look like you&#39;re sharing different blog posts, but actually they all point to one and the same blog post. This gives you the freedom to make multiple social posts for the same blog post, without it looking like spam. On the other hand you can test multiple titles, description, image and sharing time to see which will get the most clicks. You will then have higher odds of hitting a winner which will grab the most attention from your followers. When you edit the template you just need to enter the number of variations you would like to have for each blog post. You can then edit these variations on the &quot;Edit Post&quot; page.</p>
						<p><a href='http://wiziva.com/user/pluginhelp.html?id=264&sub=31' target='_blank'>Click here</a> to see more details and examples about the Variations.</p>
						<p>Now here&#39;s the info about the fields for each scheduling task:<br />
						In the &quot;Post In&quot; field you will define when the sharing is to be made. You have the option to select intervals in minutes, hours or days. Then you have the option to repeat the posting multiple times and to set the interval between each re-post. At the end you select which variation you would like to have used and also choose the social account you would like to post to. If you elect tthat he post be repeated, then you can choose &quot;[ROTATE]&quot; or &quot;[RANDOMIZE]&quot; in the &quot;Variations&quot; field and then it will use a different variation in each post - it will cycle through them if you select &quot;ROTATE&quot; or will pick randomly if you select &quot;RANDOMIZE&quot;.</p><p>	Depending on the number of shcedules you choose and the repeats for each of them, you will get a total number of shares that this template will make for each post. When you go to the templates list you will see this number and also the number of social accounts and the number of variations included within each template:</p>
						<p><img src='http://wiziva.com/helpimg/264/stm2.jpg' style='width: 100%;' /></p><br />
						<p>Now you&#39;re ready to use the template when you schedule the blog post sharing.</p>
						<p><a href='http://wiziva.com/user/pluginhelp.html?id=264&sub=30' target='_blank'>Click here</a> to see an example Template and detailed explanations.</p>
					";
				}
				if ($_GET['sub'] == 'settings') {
					$_GET['w'] = 680; $_GET['h'] = 500;
					$title = 'Edit Settings';
					$content = "
						<p>
							<strong>Twitter Cards</strong> are used to format the updates you share on Twitter.<br />
							Read more about the different twitter cards formats <a href='https://dev.twitter.com/cards/types' target='_blank'>here</a><br />
							Please pick the format wisely, because once you have an approval from Twitter it is not easy to switch to another format.<br />
							<strong>IMPORTANT:</strong> Twitter shares will not be rendered according to the Twitter Cards, before your domain is approved and whitelisted.<br />
							To validate your twitter cards and request an approval please submit any of your post URLs <a href='https://cards-dev.twitter.com/validator' target='_blank'>here</a>.<br />
							<br />
							You can test the Facebook Open Graph meta tags <a href='https://developers.facebook.com/tools/debug/og/object/' target='_blank'>here</a>.<br />
							Just paste-in any of your blog post URLs to the &quot;Input URL&quot; field and click &quot;Fetch new scrape information&quot;.<br />
							<br />
							<strong>&quot;Twitter User&quot;</strong> and <strong>&quot;Twitter Site&quot;</strong> wil be used in the twitter cards. For the first field you should enter your twitter id, for the second - the twitter id of your company or again your id if you don&#39;t have a separate company twitter account.<br />
							&nbsp;</p>
						<h3>
							Integrating Bit.ly</h3>
						<p>
							To be able to track the clicks we recommend using Bit.ly as a URL shortening service.<br />
							To integrate Bit.ly with Social Time Master all you need to do is get an <strong>access token</strong> from <a href='https://bitly.com/a/oauth_apps' target='_blank'>this page</a> and then save it in the settings.<br />
							<br />
							<br />
							The plugin will add the required <strong>Twitter Cards and Open Graph meta tags</strong> to your blog pages, but if you&#39;re using other plugins for that purpose then you have the option to disable them in the settings. Please note that Social Time Master will generate different meta tags for every sharing variation you create for your posts, which will not be possible if you&#39;re using other plugins.<br />
							<br />
							And with the last checkbox in the settings you can hide the help tab for the plugin that you see on the right side of the plugin pages.<br />
							Generally you can hide the help once you become familiar with the plugin and feel comfortable using it without any problems and doubts.<br />
							&nbsp;</p>
					";
				}
				if ($_GET['sub'] == 'schedule') {
					$_GET['w'] = 680; $_GET['h'] = 500;
					$title = 'Schedule Social Postings';
					$content = "
						<p>When you&#39;re editing the posts in WordPress you will see this &quot;Social Time Master&quot; block somewhere below the content editor:</p>
						<p><img src='http://wiziva.com/helpimg/264/stm4.jpg' style='width: 100%;'/></p><br />
						<p>	If you would like to schedule the social sharing for the post, then mark the &quot;Edit Scheduling&quot; checkbox and you will see the tools of the &quot;Social Time Master&quot;. You have the option to &quot;Add Scheduling&quot; manually one by one, but it's easier to load one of the templates you have created. When you load a template it will show all the scheduled tasks within this template and if you like, you can still edit them at the blog post level, but generally you can leave it as it is. More importantly, at this point, is to add the details for every Variation of the post. Click on the &quot;Variations&quot; tab and edit the content as you need. If you leave any of the fields of any variation empty then at the time of posting the plugin will take the main blog post details (the title, the first 120 characters from the excerpt or the content, the post featured image).</p>
						<p>Note that when you edit the scheduling here you have the option to select when the process of sharing should start. You can let it start at the time of publishing the blog post, but you can also pick a date and time in the future manually. Maybe it is a good idea to pick a time a few hours after your current time to let you finish the post editing and preview it on the site, before it starts sharing.</p>
						<p>Here you can also check the history (the &quot;Post Log&quot;) of the sharing if there will be any for this blog post:</p>
						<p><img src='http://wiziva.com/helpimg/264/stm3.jpg' style='width: 100%;' /></p><br />
						<p>&nbsp;</p>
						<p>See also how you can <a href='http://wiziva.com/user/pluginhelp.html?id=264&amp;sub=25' target='_blank'>schedule external posts</a>&nbsp;and <a href='http://wiziva.com/user/pluginhelp.html?id=264&amp;sub=28' target='_blank'>bulk schedule blog posts</a>.</p>
						<p>	&nbsp;</p>
						<p><a href='http://wiziva.com/user/pluginhelp.html?id=264&sub=31' target='_blank'>Click here</a> to see more details and examples about the Variations.</p>
						<p><a href='http://wiziva.com/user/pluginhelp.html?id=264&sub=30' target='_blank'>Click here</a> to see an example Template and detailed explanations.</p>
					";
				}
				$dialbuttons = "'Close': function() { jQuery(this).dialog('close'); }";
				$content .= "<br /><br />
					You are using the free version of Social Time Master!<br />
					To unlock lot more great features and get premium support, please get the Pro version of the plugin here:<br /><br />
					<center><a href='http://wiziva.com/stm/pro.php' target='_blank'>Social Time Master Pro<br />[discounted offer]</a></center><br />
				";
			break;
		}
		$this->AjaxContent($content);
		if (!isset($_GET['fld'])) $_GET['fld'] = '';
		$w = isset($_GET['w'])?$_GET['w']:400;
		$h = isset($_GET['h'])?$_GET['h']:300;
		if (!$dialbuttons) $dialbuttons = "'Submit': function() { AjaxSubmitSP('frm$_GET[pg]', '$_GET[pg]&subid=$tm$g'); }, 'Cancel': function() { jQuery(this).dialog('close'); }";
		$html .= "
			document.getElementById('dialog-main').innerHTML=\"$content\";
			jQuery('#dialog-main').dialog({ height: $h, width: $w, title: '$title', modal: true, buttons: { $dialbuttons } });
			$moreops
			document.getElementById('dialog-main').scrollTop = 0;
			AjaxLoadedSP();
		";
		$html .= "AjaxLoadedSP();";
		die($html);
	}




	function Ajax() {
		global $moreops, $wpdb;
		$moreops .= "if (document.getElementById('loading')) document.getElementById('loading').style.visibility = 'hidden';";
		$tm = time();
		$_GET['ajaxop'] = 1;
		switch ($_GET['a']) {
			case 'markpostforop':
				$marked = get_option('stmpostmarked');
				if (!$marked) $marked = ';';
				if ($_GET['stat']=='false') $marked = str_replace(";$_GET[id];", ';', $marked);
				else $marked .= $_GET['id'].';';
				update_option('stmpostmarked', $marked);
			break;
			case 'storezoom':
				update_option('stmzoomlevel', $_GET['z']);
			break;
			case 'postvariations':
				$numvars = $wpdb->get_var($wpdb->prepare("SELECT MAX(numvar) FROM {$wpdb->prefix}stm_variations WHERE (postid=%d) AND (numvar<200)", $_GET['id']));
				$content = $this->SelVariation('varnum', 0, $numvars, " onchange=\"if (this.value==-1) document.getElementById('opt2').style.display='block'; else document.getElementById('opt2').style.display='none';\"", "<option value='-1'>[add new]</option>");
				$this->AjaxContent($content);
				$moreops .= "document.getElementById('opt2').style.display='none'; document.getElementById('varsbox').innerHTML = \"$content\";";
				die($moreops);
			break;
			case 'deltimeline':
				$tlid = $_GET['id'];
				$scheduleid = $wpdb->get_var($wpdb->prepare("SELECT scheduleid FROM {$wpdb->prefix}stm_timeline WHERE id=%d;", $tlid));
				$variationid = $wpdb->get_var($wpdb->prepare("SELECT variationid FROM {$wpdb->prefix}stm_timeline WHERE id=%d;", $tlid));
				$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_timeline WHERE id=%d;", $tlid));
				$hasrec = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_timeline WHERE scheduleid=%d;", $scheduleid));
				if (!$hasrec) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_schedule WHERE id=%d;", $scheduleid));
				if ($variationid) {
					$hasrec = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_timeline WHERE variationid=%d;", $variationid));
					$hasrec2 = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_schedule WHERE variationid=%d;", $variationid));
					if (!$hasrec && !$hasrec2) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_variations WHERE id=%d;", $variationid));
				}
				$moreops .= "RemoveDiv('timel_$tlid');TLNumPosts();";
				die($moreops);
			break;
			case 'refreshclicks':
				$arr = array();
				$data = $wpdb->get_results($wpdb->prepare("SELECT p.id, p.bitly FROM {$wpdb->prefix}stm_postlog as p LEFT JOIN {$wpdb->prefix}stm_urls as u ON u.bitly=p.bitly WHERE p.id=%d;", $_GET['plid']));
				if (!count($data)) {
					$moreops .= "document.getElementById('clicks_$_GET[plid]').innerHTML='-'; RefreshNext();";
					die($moreops);
				}
				$info = (array)$data[0];
				if (isset($arr[$info['bitly']])) $clickshares = $arr[$info['bitly']];
				else {
					$clickcount = $this->BitLyClicks($info['bitly'], 1);
					$shares = $wpdb->get_var($wpdb->prepare("SELECT numshares FROM {$wpdb->prefix}stm_urls WHERE bitly=%s", $info['bitly']));
					if (!$shares) $shares = 0;
					if ($clickcount || $shares) $clickshares = $clickcount.'/'.$shares;
					else $clickshares = '-';
					$arr[$info['bitly']] = $clickshares;
				}
				$moreops .= "document.getElementById('clicks_$_GET[plid]').innerHTML='$clickshares'; RefreshNext();";
				die($moreops);
			break;
			case 'addpostvar':
				$i = $_GET['numvars'];
				$content = "
					<div id='varbox_$i'>
						<h4>Variation <span id='varnum_$i'>$i</span> <img src='".STM_URL."images/delete.png' align='absmiddle' class='icon' onclick=\"RemoveVariation(this.parentElement.parentElement);\" /></h4>
						<table class='frm'>
							<tr>
								<td>Title:</td><td><input type='text' name='title_$i' id='title_$i' style='width: 540px;' value='' /></td>
								<td rowspan='4'><div id='previewbox_$i' style='width: 140px; height: 140px; overflow: hidden; padding: 5px;'></div></td>
							<tr>
							<tr><td valign='top'>Content:<br /><br /><span class='tacount' id='count_$i'>0</span></td><td><textarea name='content_$i' id='content_$i' style='width: 540px; height: 60px;' onkeyup=\"jQuery('#count_$i').html(jQuery(this).val().length);\"></textarea><br /><small>put [url] where you'd like the URL to appear and <a href='admin.php?page=stm-settings' target='_blank'>integrate bit.ly</a> to be able to track click stats</small></td></tr>
							<tr><td>Image URL:</td><td><input type='text' name='imgurl_$i' id='imgurl_$i' style='width: 440px;' value='' /> <input type='button' value='Select Image' onclick='SelWPImage($i);' /></td><tr>
						</table><br />
					</div>
				";
				$this->AjaxContent($content);
				$moreops .= "var divNew = document.createElement('div'); divNew.innerHTML = \"$content\"; document.getElementById('variationsbox').appendChild(divNew);";
				die($moreops);
			break;
			case 'addpostschedule':
				$i = $_GET['num'];
				if (isset($_GET['ext'])) $vars = '';
				else $vars = "<td style='width: 60px;'>Variation:</td><td>".$this->SelVariation("numvar_$i", 0, $_GET['numvars'])."</td>";
				$sp = (isset($_GET['ext']))?" colspan='2'":'';
				$content = "
					<div id='schbox_$i'>
						<h4>Schedule <span id='schnum_$i'>$i</span> <img src='".STM_URL."images/delete.png' align='absmiddle' class='icon' onclick=\"RemoveSchedule(this.parentElement.parentElement);\" /></h4>
						<table class='frm'>
							<tr>
								<td>Post In:</td><td><input type='text' name='intnum_$i' id='intnum_$i' style='width: 50px;' value='' /> ".$this->SelIntType("inttype_$i", 'h')."</td>
								<td colspan='2' nowrap>
									<input type='checkbox' name='dorepeat_$i' id='dorepeat_$i' value='1' onclick=\"if (this.checked) document.getElementById('repbox_$i').style.display='inline'; else document.getElementById('repbox_$i').style.display='none';\" /><label for='dorepeat_$i'>Repeat</label> 
									<span id='repbox_$i' style='display:none'> every <input type='text' name='repnum_$i' id='repnum_$i' style='width: 50px;' value='' /> ".$this->SelIntType("reptype_$i", 'h')." for <input type='text' name='repcount_$i' id='repcount_$i' style='width: 50px;' value='' /> times</span>
								</td>
							</tr>
							<tr>
								$vars
								<td style='width: 60px;'>Account:</td><td$sp>".$this->SelAccount("accountid_$i", 0)."</td>
							</tr>
						</table>
					</div>
				";
				$this->AjaxContent($content);
				$moreops .= "var divNew = document.createElement('div'); divNew.innerHTML = \"$content\"; document.getElementById('schedulingbox').appendChild(divNew);";
				die($moreops);
			break;
			case 'loadtemplate':
				$vars = '';
				$numvars = $wpdb->get_var($wpdb->prepare("SELECT numvars FROM {$wpdb->prefix}stm_templates WHERE id=%d", $_GET['tmplid']));
				for ($i=0; $i<=$numvars; $i++) {
					$title = '';
					$content = '';
					$stub = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_variations WHERE (postid=%d) AND (numvar=%d)", $_GET['postid'], $i));
					if (count($stub)) $info = (array)$stub[0];
					else $info = array('title'=>'', 'content'=>'', 'imgurl'=>'');
					$img = $info['imgurl']?"<img src='$info[imgurl]' style='max-width: 140px; max-height: 140px;' />":'';
					$len = strlen($info['content']);
					if ($i==0) {
						$vars .= "
							<div>
								<h4>ORIGINAL</h4>
								<table class='frm'>
									<tr>
										<td>Title:</td><td><input type='text' name='title_$i' id='title_$i' style='width: 540px;' value=\"$info[title]\" /></td>
										<td rowspan='4'><div id='previewbox_$i' style='width: 140px; height: 140px; overflow: hidden; padding: 5px;'>$img</div></td>
									<tr>
									<tr><td valign='top'>Content:<br /><br /><span class='tacount' id='count_$i'>$len</span></td><td><textarea name='content_$i' id='content_$i' style='width: 540px; height: 60px;' onkeyup=\"jQuery('#count_$i').html(jQuery(this).val().length);\">$info[content]</textarea><br /><small>put [url] where you'd like the URL to appear and <a href='admin.php?page=stm-settings' target='_blank'>integrate bit.ly</a> to be able to track click stats</small></td></tr>
									<tr><td>Image URL:</td><td><input type='text' name='imgurl_$i' id='imgurl_$i' style='width: 440px;' value='$info[imgurl]' /> <input type='button' value='Select Image' onclick='SelWPImage($i);' /></td><tr>
								</table><br />
							</div>
						";					
					}
					else {
						$vars .= "
							<div id='varbox_$i'>
								<h4>Variation <span id='varnum_$i'>$i</span> <img src='".STM_URL."images/delete.png' align='absmiddle' class='icon' onclick=\"RemoveVariation(this.parentElement.parentElement);\" /></h4>
								<table class='frm'>
									<tr>
										<td>Title:</td><td><input type='text' name='title_$i' id='title_$i' style='width: 540px;' value=\"$info[title]\" /></td>
										<td rowspan='4'><div id='previewbox_$i' style='width: 140px; height: 140px; overflow: hidden; padding: 5px;'>$img</div></td>
									<tr>
									<tr><td valign='top'>Content:<br /><br /><span class='tacount' id='count_$i'>$len</span></td><td><textarea name='content_$i' id='content_$i' style='width: 540px; height: 60px;' onkeyup=\"jQuery('#count_$i').html(jQuery(this).val().length);\">$info[content]</textarea><br /><small>put [url] where you'd like the URL to appear and <a href='admin.php?page=stm-settings' target='_blank'>integrate bit.ly</a> to be able to track click stats</small></td></tr>
									<tr><td>Image URL:</td><td><input type='text' name='imgurl_$i' id='imgurl_$i' style='width: 440px;' value='$info[imgurl]' /> <input type='button' value='Select Image' onclick='SelWPImage($i);' /></td><tr>
								</table><br />
							</div>
						";
					}
				}
				if ($vars) $vars = "<div style='text-align: right;'><input type='button' class='button-primary' value='Add Variation' onclick=\"AddVariation();\" /></div><div id='variationsbox'>$vars</div><div style='text-align: right;'><input type='button' class='button-primary' value='Add Variation' onclick=\"AddVariation();\" /></div>";
				$schedule = '';
				$i = 1;
				$data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_schedule WHERE templid=%d ORDER BY numschedule", $_GET['tmplid']));
				foreach ($data as $k=>$info) {
					$info = (array)$info;
					$ch = $info['repnum']?' checked':'';
					$disp = $info['repnum']?'inline':'none';
					if (!$info['repnum']) $info['repnum'] = 24;
					if (!$info['repcount']) $info['repcount'] = 5;
					if ($_GET['postid']==0) $selvars = ''; //external URLs - no Variations
					else $selvars = "<td style='width: 60px;'>Variation:</td><td>".$this->SelVariation("numvar_$i", $info['numvar'], $numvars)."</td>";
					$sp = ($_GET['postid']==0)?" colspan='2'":'';
					$schedule .= "
						<div id='schbox_$i'>
							<h4>Schedule <span id='schnum_$i'>$i</span> <img src='".STM_URL."images/delete.png' align='absmiddle' class='icon' onclick=\"RemoveSchedule(this.parentElement.parentElement);\" /></h4>
							<table class='frm'>
								<tr>
									<td>Post In:</td><td><input type='text' name='intnum_$i' id='intnum_$i' style='width: 50px;' value='$info[intnum]' /> ".$this->SelIntType("inttype_$i", $info['inttype'])."</td>
									<td colspan='2' nowrap>
										<input type='checkbox' name='dorepeat_$i' id='dorepeat_$i' id='dorepeat_$i' value='1'$ch onclick=\"if (this.checked) document.getElementById('repbox_$i').style.display='inline'; else document.getElementById('repbox_$i').style.display='none';\" /><label for='dorepeat_$i'>Repeat</label> 
										<span id='repbox_$i' style='display:$disp'> every <input type='text' name='repnum_$i' id='repnum_$i' style='width: 50px;' value='$info[repnum]' /> ".$this->SelIntType("reptype_$i", $info['reptype'])." for <input type='text' name='repcount_$i' id='repcount_$i' style='width: 50px;' value='$info[repcount]' /> times</span>
									</td>
								</tr>
								<tr>
									$selvars
									<td style='width: 60px;'>Account:</td><td$sp>".$this->SelAccount("accountid_$i", $info['accountid'])."</td>
								</tr>
							</table>
						</div>
					";
					$i++;
				}
				if ($_GET['postid']==0) { //external URL
					$schedule = "<div id='schedulingbox'>$schedule</div>";
					$this->AjaxContent($schedule);
					$moreops .= "document.getElementById('scheduling').innerHTML=\"$schedule\";";
					die($moreops);
				}
				if ($schedule) $schedule = "<div style='text-align: right;'><input type='button' class='button-primary' value='Add Scheduling' onclick=\"AddPostSchedule();\" /></div><div id='schedulingbox'>$schedule</div><div style='text-align: right;'><input type='button' class='button-primary' value='Add Scheduling' onclick=\"AddPostSchedule();\" /></div>";
				$this->AjaxContent($vars);
				$moreops .= "document.getElementById('variations').innerHTML=\"$vars\";";
				$this->AjaxContent($schedule);
				$moreops .= "document.getElementById('scheduling').innerHTML=\"$schedule\";";
				die($moreops);
			break;
		}
		return '';
	}


	function DoAuth() {
		global $wpdb;
		$lurl = admin_url()."admin.php?page=stm&doauth=$_GET[doauth]";
		$data = $wpdb->get_results($wpdb->prepare("SELECT atype, auth1, auth2, info FROM {$wpdb->prefix}stm_accounts WHERE id=%d;", $_GET['doauth']));
		$info = (array)$data[0];
		if ($info['atype'] == 'facebook') {
			$lurl = urlencode($lurl);
			$appid = $info['auth1'];
			$appsecret = $info['auth2'];
			if (isset($_REQUEST['code']) && isset($_REQUEST['state'])) {
				$state = get_option('stmstate');
				if ($state != $_REQUEST['state']) die("<META HTTP-EQUIV='Refresh' Content='0; URL=admin.php?page=stm-accounts'>");
				$token_url = "https://graph.facebook.com/oauth/access_token?client_id=$appid&redirect_uri=$lurl&client_secret=$appsecret&code=$_REQUEST[code]";
				$response = file_get_contents($token_url);
				$params = null;
				parse_str($response, $params);
				$access_token = $params['access_token'];
				$user = json_decode(file_get_contents("https://graph.facebook.com/me?access_token=$access_token"), true);
				$fbid = $user['id'];
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET auth3=%s, auth4=%s WHERE id=%d", $fbid, $access_token, $_GET['doauth']));
				if (strpos(' '.$info['info'], 'imppages')) {
					$accounts = json_decode(file_get_contents("https://graph.facebook.com/$fbid/accounts?access_token=$access_token"), true);
					$accounts = $accounts['data'];
					$start = 3;
					foreach ($accounts as $k=>$acc) {
						$perms = $acc['perms'];
						$cango = 0;
						foreach ($perms as $stub=>$perm) if ($perm == 'CREATE_CONTENT') {
							$cango = 1;
							break;
						}
						if (!$cango) continue;
						$pgid = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_accounts WHERE (parentid=%d) AND (username=%s) AND (info='page')", $_GET['doauth'], $acc['name']));
						if ($pgid) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET username=%s, auth3=%s, auth4=%s WHERE id=$pgid;", $acc['name'], $acc['id'], $acc['access_token']));
						else $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_accounts (parentid, atype, username, auth3, auth4, info) VALUES (%d, 'facebook', %s, %s, %s, 'page');", $_GET['doauth'], $acc['name'], $acc['id'], $acc['access_token']));
						$start--;
						if ($start <= 0) break;
					}
				}
				if (strpos(' '.$info['info'], 'impgroups')) {
					$amdinonly = 0;
					if (strpos(' '.$info['info'], 'admingroups')) $amdinonly = 1;
					$groups = json_decode(file_get_contents("https://graph.facebook.com/$fbid/groups?access_token=$access_token"), true);
					$groups = $groups['data'];
					$start = 3;
					foreach ($groups as $k=>$gr) {
						if ($amdinonly && ($gr['administrator'] != '1')) continue;
						$grid = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_accounts WHERE (parentid=%d) AND (username=%s) AND (info='group')", $_GET['doauth'], $gr['name']));
						if ($grid) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET username=%s, auth3=%s WHERE id=%d;", $gr['name'], $gr['id'], $grid));
						else $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_accounts (parentid, atype, username, auth3, info) VALUES (%s, 'facebook', %s, %s, 'group');", $_GET['doauth'], $gr['name'], $gr['id']));
						$start--;
						if ($start <= 0) break;
					}
				}
				die("<META HTTP-EQUIV='Refresh' Content='0; URL=admin.php?page=stm-accounts'>");
			}
			$state = md5(uniqid(rand(), true));
			update_option('stmstate', $state);
			$url = "https://www.facebook.com/dialog/oauth?client_id=$appid&redirect_uri=$lurl&state=$state&scope=publish_actions,manage_pages,user_groups";
			die("<META HTTP-EQUIV='Refresh' Content='0; URL=$url'>");
		}
		if ($info['atype'] == 'twitter') {
			$consumerkey = $info['auth1'];
			$consumersecret = $info['auth2'];
			if (isset($_GET['oauth_token']) && $_GET['oauth_verifier']) {
				$url = 'https://api.twitter.com/oauth/access_token';
				$nonce = time();
				$tm = time();
				$params = array(
					'oauth_token'=>trim($_GET['oauth_token']),
					'oauth_verifier'=>trim($_GET['oauth_verifier']),
					'oauth_nonce'=>$nonce, 
					'oauth_signature_method'=>'HMAC-SHA1', 
					'oauth_timestamp'=>$tm, 
					'oauth_version' => '1.0'
				);
				$baseString = $this->buildBaseString($url, $params);
				$compositeKey = $this->getCompositeKey($consumersecret, $_GET['oauth_token']);
				$signature = base64_encode(hash_hmac('sha1', $baseString, $compositeKey, true));
				$params['oauth_signature'] = $signature;
				$response = $this->HTTPPost($url, $params);
				parse_str($response, $params);
				$info = serialize($params);
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET auth3=%s, auth4=%s, info=%s WHERE id=%d;", $params['oauth_token'], $params['oauth_token_secret'], $info, $_GET['doauth']));
				die("<META HTTP-EQUIV='Refresh' Content='0; URL=admin.php?page=stm-accounts'>");
			}
			$nonce = time();
			$tm = time();
			$url = 'https://api.twitter.com/oauth/request_token';
			$params = array('oauth_callback'=>$lurl, 'oauth_consumer_key'=>$consumerkey, 'oauth_nonce'=>$nonce, 'oauth_signature_method'=>'HMAC-SHA1', 'oauth_timestamp'=>$tm, 'oauth_version' => '1.0');
			$baseString = $this->buildBaseString($url, $params);
			$compositeKey = $this->getCompositeKey($consumersecret, null);
			$signature = base64_encode(hash_hmac('sha1', $baseString, $compositeKey, true));
			$params['oauth_signature'] = $signature;
			$response = $this->HTTPPost($url, $params);
			$params = null;
			parse_str($response, $params);
			$url = "https://api.twitter.com/oauth/authorize?oauth_token=$params[oauth_token]";
			die("<META HTTP-EQUIV='Refresh' Content='0; URL=$url'>");
		}
		if ($info['atype'] == 'linkedin') {
			$lurl = urlencode($lurl);
			$apikey = $info['auth1'];
			$secretkey = $info['auth2'];
			if (isset($_REQUEST['code']) && isset($_REQUEST['state'])) {
				$state = get_option('stmstate');
				if($state != $_REQUEST['state']) die("<META HTTP-EQUIV='Refresh' Content='0; URL=admin.php?page=stm-accounts'>");
				$token_url = "https://www.linkedin.com/uas/oauth2/accessToken?grant_type=authorization_code&code=$_REQUEST[code]&redirect_uri=$lurl&client_id=$apikey&client_secret=$secretkey";
				$response = file_get_contents($token_url);
				$res = json_decode($response);
				$token = $res->access_token;
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET auth3=%s WHERE id=%d", $token, $_GET['doauth']));
				die("<META HTTP-EQUIV='Refresh' Content='0; URL=admin.php?page=stm-accounts'>");
			}
			$state = md5(uniqid(rand(), true));
			update_option('stmstate', $state);
			$url = "https://www.linkedin.com/uas/oauth2/authorization?response_type=code&client_id=$apikey&scope=r_fullprofile%20r_emailaddress%20r_network%20r_basicprofile%20r_contactinfo%20rw_nus&state=$state&redirect_uri=$lurl";
			die("<META HTTP-EQUIV='Refresh' Content='0; URL=$url'>");
		}
		if ($info['atype'] == 'tumblr') {
			$consumerkey = $info['auth1'];
			$consumersecret = $info['auth2'];
			if (isset($_GET['oauth_token']) && $_GET['oauth_verifier']) {
				require_once(STM_DIR.'/tumblroauth/tumblroauth.php');
				$oauth_token_secret = get_option('stmstate');
				$tum_oauth = new TumblrOAuth($consumerkey, $consumersecret, $_GET['oauth_token'], $oauth_token_secret);
				$access_token = $tum_oauth->getAccessToken($_REQUEST['oauth_verifier']);
				$tum_oauth = new TumblrOAuth($consumerkey, $consumersecret, $access_token['oauth_token'], $access_token['oauth_token_secret']);
				$userinfo = $tum_oauth->get('http://api.tumblr.com/v2/user/info');
				if (200 == $tum_oauth->http_code) $username = $userinfo->response->user->name;
				else $username = '';
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET auth3=%s, auth4=%s, info=%s WHERE id=%d", $access_token['oauth_token'], $access_token['oauth_token_secret'], $username, $_GET['doauth']));
				die("<META HTTP-EQUIV='Refresh' Content='0; URL=admin.php?page=stm-accounts'>");
			}
			$nonce = time();
			$tm = time();
			$url = 'http://www.tumblr.com/oauth/request_token';
			$params = array(
				'oauth_callback'=>$lurl,
				'oauth_consumer_key'=>$consumerkey,
				'oauth_nonce'=>$nonce, 
				'oauth_signature_method'=>'HMAC-SHA1',
				'oauth_timestamp'=>$tm,
				'oauth_version' => '1.0'
			);
			$baseString = $this->buildBaseString($url, $params);
			$compositeKey = $this->getCompositeKey($consumersecret, null);
			$signature = base64_encode(hash_hmac('sha1', $baseString, $compositeKey, true));
			$params['oauth_signature'] = $signature;
			$response = $this->HTTPPost($url, $params);
			$params = null;
			parse_str($response, $params);
			update_option('stmstate', $params['oauth_token_secret']);
			$url = "http://www.tumblr.com/oauth/authorize?oauth_token=$params[oauth_token]";
			die("<META HTTP-EQUIV='Refresh' Content='0; URL=$url'>");
		}
	}


	function UserOffset() { // user timezone offset in seconds
		$stz = date('P');
		$sign = substr($stz, 0, 1);
		if (($sign == '+') || ($sign == '-')) $stz = str_replace($sign, '', $stz);
		else $sign = '+';
		$stub = explode(':', $stz);
		$difserver = ((int)$stub[0])*3600 + ((int)$stub[0])*60;
		if ($sign=='-') $difserver = -$difserver;
		$utz = get_option('gmt_offset');
		$sign = substr($utz, 0, 1);
		if (($sign == '+') || ($sign == '-')) $utz = str_replace($sign, '', $utz);
		else $sign = '+';
		$difuser = ((int)$utz)*3600;
		if ($sign=='-') $difuser = -$difuser;
		return $difuser - $difserver;
	}

	function UserTime($tm=0) {
		if (!$tm) $tm = time();
		return $tm + $this->UserOffset();
	}


	function savePost($postid) {
		global $wpdb;
		//echo $postid; die();
		if (!$postid) return '';
		if (!isset($_POST['stmstartfrom'])) return '';
		add_post_meta($postid, 'stmstartfrom', $_POST['stmstartfrom'], true);
		if ($_POST['stmstartfrom'] == 1) {
			$tm = get_post_time('m/d/Y H:i', false, $postid);
			$stub = explode(' ', $tm);
			$d = explode('/', $stub[0]);
			$stub = explode(':', $stub[1]);
			$starttm = mktime($stub[0], $stub[1], 0, $d[0], $d[1], $d[2]);
		}
		else {
			$d = explode('.', $_POST['stmdate']);
			$h = explode(':', $_POST['stmhour']);
			$starttm = mktime($h[0], $h[1], 0, $d[1], $d[0], $d[2]);
		}
		$starttm -= $this->UserOffset();
		add_post_meta($postid, 'stmstartfrom', $_POST['stmstartfrom'], true);
		add_post_meta($postid, 'stmstarttime', $starttm, true);
		update_post_meta($postid, 'stmstartfrom', $_POST['stmstartfrom']);
		update_post_meta($postid, 'stmstarttime', $starttm);
		// save variations
		$maxnumvar = 0;
		foreach ($_POST as $k=>$title) if (substr($k, 0, 6) == 'title_') {
			$numvar = substr($k, 6);
			if ($numvar > $maxnumvar) $maxnumvar = $numvar;
			$content = $_POST['content_'.$numvar];
			$imgurl = $_POST['imgurl_'.$numvar];
			$id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_variations WHERE (postid=%d) AND (numvar=%d)", $postid, $numvar));
			if (!$id) {
				$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_variations (postid, numvar) VALUES (%d, %d);", $postid, $numvar));
				$id = $wpdb->insert_id;
			}
			if (!$title) $title = get_the_title($postid);
			if (!$content) {
				$content = $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->prefix}posts WHERE ID=%d", $postid));
				$content = $this->LimitLength(strip_tags($content), 300);
			}
			if (!$content) $content = $title;
			if (!$imgurl) {
				$imgurl = get_the_post_thumbnail($postid, 'large');
				if (strpos($imgurl, 'src="')) $imgurl = $this->GetBetweenTags($imgurl, 'src="', '"');
				else $imgurl = '';
			}
			$url = $this->PostVarURL($postid, $numvar);
			$title = addslashes(stripslashes($title));
			$content = addslashes(strip_tags(stripslashes($content)));
			$imgurl = addslashes($imgurl);
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_variations SET title=%s, content=%s, imgurl=%s, url=%s WHERE id=%d;", $title, $content, $imgurl, $url, $id));
		}
		if ($maxnumvar) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_variations WHERE (postid=%d) AND (numvar>%d);", $postid, $maxnumvar));
		if (!isset($_POST['spdoschedule'])) return '';
		// save schedules
		$maxschedule = 0;
		$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_schedule SET fordel=1 WHERE postid=%d;", $postid));
		foreach ($_POST as $k=>$intnum) if (substr($k, 0, 7) == 'intnum_') {
			$numschedule = substr($k, 7);
			if ($numschedule > $maxschedule) $maxschedule = $numschedule;
			$inttype = $_POST['inttype_'.$numschedule];
			if ($inttype == 'm') $intsec = 60*$intnum;
			if ($inttype == 'h') $intsec = 3600*$intnum;
			if ($inttype == 'd') $intsec = 24*3600*$intnum;
			$repnum = 0;
			$repsec = 0;
			$repcount = 0;
			$reptype = 'h';
			if (isset($_POST['dorepeat_'.$numschedule])) {
				$reptype = $_POST['reptype_'.$numschedule];
				$repnum = $_POST['repnum_'.$numschedule];
				if ($reptype == 'm') $repsec = 60*$repnum;
				if ($reptype == 'h') $repsec = 3600*$repnum;
				if ($reptype == 'd') $repsec = 24*3600*$repnum;
				$repcount = $_POST['repcount_'.$numschedule];
			}
			$numvar = $_POST['numvar_'.$numschedule];
			$accountid = $_POST['accountid_'.$numschedule];
			$id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_schedule WHERE (postid=%d) AND (numschedule=%d)", $postid, $numschedule));
			if (!$id) {
				$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_schedule (postid, numschedule) VALUES (%d, %d);", $postid, $numschedule));
				$id = $wpdb->insert_id;
			}
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_schedule SET numvar=%d, intnum=%d, inttype=%s, intsec=%d, repnum=%d, repcount=%d, reptype=%s, repsec=%d, accountid=%d, fordel=0 WHERE id=%d;", $numvar, $intnum, $inttype, $intsec, $repnum, $repcount, $reptype, $repsec, $accountid, $id));
		}
		$this->DelSchedules();
		if ($maxschedule) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_schedule WHERE (postid=%d) AND (numschedule>%d);", $postid, $maxschedule));
		$this->BuildTimeLine("postid=$postid");
	}


	function PostVarURL($postid, $numvar) {
		$permalink = get_permalink($postid);
		if (!$numvar) return $permalink;
		$del = strpos($permalink, '?')?'&':'?';
		return $permalink.$del.'stmv='.$numvar;
	}


	function editPost() {
		global $post, $wpdb;
	    $post_id = $post;
		//echo $this->BitLyURL($this->PostVarURL($post_id, 1)); die();
	    if (is_object($post_id)) $post_id = $post_id->ID;
		$postschedule = $this->ListPostSchedule($post_id);
		$buts = $postschedule?"<div style='text-align: right;'><input type='button' class='button-primary' value='Add Scheduling' onclick=\"AddPostSchedule();\" /></div>":'';
		$postvars = $this->ListPostVars($post_id);
		$but = $postvars?"<div style='text-align: right;'><input type='button' class='button-primary' value='Add Variation' onclick=\"AddVariation();\" /></div>":'';
		$starttime = get_post_meta($post_id, 'stmstarttime', true);
		$dateformat = 'd.m.Y';
		$timeformat = 'H:i';
		if ($starttime) {
			$starttime = $this->UserTime($starttime);
			$stmdate = date($dateformat, $starttime);
			$stmhour = date($timeformat, $starttime);
		}
		else {
			$tm = $this->UserTime();
			$stmdate = date($dateformat, $tm);
			$stmhour = date($timeformat, $tm);
		}
		$stmstartfrom = get_post_meta($post_id, 'stmstartfrom', true);
		if (!$stmstartfrom) $stmstartfrom = 1;
		$disp = ($stmstartfrom==2)?'inline':'none';
		$hasscheduled = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$wpdb->prefix}stm_schedule WHERE postid=%d;", $post_id));
		if ($hasscheduled) {
			$cb = "<input type='checkbox' name='spdoschedule' id='spdoschedule' value='1' onclick=\"if (this.checked) document.getElementById('stmeditbox').style.display='block'; else document.getElementById('stmeditbox').style.display='none';\" /> <label for='spdoschedule'><strong>Edit Scheduling</strong> (if you do this any manual changes in scheduled sharing for this post will be overwritten)</label><br /><br />";
			$div1 = "<div id='stmeditbox' style='display: none;'>";
			$div2 = "</div";
		}
		else {
			$cb = "<input type='hidden' name='spdoschedule' value='1' />";
			$div1 = '<br /><br />';
			$div2 = '';
		}
		echo "
			<div id='advanced-sortables' class='meta-box-sortables'>
				<div class='postbox'>
					<div class='handlediv' title='Social Time Master'><br /></div>
					<h3 class='hndle'><span>Social Time Master</span></h3>
					<div class='inside'><br /><br />
						<div style='float: right; margin-top: -25px;'>
							Start Time: ".$this->SelStartFrom('stmstartfrom', $stmstartfrom)." <span id='sttimebox' style='display:$disp'><input type='text' name='stmdate' id='stmdate' value='$stmdate' style='width: 100px;' /> <input type='text' name='stmhour' id='stmhour' value='$stmhour' style='width: 60px;' /></span><br />
							Load Template: ".$this->SelTemplate('templid')." <input type='button' class='button-primary' value='Load' onclick=\"AjaxActionSP('loadtemplate&postid=$post_id&tmplid='+document.getElementById('templid').value);\" />
						</div>
						<div class='tabbed_area'>
							<ul class='tabs' style='padding-top: 19px;'>
								<li><a href='#' title='schedtab' class='tab active'>Social Post Scheduling</a></li>
								<li><a href='#' title='variations' class='tab'>Variations</a></li>
								<li><a href='#' title='log' class='tab'>Post Log</a></li>
								<li><a href='#' title='help' class='tab'>Help</a></li>
								<div class='clear'></div>
							</ul>
							<div id='schedtab' class='tabcontent'>
								$cb
								$div1
									<div id='scheduling'>
										<div style='text-align: right;'><input type='button' class='button-primary' value='Add Scheduling' onclick=\"AddPostSchedule();\" /></div>
										<div id='schedulingbox'>$postschedule</div>
										$buts
									</div>
								$div2
							</div>
							<div id='variations' class='tabcontent' style='display:none;'>
								<div style='text-align: right;'><input type='button' class='button-primary' value='Add Variation' onclick=\"AddVariation();\" /></div>
								<div id='variationsbox'>$postvars</div>
								$but
							</div>
							<div id='log' class='tabcontent' style='display:none;'>
								".$this->PostLog($post_id)."
							</div>
							<div id='help' class='tabcontent' style='display:none;'>
								Help...
							</div>
						</div>
					</div>
				</div>
			</div>
			<script type='text/javascript'>	
				jQuery(document).ready(function() {
					jQuery('#stmdate').datepicker({ changeMonth: true, changeYear: true, dateFormat: 'dd.mm.yy', defaultDate: '$stmdate', dayNames: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], dayNamesMin: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'], firstDay: 1, monthNamesShort: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] });
					jQuery('#stmhour').timepicker();
					jQuery('a.tab').click(function (e) { 
						jQuery('.active').removeClass('active');   
						jQuery(this).addClass('active');
						e.preventDefault();
						jQuery('.tabcontent').slideUp();    
						var content_show = jQuery(this).attr('title');  
						jQuery('#'+content_show).slideDown();
					});
				});
			</script>
		".$this->Footer(0);
	}

	function PostLogAll() {
		global $wpdb;
		//$this->Cron();
        if (isset($_POST['DoDelete']) && isset($_POST['godel'])) {
            foreach ($_POST['godel'] as $id) 
				$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_postlog WHERE id=%d", $id));
            die("<META HTTP-EQUIV='Refresh' Content='0; URL=admin.php?page=stm-postlog&msg=dels' />");
        }
		$msg = '';
        if (isset($_GET['msg'])) {
            if ($_GET['msg'] == 'del') $msg = 'PostLog Record Deleted!';
            if ($_GET['msg'] == 'dels') $msg = 'PostLog Record(s) Deleted!';
        }
		if ($msg) $msg = $this->Msg_OK($msg);
		$html = $this->TopMsg()."
			<div class='wrap' style='position: relative;'>
				<div id='extsub' onmouseover=\"clearTimeout(subtimer);\" onmouseout=\"ShowDiv('extsub')\" style='left: 340px;'>
					<a href='admin.php?page=stm-scheduled&addpost=1'>Add Manually</a>
					<a href='admin.php?page=stm-scheduled&addpost=2'>Bulk Add URLs</a>
					<a href='admin.php?page=stm-scheduled&addpost=3'>Add from RSS</a>
				</div>
				<div id='icon-edit-pages' class='icon32'></div>
				<h2><img src='".STM_URL."images/logofull.png' align='absmiddle' class='logo' /> &nbsp; Post Log
					&nbsp;<a href='#' class='button add-new-h2' style='background: #2ea2cc; border-color: #0074a2; color: #FFF; box-shadow: inset 0 1px 0 rgba(120,200,230,.5),0 1px 0 rgba(0,0,0,.15);' onclick=\"ShowDiv('extsub')\">Schedule More</a>
					&nbsp;<a href='admin.php?page=stm' class='button add-new-h2'>Timeline</a>
					&nbsp;<a href='admin.php?page=stm-scheduled' class='button add-new-h2'>Scheduled Posts</a>
					&nbsp;<a href='admin.php?page=stm-accounts' class='button add-new-h2'>Social Accounts</a>
					&nbsp;<a href='admin.php?page=stm-templates' class='button add-new-h2'>Schedule Templates</a>
					&nbsp;<a href='admin.php?page=stm-settings' class='button add-new-h2'>Settings</a>
				</h2><br />
				<div class='clear'></div>
				$msg
		";
		$qprop = $this->slPrepareQuerry('postlog', 'ptime', 'desc');
		$bitlyactive = get_option('stmbitly');
		if ($bitlyactive) $data = $wpdb->get_results($wpdb->prepare("SELECT l.*, u.clickcount, u.numshares FROM {$wpdb->prefix}stm_postlog as l LEFT JOIN {$wpdb->prefix}stm_urls as u ON l.bitly=u.bitly WHERE 1 GROUP BY l.id ORDER BY %s %s LIMIT %d, %d;", $qprop['order'], $qprop['dir'], $qprop['start'], $qprop['limit']));
		else $data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_postlog WHERE 1 ORDER BY %s %s LIMIT %d, %d;", $qprop['order'], $qprop['dir'], $qprop['start'], $qprop['limit']));
		if (!count($data)) {
			echo $html."There are no social shares made yet!</div>".$this->Footer();
			return '';
		}
		if ($bitlyactive) {
			$clickscol = sprintf("<th scope='col' class='manage-column' style='text-align: center; width: 130px;'><a href='admin.php?page=stm-postlog&order=clickcount'>Clicks</a>%s/<a href='admin.php?page=stm-postlog&order=numshares'>Shares</a>%s *</th>", $qprop['order']=='clickcount'?$qprop['img']:'', $qprop['order']=='numshares'?$qprop['img']:'');
		}
		else $clickscol = "<th scope='col' class='manage-column' style='text-align: center; width: 130px;'>Clicks/Shares *</th>";
		$html .= "
			<form method='post'>
			<input type='hidden' name='limit' id='limit' value='$qprop[limit]' />
			<div id='urlsbox'>
			<table class='wp-list-table widefat fixed pages' cellspacing='0' style='width: 95%; margin: 10px 0 7px 0;'>
				<thead><tr>
					<th scope='col' class='manage-column column-cb check-column'><input type='checkbox' /></th>".
					sprintf("<th scope='col' class='manage-column'><a href='admin.php?page=stm-postlog&order=postid'>Post</a>%s</th>", $qprop['order']=='postid'?$qprop['img']:'').
					sprintf("<th scope='col' class='manage-column'><a href='admin.php?page=stm-postlog&order=userid'>Social Account%s</th>", $qprop['order']=='userid'?$qprop['img']:'').
					sprintf("<th scope='col' class='manage-column'><a href='admin.php?page=stm-postlog&order=bitly'>Shared URL%s</th>", $qprop['order']=='bitly'?$qprop['img']:'').
					sprintf("<th scope='col' class='manage-column' style='width: 80px;'><a href='admin.php?page=stm-postlog&order=posturl'>Social URL%s</th>", $qprop['order']=='posturl'?$qprop['img']:'').
					"<th scope='col' class='manage-column' style='text-align: center; width: 60px;'>Variation</th>".
					sprintf("<th scope='col' class='manage-column' style='text-align: center; width: 110px;'><a href='admin.php?page=stm-postlog&order=ptime'>Post Time%s</th>", $qprop['order']=='ptime'?$qprop['img']:'')."
					$clickscol
				</tr></thead>
				<tbody>
		";
		$clicks = 0;
		$arrb = array();
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			$lnk = '';
			if (!$info['userid'] && $info['aname']) $info['userid'] = $info['aname'];
			if ($info['atype'] == 'twitter') $lnk = "https://twitter.com/$info[userid]";
			if (in_array($info['atype'], array('fbpage', 'fbgroup', 'fbacc'))) $lnk = "https://www.facebook.com/$info[userid]/";
			if ($info['atype'] == 'linkedin') $lnk = "https://www.linkedin.com/profile/view?id=$info[userid]";
			if ($info['atype'] == 'tumblr') $lnk = "http://$info[userid].tumblr.com";
			$info['atype'] = ucfirst($info['atype']);
			if ($info['atype'] == 'Fbacc') $info['atype'] = 'FB';
			if ($info['atype'] == 'Fbgroup') $info['atype'] = 'FB Group';
			if ($info['atype'] == 'Fbpage') $info['atype'] = 'FB Page';
			$bitlystats = '';
			if ($bitlyactive) {
				$bitlystats = " <a href='$info[bitly]+' target='_blank'><img src='".STM_URL."images/find.png' align='absmiddle' class='icon' /></a>";
				if ($info['bitly']) {
					if (!isset($arrb[$info['bitly']])) {
						$clicks += $info['clickcount'];
						$arrb[$info['bitly']] = 1;
					}
					if ($info['clickcount'] || $info['numshares']) $clickshares = "$info[clickcount]/$info[numshares]";
					else $clickshares = '-';
				}
				else $clickshares = 'n/a';
			}
			else {
				if ($info['bitly']) {
					$bitlystats = " <a href='$info[bitly]+' target='_blank'><img src='".STM_URL."images/find.png' align='absmiddle' class='icon' /></a>";
					if (isset($arrb[$info['bitly']])) $clickshares = $arrb[$info['bitly']];
					else {
						$clickcount = $wpdb->get_var($wpdb->prepare("SELECT clickcount FROM {$wpdb->prefix}stm_urls WHERE bitly=%s;", $info['bitly']));
						$clicks += $clickcount;
						$shares = $wpdb->get_var($wpdb->prepare("SELECT numshares FROM {$wpdb->prefix}stm_urls WHERE bitly=%s;", $info['bitly']));
						if ($clickcount || $shares) $clickshares = $clickcount.'/'.$shares;
						else $clickshares = '-';
						$arrb[$info['bitly']] = $clickshares;
					}
				}
				else $clickshares = 'n/a';
			}
			if (!$info['bitly']) $info['bitly'] = $info['url'];
			$info['ptime'] = $this->UserTime($info['ptime']);
			$info['ptime'] = date(get_option('date_format'), $info['ptime']).', '.date(get_option('time_format'), $info['ptime']);
			if ($info['postid']) {
				$title = $wpdb->get_var($wpdb->prepare("SELECT post_title FROM {$wpdb->prefix}posts WHERE id=%d;", $info['postid']));
				if ($info['numvar'] == 0) $info['numvar'] = 'O';
			}
			else {
				$title = $wpdb->get_var($wpdb->prepare("SELECT title FROM {$wpdb->prefix}stm_variations WHERE id=%d;", $info['variationid']));
				$info['numvar'] = '-';
			}
			$html .= "
				<tr>
					<td scope='row' class='check-column' style='text-align: center;'><input type='checkbox' name='godel[]' value='$info[id]' /></td>
					<td><a href='post.php?post=$info[postid]&action=edit' target='_blank'>$title</a></td>
					<td><a href='$lnk' target='_blank'>$info[atype] - $info[userid]</a></td>
					<td><a href='$info[bitly]' target='_blank'>$info[bitly]</a>$bitlystats</td>
					<td style='text-align: center;'><a href='$info[posturl]' target='_blank'><img src='".STM_URL."images/earth_find.png' align='absmiddle' class='icon' title='$info[posturl]' /></a></td>
					<td style='text-align: center;'><a href='#' onclick=\"AjaxPopSP('postlogdet&id=$info[id]');\">$info[numvar]</a></td>
					<td style='text-align: center;'>$info[ptime]</td>
					<td style='text-align: center;'><span id='clicks_$info[id]'>$clickshares</span></td>
				</tr>
			";
		}
		$numrecs = $wpdb->get_var("SELECT COUNT(id) FROM {$wpdb->prefix}stm_postlog WHERE 1;");
		$html .= "
					<tr style='font-weight: bold;'><td colspan='7'>Total Clicks:</td><td style='text-align: center;'><span id='totclicks'>$clicks</span></td></tr>
				</tbody>
			</table><br />
			</div>
			<div style='width: 95%;'>
				<div style='float: left;'>
					<input class='button-primary' type='button' value='Refresh Clicks' onclick=\"RefreshClicks('".STM_URL."images/refreshanim.gif');\" /> &nbsp;
					<input class='button-secondary' type='submit' name='DoDelete' value='Delete Selected' />
				</div>
				<div style='float: right;'>".$this->slPagesLinks($numrecs, $qprop['start'], $qprop['limit'], "admin.php?page=stm-postlog", 1) . "</div>
			</div>
			<div class='clear'></div>
			<br />
			* click tracking is available only if you have setup your Bit.ly account on the <a href='admin.php?page=stm-settings'>settings page</a>.<br />Clicks are tracked by Bit.Ly URL and in this column you will also see the number of shares for this URL to get that amount of clicks.
			</form>
			</div>
		";
		$html .= $this->Footer();
		echo $html;
	}

	function PostLog($postid) {
		global $wpdb;
		$data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_postlog WHERE postid=%d ORDER BY ptime DESC;", $postid));
		if (!count($data)) return "There are no social shares made yet for this post!";
		$html = "
			<table class='wp-list-table widefat fixed pages' cellspacing='0' style='width: 100%; margin: 10px 0 7px 0;'>
				<thead><tr>
					<th scope='col' class='manage-column'>Social Account</th>
					<th scope='col' class='manage-column'>Shared URL</th>
					<th scope='col' class='manage-column' style='width: 80px;'>Social URL</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 60px;'>Variation</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 110px;'>Post Time</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 60px;'>Clicks/Shares *</th>
				</tr></thead>
				<tbody>
		";
		$clicks = 0;
		$arrb = array();
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			$lnk = '';
			if ($info['atype'] == 'twitter') $lnk = "https://twitter.com/$info[userid]";
			if (in_array($info['atype'], array('fbpage', 'fbgroup', 'fbacc'))) $lnk = "https://www.facebook.com/$info[userid]/";
			if ($info['atype'] == 'linkedin') $lnk = "https://www.linkedin.com/profile/view?id=$info[userid]";
			if ($info['atype'] == 'tumblr') $lnk = "http://$info[userid].tumblr.com";
			$info['atype'] = ucfirst($info['atype']);
			$bitlystats = '';
			if ($info['bitly']) {
				$bitlystats = " <a href='$info[bitly]+' target='_blank'><img src='".STM_URL."images/find.png' align='absmiddle' class='icon' /></a>";
				if (isset($arrb[$info['bitly']])) $clickshares = $arrb[$info['bitly']];
				else {
					$clickcount = $wpdb->get_var($wpdb->prepare("SELECT clickcount FROM {$wpdb->prefix}stm_urls WHERE bitly=%s;", $info['bitly']));
					$clicks += $clickcount;
					$shares = $wpdb->get_var($wpdb->prepare("SELECT numshares FROM {$wpdb->prefix}stm_urls WHERE bitly=%s;", $info['bitly']));
					if ($clickcount || $shares) $clickshares = $clickcount.'/'.$shares;
					else $clickshares = '-';
					$arrb[$info['bitly']] = $clickshares;
				}
			}
			else $clickshares = 'n/a';
			if (!$info['bitly']) $info['bitly'] = $info['url'];
			$info['ptime'] = $this->UserTime($info['ptime']);
			$info['ptime'] = date(get_option('date_format'), $info['ptime']).', '.date(get_option('time_format'), $info['ptime']);
			if ($info['numvar'] == 0) $info['numvar'] = 'O';
			$html .= "
				<tr>
					<td><a href='$lnk' target='_blank'>$info[atype] - $info[userid]</a></td>
					<td><a href='$info[bitly]' target='_blank'>$info[bitly]$bitlystats</a></td>
					<td style='text-align: center;'><a href='$info[posturl]' target='_blank'><img src='".STM_URL."images/earth_find.png' align='absmiddle' class='icon' title='$info[posturl]' /></a></td>
					<td style='text-align: center;'>$info[numvar]</td>
					<td style='text-align: center;'>$info[ptime]</td>
					<td style='text-align: center;'><span id='clicks_$info[id]'>$clickshares</span></td>
				</tr>
			";
		}
		$html .= "
					<tr style='font-weight: bold;'><td colspan='5'>Total Clicks:</td><td style='text-align: center;'><span id='totclicks'>$clicks</span></td></tr>
				</tbody>
			</table><br />
			<input class='button-primary' type='button' value='Refresh Clicks' onclick=\"AjaxLoadingSP();AjaxActionSP('refreshclicks&postid=$postid');\" /><br /><br />
			* click tracking is available only if you have setup your Bit.ly account on the <a href='admin.php?page=stm-settings'>settings page</a>.<br />Clicks are tracked by Bit.Ly URL and in this column you will also see the number of shares for this URL to get that amount of clicks.<br /><br />
		";
		return $html;
	}


	function ListPostVars($postid) {
		global $wpdb;
		if (!$postid) return '';
		$i = 0;
		$stub = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_variations WHERE (postid=%d) AND (numvar=0)", $postid));
		if (count($stub)) $info = (array)$stub[0];
		else $info = array('title'=>'', 'content'=>'', 'imgurl'=>'');
		foreach ($info as $k=>$v) $info[$k] = stripslashes(stripslashes($v));
		$img = $info['imgurl']?"<img src='$info[imgurl]' style='max-width: 140px; max-height: 140px;' />":'';
		$len = strlen($info['content']);
		$html = "
			<div>
				<h4>ORIGINAL</h4>
				<table class='frm'>
					<tr>
						<td>Title:</td><td><input type='text' name='title_$i' id='title_$i' style='width: 540px;' value=\"$info[title]\" /></td>
						<td rowspan='4'><div id='previewbox_$i' style='width: 140px; height: 140px; overflow: hidden; padding: 5px;'>$img</div></td>
					<tr>
					<tr><td valign='top'>Content:<br /><br /><span class='tacount' id='count_$i'>$len</span></td><td><textarea name='content_$i' id='content_$i' style='width: 540px; height: 60px;' onkeyup=\"jQuery('#count_$i').html(jQuery(this).val().length);\">$info[content]</textarea><br /><small>put [url] where you'd like the URL to appear and <a href='admin.php?page=stm-settings' target='_blank'>integrate bit.ly</a> to be able to track click stats</small></td></tr>
					<tr><td>Image URL:</td><td><input type='text' name='imgurl_$i' id='imgurl_$i' style='width: 440px;' value='$info[imgurl]' /> <input type='button' value='Select Image' onclick='SelWPImage($i);' /></td><tr>
				</table><br />
			</div>
		";	
		$i++;
		$data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_variations WHERE (postid=%d) AND (numvar>0) ORDER BY numvar", $postid));
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			$img = $info['imgurl']?"<img src='$info[imgurl]' style='max-width: 140px; max-height: 140px;' />":'';
			$len = strlen($info['content']);
			$html .= "
				<div id='varbox_$i'>
					<h4>Variation <span id='varnum_$i'>$i</span> <img src='".STM_URL."images/delete.png' align='absmiddle' class='icon' onclick=\"RemoveVariation(this.parentElement.parentElement);\" /></h4>
					<table class='frm'>
						<tr>
							<td>Title:</td><td><input type='text' name='title_$i' id='title_$i' style='width: 540px;' value=\"$info[title]\" /></td>
							<td rowspan='4'><div id='previewbox_$i' style='width: 140px; height: 140px; overflow: hidden; padding: 5px;'>$img</div></td>
						<tr>
						<tr><td valign='top'>Content:<br /><br /><span class='tacount' id='count_$i'>$len</span></td><td><textarea name='content_$i' id='content_$i' style='width: 540px; height: 60px;' onkeyup=\"jQuery('#count_$i').html(jQuery(this).val().length);\">$info[content]</textarea><br /><small>put [url] where you'd like the URL to appear and <a href='admin.php?page=stm-settings' target='_blank'>integrate bit.ly</a> to be able to track click stats</small></td></tr>
						<tr><td>Image URL:</td><td><input type='text' name='imgurl_$i' id='imgurl_$i' style='width: 440px;' value='$info[imgurl]' /> <input type='button' value='Select Image' onclick='SelWPImage($i);' /></td><tr>
					</table><br />
				</div>
			";
			$i++;
		}
		return $html;	
	}

	function ListPostSchedule($postid) {
		global $wpdb;
		if (!$postid) return '';
		$i = 1;
		$html = '';
		$numvars = $wpdb->get_var($wpdb->prepare("SELECT MAX(numvar) FROM {$wpdb->prefix}stm_variations WHERE (postid=%d) AND (numvar<200)", $postid));
		$data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_schedule WHERE postid=%d ORDER BY intsec", $postid));
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			$ch = $info['repnum']?' checked':'';
			$disp = $info['repnum']?'inline':'none';
			if (!$info['repnum']) $info['repnum'] = 24;
			if (!$info['repcount']) $info['repcount'] = 5;
			$html .= "
				<div id='schbox_$i'>
					<h4>Schedule <span id='schnum_$i'>$i</span> <img src='".STM_URL."images/delete.png' align='absmiddle' class='icon' onclick=\"RemoveSchedule(this.parentElement.parentElement);\" /></h4>
					<table class='frm'>
						<tr>
							<td>Post In:</td><td><input type='text' name='intnum_$i' id='intnum_$i' style='width: 50px;' value='$info[intnum]' /> ".$this->SelIntType("inttype_$i", $info['inttype'])."</td>
							<td colspan='2' nowrap>
								<input type='checkbox' name='dorepeat_$i' id='dorepeat_$i' value='1'$ch onclick=\"if (this.checked) document.getElementById('repbox_$i').style.display='inline'; else document.getElementById('repbox_$i').style.display='none';\" /><label for='dorepeat_$i'>Repeat</label> 
								<span id='repbox_$i' style='display:$disp'> every <input type='text' name='repnum_$i' id='repnum_$i' style='width: 50px;' value='$info[repnum]' /> ".$this->SelIntType("reptype_$i", $info['reptype'])." for <input type='text' name='repcount_$i' id='repcount_$i' style='width: 50px;' value='$info[repcount]' /> times</span>
							</td>
						</tr>
						<tr>
							<td style='width: 60px;'>Variation:</td><td>".$this->SelVariation("numvar_$i", $info['numvar'], $numvars)."</td>
							<td style='width: 60px;'>Account:</td><td>".$this->SelAccount("accountid_$i", $info['accountid'])."</td>
						</tr>
					</table>
				</div>
			";
			$i++;
		}
		return $html;
	}


	function DelSchedules() {
		global $wpdb;
		$data = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}stm_schedule WHERE fordel=1;");
		foreach ($data as $k=>$info) $this->DelSchedule($info->id);
	}

	function DelSchedule($id) {
		global $wpdb;
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_timeline WHERE scheduleid=%d;", $id));
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_schedule WHERE id=%d;", $id));
	}

	function UpdateProc() {
		//put any update code here in the future versions as needed
		//for example - DB updates
	}

	function Timeline() {
		if (isset($_GET['doauth'])) return $this->DoAuth();
		global $wpdb;
		$tldate = isset($_GET['tldate'])?$_GET['tldate']:date('d.m.Y', $this->UserTime());
		$numdays = isset($_GET['numdays'])?$_GET['numdays']:1;
		if ($numdays > 3) $numdays = 3;
		$accountid = isset($_GET['accountid'])?$_GET['accountid']:$wpdb->get_var("SELECT id FROM {$wpdb->prefix}stm_accounts WHERE 1;");
		$tldatei = strtotime($tldate);
		$lineheight = 1440*$numdays.'px';
		$pxpersec = 60;
		$starttm = mktime(0, 0, 0, date('n', $tldatei), date('d', $tldatei), date('Y', $tldatei));
		$starttm -= $this->UserOffset();
		$endtm = $starttm+($numdays*24*3600)-1;
		$html = $this->TopMsg()."
			<div class='wrap' style='position: relative;'>
				<div id='extsub' onmouseover=\"clearTimeout(subtimer);\" onmouseout=\"ShowDiv('extsub')\" style='left: 340px;'>
					<a href='admin.php?page=stm-scheduled&addpost=1'>Add Manually</a>
					<a href='admin.php?page=stm-scheduled&addpost=2'>Bulk Add URLs</a>
					<a href='admin.php?page=stm-scheduled&addpost=3'>Add from RSS</a>
				</div>
				<div id='expanddiv' style='display: none; text-align: right; margin-bottom: 3px;'>
					<span id='savelnk' style='display: none;'><a href='#' onclick=\"AjaxPopSP('savetimelineall');\">&nbsp;&nbsp;Save Changes&nbsp;&nbsp;</a> &nbsp;&nbsp;&nbsp;&nbsp;</span>
					<img src='".STM_URL."images/leftd.png' id='prevday' title='Scroll to Previous Day' class='iconlink' align='absmiddle' onclick=\"NavDay('-1');\" />&nbsp;
					<img src='".STM_URL."images/right.png' id='nextday' title='Scroll to Next Day' class='iconlink' align='absmiddle' onclick=\"NavDay('+1');\" />&nbsp;&nbsp;&nbsp;&nbsp;
					<img src='".STM_URL."images/zoom_out.png' id='zoimage2' title='Zoom Out' class='iconlink' align='absmiddle' onclick=\"TimelineZoomOut();\" />&nbsp;
					<img src='".STM_URL."images/zoom_in.png' id='ziimage2' title='Zoom In' class='iconlink' align='absmiddle' onclick=\"TimelineZoomIn();\" />&nbsp;&nbsp;&nbsp;&nbsp;
					<img src='".STM_URL."images/random.png' title='Distribute (Randomly or Evenly)' class='iconlink' align='absmiddle' onclick=\"AjaxPopSP('distribute&numdays=$numdays');\" />&nbsp;&nbsp;&nbsp;&nbsp;
					<img src='".STM_URL."images/gear.png' title='TimeLine Settings' class='iconlink' align='absmiddle' onclick=\"AjaxPopSP('tlsettings');\" />&nbsp;&nbsp;&nbsp;&nbsp;
					<img src='".STM_URL."images/expand.png' id='htimg' title='Show Top Area' class='iconlink' style='width: 16px; height: 12px;' align='absmiddle' onclick=\"ShowHideTools();\" />
				</div>
				<div id='toptools' style='display: block; margin-bottom: 14px;'>
					<div id='icon-edit-pages' class='icon32'></div>
					<img src='".STM_URL."images/shrink.png' id='htimg' title='Hide Top Area' class='iconlink' style='width: 24px; height: 18px; float: right;' align='absmiddle' onclick=\"ShowHideTools();\" />
					<h2><img src='".STM_URL."images/logofull.png' align='absmiddle' class='logo' /> &nbsp; Timeline
						&nbsp;<a href='#' class='button add-new-h2' style='background: #2ea2cc; border-color: #0074a2; color: #FFF; box-shadow: inset 0 1px 0 rgba(120,200,230,.5),0 1px 0 rgba(0,0,0,.15);' onclick=\"ShowDiv('extsub')\">Schedule More</a>
						&nbsp;<a href='admin.php?page=stm-scheduled' class='button add-new-h2'>Scheduled Posts</a>
						&nbsp;<a href='admin.php?page=stm-postlog' class='button add-new-h2'>Post Log</a>
						&nbsp;<a href='admin.php?page=stm-accounts' class='button add-new-h2'>Social Accounts</a>
						&nbsp;<a href='admin.php?page=stm-templates' class='button add-new-h2'>Schedule Templates</a>
						&nbsp;<a href='admin.php?page=stm-settings' class='button add-new-h2'>Settings</a>
					</h2><br />
					<div class='clear'></div>
					<div id='resmsg'></div>
					<div style='float: left;'>
						<form method='get' action='admin.php'>
							<input type='hidden' name='page' value='stm' /> 
							Account: ".$this->SelAccount('accountid', $accountid)." &nbsp; <img src='".STM_URL."images/clock_run.png' title='Set Prefered Posting Time' class='iconlink' align='absmiddle' onclick=\"AjaxPopSP('setpreftime&accountid=$accountid&numdays=$numdays&zoom='+tlzoom);\" />&nbsp;&nbsp;&nbsp;
							Start Date: <input type='text' name='tldate' id='tldate' value='$tldate' style='width: 100px; position: relative; z-index: 100000;' /> &nbsp;&nbsp; 
							Days: ".$this->SelNum('numdays', $numdays, 1, 3)."&nbsp;&nbsp;
							<input class='button-primary' type='submit' name='doshow' value='Show' />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
							<span id='savebutbox' style='display: none;'><input type='button' class='button-primary' value='Save Changes' onclick=\"AjaxPopSP('savetimelineall');\" style='background: #f01e1e; border-color: #f9a4a4; color: #FFF; box-shadow: inset 0 1px 0 rgba(120,200,230,.5),0 1px 0 rgba(0,0,0,.15);' /></span>
						</form>
					</div>
		";
		$posts = '';
		if (!$accountid) {
			echo $html .= "<br /><br />Please select account (or <a href='admin.php?page=stm-accounts'>add one</a>) and date!";
			return '';
		}
		$data = $wpdb->get_results($wpdb->prepare("SELECT t.*, v.title, v.content, v.postid, v.url FROM {$wpdb->prefix}stm_timeline as t LEFT JOIN {$wpdb->prefix}stm_variations as v ON t.variationid=v.id WHERE (posttime>=%d) AND (posttime<=%d) AND (accountid=%d)", $starttm, $endtm, $accountid));
		$numposts = count($data);
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			foreach ($info as $k=>$v) $info[$k] = stripslashes($v);
			$secs = ($info['posttime']-$starttm)/3600;
			$tpx = round($secs*$pxpersec).'px';
			$info['posttime'] = $this->UserTime($info['posttime']);
			$ptime = date('H:i', $info['posttime']);
			$t = '';
			if ($info['postid']) $t = "<a href='post.php?post=$info[postid]&action=edit' target='_blank'>".get_the_title($info['postid']).'</a>';
			if ($t) $t .= ' - ';
			if (!$info['title']) $info['title'] = $info['content'];
			$info['title'] = $this->LimitLength($info['title'], 100);
			$posts .= "
				<div class='item' style='top: $tpx;' onmousedown=\"ddInit(event, this);\" id='timel_$info[id]'>
					<div class='tm' id='ptimeshow_$info[id]'>$ptime<span></span></div>
					<div class='move' title='drag to move the post on the timeline'></div>
					<div class='meta'>
						<a href='$info[url]' target='_blank'><img src='".STM_URL."images/earth_find.png' align='absmiddle' class='icon' title='$info[url]' /></a>&nbsp;
						<img src='".STM_URL."images/share.png' title='Share Now' class='iconlink' align='absmiddle' onclick=\"AjaxPopSP('sharenow&id=$info[id]');\" />&nbsp;
						<img src='".STM_URL."images/edit.gif' title='Edit Posting' class='iconlink' align='absmiddle' onclick=\"AjaxPopSP('edittimeline&id=$info[id]');\" />&nbsp;
						<img src='".STM_URL."images/delete.png' title='Delete' class='iconlink' align='absmiddle' onclick=\"AjaxActionSP('deltimeline&id=$info[id]');\" />&nbsp;&nbsp;&nbsp;&nbsp;
						<span id='titlebox_$info[id]'>$t$info[title]</span>
					</div>
				</div>
			";
		}
		$ainfo = $wpdb->get_results($wpdb->prepare("SELECT prefstart, prefend FROM {$wpdb->prefix}stm_accounts WHERE id=%d;", $accountid));
		$ainfo = $ainfo[0];
		$prefstart = $ainfo->prefstart;
		$prefend = $ainfo->prefend;
		$prefs = '';
		$days = '';
		$dayopts = '';
		$prefh = 0;
		$stub = explode(':', $prefstart);
		$startsec = (3600*(int)$stub[0] + 60*(int)$stub[1])/3600;
		for ($i=1; $i<=$numdays; $i++) {
			$daytop = ($i-1)*1440;
			$t = $daytop.'px';
			$tm = $starttm+$this->UserOffset()+(($i-1)*24*3600)+10;
			$day = date('M d', $tm).'<br />'.date('Y', $tm);
			$days .= "<div class='linedate' id='date_$i' style='top: $t;'>$day<span></span></div>";
			$ds = date('M d', $tm);
			$dayopts .= "<option value='$i'>$ds</option>";
			$preft = ($i-1)*1440 + round($startsec*$pxpersec);
			if (!$prefh) {
				$stub = explode(':', $prefend);
				$secs = (3600*(int)$stub[0] + 60*(int)$stub[1])/3600;
				$prefh = round($secs*$pxpersec) - $preft;
				$prefh .= 'px';
			}
			for ($j=1; $j<=24; $j++) {
				$t = ($daytop + $j*60);
				$tm = $t-30;
				$t .= 'px';
				$tm .= 'px';
				$class = ($j%4)?'hourtick':'hourtickb';
				$lbl = ($j%4)?'':$j;
				if ($lbl && ($lbl<10)) $lbl = '0'.$lbl;
				if ($lbl) $lbl .= ':00';
				$days .= "<span class='$class' id='htick_$i-$j' style='top: $t;'>$lbl</span>";
				$days .= "<span class='hourtickm' id='htick_$i-$j-m' style='top: $tm;'></span>";
			}
			$preft .= 'px';
			$prefs .= "<div class='lineprefered' id='pref_$i' onmouseover='ShowLinetime(event, 1);' onmouseout='ShowLinetime(event, 0);' onmousemove='LinetimePos(event);' ondblclick=\"AjaxPopSP('addtotimeline');\" style='top: $preft; height: $prefh;'></div>";
		}
		$tmoffs = $this->UserOffset();
		$dozoom = get_option('stmzoomlevel');
		$dozoom = (($dozoom>0) && ($dozoom<11) && ($dozoom!=5))?"TimelineZoomTo($dozoom);":'';
		$tlheight = get_option('tlheight');
		if (!$tlheight) $tlheight = 0;
		$tlh = $tlheight?" style='height:$tlheight"."px;'":'';
		$noanim = get_option('stmnoanim');
		if (!$noanim) $noanim = 0;
		$html .= "
				<div style='float: right;'>
					<span id='numposts'>$numposts</span> posts &nbsp;&nbsp;&nbsp;
					<img src='".STM_URL."images/leftd.png' id='prevday2' title='Scroll to Previous Day' class='iconlink' align='absmiddle' onclick=\"NavDay('-1');\" />&nbsp;
					<select id='curday' onchange=\"NavDay(this.value-1)\">$dayopts</select>&nbsp;
					<img src='".STM_URL."images/right.png' id='nextday2' title='Scroll to Next Day' class='iconlink' align='absmiddle' onclick=\"NavDay('+1');\" />&nbsp;&nbsp;&nbsp;&nbsp;
					<img src='".STM_URL."images/zoom_out.png' id='zoimage' title='Zoom Out' class='iconlink' align='absmiddle' onclick=\"TimelineZoomOut();\" />&nbsp;
					<img src='".STM_URL."images/zoom_in.png' id='ziimage' title='Zoom In' class='iconlink' align='absmiddle' onclick=\"TimelineZoomIn();\" />&nbsp;&nbsp;&nbsp;&nbsp;
					<input type='button' class='button-secondary' value='Distribute' onclick=\"AjaxPopSP('distribute&numdays=$numdays');\" />&nbsp;&nbsp;&nbsp;&nbsp;
					<img src='".STM_URL."images/gear.png' title='TimeLine Settings' class='iconlink' align='absmiddle' onclick=\"AjaxPopSP('tlsettings');\" />
				</div>
				<div class='clear'></div>
			</div>
			<form method='post' name='frmtimline' id='frmtimline'>
				<input type='hidden' name='lttime' id='lttime' value='0' />
				<input type='hidden' name='starttm' id='starttm' value='$starttm' />
				<input type='hidden' name='endtm' id='endtm' value='$endtm' />
				<input type='hidden' id='stmurl' value='".STM_URL."' />
				<div id='timelinebox' onscroll=\"TLBScroll();\"$tlh>
					<div id='timeline' style='height: $lineheight;'>
						<div class='line' id='line' style='height: $lineheight;' onmouseover='ShowLinetime(event, 1);' onmouseout='ShowLinetime(event, 0);' onmousemove='LinetimePos(event);' ondblclick=\"AjaxPopSP('addtotimeline');\"></div>
						$days
						<div id='prefsbox'>$prefs</div>
						$posts
						<div id='linetime'><span></span>20:00</div>
					</div>
				</div>
				</form>
			</div>
			<script type='text/javascript'>
				window.scrollTo(0,0);
				window.onresize = ResizeTLBox;
				document.onscroll = function(){window.scrollTo(0,0);};
				pxpersec = $pxpersec;
				starttm = $starttm;
				tlzoom = 5;
				numdays = $numdays;
				usertmoffset = $tmoffs;
				tlheight = $tlheight;
				globalnoanim = $noanim;
				jQuery(document).ready(function() {
					ResizeTLBox();
					InPreferredAll();
					$dozoom
					jQuery('#tldate').datepicker({ changeMonth: true, changeYear: true, dateFormat: 'dd.mm.yy', defaultDate: '$tldate', dayNames: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], dayNamesMin: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'], firstDay: 1, monthNamesShort: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] });
				});
			</script>
		".$this->Footer(0);
		echo $html;
	}

	function ScheduledPostings() {
		global $wpdb;
		if (isset($_GET['addpost'])) {
			if ($_GET['addpost'] == 1) return $this->AddExtPost();
			if ($_GET['addpost'] == 2) return $this->BulkAddURLs();
			if ($_GET['addpost'] == 3) return $this->AddFromRSS();
			if ($_GET['addpost'] == 4) return $this->BulkSchedule();
		}
		if (isset($_GET['del'])) {
			$this->DelSchedule($_GET['del']);
			$_GET['msg'] = 'del';
		}
        if (isset($_POST['DoDelete'])) {
            foreach ($_POST['godel'] as $id) $this->DelSchedule($id);
            die("<META HTTP-EQUIV='Refresh' Content='0; URL=admin.php?page=stm-scheduled&msg=dels' />");
        }		
		$html = $this->TopMsg()."
			<div class='wrap' style='position: relative;'>
				<div id='extsub' onmouseover=\"clearTimeout(subtimer);\" onmouseout=\"ShowDiv('extsub')\">
					<a href='admin.php?page=stm-scheduled&addpost=1'>Add Manually</a>
					<a href='admin.php?page=stm-scheduled&addpost=2'>Bulk Add URLs</a>
					<a href='admin.php?page=stm-scheduled&addpost=3'>Add from RSS</a>
				</div>
				<div id='icon-edit-pages' class='icon32'></div>
				<h2><img src='".STM_URL."images/logofull.png' align='absmiddle' class='logo' /> &nbsp; Scheduled Posts 
					&nbsp;<a href='#' class='button add-new-h2' style='background: #2ea2cc; border-color: #0074a2; color: #FFF; box-shadow: inset 0 1px 0 rgba(120,200,230,.5),0 1px 0 rgba(0,0,0,.15);' onclick=\"ShowDiv('extsub')\">Schedule More</a>
					&nbsp;<a href='admin.php?page=stm' class='button add-new-h2'>Timeline</a>
					&nbsp;<a href='admin.php?page=stm-postlog' class='button add-new-h2'>Post Log</a>
					&nbsp;<a href='admin.php?page=stm-accounts' class='button add-new-h2'>Social Accounts</a>
					&nbsp;<a href='admin.php?page=stm-templates' class='button add-new-h2'>Schedule Templates</a>
					&nbsp;<a href='admin.php?page=stm-settings' class='button add-new-h2'>Settings</a>
				</h2><br />
				<div class='clear'></div>
				<div id='resmsg'></div>
		";
		$qprop = $this->slPrepareQuerry('schedule', 'nextposttime', 'asc');
		$data = $wpdb->get_results($wpdb->prepare("SELECT s.*, post_title FROM {$wpdb->prefix}stm_schedule as s LEFT JOIN {$wpdb->prefix}posts as p ON p.id=s.postid WHERE (postid>0) OR (variationid>0) ORDER BY %s %s LIMIT %d, %d;", $qprop['order'], $qprop['dir'], $qprop['start'], $qprop['limit']));
		if (!count($data)) {
			echo $html."There are no postings scheduled yet!<br />You can start by <a href='admin.php?page=stm-templates&sub=template'>creating a schedule template</a>, and then use it when you edit the blog posts.<br />You can also use the button \"Schedule More\" above.</div>".$this->Footer();
			return '';
		}
		$msg = '';
        if (isset($_GET['msg'])) {
            if ($_GET['msg'] == 'del') $msg = 'Posting Deleted!';
            if ($_GET['msg'] == 'dels') $msg = 'Posintg(s) Deleted!';
        }
		if ($msg) $msg = $this->Msg_OK($msg);
		$html .= "
			$msg
			<form method='post'>
			<input type='hidden' name='limit' id='limit' value='$qprop[limit]' />
			<table class='wp-list-table widefat fixed pages' cellspacing='0' style='width: 95%; margin: 10px 0 7px 0;'>
				<thead><tr>
					<th scope='col' class='manage-column column-cb check-column'><input type='checkbox' /></th>
					<th scope='col' class='manage-column'>Post, Variation Title</th>
					<th scope='col' class='manage-column' style='width: 240px;'>Social Account</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 60px;'>Variation</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 140px;'>Post Time</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 80px;'>Repeat</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 80px;'>Repeat Freq.</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 50px;'>&nbsp;</th>
				</tr></thead>
				<tfoot><tr>
					<th scope='col' class='manage-column column-cb check-column'><input type='checkbox' /></th>
					<th scope='col' class='manage-column'>Post, Variation Title</th>
					<th scope='col' class='manage-column' style='width: 240px;'>Social Account</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 60px;'>Variation</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 140px;'>Post Time</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 80px;'>Repeat</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 80px;'>Repeat Freq.</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 50px;'>&nbsp;</th>
				</tr></tfoot>
				<tbody>
		";
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			if ($info['postid']) {
				if ($info['numvar'] == 0) $subt = '';
				if ($info['numvar'] == 200) $info['numvar'] = $info['rotatenum'];
				if ($info['numvar'] == 201) {
					$maxnumvars = $wpdb->get_var($wpdb->prepare("SELECT MAX(numvar) FROM {$wpdb->prefix}stm_variations WHERE (postid=%d) AND (numvar<200);", $info['postid']));
					$info['numvar'] = rand(0, $maxnumvars);
				}
				$subt = $wpdb->get_var($wpdb->prepare("SELECT title FROM {$wpdb->prefix}stm_variations WHERE (postid=%d) AND (numvar=%d)", $info['postid'], $info['numvar']));
				if ($subt) $subt = ' - '.$subt;
				if ($info['numvar'] == 0) $info['numvar'] = 'O';
			}
			else {
				$info['numvar'] = '-';
				$info['post_title'] = $wpdb->get_var($wpdb->prepare("SELECT title FROM {$wpdb->prefix}stm_variations WHERE id=%d", $info['variationid']));
				$subt = '';
			}
			$stub = $wpdb->get_results($wpdb->prepare("SELECT atype, username FROM {$wpdb->prefix}stm_accounts WHERE id=%d;", $info['accountid']));
			if (!count($stub)) continue;
			$ainfo = (array)$stub[0];
			$acc = ucfirst($ainfo['atype']).' - '.$ainfo['username'];
			$info['nextposttime'] = $info['nextposttime']+$this->UserOffset();
			$info['nextposttime'] = date(get_option('date_format'), $info['nextposttime']).', '.date(get_option('time_format'), $info['nextposttime']);
			$rep = $info['repcount']?$info['repdone'].'/'.$info['repcount']:'-';
			$edlnk = $info['postid']?"post.php?post=$info[postid]&action=edit":"admin.php?page=stm-scheduled&addpost=1&varid=$info[variationid]";
			$repfreq = '-';
			if ($rep != '-') {
				if ($info['reptype'] == 'm') $info['reptype'] = 'minutes';
				if ($info['reptype'] == 'h') $info['reptype'] = 'hours';
				if ($info['reptype'] == 'd') $info['reptype'] = 'days';
				$repfreq = "$info[repnum] $info[reptype]";
			}
			$html .= "
				<tr id='spost_$info[id]'>
					<td scope='row' class='check-column' style='text-align: center;'><input type='checkbox' name='godel[]' value='$info[id]' /></td>
					<td><a href='$edlnk' target='_blank'>$info[post_title]$subt</a></td>
					<td>$acc</td>
					<td style='text-align: center;'>$info[numvar]</td>
					<td style='text-align: center;' id='nextptime_$info[id]'>$info[nextposttime]</td>
					<td style='text-align: center;' id='prep_$info[id]'>$rep</td>
					<td style='text-align: center;' id='prep_$info[id]'>$repfreq</td>
					<td style='text-align: center;'>
						<a href='admin.php?page=stm-scheduled&del=$info[id]'><img src='".STM_URL."images/delete.png' align='absmiddle' title='Delete this social posting' class='icon' /></a>
					</td>
				</tr>
			";
		}
		$numrecs = $wpdb->get_var("SELECT COUNT(id) FROM {$wpdb->prefix}stm_schedule WHERE (postid>0) OR (variationid>0);");
		$html .= "
				</tbody>
			</table>
			<div style='width: 95%;'>
				<div style='float: left;'>
					<input class='button-secondary' type='submit' name='DoDelete' value='Delete Selected' />
				</div>
				<div style='float: right;'>".$this->slPagesLinks($numrecs, $qprop['start'], $qprop['limit'], "admin.php?page=stm-scheduled", 1) . "</div>
			</div>
			<div class='clear'></div>
			</form>
		";
		$html .= $this->Footer().'</div>';
		echo $html;
	}


	function BuildTimeLine($q) {
		global $wpdb;
		$numposts = 0;
		$data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stm_schedule WHERE $q;");
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_timeline WHERE scheduleid=%d;", $info['id']));
			if ($info['reptype'] == 'm') $repmult = 60;
			if ($info['reptype'] == 'h') $repmult = 3600;
			if ($info['reptype'] == 'd') $repmult = 24*3600;
			$counter = 0;
			$posttime = 0;
			$rotatenum = 0;
			if ($info['postid']) $starttime = get_post_meta($info['postid'], 'stmstarttime', true);
			elseif ($info['variationid']) {
				$variationid = $info['variationid'];
				$starttime = $wpdb->get_var($wpdb->prepare("SELECT starttm FROM {$wpdb->prefix}stm_variations WHERE id=%d;", $variationid));
			}
			else continue;
			while (1) {
				if ($info['postid']) {
					if ($info['numvar'] > 199) {
						$numvars = $wpdb->get_var($wpdb->prepare("SELECT MAX(numvar) FROM {$wpdb->prefix}stm_variations WHERE (postid=%d) AND (numvar<200);", $info['postid']));
						if ($info['numvar'] == 200) { //rotate
							$rotatenum++;
							if ($rotatenum > $numvars) $rotatenum = 0;
						}
						if ($info['numvar'] == 201) { //randomize
							$rotatenum = rand(1, $numvars);
						}
					}
					else $rotatenum = $info['numvar'];
					$variationid = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_variations WHERE (postid=%d) AND (numvar=%d);", $info['postid'], $rotatenum));
					if (!$variationid) $variationid = 0;
				}
				if ($counter == 0) { // the rist post
					if ($info['inttype'] == 'm') $mult = 60;
					if ($info['inttype'] == 'h') $mult = 3600;
					if ($info['inttype'] == 'd') $mult = 24*3600;
					$intsec = $info['intnum']*$mult;
					$posttime = $starttime+$intsec;
					$repcount = $info['repcount'];
				}
				else { //repeats
					$posttime += $info['repnum']*$repmult;
					$repcount--;
				}
				$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_timeline (scheduleid, variationid, posttime, accountid) VALUES (%d, %d, %d, %d);", $info['id'], $variationid, $posttime, $info['accountid']));
				$counter++;
				if (!$repcount) break;
			}
			$numposts += $counter;
			$nextposttime = $wpdb->get_var($wpdb->prepare("SELECT posttime FROM {$wpdb->prefix}stm_timeline WHERE scheduleid=%d ORDER BY posttime ASC LIMIT 0,1;", $info['id']));
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_schedule SET nextposttime=%d WHERE id=%d;", $nextposttime, $info['id']));
		}
		return $numposts;
	}


	function ExtractURLMetas($url) {
		$url = trim($url);
		$title = '';
		$content = '';
		$imgurl = '';
		$tags = get_meta_tags($url);
		if (isset($tags['twitter:title'])) $title = $tags['twitter:title'];
		elseif (isset($tags['og:title'])) $title = $tags['og:title'];
		if (isset($tags['twitter:description'])) $content = $tags['twitter:description'];
		elseif (isset($tags['og:description'])) $content = $tags['og:description'];
		if (!$title) {
			$ucontent = file_get_contents($url);
			$ucontentl = strtolower($ucontent);
			$pos1 = strpos($ucontentl, '<title>')+7;
			$pos2 = strpos($ucontentl, '</title>');
			if ($pos1 && $pos2) $title = substr($ucontent, $pos1, $pos2-$pos1);
		}
		if (!$title) if (isset($tags['description'])) $title = $tags['description'];
		if (!$title) return false;
		if (isset($tags['twitter:image:src'])) $imgurl = $tags['twitter:image:src'];
		elseif (isset($tags['og:image'])) $imgurl = $tags['og:image'];
		if (!$content) {
			if (strlen($title) < 120) $content = $title.' [url]';
			else $content = '[url] '.$title;
		}
		return array('title'=>$title, 'content'=>$content, 'imgurl'=>$imgurl);
	}



	function BulkAddURLs() {
		global $wpdb;
		$variationid = isset($_GET['varid'])?$_GET['varid']:0;
		if (isset($_POST['goAdd'])) {
			if ($_POST['stype'] == 1) {
				$inttype = $_POST['inttype2'];
				$ifrom = $_POST['intfrom2'];
				$ito = $_POST['intto2'];
				$d = explode('.', $_POST['stmdate2']);
				$h = explode(':', $_POST['stmhour2']);
			}
			else {
				$inttype = $_POST['inttype'];
				$ifrom = $_POST['intfrom'];
				$ito = $_POST['intto'];
				$d = explode('.', $_POST['stmdate']);
				$h = explode(':', $_POST['stmhour']);
			}
			$starttm = mktime($h[0], $h[1], 0, $d[1], $d[0], $d[2]);
			$starttm -= $this->UserOffset();
			foreach ($_POST as $k=>$v) $_POST[$k] = addslashes($v);
			$urls = explode("\n", $_POST['urls']);
			$i = 1;
			if ($inttype == 'm') $mult = 60;
			if ($inttype == 'h') $mult = 3600;
			if ($inttype == 'd') $mult = 24*3600;
			$accountid = $_POST['accountid'];
			$nexttm = $starttm;
			$numposts = 0;
			$intnum = 0;
			$intsec = 0;
			$accarr = array();
			foreach ($urls as $url) if ($url = trim($url)) {
				if (!$metas = $this->ExtractURLMetas($url)) continue;
				$title = addslashes($metas['title']);
				$content = addslashes($metas['content']);
				$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_variations (title, content, url, imgurl, starttm) VALUES (%s, %s, %s, %s, %d);", $title, $content, $url, $metas['imgurl'], $nexttm));
				$variationid = $wpdb->insert_id;
				if ($_POST['stype'] == 1) { //TimeBack
					$data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_schedule WHERE templid=%d ORDER BY numschedule", $_POST['templid']));
					foreach ($data as $k=>$info) {
						$info = (array)$info;
						$isec = $intsec + $info['intsec'];
						$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_schedule (variationid, numschedule, numvar, intnum, inttype, repcount, repdone, repnum, reptype, intsec, repsec, accountid) VALUES (%d, %d, $d, %d, %s, %d, %d, %d, %s, %d, %d, %d);", $variationid, $info['numschedule'], $info['numvar'], $info['intnum'], $info['inttype'], $info['repcount'], $info['repdone'], $info['repnum'], $info['reptype'], $isec, $info['repsec'], $info['accountid']));
						if (!in_array($info['accountid'], $accarr)) $accarr[] = $info['accountid'];
					}
				}
				if ($_POST['stype'] == 2) {
					$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_schedule (variationid, numschedule, inttype, intnum, accountid, intsec) VALUES (%d, %d, %s, %d, %d, %d);", $variationid, $i, $inttype, $intnum,  $accountid, $intsec));
					if (!in_array($accountid, $accarr)) $accarr[] = $accountid;
				}
				$numposts += $this->BuildTimeLine("variationid=$variationid");
				$intnum = rand($ifrom, $ito);
				$intsec = $intnum*$mult;
				$nexttm += $intsec;
				$i++;
			}
			$i--;
			$numacc = count($accarr);
			echo "
				<div class='wrap'>
					<div id='icon-edit-pages' class='icon32'></div>
					<h2><img src='".STM_URL."images/logofull.png' align='absmiddle' class='logo' /> &nbsp; Bulk Add URLs</h2>
					$i URLs added. $numposts social posts scheduled across $numacc social accounts.
				</div>
			";
			return ;
		}
		$dateformat = 'd.m.Y';
		$timeformat = 'H:i';
		$tm = $this->UserTime();
		$stmdate = date($dateformat, $tm);
		$stmhour = date($timeformat, $tm);
		$html = $this->TopMsg()."
			<div class='wrap'>
				<div id='icon-edit-pages' class='icon32'></div>
				<h2><img src='".STM_URL."images/logofull.png' align='absmiddle' class='logo' /> &nbsp; Bulk Add URLs
					&nbsp;<a href='admin.php?page=stm' class='button add-new-h2'>Timeline</a>
					&nbsp;<a href='admin.php?page=stm-scheduled' class='button add-new-h2'>Scheduled Posts</a>
					&nbsp;<a href='admin.php?page=stm-postlog' class='button add-new-h2'>Post Log</a>
					&nbsp;<a href='admin.php?page=stm-accounts' class='button add-new-h2'>Social Accounts</a>
					&nbsp;<a href='admin.php?page=stm-templates' class='button add-new-h2'>Schedule Templates</a>
					&nbsp;<a href='admin.php?page=stm-settings' class='button add-new-h2'>Settings</a>
				</h2><br />
				Here you can schedule social sharing for external pages/URLs.<br />
				The posts and pages from your blog can be scheduled on the post edit page.<br />
				<div class='clear'></div>
				<div id='resmsg'></div>
				<form method='post'>
					<h3>Post Details</h3>
					URLs (one URL per line):<br />
					<textarea style='width: 80%;' name='urls' rows='8'></textarea>
					<h3>Schedule Sharing</h3>
					<input type='radio' name='stype' id='stype1' value='1' checked onclick=\"document.getElementById('opt1').style.display='block';document.getElementById('opt2').style.display='none';\" /> <label for='stype1'>TimeBack Schedule</label> <a href='http://wiziva.com/user/pluginhelp.html?id=".STM_WIZIVA_ID."&sub=29' target='_blank'><img src='".STM_URL."images/help.png' class='iconlink' align='absmiddle' title='click to read about TimeBack Schedule' /></a> &nbsp;&nbsp;
					<input type='radio' name='stype' id='stype2' value='2' onclick=\"document.getElementById('opt1').style.display='none';document.getElementById('opt2').style.display='block';\" /> <label for='stype2'>Spread the postings across specified time interval </label><br /><br />
					<div id='opt1'>
						<table class='frm'>
							<tr><td>Start Time:</td><td><input type='text' name='stmdate2' id='stmdate2' value='$stmdate' style='width: 100px;' /> <input type='text' name='stmhour2' id='stmhour2' value='$stmhour' style='width: 60px;' /></td></tr>
							<tr><td>Template:</td><td>".$this->SelTemplate('templid')."</td></tr>
							<tr><td>Interval Between Posts:</td><td><input type='text' name='intfrom2' value='1' style='width: 40px;' /> to <input type='text' name='intto2' value='2' style='width: 40px;' /> ".$this->SelIntType('inttype2', 'h')." (random)</td></tr>
						</table>
					</div>
					<div id='opt2' style='display: none;'>
						<table class='frm'>
							<tr><td>Account:</td><td>".$this->SelAccount('accountid', 0)."</td></tr>
							<tr><td>Start Time:</td><td><input type='text' name='stmdate' id='stmdate' value='$stmdate' style='width: 100px;' /> <input type='text' name='stmhour' id='stmhour' value='$stmhour' style='width: 60px;' /></td></tr>
							<tr><td>Interval Between Posts:</td><td><input type='text' name='intfrom' value='1' style='width: 40px;' /> to <input type='text' name='intto' value='2' style='width: 40px;' /> ".$this->SelIntType('inttype', 'h')." (random)</td></tr>
						</table>
					</div>
					<br /><input class='button-primary' type='submit' name='goAdd' value='Submit' /><br /><br />
					<strong>Please wait after you click the button.</strong><br />
					The operation may take up to few minutes (depending on the number of the URLs you're importing).<br />
				</form>
			</div>
			<script type='text/javascript'>	
				jQuery(document).ready(function() {
					jQuery('#stmdate').datepicker({ changeMonth: true, changeYear: true, dateFormat: 'dd.mm.yy', defaultDate: '$stmdate', dayNames: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], dayNamesMin: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'], firstDay: 1, monthNamesShort: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] });
					jQuery('#stmdate2').datepicker({ changeMonth: true, changeYear: true, dateFormat: 'dd.mm.yy', defaultDate: '$stmdate', dayNames: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], dayNamesMin: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'], firstDay: 1, monthNamesShort: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] });
					jQuery('#stmhour').timepicker();
					jQuery('#stmhour2').timepicker();
				});
			</script>
		";
		echo $html;
	}


	function AddFromRSS() {
		global $wpdb;
		$variationid = isset($_GET['varid'])?$_GET['varid']:0;
		if (isset($_POST['goAdd'])) {
			if ($_POST['stype'] == 1) {
				$inttype = $_POST['inttype2'];
				$ifrom = $_POST['intfrom2'];
				$ito = $_POST['intto2'];
				$d = explode('.', $_POST['stmdate2']);
				$h = explode(':', $_POST['stmhour2']);
			}
			else {
				$inttype = $_POST['inttype'];
				$ifrom = $_POST['intfrom'];
				$ito = $_POST['intto'];
				$d = explode('.', $_POST['stmdate']);
				$h = explode(':', $_POST['stmhour']);
			}
			$starttm = mktime($h[0], $h[1], 0, $d[1], $d[0], $d[2]);
			$starttm -= $this->UserOffset();
			foreach ($_POST as $k=>$v) $_POST[$k] = addslashes($v);
			if ($inttype == 'm') $mult = 60;
			if ($inttype == 'h') $mult = 3600;
			if ($inttype == 'd') $mult = 24*3600;
			$accountid = $_POST['accountid'];
			$nexttm = $starttm;
			$numposts = 0;
			$intnum = 0;
			$intsec = 0;
			$content = file_get_contents($_POST['rss']);
			$content = explode('<item>', $content);
			array_shift($content);
			if (isset($_POST['doimport'])) $limit = $_POST['impsize'];
			else $limit = 0;
			$i = 1;
			if (($_POST['stype'] == 1) && ($_POST['ityp'] == 1)) {
				$mintime = 1000000000000;
				foreach ($content as $item) {
					$ptime = StrToTime($this->GetBetweenTags($item, '<pubDate>', '</pubDate>'));
					if ($ptime < $mintime) $mintime = $ptime;
					$i++;
					if ($limit && ($i>$limit)) break;
				}
			}
			$i = 1;
			$accarr = array();
			foreach ($content as $item) {
				$url = $this->GetBetweenTags($item, '<link>', '</link>');
				$title = $this->GetBetweenTags($item, '<title>', '</title>');
				$title = strip_tags($title);
				if (isset($_POST['dousetitle'])) $content =  $title;
				elseif (strpos(' '.$item, '<content:encoded>')) $content = strip_tags($this->RemCDATA($this->GetBetweenTags($item, '<content:encoded>', '</content:encoded>')));
				elseif (strpos(' '.$item, '<description>')) $content = strip_tags($this->GetBetweenTags($item, '<description>', '</description>'));
				if (isset($_POST['dolimit']) && (strlen($content) > $_POST['limitsize'])) $content = $this->LimitLength($content, $_POST['limitsize']);
				if (strlen($content) < 120) $content = $content.' [url]';
				else $content = '[url] '.$content;
				$content = addslashes($content);
				if (($_POST['stype'] == 1) && ($_POST['ityp'] == 1)) {
					$ptime = StrToTime($this->GetBetweenTags($item, '<pubDate>', '</pubDate>'));
					$intsec = $ptime - $mintime;
					$nexttm = $starttm + $intsec;
				}
				$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_variations (title, content, url, starttm) VALUES (%s, %s, %s, %d);", $title, $content, $url, $nexttm));
				$variationid = $wpdb->insert_id;
				if ($_POST['stype'] == 1) { //TimeBack
					$data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_schedule WHERE templid=%d ORDER BY numschedule;", $_POST['templid']));
					foreach ($data as $k=>$info) {
						$info = (array)$info;
						$isec = $intsec + $info['intsec'];
						$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_schedule (variationid, numschedule, numvar, intnum, inttype, repcount, repdone, repnum, reptype, intsec, repsec, accountid) VALUES (%d, %d, %d, %d, %s, %d, %d, %d, %s, %d, %d, %d);", $variationid, $info['numschedule'], $info['numvar'], $info['intnum'], $info['inttype'], $info['repcount'], $info['repdone'], $info['repnum'], $info['reptype'], $isec, $info['repsec'], $info['accountid']));
						if (!in_array($info['accountid'], $accarr)) $accarr[] = $info['accountid'];
					}
				}
				if ($_POST['stype'] == 2) {
					$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_schedule (variationid, numschedule, inttype, intnum, accountid, intsec) VALUES (%d, %d, %s, %d,  %d, %d);", $variationid, $i, $inttype, $intnum,  $accountid, $intsec));
					if (!in_array($accountid, $accarr)) $accarr[] = $accountid;
				}
				$numposts += $this->BuildTimeLine("variationid=$variationid");
				if (($_POST['stype'] == 2) || ($_POST['ityp'] == 2)) {
					$intnum = rand($ifrom, $ito);
					$intsec = $intnum*$mult;
					$nexttm += $intsec;
				}
				$i++;
				if ($limit && ($i>$limit)) break;
			}
			$i--;
			$numacc = count($accarr);
			echo "
				<div class='wrap'>
					<div id='icon-edit-pages' class='icon32'></div>
					<h2><img src='".STM_URL."images/logofull.png' align='absmiddle' class='logo' /> &nbsp; Add URLs from RSS</h2>
					$i URLs added. $numposts social posts scheduled across $numacc social accounts.
				</div>
			";
			return ;
		}
		$dateformat = 'd.m.Y';
		$timeformat = 'H:i';
		$tm = $this->UserTime();
		$stmdate = date($dateformat, $tm);
		$stmhour = date($timeformat, $tm);
		$html = $this->TopMsg()."
			<div class='wrap'>
				<div id='icon-edit-pages' class='icon32'></div>
				<h2><img src='".STM_URL."images/logofull.png' align='absmiddle' class='logo' /> &nbsp; Add URLs from RSS
					&nbsp;<a href='admin.php?page=stm' class='button add-new-h2'>Timeline</a>
					&nbsp;<a href='admin.php?page=stm-scheduled' class='button add-new-h2'>Scheduled Posts</a>
					&nbsp;<a href='admin.php?page=stm-postlog' class='button add-new-h2'>Post Log</a>
					&nbsp;<a href='admin.php?page=stm-accounts' class='button add-new-h2'>Social Accounts</a>
					&nbsp;<a href='admin.php?page=stm-templates' class='button add-new-h2'>Schedule Templates</a>
					&nbsp;<a href='admin.php?page=stm-settings' class='button add-new-h2'>Settings</a>
				</h2><br />
				Here you can schedule social sharing for external pages/URLs.<br />
				The posts and pages from your blog can be scheduled on the post edit page.<br /><br />
				<div class='clear'></div>
				<div id='resmsg'></div>
				<form method='post'>
					RSS 2.0 URL: <input type='text' name='rss' value='' style='width: 400px;' /><br /><br />
					<input type='checkbox' name='dousetitle' id='dousetitle' value='1' /> <label for='dousetitle'>Use the title for content</label><br />
					<input type='checkbox' name='dolimit' id='dolimit' value='1' /> <label for='dolimit'>Limit the content length to </label><input type='text' name='limitsize' style='width: 50px;' value='120' /> characters<br />
					<input type='checkbox' name='doimport' id='doimport' value='1' /> <label for='doimport'>Import only the first </label><input type='text' name='impsize' style='width: 50px;' value='5' /> posts<br />
					<h3>Schedule Sharing</h3>
					<input type='radio' name='stype' id='stype1' value='1' checked onclick=\"document.getElementById('opt1').style.display='block';document.getElementById('opt2').style.display='none';\" /> <label for='stype1'>TimeBack Schedule</label> <a href='http://wiziva.com/user/pluginhelp.html?id=".STM_WIZIVA_ID."&sub=29' target='_blank'><img src='".STM_URL."images/help.png' class='iconlink' align='absmiddle' title='click to read about TimeBack Schedule' /></a> &nbsp;&nbsp;
					<input type='radio' name='stype' id='stype2' value='2' onclick=\"document.getElementById('opt1').style.display='none';document.getElementById('opt2').style.display='block';\" /> <label for='stype2'>Spread the postings across specified time interval </label><br /><br />
					<div id='opt1'>
						<table class='frm'>
							<tr><td>Start Time:</td><td><input type='text' name='stmdate2' id='stmdate2' value='$stmdate' style='width: 100px;' /> <input type='text' name='stmhour2' id='stmhour2' value='$stmhour' style='width: 60px;' /></td></tr>
							<tr><td>Template:</td><td>".$this->SelTemplate('templid')."</td></tr>
							<tr><td valign='top'>Interval Between Posts:</td><td>
								<input type='radio' name='ityp' id='ityp1' value='1' checked onclick=\"document.getElementById('opt').style.display='none';\" /> <label for='ityp1'>Use the Publish Date from the feed</label><br />
								<input type='radio' name='ityp' id='ityp2' value='2' onclick=\"document.getElementById('opt').style.display='block';\" /> <label for='ityp2'>Random Interval</label>
								<div id='opt' style='display: none;'>
									<input type='text' name='intfrom2' value='1' style='width: 40px;' /> to <input type='text' name='intto2' value='2' style='width: 40px;' /> ".$this->SelIntType('inttype2', 'h')." (random)
								</div>
							</td></tr>
						</table>
					</div>
					<div id='opt2' style='display: none;'>
						<table class='frm'>
							<tr><td>Account:</td><td>".$this->SelAccount('accountid', 0)."</td></tr>
							<tr><td>Start Time:</td><td><input type='text' name='stmdate' id='stmdate' value='$stmdate' style='width: 100px;' /> <input type='text' name='stmhour' id='stmhour' value='$stmhour' style='width: 60px;' /></td></tr>
							<tr><td>Interval Between Posts:</td><td><input type='text' name='intfrom' value='1' style='width: 40px;' /> to <input type='text' name='intto' value='2' style='width: 40px;' /> ".$this->SelIntType('inttype', 'h')." (random)</td></tr>
						</table>
					</div>
					<br /><input class='button-primary' type='submit' name='goAdd' value='Submit' /><br /><br />
					<strong>Please wait after you click the button.</strong><br />
					The operation may take up to few minutes (depending on the number of the URLs you're importing).<br />
				</form>
			</div>
			<script type='text/javascript'>	
				jQuery(document).ready(function() {
					jQuery('#stmdate').datepicker({ changeMonth: true, changeYear: true, dateFormat: 'dd.mm.yy', defaultDate: '$stmdate', dayNames: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], dayNamesMin: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'], firstDay: 1, monthNamesShort: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] });
					jQuery('#stmhour').timepicker();
				});
			</script>
		";
		echo $html;
	}


	function AddExtPost() {
		global $wpdb;
		$variationid = isset($_GET['varid'])?$_GET['varid']:0;
		if (isset($_POST['goAdd'])) {
			$d = explode('.', $_POST['stmdate']);
			$h = explode(':', $_POST['stmhour']);
			$starttm = mktime($h[0], $h[1], 0, $d[1], $d[0], $d[2]);
			$starttm -= $this->UserOffset();
			foreach ($_POST as $k=>$v) $_POST[$k] = addslashes($v);
			if (!$variationid) {
				if (!$_POST['title']) $_POST['title'] = 'No Title';
				$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_variations (title, content, imgurl, url, starttm) VALUES (%s, %s, %s, %s, %d);", $_POST['title'], $_POST['content'], $_POST['imgurl'], $_POST['url'], $starttm));
				$variationid = $wpdb->insert_id;
			}
			else $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_variations SET title=%s, content=%s, imgurl=%s, url=%s, starttm=$starttm WHERE id=%d;", $_POST['title'], $_POST['content'], $_POST['imgurl'], $_POST['url'], $variationid));
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_schedule SET fordel=1 WHERE variationid=%d;", $variationid));
			$i = 0;
			foreach ($_POST as $k=>$v) if (substr($k, 0, 7) == 'intnum_') {
				$i++;
				$id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_schedule WHERE (variationid=%d) AND (numschedule=%d);", $variationid, $i));
				if (!$id) {
					$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_schedule (variationid, numschedule) VALUES (%d, %d);", $variationid, $i));
					$id = $wpdb->insert_id;
				}
				$intnum = $_POST["intnum_$i"];
				$inttype = $_POST["inttype_$i"];
				$accountid = (int)$_POST["accountid_$i"];
				if (!isset($_POST["dorepeat_$i"])) {
					$_POST["repnum_$i"] = 0;
					$_POST["repcount_$i"] = 0;
				}
				$repnum = (int)$_POST["repnum_$i"];
				$repcount = (int)$_POST["repcount_$i"];
				$reptype = $_POST["reptype_$i"];
				if ($inttype == 'm') $intsec = 60*$intnum;
				if ($inttype == 'h') $intsec = 3600*$intnum;
				if ($inttype == 'd') $intsec = 24*3600*$intnum;
				if ($reptype == 'm') $repsec = 60*$repnum;
				if ($reptype == 'h') $repsec = 3600*$repnum;
				if ($reptype == 'd') $repsec = 24*3600*$repnum;
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_schedule SET intnum=%d, inttype=%s, accountid=%d, repcount=%d, repnum=%d, reptype=%s, intsec=%d, repsec=%d, fordel=0 WHERE id=%d;", $intnum, $inttype, $accountid, $repcount, $repnum, $reptype, $intsec, $repsec, $id));
			}
			$this->DelSchedules();
			$this->BuildTimeLine("variationid=$variationid");
		}
		else {
			if ($variationid) {
				$res = $wpdb->get_results($wpdb->prepare("SELECT title, content, imgurl, url, starttm FROM {$wpdb->prefix}stm_variations WHERE id=%d", $variationid));
				$info = (array)$res[0];
				foreach ($info as $k=>$v) $_POST[$k] = $v;
				$starttm = $info['starttm'] + $this->UserOffset();
			}
			else {
				$starttm = $this->UserTime();
				$_POST['title'] = '';
				$_POST['content'] = '';
				$_POST['url'] = '';
				$_POST['imgurl'] = '';
			}
		}
		$dateformat = 'd.m.Y';
		$timeformat = 'H:i';
		$stmdate = date($dateformat, $starttm);
		$stmhour = date($timeformat, $starttm);
		$html = $this->TopMsg()."
			<div class='wrap'>
				<div id='icon-edit-pages' class='icon32'></div>
				<h2><img src='".STM_URL."images/logofull.png' align='absmiddle' class='logo' /> &nbsp; Add External URL
					&nbsp;<a href='admin.php?page=stm' class='button add-new-h2'>Timeline</a>
					&nbsp;<a href='admin.php?page=stm-scheduled' class='button add-new-h2'>Scheduled Posts</a>
					&nbsp;<a href='admin.php?page=stm-postlog' class='button add-new-h2'>Post Log</a>
					&nbsp;<a href='admin.php?page=stm-accounts' class='button add-new-h2'>Social Accounts</a>
					&nbsp;<a href='admin.php?page=stm-templates' class='button add-new-h2'>Schedule Templates</a>
					&nbsp;<a href='admin.php?page=stm-settings' class='button add-new-h2'>Settings</a>
				</h2><br />
				Here you can schedule social sharing for external pages/URLs.<br />
				The posts and pages from your blog can be scheduled on the post edit page.<br />
				<div class='clear'></div>
				<div id='resmsg'></div>
				<form method='post'>
					<h3>Post Details</h3>
					<table class='frm'>
						<tr><td>Title:</td><td><input type='text' name='title' style='width: 540px;' value=\"$_POST[title]\" /></td><tr>
						<tr><td>URL:</td><td><input type='text' name='url' style='width: 540px;' value='$_POST[url]' /></td><tr>
						<tr><td valign='top'>Content:<br /><br /><span class='tacount' id='count'>0</span></td><td><textarea name='content' id='content' style='width: 540px; height: 60px;' onkeyup=\"jQuery('#count').html(jQuery(this).val().length);\">$_POST[content]</textarea><br /><small>put [url] where you'd like the URL to appear and <a href='admin.php?page=stm-settings' target='_blank'>integrate bit.ly</a> to be able to track click stats</small></td></tr>
						<tr><td>Image URL:</td><td><input type='text' name='imgurl' style='width: 540px;' value='$_POST[imgurl]' /></td><tr>
					</table><br />
					<h3>Schedule Sharing</h3>
					Start Time: <input type='text' name='stmdate' id='stmdate' value='$stmdate' style='width: 100px;' /> <input type='text' name='stmhour' id='stmhour' value='$stmhour' style='width: 60px;' />&nbsp;&nbsp;&nbsp;&nbsp;
					Load Template: ".$this->SelTemplate('templid')." <input type='button' class='button-primary' value='Load' onclick=\"AjaxActionSP('loadtemplate&postid=0&tmplid='+document.getElementById('templid').value);\" />
					<input type='button' class='button-primary' value='Manually Add' onclick=\"AddPostScheduleExt();\" />
					<div id='scheduling'>
						<div id='schedulingbox'>
							".$this->ExtScheduling($variationid)."
						</div>
					</div>
					<br /><br /><input class='button-primary' type='submit' name='goAdd' value='Submit' /><br /><br />
				</form>
			</div>
			<script type='text/javascript'>	
				jQuery(document).ready(function() {
					jQuery('#count').html(jQuery('#content').val().length);
					jQuery('#stmdate').datepicker({ changeMonth: true, changeYear: true, dateFormat: 'dd.mm.yy', defaultDate: '$stmdate', dayNames: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], dayNamesMin: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'], firstDay: 1, monthNamesShort: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] });
					jQuery('#stmhour').timepicker();
				});
			</script>
		";
		echo $html;
	}



	function ExtScheduling($variationid=0) {
		global $wpdb;
		if (!$variationid) return '';
		$i = 1;
		$html = '';
		$data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_schedule WHERE variationid=%d ORDER BY intsec", $variationid));
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			$ch = $info['repnum']?' checked':'';
			$disp = $info['repnum']?'inline':'none';
			if (!$info['repnum']) $info['repnum'] = 24;
			if (!$info['repcount']) $info['repcount'] = 5;
			$html .= "
				<div id='schbox_$i'>
					<h4>Schedule <span id='schnum_$i'>$i</span> <img src='".STM_URL."images/delete.png' align='absmiddle' class='icon' onclick=\"RemoveSchedule(this.parentElement.parentElement);\" /></h4>
					<table class='frm'>
						<tr>
							<td>Post In:</td><td><input type='text' name='intnum_$i' id='intnum_$i' style='width: 50px;' value='$info[intnum]' /> ".$this->SelIntType("inttype_$i", $info['inttype'])."</td>
							<td colspan='2' nowrap>
								<input type='checkbox' name='dorepeat_$i' id='dorepeat_$i' value='1'$ch onclick=\"if (this.checked) document.getElementById('repbox_$i').style.display='inline'; else document.getElementById('repbox_$i').style.display='none';\" /><label for='dorepeat_$i'>Repeat</label> 
								<span id='repbox_$i' style='display:$disp'> every <input type='text' name='repnum_$i' id='repnum_$i' style='width: 50px;' value='$info[repnum]' /> ".$this->SelIntType("reptype_$i", $info['reptype'])." for <input type='text' name='repcount_$i' id='repcount_$i' style='width: 50px;' value='$info[repcount]' /> times</span>
							</td>
						</tr>
						<tr>
							<td style='width: 60px;'>Account:</td><td colspan='2'>".$this->SelAccount("accountid_$i", $info['accountid'])."</td>
						</tr>
					</table>
				</div>
			";
			$i++;
		}
		return $html;
	}



	function DelAccount($accid) {
		global $wpdb;
		if (!$accid) return ;
		$data = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_schedule WHERE accountid=%d;", $accid));
		foreach ($data as $k=>$info) $this->DelSchedule($info->id);
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_accounts WHERE id=%d;", $accid));
	}


	function SocialAccounts() {
		global $wpdb;
		$msg = '';
		if (isset($_GET['del'])) {
			$this->DelAccount($_GET['del']);
			$_GET['msg'] = 'del';
		}
        if (isset($_POST['DoPause'])) {
            foreach ($_POST['godel'] as $id)
                $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_accounts SET paused=(NOT paused) WHERE id=%d;", $id));
		}
        if (isset($_POST['DoDelete']) && isset($_POST['godel'])) {
            foreach ($_POST['godel'] as $id) $this->DelAccount($id);
            die("<META HTTP-EQUIV='Refresh' Content='0; URL=admin.php?page=stm-accounts&msg=dels' />");
        }
        if (isset($_GET['msg'])) {
            if ($_GET['msg'] == 'del') $msg = 'Account Deleted!';
            if ($_GET['msg'] == 'dels') $msg = 'Account(s) Deleted!';
        }
		$accounts = $this->ListAccounts();
		if ($msg) $msg = $this->Msg_OK($msg);
		$html = $this->TopMsg('accounts')."
			<div class='wrap'>
				<div id='icon-edit-pages' class='icon32'></div>
				<h2><img src='".STM_URL."images/logofull.png' align='absmiddle' class='logo' /> &nbsp; Social Accounts
					&nbsp;<a href='#' onclick=\"AjaxPopSP('addaccount');\" class='button add-new-h2'>Add Account</a>
					&nbsp;<a href='admin.php?page=stm' class='button add-new-h2'>Timeline</a>
					&nbsp;<a href='admin.php?page=stm-scheduled' class='button add-new-h2'>Scheduled Posts</a>
					&nbsp;<a href='admin.php?page=stm-postlog' class='button add-new-h2'>Post Log</a>
					&nbsp;<a href='admin.php?page=stm-templates' class='button add-new-h2'>Schedule Templates</a>
					&nbsp;<a href='admin.php?page=stm-settings' class='button add-new-h2'>Settings</a>
				</h2><br />
				<div class='clear'></div>
				$msg
		";
		if (!$accounts) {
			echo $html."<div id='acclistbox'>There are no accounts added yet!<br /><a href='#' onclick=\"AjaxPopSP('addaccount');\">Add Account</a></div><div id='resmsg'></div><div class='spacer10'></div>".$this->Footer();
			return '';
		}
		$html .= "
				<div id='acclistbox'>$accounts</div>
				<div id='resmsg'></div>
				<div class='spacer10'></div>
			</div>
		".$this->Footer();
		echo $html;
	}

	function ListAccounts() {
		global $wpdb;
		$data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stm_accounts WHERE 1 ORDER BY username ASC;");
		if (!count($data)) return '';
		$html = "
			<form method='post'>
			<table class='wp-list-table widefat fixed pages' cellspacing='0' style='width: 900px; margin: 10px 0 7px 0;'>
				<thead><tr>
					<th scope='col' class='manage-column column-cb check-column'><input type='checkbox' /></th>
					<th scope='col' class='manage-column' style='text-align: center; width: 60px;'>Type</th>
					<th scope='col' class='manage-column'>Username</th>
					<th scope='col' class='manage-column' style='text-align: center;'>Scheduled<br />Posts</th>
					<th scope='col' class='manage-column' style='text-align: center;'>Preferred<br />Posting Time</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 110px;'>Authenticate</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 60px;'>Paused</th>
					<th scope='col' class='manage-column' style='text-align: center; width: 50px;'>&nbsp;</th>
				</tr></thead>
				<tfoot><tr>
					<th scope='col' class='manage-column column-cb check-column'><input type='checkbox' /></th>
					<th scope='col' class='manage-column' style='text-align: center;'>Type</th>
					<th scope='col' class='manage-column'>Username</th>
					<th scope='col' class='manage-column' style='text-align: center;'>Scheduled<br />Posts</th>
					<th scope='col' class='manage-column' style='text-align: center;'>Preferred<br />Posting Time</th>
					<th scope='col' class='manage-column' style='text-align: center;'>Authenticate</th>
					<th scope='col' class='manage-column' style='text-align: center;'>Paused</th>
					<th scope='col' class='manage-column' style='text-align: center;'>&nbsp;</th>
				</tr></tfoot>
				<tbody>
		";
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			$info['paused'] = $info['paused']?'Yes':'No';
			$auth = $info['auth3']?'[DONE]':"<span style='color: red;'>authenticate</span>";
			$authid = $info['parentid']?$info['parentid']:$info['id'];
			$auth = "<a href='admin.php?page=stm&doauth=$authid'>$auth</a>";
			$pend = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$wpdb->prefix}stm_timeline WHERE accountid=%d;", $info['id']));
			$ed = $info['parentid']?'':"<img src='".STM_URL."images/edit.gif' align='absmiddle' class='icon' onclick=\"AjaxPopSP('addaccount&id=$info[id]');\" />";
			$html .= "
				<tr>
					<td scope='row' class='check-column' style='text-align: center;'><input type='checkbox' name='godel[]' value='$info[id]' /></td>
					<td style='text-align: center;'><img src='".STM_URL."images/$info[atype].png' style='width: 16px; height: 16px;' /></td>
					<td>$info[username]</td>
					<td style='text-align: center;'>$pend</td>
					<td style='text-align: center;'>$info[prefstart] - $info[prefend]</td>
					<td style='text-align: center;'>$auth</td>
					<td style='text-align: center;'>$info[paused]</td>
					<td style='text-align: center;'>
						$ed
						<a href='admin.php?page=stm-accounts&del=$info[id]'><img src='".STM_URL."images/delete.png' align='absmiddle' class='icon' /></a>
					</td>
				</tr>
			";
		}
		$html .= "
				</tbody>
			</table>
			<input class='button-primary' type='button' name='goAdd' value='Add Account' onclick=\"AjaxPopSP('addaccount');\" />
			<input class='button-primary' type='submit' name='DoPause' value='Pause/Unpause Selected' />
			<input class='button-secondary' type='submit' name='DoDelete' value='Delete Selected' />
			</form>
		";
		return $html;
	}


	function EditTemplate() {
		global $wpdb;
		$templid = isset($_GET['id'])?$_GET['id']:0;
		$err = ''; $msg = '';
		if (isset($_GET['msg'])) {
			if ($_GET['msg'] == 'saved') $msg = 'Template updated!';
		}
		if (isset($_POST['dosave'])) {
			$newtempl = 0;
			$_POST['numvars'] = (int)$_POST['numvars'];
			$_POST['numschedules'] = (int)$_POST['numschedules'];
			if (!$_POST['title']) $err = 'Please enter title!';
			elseif ($_POST['numvars'] > 30) $err = 'You can not make more than 30 variations!';
			elseif ($_POST['numschedules'] > 50) $err = 'You can not make more than 50 variations!';
			else {
				if (!$templid) {
					$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_templates (title, numvars, numschedules) VALUES (%s, %d, %d);", $_POST['title'], $_POST['numvars'], $_POST['numschedules']));
					$templid = $wpdb->insert_id;
					$newtempl = 1;
				}
				else $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_templates SET title=%s, numvars=%d, numschedules=%d WHERE id=$templid;", $_POST['title'], $_POST['numvars'], $_POST['numschedules']));
				// save the schedules
				$numposts = 0;
				$accs = array();
				for ($i=1; $i<=$_POST['numschedules']; $i++) {
					$id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_schedule WHERE (templid=%d) AND (numschedule=%d)", $templid, $i));
					if (!$id) {
						$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}stm_schedule (templid, numschedule) VALUES (%d, %d);", $templid, $i));
						$id = $wpdb->insert_id;
					}
					if (isset($_POST["intnum_$i"])) {
						$intnum = $_POST["intnum_$i"];
						$inttype = $_POST["inttype_$i"];
						$accountid = (int)$_POST["accountid_$i"];
						$numvar = (int)$_POST["numvar_$i"];
						if (!isset($_POST["dorepeat_$i"])) {
							$_POST["repnum_$i"] = 0;
							$_POST["repcount_$i"] = 0;
						}
						$repnum = (int)$_POST["repnum_$i"];
						$repcount = (int)$_POST["repcount_$i"];
						$reptype = $_POST["reptype_$i"];
						if ($inttype == 'm') $intsec = 60*$intnum;
						if ($inttype == 'h') $intsec = 3600*$intnum;
						if ($inttype == 'd') $intsec = 24*3600*$intnum;
						if ($reptype == 'm') $repsec = 60*$repnum;
						if ($reptype == 'h') $repsec = 3600*$repnum;
						if ($reptype == 'd') $repsec = 24*3600*$repnum;
						if (!in_array($accountid, $accs)) $accs[] = $accountid;
						$numposts++;
						if ($repcount) $numposts += $repcount;
						$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_schedule SET intnum=%d, inttype=%s, accountid=%d, numvar=%d, repcount=%d, repnum=%d, reptype=%s, intsec=%d, repsec=%d WHERE id=%d;", $intnum, $inttype, $accountid, $numvar, $repcount, $repnum, $reptype, $intsec, $repsec, $id));
					}
				}
				$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_schedule WHERE (templid=%d) AND (numschedule>%d);", $templid, $_POST['numschedules']));
				$numaccounts = count($accs);
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_templates SET numaccounts=%d, numposts=%d WHERE id=%d;", $numaccounts, $numposts, $templid));
				// sort schedules by time
				$counter = 1;
				$data = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stm_schedule WHERE templid=%d ORDER BY intsec ASC;", $templid));
				foreach ($data as $k=>$info) {
					$info = (array)$info;
					$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}stm_schedule SET numschedule=%d WHERE id=%d;", $counter, $info['id']));
					$counter++;
				}
			}
			if ($newtempl) die("<META HTTP-EQUIV='Refresh' Content='0; URL=admin.php?page=stm-templates&sub=template&id=$templid&msg=saved'>");
			else $msg = 'Template updated!';
		}
		if ($err) $err = $this->Msg_Err($err);
		if ($msg) $msg = $this->Msg_OK($msg);
		if ($templid) {
			$t = 'Edit Template';
			if (!isset($_POST['dosave'])) {
				$data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_templates WHERE id=%d;", $templid));
				$info = (array)$data[0];
				foreach ($info as $k=>$v) $_POST[$k] = $v;
			}
		}
		else {
			$t = 'Add Template';
			if (!isset($_POST['dosave'])) {
				$_POST = array('title'=>'', 'numvars'=>5, 'numschedules'=>10);
			}
		}
		$html = $this->TopMsg()."
			<div class='wrap'>
				<div id='icon-edit-pages' class='icon32'></div>
				<h2><img src='".STM_URL."images/logofull.png' align='absmiddle' class='logo' /> &nbsp; $t
					&nbsp;<a href='admin.php?page=stm-templates' class='button add-new-h2'>Schedule Templates</a>
					&nbsp;<a href='admin.php?page=stm' class='button add-new-h2'>Timeline</a>
					&nbsp;<a href='admin.php?page=stm-scheduled' class='button add-new-h2'>Scheduled Posts</a>
					&nbsp;<a href='admin.php?page=stm-postlog' class='button add-new-h2'>Post Log</a>
					&nbsp;<a href='admin.php?page=stm-accounts' class='button add-new-h2'>Social Accounts</a>
					&nbsp;<a href='admin.php?page=stm-settings' class='button add-new-h2'>Settings</a>
				</h2>
				$err $msg<br />
				<div class='clear'></div>
				<form method='post'>
				<input type='hidden' name='dosave' value='1' />
				<input type='hidden' name='templid' id='templid' value='$templid' />
				<table class='frm'>
					<tr><td>Title:</td><td><input type='text' name='title' id='title' style='width: 200px;' value=\"$_POST[title]\" maxlength=30 /></td></tr>
					<tr><td>Number of Variations:</td><td><input type='text' name='numvars' id='numvars' style='width: 50px;' value='$_POST[numvars]' /></td></tr>
					<tr><td>Number of Schedules:</td><td><input type='text' name='numschedules' id='numschedules' style='width: 50px;' value='$_POST[numschedules]' /></td></tr>
				</table>
				<input class='button-primary' type='submit' name='goSave' value='Update Template' /><br /><br />
				<h2>Edit Schedules</h2>
		";
		for ($i=1; $i<=$_POST['numschedules']; $i++) {
			$data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stm_schedule WHERE (templid<>0) AND (templid=%d) AND (numschedule=%d);", $templid, $i));
			if (count($data)) {
				$info = (array)$data[0];
				foreach ($info as $k=>$v) $_POST[$k."_$i"] = $v;
			}
			if (!isset($_POST["intnum_$i"])) {
				$_POST["intnum_$i"] = 4;
				$_POST["inttype_$i"] = 'h';
				$_POST["accountid_$i"] = 'h';
				$_POST["numvar_$i"] = 0;
				$_POST["repcount_$i"] = 5;
				$_POST["repnum_$i"] = 0;
				$_POST["reptype_$i"] = 'h';
			}
			$intnum = $_POST["intnum_$i"];
			$repnum = $_POST["repnum_$i"];
			$repcount = $_POST["repcount_$i"];
			$ch = $_POST["repnum_$i"]?' checked':'';
			$disp = $_POST["repnum_$i"]?'inline':'none';
			if (!$repnum) $repnum = 24;
			if (!$repcount) $repcount = 5;
			$html .= "
				<br />
				<h4>Schedule $i</h4>
				<table class='frm'>
					<tr>
						<td>Post In:</td><td><input type='text' name='intnum_$i' style='width: 50px;' value='$intnum' /> ".$this->SelIntType("inttype_$i", $_POST["inttype_$i"])."</td>
						<td colspan='2' nowrap>
							<input type='checkbox' name='dorepeat_$i' id='dorepeat_$i' value='1'$ch onclick=\"if (this.checked) document.getElementById('repbox_$i').style.display='inline'; else document.getElementById('repbox_$i').style.display='none';\" /><label for='dorepeat_$i'>Repeat</label> 
							<span id='repbox_$i' style='display:$disp'> every <input type='text' name='repnum_$i' style='width: 50px;' value='$repnum' /> ".$this->SelIntType("reptype_$i", $_POST["reptype_$i"])." for <input type='text' name='repcount_$i' style='width: 50px;' value='$repcount' /> times</span>
						</td>
					</tr>
					<tr>
						<td style='width: 60px;'>Variation:</td><td>".$this->SelVariation("numvar_$i", $_POST["numvar_$i"], $_POST['numvars'])."</td>
						<td style='width: 60px;'>Account:</td><td>".$this->SelAccount("accountid_$i", $_POST["accountid_$i"])."</td>
					</tr>
				</table>
			";
		}
		$html .= "
				<br /><input class='button-primary' type='submit' name='goSave2' value='Update Template' /><br /><br />
				</form>
			</div>
		";
		echo $html;
	}
	
	function ScheduleTemplates() {
		global $wpdb;
		if (isset($_GET['sub'])) {
			switch ($_GET['sub']) {
				case 'template': return $this->EditTemplate(); break;
			}
		}
		if (isset($_GET['del'])) {
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_schedule WHERE templid=%d;", $_GET['del']));
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_templates WHERE id=%d;", $_GET['del']));
			$_GET['msg'] = 'del';
		}
        if (isset($_POST['DoDelete']) && isset($_POST['godel'])) {
            foreach ($_POST['godel'] as $id) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_schedule WHERE templid=%d;", $id));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stm_templates WHERE id=%d;", $id));
			}
            die("<META HTTP-EQUIV='Refresh' Content='0; URL=admin.php?page=stm-templates&msg=dels' />");
        }
		$msg = '';
        if (isset($_GET['msg'])) {
            if ($_GET['msg'] == 'del') $msg = 'Template Deleted!';
            if ($_GET['msg'] == 'dels') $msg = 'Template(s) Deleted!';
        }
		$html = $this->TopMsg()."
			<div class='wrap'>
				<div id='icon-edit-pages' class='icon32'></div>
				<h2><img src='".STM_URL."images/logofull.png' align='absmiddle' class='logo' /> &nbsp; Schedule Templates
					&nbsp;<a href='admin.php?page=stm-templates&sub=template' class='button add-new-h2'>Add Template</a>
					&nbsp;<a href='admin.php?page=stm' class='button add-new-h2'>Timeline</a>
					&nbsp;<a href='admin.php?page=stm-scheduled' class='button add-new-h2'>Scheduled Posts</a>
					&nbsp;<a href='admin.php?page=stm-postlog' class='button add-new-h2'>Post Log</a>
					&nbsp;<a href='admin.php?page=stm-accounts' class='button add-new-h2'>Social Accounts</a>
					&nbsp;<a href='admin.php?page=stm-settings' class='button add-new-h2'>Settings</a>
				</h2><br />
				<div class='clear'></div>
		";
		$data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stm_templates WHERE 1 ORDER BY title ASC;");
		if (!count($data)) {
			echo $html."There are no templates added yet!<br /><a href='admin.php?page=stm-templates&sub=template'>Add Template</a></div>".$this->Footer();
			return ;
		}
		if ($msg) $msg = $this->Msg_OK($msg);
		$html .= "
				$msg
				<form method='post'>
				<table class='wp-list-table widefat fixed pages' cellspacing='0' style='width: 700px; margin: 10px 0 7px 0;'>
					<thead><tr>
						<th scope='col' class='manage-column column-cb check-column'><input type='checkbox' /></th>
						<th scope='col' class='manage-column'>Title</th>
						<th scope='col' class='manage-column' style='text-align: center; width: 100px;'># of Variations</th>
						<th scope='col' class='manage-column' style='text-align: center; width: 100px;'># of Accounts</th>
						<th scope='col' class='manage-column' style='text-align: center; width: 100px;'># of Postings</th>
						<th scope='col' class='manage-column' style='width: 40px;'>&nbsp;</th>
					</tr></thead>
					<tfoot><tr>
						<th scope='col' class='manage-column column-cb check-column'><input type='checkbox' /></th>
						<th scope='col' class='manage-column'>Title</th>
						<th scope='col' class='manage-column' style='text-align: center;'># of Variations</th>
						<th scope='col' class='manage-column' style='text-align: center;'># of Accounts</th>
						<th scope='col' class='manage-column' style='text-align: center;'># of Postings</th>
						<th scope='col' class='manage-column'>&nbsp;</th>
					</tr></tfoot>
					<tbody>
		";
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			$html .= "
				<tr>
					<td scope='row' class='check-column' style='text-align: center;'><input type='checkbox' name='godel[]' value='$info[id]' /></td>
					<td><a href='admin.php?page=stm-templates&sub=template&id=$info[id]'>$info[title]</a></td>
					<td style='text-align: center;'>$info[numvars]</td>
					<td style='text-align: center;'>$info[numaccounts]</td>
					<td style='text-align: center;'>$info[numposts]</td>
					<td style='text-align: center;'>
						<a href='admin.php?page=stm-templates&sub=template&id=$info[id]'><img src='".STM_URL."images/edit.gif' class='icon' /></a>
						<a href='admin.php?page=stm-templates&del=$info[id]'><img src='".STM_URL."images/delete.png' class='icon' /></a>
					</td>
				</tr>
			";
		}
		$html .= "
				</tbody>
			</table>
			<input class='button-primary' type='button' name='goAdd' value='Add Template' onclick=\"document.location='admin.php?page=stm-templates&sub=template';\" />
			<input class='button-secondary' type='submit' name='DoDelete' value='Delete Selected' />
			</form>
		";
		$html .= $this->Footer().'</div>';
		echo $html;
	}

	
	function Settings() {
		global $wpdb;
		$msg = '';
		if (isset($_POST['bitly'])) {
			update_option('stmbitly', $_POST['bitly']);
			update_option('stmtwiuser', $_POST['twiuser']);
			update_option('stmtwisite', $_POST['twisite']);
			update_option('stmcardformat', $_POST['cardformat']);
			$hidehelp = isset($_POST['hidehelp'])?1:0;
			update_option('stmhidehelp', $hidehelp);
			$nocards = isset($_POST['nocards'])?1:0;
			update_option('stmnocards', $nocards);
			$noog = isset($_POST['noog'])?1:0;
			update_option('stmnoog', $noog);
			$msg = $this->Msg_OK('Settings saved!');
		}
		$bitly = get_option('stmbitly');
		$twiuser = get_option('stmtwiuser');
		$twisite = get_option('stmtwisite');
		$hidehelp = get_option('stmhidehelp');
		$nocards = get_option('stmnocards');
		$noog = get_option('stmnoog');
		$cardformat = get_option('stmcardformat');
		$ch = $hidehelp?' checked':'';
		$ch2 = $nocards?' checked':'';
		$ch3 = $noog?' checked':'';
		$html = $this->TopMsg()."
			<div class='wrap'>
				<div id='icon-edit-pages' class='icon32'></div>
				<h2><img src='".STM_URL."images/logofull.png' align='absmiddle' class='logo' /> &nbsp; Settings
					&nbsp;<a href='admin.php?page=stm' class='button add-new-h2'>Timeline</a>
					&nbsp;<a href='admin.php?page=stm-scheduled' class='button add-new-h2'>Scheduled Posts</a>
					&nbsp;<a href='admin.php?page=stm-postlog' class='button add-new-h2'>Post Log</a>
					&nbsp;<a href='admin.php?page=stm-accounts' class='button add-new-h2'>Social Accounts</a>
					&nbsp;<a href='admin.php?page=stm-templates' class='button add-new-h2'>Schedule Templates</a>
				</h2><br />
				$msg
				<div class='clear'></div>
				<form method='post'>
					<input type='hidden' name='dosave' value='1' />
					<table class='frm'>
						<tr><td>Twitter Cards Format:</td><td colspan='2'>".$this->SelCardFormat('cardformat', $cardformat)."</td></tr>
						<tr><td>Twitter User:</td><td><input type='text' name='twiuser' id='twiuser' style='width: 150px;' value='$twiuser' /></td><td rowspan='2'>These details will be<br />used for the Twitter Cards.</td></tr>
						<tr><td>Twitter Site:</td><td><input type='text' name='twisite' id='twisite' style='width: 150px;' value='$twisite' /></td></tr>
						<tr><td>BitLy Access Token:</td><td colspan='2'><input type='text' name='bitly' id='bitly' style='width: 350px;' value='$bitly' /><br /><small><span style='color: red;'>Important:</span> <a href='javascript:void(0);' onclick=\"AjaxPopSP('pophelp&amp;sub=settings');\">click here</a> to see how to integrate Bit.Ly</small></td></tr>
						<tr><td>&nbsp;</td><td colspan='2'><input type='checkbox' name='nocards' id='nocards' value='$nocards'$ch2 /> <label for='nocards'>Do not add Twitter Cards Meta-tags</label></td></tr>
						<tr><td>&nbsp;</td><td colspan='2'><input type='checkbox' name='noog' id='noog' value='$noog'$ch3 /> <label for='noog'>Do not add Facebook Open Graph Meta-tags</label></td></tr>
						<tr><td>&nbsp;</td><td colspan='2'><input type='checkbox' name='hidehelp' id='hidehelp' value='$hidehelp'$ch /> <label for='hidehelp'>Hide The Help Tab</label></td></tr>
						<tr><td>&nbsp;</td><td colspan='2'><input type='submit' class='button-primary' value='Update Settings' /></td></tr>
					</table>
				</form>
				<div class='spacer10'></div><br />
				Please <a href='#' onclick=\"AjaxPopSP('pophelp&sub=settings');\">read the help page</a> before you make any changes in the settings.
		";
		$html .= $this->Footer().'</div>';
		echo $html;
	}


	function Footer($showhelp=1) {
		$html = "
			<div id='loading'><img src='".STM_URL."images/ajax-loader.gif' alt='loading...' /></div>
			<div id='dim' onclick=\"AjaxLoadedSP();\"></div>
			<div id='dialog-main' title='' style='display: none;'></div>
		";
		if (!$showhelp || get_option('stmhidehelp')) return $html;
		$html .= "
			<div id='basketbox' style='right: -225px;' onmouseover='ShowBasket();' onmouseout='HideBasket();'>
				<a href='#' onclick=\"AjaxPopSP('pophelp&sub=about');\">Overview & Quick Start</a>
				<a href='#' onclick=\"AjaxPopSP('pophelp&sub=accounts');\">Adding Social Accounts</a>
				<a href='#' onclick=\"AjaxPopSP('pophelp&sub=templates');\">Edit Schedule Templates</a>
				<a href='#' onclick=\"AjaxPopSP('pophelp&sub=settings');\">Edit Settings</a>
				<a href='#' onclick=\"AjaxPopSP('pophelp&sub=schedule');\">Schedule Social Posts</a>
				<a href='#' onclick=\"AjaxPopSP('pophelp&sub=timeline');\">The Timeline</a>
				<a href='http://wiziva.com/user/pluginhelp.html?id=".STM_WIZIVA_ID."&wpu=".STM_URL_Encoded."' target='_blank'>Full Manual & Video Guides</a>
			</div>
			<div id='basket' onmouseover='ShowBasket();' onmouseout='HideBasket();'></div>
		";
		return $html;
	}



	function GetUserIP() {
	  if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
	  elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
	  else return $_SERVER['REMOTE_ADDR'];
	}


	function GetBetweenTags($content, $tag1, $tag2) {
		if (!$content) return '';
		$pos1 = strpos($content, $tag1)+strlen($tag1);
		if (!$pos1) return '';
		$pos2 = @strpos($content, $tag2, $pos1);
		if (!$pos2) return '';
		$content = substr($content, $pos1, $pos2-$pos1);
		return $content;
	}


	function AjaxContent(&$content, $addslashes=1) {
		$content = str_replace("\n", '', $content);
		$content = str_replace("\r", '', $content);
		$content = trim($content);
		if ($addslashes) $content = addslashes($content);
	}

	function plxRound($num, $accuracy=2) {
		$ret = round($num, $accuracy);
		if ($accuracy) {
			$pos = strpos($ret, '.');
			if (!$pos) {
				$lenpart = 0;
				$ret .= '.';
			}
			else $lenpart = strlen(substr($ret, $pos))-1;
			if ($accuracy>$lenpart) $ret .= str_repeat('0', $accuracy-$lenpart);
		}
		return $ret;
	}

	function NoQuotes(&$var) {
		$var = str_replace("'", '', $var);
		$var = str_replace('"', '', $var);
	}

	function Msg_OK($msg, $width='', $setget='') {
		if ($setget) $_GET['focus'] = $setget;
		if ($width) $width = 'width: '.$width.'px';
		return "<div class='msg_ok' style='$width'>$msg</div>";

	}

	function Msg_Err($msg, $width='', $setget='') {
		if ($setget) $_GET['focus'] = $setget;
		if ($width) $width = 'width: '.$width.'px';
		return "<div class='msg_err' style='$width'>$msg</div>";
	}


	function HTTPPost($url, $postdata, $ixml=0) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
		if ($ixml) curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_POST, true);
		$post_array = array();
		if(is_array($postdata)) {
			foreach($postdata as $key=>$value) $post_array[] = urlencode($key) . "=" . urlencode($value);
			$post_string = implode("&",$post_array);
		}
		else $post_string = $postdata;
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
		$result = curl_exec($ch);
		return $result;
	}


	function getURL($command) {
		return file_get_contents($command);
	}

	function SelSocialSites($fldname, $sel='') {
		$html = "<select name='$fldname' id='$fldname' onchange=\"ShowSubBox(this.value);\"><option value=''>[select]</option>";
		$html .= sprintf("<option value='facebook'%s>Facebook</option>", ($sel=='facebook')?' selected':'');
		$html .= sprintf("<option value='twitter'%s>Twitter</option>", ($sel=='twitter')?' selected':'');
		$html .= sprintf("<option value='linkedin'%s>LinkedIn</option>", ($sel=='linkedin')?' selected':'');
		$html .= sprintf("<option value='tumblr'%s>Tumblr</option>", ($sel=='tumblr')?' selected':'');
		$html .= "</select>";
		return $html;
	}


	function buildBaseString($baseURI, $params){
		$r = array();
		ksort($params);
		foreach($params as $key=>$value) $r[] = "$key=" . rawurlencode($value);
		return 'POST&' . rawurlencode($baseURI) . '&' . rawurlencode(implode('&', $r));
	}

	function getCompositeKey($consumerSecret, $requestToken){
		return rawurlencode($consumerSecret) . '&' . rawurlencode($requestToken);
	}

	function SelIntType($fld, $val) {
		$html = "<select name='$fld' id='$fld'>";
		$html .= sprintf("<option value='m'%s>minute(s)</option>", ($val=='m')?' selected':'');
		$html .= sprintf("<option value='h'%s>hour(s)</option>", ($val=='h')?' selected':'');
		$html .= sprintf("<option value='d'%s>day(s)</option>", ($val=='d')?' selected':'');
		$html .= "</select>";
		return $html;
	}
	
	function SelAccount($fld, $val) {
		global $wpdb;
		$html = "<select name='$fld' id='$fld'><option value='0'></option>";
		$data = $wpdb->get_results("SELECT id, atype, username FROM {$wpdb->prefix}stm_accounts WHERE 1 ORDER BY atype;");
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			$info['atype'] = ucfirst($info['atype']);
			$html .= sprintf("<option value='$info[id]'%s>$info[atype] - $info[username]</option>", ($val==$info['id'])?' selected':'');
		}
		$html .= "</select>";
		return $html;
	}

	
	function SelVariation($fld, $val, $numvars, $addstr='', $addopt='', $addmore=1) {
		global $wpdb;
		$html = "<select name='$fld' id='$fld'$addstr><option value='0'>ORIGINAL</option>";
		for ($i=1; $i<=$numvars; $i++) $html .= sprintf("<option value='$i'%s>Variation $i</option>", ($val==$i)?' selected':'');
		if ($addmore) {
			$html .= sprintf("<option value='200'%s>[ROTATE]</option>", ($val==200)?' selected':'');
			$html .= sprintf("<option value='201'%s>[RANDOMIZE]</option>", ($val==201)?' selected':'');
		}
		$html .= "$addopt</select>";
		return $html;
	}

	function SelTemplate($fld, $val=0) {
		global $wpdb;
		$html = "<select name='$fld' id='$fld'>";
		$data = $wpdb->get_results("SELECT id, title FROM {$wpdb->prefix}stm_templates WHERE 1 ORDER BY title;");
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			$html .= sprintf("<option value='$info[id]'%s>$info[title]</option>", ($val==$info['id'])?' selected':'');
		}
		$html .= "</select>";
		return $html;
	}

	function SelStartFrom($fld, $val) {
		$html = "<select name='$fld' id='$fld' onclick=\"if (this.value==2) document.getElementById('sttimebox').style.display='inline'; else document.getElementById('sttimebox').style.display='none';\">";
		$html .= sprintf("<option value='1'%s>Publish Time</option>", ($val==1)?' selected':'');
		$html .= sprintf("<option value='2'%s>Specified Time</option>", ($val==2)?' selected':'');
		$html .= "</select>";
		return $html;
	}

	function slPrepareQuerry($name, $defaultsort = 'id', $defaultdir = 'desc', $limit = 0) {
		$dname = $name . 'dir';
		$oname = $name . 'order';
		$limit = 20;
		if ($stub = get_option('stmlimit'.$name)) $limit = $stub;
		if (isset($_POST['limit']) && ($limit != $_POST['limit'])) $_GET['start'] = 0;
		if (isset($_POST['limit'])) $limit = $_POST['limit'];
		elseif (isset($_GET['limit'])) $limit = $_GET['limit'];
		if (isset($_GET['start'])) $start = $_GET['start'];
		else $start = 0;
		if ($stub = get_option('stmdir'.$name)) $dir = $stub;
		else $dir = $defaultdir;
		if (isset($_GET['order'])) {
			if (($stub = get_option('stmord'.$name)) && ($stub == $_GET['order'])) $dir = ($dir == 'desc') ? 'asc' : 'desc';
			$order = $_GET['order'];
		}
		elseif ($stub = get_option('stmord'.$name)) $order = $stub;
		else $order = $defaultsort;
		update_option('stmord'.$name, $order);
		update_option('stmdir'.$name, $dir);
		update_option('stmlimit'.$name, $limit);
		$ret = array();
		$ret['start'] = $start;
		$ret['limit'] = $limit;
		$ret['order'] = $order;
		$ret['dir'] = $dir;
		$ret['img'] = " <img src='".STM_URL."images/$dir.gif' width='21' height='6' border='0'>";
		return $ret;
	}



	function slPagesLinks($reccount, $start, $limit, $url, $numinstance = 1) {
		$arr = array(10, 20, 30, 50, 100, 200, 500, 1000, 3000, 5000, 100000);
		$html = '';
		if ($reccount > $limit) {
			$n = ceil($reccount / $limit); //no of pages
			$last = ($n - 1) * $limit;
			$index_start = floor($start / $limit);
			if ($index_start > $n - 1) $index_start = $n - 1;
			if ($index_start < 1) $index_start = 1;
			$index_end = $index_start + 4;
			if ($index_end > $n - 1) $index_end = $n - 1;
			for ($i = $index_start; $i <= $index_end; $i++) {
				$s = $limit * ($i - 1);
				$show = (($i - 1) * $limit + 1) . '-' . (($i) * $limit);
				if (($i - 1) * $limit == ($start - 1)) {
					$html .="<span class='pgnum'>[$show]</span> ";
					$next = $i * $limit;
					$prev = ($i - 2) * $limit;
				} 
				else {
					if ($start != (($i - 1) * $limit)) $html .= "<a class='pglink' href='$url&start=$s'>[$show]</a> ";
					else $html .= "<span class='pgnum'>[$show]</span> ";
				}
			}
			$prev = $start - $limit;
			$next = $start + $limit;
			if ($start > 2 * $limit) {
				$show = '1-' . $limit;
				if ($start) $html = "<a class='pglink' href='$url&start=0'>[$show]</a> .. " . $html;
				else $html = "<span class='pgnum'>[$show]</span> .. " . $html;
			}
			if ($start > $limit - 1) $html = "<a class='pglink' href='$url&start=$prev'><<</a> " . $html;
			if ($start + $limit < $reccount) $next = "<a class='pglink' href='$url&start=$next'>>></a>";
			else $next = '';
			$show = (($n - 1) * $limit + 1) . '-' . (($n) * $limit);
			if ($start != (($n - 1) * $limit)) $html .= " .. <a class='pglink' href='$url&start=$last'>[$show]</a> $next</font>";
			else $html .= " .. <span class='pgnum'>[$show] $next</span>";
		}
		$sel = "<select name='numpages$numinstance' id='numpages$numinstance' onchange=\"document.getElementById('limit').value=this.value;this.form.submit();\">";
		foreach ($arr as $num) $sel .= sprintf("<option%s>$num</option>", ($limit == $num) ? ' selected' : '');
		$sel .= "</select>&nbsp;&nbsp;&nbsp;";
		$html = $sel . $html;
		return $html;
	}

	function SelCardFormat($fld, $val) {
		$html = "<select name='$fld' id='$fld'>";
		$html .= sprintf("<option value='1'%s>Summary with Large Image (Recommended)</option>", ($val==1)?' selected':'');
		$html .= sprintf("<option value='2'%s>Summary Card</option>", ($val==2)?' selected':'');
		$html .= sprintf("<option value='3'%s>Photo Card</option>", ($val==3)?' selected':'');
		$html .= "</select>";
		return $html;
	}

	function RemCDATA($str) {
		$str = str_replace('<![CDATA[', '', $str);
		$str = str_replace(']]>', '', $str);
		$str = trim($str);
		return $str;
	}

	function SelBlogPost($fld, $val, $addstr='') {
		global $wpdb;
		$html = "<select name='$fld' id='$fld' $addstr><option value='0'></option>";
		$data = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->prefix}posts WHERE (post_status='publish') AND (post_title<>'') ORDER BY post_title;");
		foreach ($data as $k=>$info) {
			$info = (array)$info;
			$info['post_title'] = $this->LimitLength($info['post_title'], 70);
			$html .= sprintf("<option value='$info[ID]'%s>$info[post_title]</option>", ($val==$info['ID'])?' selected':'');
		}
		$html .= "</select>";
		return $html;
	}

	function SelNum($fld, $val, $startnum, $endnum) {
		$html = "<select name='$fld' id='$fld'>";
		for ($i=$startnum; $i<=$endnum; $i++)
			$html .= sprintf("<option value='$i'%s>$i</option>", ($val==$i)?' selected':'');
		$html .= "</select>";
		return $html;
	}

	function TopMsg($accpage='') {
		global $wpdb;
		$html = '';
		if ($accpage!='accounts') {
			$noauth = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}stm_accounts WHERE auth3='';");
			if ($noauth) $html .= "<div class='topmsg'>Social Time Master Message: You have social <strong>accounts which are not authorized</strong>! <a href='admin.php?page=stm-accounts'>CLICK HERE</a> to fix this problem!</div>";
		}
		return $html;
	}


	function unzip($zipfile) {
		$rootd = '';
		$dirs = array();
		$zip = zip_open($zipfile);
		if (!$zip) return false;
		while ($zip_entry = zip_read($zip)) {
			zip_entry_open($zip, $zip_entry);
			if (substr(zip_entry_name($zip_entry), -1) != '/') {
				$name = zip_entry_name($zip_entry);
				//if ($rootd && file_exists($name)) continue;
				if (strpos($name, '/')) {
					$curdir = substr($name, 0, strrpos($name, '/'));
					if (!in_array($curdir, $dirs)) {
						$stub = explode('/', $name);
						if (!$rootd) $rootd = $stub[0];
						//if (file_exists($name)) continue;
						$fldr = '';
						for ($i=0; $i<count($stub)-1; $i++) {
							$fldr .= $stub[$i];
							if (!in_array($fldr, $dirs)) {
								$dirs[] = $fldr;
								if (!file_exists($fldr)) mkdir($fldr);
							}
							$fldr .= '/';
						}
						$dirs[] = $curdir;
					}
				}
				$fopen = fopen($name, "w");
				fwrite($fopen, zip_entry_read($zip_entry, zip_entry_filesize($zip_entry)), zip_entry_filesize($zip_entry));
			}
			zip_entry_close($zip_entry);
		}
		zip_close($zip);
		return $rootd;
	}
}

?>