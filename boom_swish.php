<?php
require_once 'define_root.php';
require_once INCLUDE_ROOT."Model/Site.php";

if(isset($_SERVER['HTTP_REFERER']))
	redirect_to($_SERVER['HTTP_REFERER']);
else
	redirect_to("index.php");

//<a href="http://localhost:8888/zwang/survey/boom_swish.php">aaa</a>