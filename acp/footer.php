          <?php
          if(userIsLoggedIn()) {  
            global $currentUser;
            ?>
            <a href="../logout.php"><?php echo $lang['LOGOUT']; ?></a> | 
            <a href="../edit_user.php"><?php echo $lang['EDIT_USER']; ?></a>

<?php
   if(userIsAdmin()) 
     echo "| <a href='acp.php'>ACP</a>";
    } else {
?>
    <a href="../login.php"><?php echo $lang['LOGIN']; ?></a> |
    <a href="../register.php"><?php echo $lang['REGISTER']; ?></a>
<?php
    }
if(isset($currentUser))
  echo " ".$currentUser->email." ".$currentUser->vpncode;
?>
