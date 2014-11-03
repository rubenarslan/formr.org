<?php
class OpenCPU {
	private $instance;
	private $user_data = '';
	private $curl_c;
	public $http_status = null;
	private $knitr_source = null;

	public $admin_usage = false;
	private $hashes = array();
	private $dbh = null;
	private $hash_of_call = null;
	
	public function __construct($instance, $fdb = null)
	{
		$this->dbh = $fdb;
		$this->instance = $instance;
		$this->curl_c = curl_init();
		curl_setopt($this->curl_c, CURLOPT_RETURNTRANSFER, 1); // Returns the curl_exec string, rather than just Logical value
	}
	public function clearUserData() // reset to init state, more or less (keep cache)
	{
		$this->user_data = '';
		$this->knitr_source = null;
		$this->admin_usage = false;
		$this->http_status = null;
		$this->hash_of_call = null;
		$this->called_function = null;
	}
	public function addUserData($data)
	{
		// could I check here whether the dataset contains only null and not even send it to R? but that would break for e.g. is.na(email). hm.
		foreach($data['datasets'] AS $df_name => $content):
			$this->user_data .= $df_name . ' = as.data.frame(jsonlite::fromJSON("'.addslashes(json_encode($content, JSON_UNESCAPED_UNICODE + JSON_NUMERIC_CHECK)).'"), stringsAsFactors=F)
'; ### loop through the given datasets and import them to R via JSON
		endforeach;
		unset($data['datasets']);
		foreach($data AS $variable => $value):
			$this->user_data .= $variable . ' = ' . $value.'
';
		endforeach;
	}
	public function anyErrors()
	{
		if($this->http_status !== NULL AND ($this->http_status < 100 OR $this->http_status > 302)) return true;
		else return false;
	}
	public function speed()
	{
		return curl_getinfo($this->curl_c);
	}
	
