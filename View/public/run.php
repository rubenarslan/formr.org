<?php

Template::load('header', array('title' => $title, 'css' => $css, 'js' => $js));

echo $run_content;

Template::load('footer');
