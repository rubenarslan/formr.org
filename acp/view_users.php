<?php
require_once $_SERVER['DOCUMENT_ROOT']."/tmp/config/config.php";
if(!userIsAdmin()) {
  header("Location: index.php");
  die();
}
?>
<?php
include("pre_content.php");
?>	
	<!-- Start Content -->
	<div id="content">
	
		<!-- Start Left Content -->
		<div id="c_left">
		
		<!-- Start Headline -->
		<h1>Questions?  <span> - Feel free to contact us </span></h1>
	    <img src="../graphic/headline_line.gif" alt="" width="550" height="25" class="headline_line" />
		<br />
		<!-- End Headline -->		
		
		<!-- Start Text -->
		<div id="text_left">
		<p><strong>List of all Users </strong><br /> 
<?php
    global $dbhost,$dbname,$dbuser,$dbpass;
    $conn=mysql_connect($dbhost,$dbuser,$dbpass);
    if(!$conn) {
      $this->errors[]="Could not connect do database";
      return;
    }
    if(!mysql_select_db($dbname,$conn)) {
      $this->errors[]="Could not connect do database";
      mysql_close();
      return;
    }
    $query="SELECT id, email FROM users";
    $result=mysql_query($query);
    if($result===false) {
      $this->errors[]="Query Error";
      mysql_close();
      return;
    }
if(mysql_num_rows($result)!==false and mysql_num_rows($result)>0) {
  while($row=mysql_fetch_array($result)) {
    echo "<p><a href='edit_user.php?id=".$row['id']."'>Edit:".$row['email']."</a></p>";
  }
    }
?>
		  <br />
		  <br />

		</div>
		<!-- End Text -->
		
		</div>
		<!-- End Left Content -->
		
		<!-- Start Right Content -->
      <div id="c_right">
	  	
		<!-- Start Headline -->
		<h1>From our blog  <span> - The latest news </span></h1>
		<img src="../graphic/headline_line.gif" alt="" width="300" height="25" class="headline_line" />
		<br />
		<!-- End Headline -->
		
		<!-- Start Image -->
		<div class="image">
		  <p><a href="http://themeforest.net/item/coffee-junkie-xhmtlcss-version/44738?ref=-ilove2design-" target="_blank"><img src="../graphic/blog.gif" alt="Coffee Junkie" width="82" height="82" border="0" /></a></p>
		</div>
		<!-- End Image -->
		
		<!-- Start Text -->
		<div class="text_right">
		<p><strong>Lorem ipsum dolor sit</strong>
		<br />
		Amet, con adipiscing elit. Proin aliquam,  er non bibendum venenatis, <a href="http://themeforest.net/item/coffee-junkie-xhmtlcss-version/44738?ref=-ilove2design-" target="_blank">see it online here</a>.</p>
		</div>
		<!-- End Text -->
		
		<br style="clear:both" /> 
		<!-- DO NOT REMOVE THIS LINE!!! -->
		
		<!-- Start Divider-->
		<div class="divider">
		<img src="../graphic/headline_line.gif" alt="" width="300" height="25" class="headline_line" />
		</div>
		<!-- End Divider-->
		
		<!-- Start Image -->
		<div class="image">
		  <p><a href="http://themeforest.net/item/coffee-junkie-xhmtlcss-version/44738?ref=-ilove2design-" target="_blank"><img src="../graphic/blog.gif" alt="Coffee Junkie" width="82" height="82" border="0" /></a></p>
		</div>
		<!-- End Image -->
		
		<!-- Start Text -->
		<div class="text_right">
		<p><strong>Lorem ipsum dolor sit</strong>
		<br />
		Amet, con adipiscing elit. Proin aliquam,  er non bibendum venenatis, <a href="http://themeforest.net/item/coffee-junkie-xhmtlcss-version/44738?ref=-ilove2design-" target="_blank">see it online here</a>.</p>
		</div>
		<!-- End Text -->
		
		<br style="clear:both" /> <!-- DO NOT REMOVE THIS LINE!!! -->
		
		<!-- Start Divider-->
		<div class="divider">
		<img src="../graphic/headline_line.gif" alt="" width="300" height="25" class="headline_line" />
		</div>
		<!-- End Divider-->
		
		<!-- Start RSS Line-->
		<div id="rss">
		<p><strong>&raquo; Subscribe to our RSS Feed </strong><img src="../graphic/rss.gif" alt="rss" width="16" height="16" /></p>
		</div>
		<!-- End RSS Line-->
		
	  </div>		
		<!-- End Right Content -->
		
		<br style="clear:both" /> <!-- DO NOT REMOVE THIS LINE!!! -->
		
	</div>
	<!-- End Content -->
<?php
include("post_content.php");
?>	