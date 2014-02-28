<?php
class OpenCPU {
	private $instance;
	private $user_data = '';
	private $curl_c;
	public $http_status = null;
	public function __construct($instance)
	{
		$this->instance = $instance;
		$this->curl_c = curl_init();
		curl_setopt($this->curl_c, CURLOPT_RETURNTRANSFER, 1); // Returns the curl_exec string, rather than just Logical value
	}

	public function r_function($function,$post)
	{
		curl_setopt($this->curl_c, CURLOPT_URL, $this->instance.'/ocpu/library/'.$function);
		
		if($post !== null):
			curl_setopt($this->curl_c, CURLOPT_POST, 1); // Method is "POST"
			$post = array_map("cr2nl", $post); # get rid of windows new lines, not that there should be any, but this causes such annoying-to-debug errors
			curl_setopt($this->curl_c, CURLOPT_POSTFIELDS, http_build_query($post));
		endif;
		curl_setopt($this->curl_c, CURLINFO_HEADER_OUT, true); // enable tracking

		curl_setopt($this->curl_c, CURLOPT_HEADER, 1);
		
		$result = curl_exec($this->curl_c);
		$this->http_status = curl_getinfo($this->curl_c,CURLINFO_HTTP_CODE);

		$this->header_size = curl_getinfo($this->curl_c, CURLINFO_HEADER_SIZE);
	#	curl_close($this->curl_c);

		$header = mb_substr($result, 0, $this->header_size);
		$body = mb_substr($result, $this->header_size);
		##		list($header, $body) = explode("\r\n\r\n", $results, 2); # does not work with 100 Continue
		
		return compact('header','body','post');
	}
	public function identity($post, $return = '/json')
	{
		return $this->r_function('base/R/identity'.$return, $post);
	}
	
	public function evaluate($source,$return = '/json')
	{
		$post = array('x' => '{ 
			(function() {
		'.$this->user_data.'
			'.$source.'
			})() }');
			
		$result = $this->identity($post,$return);
		$parsed = json_decode($result['body'], true);
		if($parsed===null):
			alert($result,'alert-danger');
			alert("<pre style='background-color:transparent;border:0'>".$source."</pre>",'alert-danger');
			return null;
		elseif(empty($parsed)):
			return null;
		else:
			return $parsed[0];
		endif;
	}
	public function evaluateWith($results_table, $source,$return = '/json')
	{
		$post = array('x' => '{ 
(function() {
"%contains%" = function(haystack, needle) {
    stringr::str_detect(haystack, stringr::fixed(as.character(needle)) )
}

'.$this->user_data.'
with('.$results_table.', {
'.$source.'
})
})() }');
			
		$result = $this->identity($post,$return);
#		echo $this->debugCall($result);
# pr($post);
		$parsed = json_decode($result['body'], true);
		if($parsed===null):
			global $user;
			if($user->isAdmin()):
				alert($result['body'],'alert-danger',true);
				alert("Dynamic R expressions can only be tested as part of a proper run. <pre style='background-color:transparent;border:0'>".$post["x"]."</pre>",'alert-danger',true);
			endif;
			return null;
		elseif(empty($parsed)):
			return null;
		else:
			return $parsed[0];
		endif;
	}
	public function evaluateAdmin($source,$return = '')
	{
		$post = array('x' => '{ 
(function() {
'.$this->user_data.'
'.$source.'
})() }');
		$result = $this->identity($post,$return);
		return $this->debugCall($result);
	}
	
