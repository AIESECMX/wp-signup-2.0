<?php
/*
Plugin Name: AIESEC EXPA Registration 
Description: Plugin based on gis_curl_registration script by Dan Laush upgraded to Wordpress plugin by Krzysztof Jackowski, updated and optimized for WP podio and getResponse by Enrique Suarez, revamped by Sergio Garcia
Version: 2.0.0
Author: Sergio Garcia
Author URI: https://www.linkedin.com/in/sgs_garcia
License: GPL 
*/
wp_enqueue_script('jquery');

defined( 'ABSPATH' ) or die( 'Plugin file cannot be accessed directly.' );

function load_scripts($palette = "--aiesec-color") {
  wp_enqueue_script('signup-main',plugins_url('js/signup_main.js',__FILE__),array('jquery'));

  wp_localize_script('signup-main','wp_data',array(
    'allocationUrl' => plugins_url('model/ur_allocation.php',__FILE__),
    'palette' => $palette,
  ));

  wp_enqueue_script('signup-allocations',plugins_url('js/ur_allocation1.1.js',__FILE__),array('signup-main'),null);
  wp_enqueue_style('purecss','https://cdnjs.cloudflare.com/ajax/libs/pure/1.0.0/pure-min.css');
  wp_enqueue_style('singup-style',plugins_url('css/signupform.css',__FILE__));
}

function getForm($product = "",$configs = null) {
  //Get File
  $form = file_get_contents('form.html',TRUE);
  
  //Do basic replacements
  $actual_link = 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
  
  $form = str_replace("{website_url}",$actual_link,$form);
  $form = str_replace("{website_url}",$actual_link,$form);
  $form = str_replace("{action_url}",plugins_url('controller/signUp.php',__FILE__),$form);
  $form = str_replace("{product}",$product,$form);
  if ($configs != null) {
    $form = str_replace($configs['recaptcha_public_test'],$configs['recaptcha_public'],$form);
  }

  return $form;
}

//General Sign-up Form
function signup_form( $atts ) {
    load_scripts();

    $configs = include('config.php');
    return getForm("",$configs);
}
add_shortcode('signup-form', 'signup_form');

//OGT
function signup_form_ogt( $atts ) {
    load_scripts("--gt-color");

    $configs = include('config.php');
    return getForm("ogt",$configs);
}
add_shortcode( 'signup-form-ogt', 'signup_form_ogt' );

//OGV
function signup_form_ogv( $atts ) {
    load_scripts("--gv-color");

    $configs = include('config.php');
    return getForm("ogv",$configs);
}
add_shortcode( 'signup-form-ogv', 'signup_form_ogv' );

//OGE
function signup_form_oge( $atts ) {
    load_scripts("--ge-color");

    $configs = include('config.php');
    return getForm("oge",$configs);
}
add_shortcode( 'signup-form-oge', 'signup_form_oge' );
