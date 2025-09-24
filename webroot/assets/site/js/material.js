//import './main.js';
import $ from 'jquery';

import 'bootstrap-material-design/dist/js/material.js';
import 'bootstrap-material-design/dist/js/ripples.js';
import 'bootstrap-material-design/dist/css/bootstrap-material-design.css';
import 'bootstrap-material-design/dist/css/ripples.css';


$(function () {
    $('body').addClass('bs-material');   // enable formr’s material CSS overrides
    if ($.material) {
        $.material.init();   // activate Material-Design JS
    }
});

$.noConflict(false);