	public function knit($source,$return = '/json',$options = '"base64_images","smartypants","highlight_code","mathjax"')
	{
		$post = array('x' => '{
library(knitr)
	knit2html(text = "' . addslashes($source) . '",
    fragment.only = T, options=c('.$options.')
)
}');
		$result = $this->identity($post,$return);
		return $result;
	}
	public function clearUserData()
	{
		$this->user_data = '';
	}
	public function addUserData($datasets)
	{
		foreach($datasets AS $df_name => $data):
			$this->user_data .= $df_name . ' = as.data.frame(jsonlite::fromJSON("'.addslashes(my_json_encode($data)).'"), stringsAsFactors=F)
'; ### loop through the given datasets and import them to R via JSON
		endforeach;
	}
	public function knitForUserDisplay($source)
	{
		$source =
'```{r settings,message=FALSE,warning=F,echo=F}
opts_chunk$set(warning=F,message=F,echo=F)
'.
$this->user_data .
'```
'.
		$source;
		
		$result = $this->knit($source,'/json');
		$html = json_decode($result['body'], true);
		
		if(!$html):
			alert($result['body'],'alert-danger');
			alert("<pre style='background-color:transparent;border:0'>".$source."</pre>",'alert-danger');
			return false;
		endif;
		
		return $html[0];
	}
	public function knitForAdminDebug($source)
	{
		$source =
'```{r settings,message=FALSE,warning=F,echo=F}
opts_chunk$set(warning=T,message=T,echo=T)
'.
$this->user_data .
'```
'.
		$source;
		
		$result = $this->knit($source,'');
		return $this->debugCall($result);

	}



	public function knitEmail($source)
	{
		$source =
'```{r settings,message=FALSE,warning=F,echo=F}
email_image = function(x) {
	cid = gsub("[^a-zA-Z0-9]", "", substring(x,8))
	structure(paste0("cid:",cid,".png"), link = x)
}
opts_chunk$set(warning=F,message=F,echo=F)
opts_knit$set(upload.fun=email_image)
'.
$this->user_data .
'```
'.
		$source;
		
		$result = $this->knit($source,'','"smartypants","highlight_code","mathjax"');

		if($this->http_status > 302):
			 $response = array(
				 'Response' => '<pre>'. htmlspecialchars($result['body']). '</pre>',
				 'HTTP headers' => '<pre>'. htmlspecialchars($result['header']). '</pre>',
			 );
		else:
			$header_parsed = http_parse_headers($result['header']);
			$session = '/ocpu/tmp/'. $header_parsed['X-ocpu-session'] . '/';
		
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
				$response['body'] = current( json_decode(file_get_contents($this->instance. $session . 'R/.val/json'), true) );
			endif;
		endif;
		
		return $response;
	}
	public function debugCall($result)
	{
		if($this->http_status < 303):
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
				
  			 	$response['Call'] = '<pre>'. htmlspecialchars(current($result['post'])). '</pre>';
			
				$response['HTTP headers'] = '<pre>'. htmlspecialchars($result['header']). '</pre>';
			 	$response['Headers sent'] = '<pre>'. htmlspecialchars(curl_getinfo($this->curl_c, CURLINFO_HEADER_OUT )) . '</pre>';
			
				if(in_array($session . 'info',$available)):
					$response['Session info'] = '<pre>'. htmlspecialchars(file_get_contents($this->instance. $session . 'info/print')). '</pre>';
				endif;
				
			else:
		   		 $response = array(
		   			 'Response' => 'OpenCPU at '.$this->instance.' is down.'
		   		 );
			endif;
		else:
			
	   		 $response = array(
	   			 'Response' => '<pre>'. htmlspecialchars($result['body']). '</pre>',
	   			 'HTTP headers' => '<pre>'. htmlspecialchars($result['header']). '</pre>',
	   			 'Call' => '<pre>'. htmlspecialchars(current($result['post'])). '</pre>',
				 'Headers sent' => '<pre>'. htmlspecialchars(curl_getinfo($this->curl_c, CURLINFO_HEADER_OUT )) . '</pre>',
	   		 );
		endif;
		
		return $this->ArrayToAccordion($response);
	}
	private function ArrayToAccordion($array)
	{
		$rand = mt_rand(0,1000);
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



function my_json_encode($arr)
{
	if(!defined("JSON_UNESCAPED_UNICODE")):
	
        //convmap since 0x80 char codes so it takes all multibyte codes (above ASCII 127). So such characters are being "hidden" from normal json_encoding
        array_walk_recursive($arr, function (&$item, $key) { if (is_string($item)) $item = mb_encode_numericentity($item, array (0x80, 0xffff, 0, 0xffff), 'UTF-8'); });
        return mb_decode_numericentity(json_encode($arr, JSON_NUMERIC_CHECK), array (0x80, 0xffff, 0, 0xffff), 'UTF-8');
	else:
		return json_encode($arr,JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
	endif;
}
