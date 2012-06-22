<?php
/* require_once $_SERVER['DOCUMENT_ROOT']."/tmp/config/config.php"; */
require_once "../config/config.php";
global $currentUser;
if(!userIsAdmin() or !isset($_GET['id'])) {
  header("Location: index.php");
  die();
}
global $language,$available_languages,$lang;
$study=new Study;
$study->fillIn($_GET['id']);
if(!$study->status)
  header("Location: ../index.php");
?>
<?php
include("pre_content.php");
?>	
<p>
<?php
echo $study->prefix;
?>
</p>
<p><a href="acp.php">ACP</a></p>

<?php
include("post_content.php");
?>	