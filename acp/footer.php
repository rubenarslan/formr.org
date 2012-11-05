          <?php
          if(userIsLoggedIn()) {  
            global $currentUser;
            ?>
            <a href="../logout.php"><?php echo _("Ausloggen"); ?></a> | 
            <a href="../edit_user.php"><?php echo _("Einstellungen &auml;ndern"); ?></a>

<?php
   if(userIsAdmin()) 
     echo "| <a href='acp.php'>ACP</a>";
    } else {
?>
     <a href="../login.php"><?php echo _("Login"); ?></a> | 
     <a href="../register.php"><?php echo _("Registrieren") ?></a>
<?php
    }
if(isset($currentUser))
  echo " ".$currentUser->email." ".$currentUser->vpncode;
?>