	private function handleErrors($message, $result, $post, $in, $level = "alert-danger")
	{
		if($this->admin_usage):
			$error_msg = $result['body'];
			if(! trim($error_msg)):
				$error_msg = "OpenCPU appears to be down.";
				if(mb_substr($this->instance,0,5)=='https'):
					$error_msg .= " Maybe check your server's certificates to use encrypted connection to openCPU.";
				endif;
			endif;
			if(is_array($error_msg)) $error_msg = current($error_msg);
			
			if(is_array($post)) $post = current($post);

			alert($message . " <blockquote>".h($error_msg)."</blockquote><pre style='background-color:transparent;border:0'>".h($post)."</pre>",$level,true);
		endif;
		opencpu_log( "R error in $in:
".print_r($post, true)."
".print_r($result, true)."\n");
	}
	
	private function returnParsed($result, $in = '') 
	{
		$post = $result['post'];
		$parsed = json_decode($result['body'], true);
				
		if($parsed === null):
			$this->handleErrors("There was an R error. If you don't find a problem, sometimes this may happen, if you do not test as part of a proper run, especially when referring to other surveys.", $result, $post, $in);
			return null;
		elseif(empty($parsed)):
			$this->handleErrors("This expression led to a null result (may be intentional, but most often isn't)", $result, $post, $in, 'alert-warning');
			return null;
		else:
			if( is_string( $parsed[0]) ) // dont change type by accident!
				$parsed = str_replace("/usr/local/lib/R/site-library/", $this->instance.'/ocpu/library/' , $parsed[0]);
			else $parsed = $parsed[0];
			$this->cache_query($result);
			return $parsed;
		endif;
	}
	
	public function r_function($function,$post)
	{
		
		used_opencpu();

		if($was_cached = $this->query_cache($function, $post)):
			return $was_cached;
		endif;
		
		$this->called_function = $this->instance.'/ocpu/'.$function;

		curl_setopt($this->curl_c, CURLOPT_URL, $this->called_function );
		
		if($post !== null):
			curl_setopt($this->curl_c, CURLOPT_POST, 1); // Method is "POST"
			$post = array_map("cr2nl", $post); # get rid of windows new lines, not that there should be any, but this causes such annoying-to-debug errors
			curl_setopt($this->curl_c, CURLOPT_POSTFIELDS, http_build_query($post));
		endif;
		
		curl_setopt($this->curl_c, CURLINFO_HEADER_OUT, true); // enable tracking
		curl_setopt($this->curl_c, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
		curl_setopt($this->curl_c, CURLOPT_HEADER, 1);
		
		$result = curl_exec($this->curl_c);
		$this->http_status = curl_getinfo($this->curl_c,CURLINFO_HTTP_CODE);

		if($this->anyErrors() AND ! $this->admin_usage) {
			alert(date("Y-m-d H:i:s") . " There were problems with openCPU. Please try again later.", 'alert-danger');
//			bad_request();	
		}
		$this->header_size = curl_getinfo($this->curl_c, CURLINFO_HEADER_SIZE);
	#	curl_close($this->curl_c);

		$header = mb_substr($result, 0, $this->header_size);
		$body = mb_substr($result, $this->header_size);
	##	list($header, $body) = explode("\r\n\r\n", $results, 2); # does not work with 100 Continue
		
		$result = compact('header','body','post');
		
		return $result;
	}
	
	public function identity($post, $return = '/json')
	{
		return $this->r_function('library/base/R/identity'.$return, $post);
	}
	
	private function query_cache($function, $post)
	{
		$this->hash_of_call = hash("md5", $function . json_encode($post) );
		
		if(isset($this->hashes[ $this->hash_of_call ])): // caching at the lowest level for where I forgot it elsewhere
			used_cache();
			return $this->hashes[ $this->hash_of_call ];
		else:
			if($this->dbh !== null):
				$cache = $this->dbh->prepare("SELECT result_short FROM `survey_opencpu_query_cache` WHERE hash = :hash LIMIT 1;");
				$cache->bindValue(':hash', $this->hash_of_call);
				$cache->execute();
				$result = $cache->fetch();
				if($result):
					$result['post'] = $post;
					$result['body'] = array();
					if( $result['result_short'] != 9):
						$result['body'][0] = $result['result_short'];
					else:
						$cache = $this->dbh->prepare("SELECT result_long FROM `survey_opencpu_query_cache` WHERE hash = :hash LIMIT 1;");
						$cache->bindValue(':hash', $this->hash_of_call);
						$cache->execute();
						$result = $cache->fetch();
						$result['body'][0] = $result['result_long'];
					endif;
					return $result;
				endif;
			endif;
		endif;
		return false;
	}
	
	private function cache_query($result)
	{
		$header_parsed = http_parse_headers($result['header']);

		if(isset($header_parsed['x-ocpu-cache']) AND $header_parsed['x-ocpu-cache'] == "HIT") used_nginx_cache();
		if(!isset($header_parsed['Cache-Control'])) return false;
		if(isset($header_parsed['X-ocpu-session'])): # won't be there if openCPU is down
			
			$this->hashes[ $this->hash_of_call ] = $result;
			
			if($this->dbh !== null):
			
				$location = $header_parsed['Location'];
				if(isset($header_parsed['Content-Type']) AND $header_parsed['Content-Type'] == 'application/json'):
					$location .= "R/.val/json";
				endif;
				$cache = $this->dbh->prepare("INSERT INTO `survey_opencpu_query_cache` (created, hash, result_short, result_long) VALUES(NOW(), :hash, :result_short, :result_long);");
				$cache->bindValue(':hash', $this->hash_of_call);
				if($result['body'] == true OR $result['body'] == false):
					$cache->bindValue(':result_short', $result['body']);
				else:
					$cache->bindValue(':result_short', 9);
				endif;
				$cache->bindValue(':result_long', $location);
				$cache->execute();
			endif;

			return true;
		else:
			return false; // don't cache buggy requests
		endif;
	}
	
	public function evaluate($source,$return = '/json')
	{
		$post = array('x' => '{ 
(function() {
library(formr)
'.$this->user_data.'
'.$source.'
})() }');

		$result = $this->identity($post,$return);

		return $this->returnParsed($result, "evaluate");
	}
	
	public function evaluateWith($name, $source,$return = '/json')
	{
		$post = array('x' => '{ 
(function() {
library(formr)
'.$this->user_data.'
with(tail('.$name.',1), { ## by default evaluated in the most recent results row
'.$source.'
})
})() }');
			
		$result = $this->identity($post,$return);

		return $this->returnParsed($result, "evaluateWith");
	}

	public function evaluateAdmin($source,$return = '')
	{
		$this->admin_usage = true;
		$post = array('x' => '{ 
(function() {
library(formr)
'.$this->user_data.'
'.$source.'
})() }');
		$result = $this->identity($post,$return);
		
		return $this->debugCall($result);
	}
	
	public function knit($source,$return = '/json')
	{
		$post = array(	'text' 			=> "'".addslashes($source)."'");
		$result = $this->r_function('library/knitr/R/knit'.$return, $post);
		
		return $this->returnParsed($result, "knit");
	}
	
	public function knit2html($source,$return = '/json',$options = '"base64_images","smartypants","highlight_code","mathjax"')
	{
		
		$post = array(	'text' => "'".addslashes($source)."'");
		return $this->r_function('library/formr/R/formr_render'.$return, $post);
	}
	public function knitForUserDisplay($source)
	{
		$source =
'```{r settings,message=FALSE,warning=F,echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=F,message=F,echo=F)
'.
$this->user_data .
'```
'.
$source;
		
		$result = $this->knit2html($source,'/json');
		
		return $this->returnParsed($result, "knit");
	}
	//FIXME: something wrong! probably because I did not turn off base64_images!
	public function knitEmail($source)
	{
		$source =
'```{r settings,message=FALSE,warning=F,echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=F,message=F,echo=F)
opts_knit$set(upload.fun=formr::email_image)
'.
$this->user_data .
'```
'.
		$source;
		
		$result = $this->knit2html($source,'','"smartypants","highlight_code","mathjax"');

		if($this->anyErrors()):
			 $response = array(
				 'Response' => '<pre>'. htmlspecialchars($result['body']). '</pre>',
				 'HTTP headers' => '<pre>'. htmlspecialchars($result['header']). '</pre>',
			 );
		else:
			$header_parsed = http_parse_headers($result['header']);
			if(isset($header_parsed['X-ocpu-session']))
				$session = '/ocpu/tmp/'. $header_parsed['X-ocpu-session'] . '/';
			else return "Error, no session header.";
		
			$available = explode("\n",$result['body']);
		
			$response = array();
			$response['images'] = array();
		
			foreach($available AS $part):
				$upto = mb_strpos($part,'/files/figure/');
				if($upto!==false):
					$image_id = preg_replace("/[^a-zA-Z0-9]/",'',mb_substr($part,$upto+14)) . '.png';
					$response['images'][ $image_id ] =  $this->instance. $part;
				endif;
			endforeach;
		
			// info/text stdout/text console/text R/.val/text
		
			if(in_array($session . 'R/.val',$available)):
				$response['body'] = $this->returnParsed(
					array(
					"post" => $result['post'],
					"header" => $result['header'],
					"body" => file_get_contents($this->instance. $session . 'R/.val/json'),
					)
				);
			endif;
		endif;
		return $response;
	}
	
	public function knitForAdminDebug($source)
	{
		$this->admin_usage = true;
		
		$source =
'```{r settings,message=FALSE,warning=F,echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=T,message=T,echo=T)
'.
$this->user_data .
'```
'.
		$source;
		$this->knitr_source = $source;
		$result = $this->knit2html($source,'');
		return $this->debugCall($result);

	}


	public function debugCall($result)
	{
		if($this->http_status === 0):
			 $response = array(
				 'Response' => 'OpenCPU at '.$this->instance.' is down.'
			 );
			if(mb_substr($this->instance,0,5)=='https'):
				$response["Response"] .= " Maybe check your server's certificates to use encrypted connection to openCPU.";
			endif;
		elseif($this->http_status < 303):
			$header_parsed = http_parse_headers($result['header']);
			$available = explode("\n",$result['body']);
			
			$response = array();

			if(isset($header_parsed['X-ocpu-session'])): # won't be there if openCPU is down
				$session = '/ocpu/tmp/'. $header_parsed['X-ocpu-session'] . '/';
	#			$session = explode('/',$available[0]);
	#			$session = '/'.$session[1].'/'.$session[2] .'/'.$session[3] . '/';
				// info/text stdout/text console/text R/.val/text
			
				if(in_array($session . 'R/.val',$available)):
					$response['Result'] = file_get_contents($this->instance. $session . 'R/.val/text');
				endif;

				if(in_array($session . 'console',$available)):
					$response['Console'] = '<pre>'. htmlspecialchars(file_get_contents($this->instance. $session . 'console/print')).'</pre>';
				endif;
				if(in_array($session . 'stdout',$available)):
					$response['Stdout'] = '<pre>'. htmlspecialchars(file_get_contents($this->instance. $session . 'stdout/print')). '</pre>';
				endif;
				
	  			if($this->knitr_source === NULL) $response['Call'] = '<pre>'. htmlspecialchars(current($result['post'])). '</pre>';
				else $response['Knitr doc'] =  '<pre>'. htmlspecialchars($this->knitr_source). '</pre>';
			
				$response['HTTP headers'] = '<pre>'. htmlspecialchars($result['header']). '</pre>';
			 	$response['Headers sent'] = '<pre>'. htmlspecialchars(curl_getinfo($this->curl_c, CURLINFO_HEADER_OUT )) . '</pre>';
			
				if(in_array($session . 'info',$available)):
					$response['Session info'] = '<pre>'. htmlspecialchars(file_get_contents($this->instance. $session . 'info/print')). '</pre>';
				endif;
				
			else:
		   		 $response = array(
		   			 'Response' => 'OpenCPU at '.$this->instance.' is down.'
		   		 );
 				if(mb_substr($this->instance,0,5)=='https'):
 					$response['Response'] .= " Maybe check your server's certificates to use encrypted connection to openCPU.";
 				endif;
			endif;
		else:
			
	   		 $response = array(
	   			 'Response' => '<pre>'. htmlspecialchars($result['body']). '</pre>',
	   			 'HTTP headers' => '<pre>'. htmlspecialchars($result['header']). '</pre>',
	   			 'Call' => '<pre>'. htmlspecialchars(current($result['post'])). '</pre>',
				 'Headers sent' => '<pre><code class="r hljs">'. htmlspecialchars(curl_getinfo($this->curl_c, CURLINFO_HEADER_OUT )) . '</code></pre>',
	   		 );
		endif;
		 
		return $this->ArrayToAccordion($response);
	}
	private function ArrayToAccordion($array)
	{
		$rand = mt_rand(0,10000);
		$acc = '<div class="panel-group opencpu_accordion" id="opencpu_accordion'.$rand.'">';
		$first = ' in';
		foreach($array AS $title => $content):
			if($content == null) $content = stringBool($content);
			$acc .= '
<div class="panel panel-default">
	<div class="panel-heading">
		<a class="accordion-toggle" data-toggle="collapse" data-parent="#opencpu_accordion'.$rand.'" href="#collapse'.str_replace(' ', '', $rand.$title).'">
			'.$title.'
		</a>
	</div>
	<div id="collapse'.str_replace(' ', '', $rand.$title).'" class="panel-collapse collapse'.$first.'">
		<div class="panel-body">
			'.$content.'
		</div>
	</div>
</div>';
			$first = '';
		endforeach;
		$acc .= '</div>';
		return $acc;
	}
}