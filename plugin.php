<?php
/*
Plugin Name: AIESEC EXPA Registration 
Description: Plugin based on gis_curl_registration script by Dan Laush upgraded to Wordpress plugin by Krzysztof Jackowski, updated and optimized for WP podio and getResponse by Enrique Suarez, revamped by Sergio Garcia
Version: 2.0.0
Author: Sergio Garcia
Author URI: https://www.linkedin.com/in/sgs_garcia
License: GPL 
TEST: YES
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

function getFormWithError($form,$code) {
  $msg = null;
  switch($code) {
    case "email":
      $msg = "Ya te has registrado con esta dirección de correo. Intenta de nuevo con otro correo electrónico.";
      break;
    case "validation":
      $msg = "Los datos ingresados no son válidos, por favor intenta de nuevo.";
      break;
    case "captcha":
      $msg = "La verificación CAPTCHA es incorrecta, por favor intenta de nuevo.";
      break;
  }

  if($msg != null) {
    $form = str_replace('<div id="error" class="error"><p></p></div>','<div id="error" class="error-show"><p>'.$msg.'</p></div>',$form);
  }

  return $form;
}

//General Sign-up Form
function signup_form( $atts ) {
    load_scripts();

    //This part is legacy, better leave it as is
    $a = shortcode_atts( array(
        'program' => '',
    ), $atts );

    $configs = include('config.php');
    $form = getForm("",$configs);
    
    //Legacy: Better leave it "as is"
    if(isset($_GET["thank_you"]) && $_GET["thank_you"]==="true") {
      return $configs["thank-you-message"]; 
    }
    if (isset($_REQUEST['error'])) {
      return getFormWithError($form,$_REQUEST['error']);    
    }

    return $form;

}
add_shortcode('signup-form', 'signup_form');


//OGT
function signup_form_ogt( $atts ) {
    load_scripts("--gt-color");

    //This part is legacy, better leave it as is
    $a = shortcode_atts( array(
        'program' => '',
    ), $atts );

    $configs = include('config.php');
    $form = getForm("ogt",$configs);
    
    //Legacy: Better leave it "as is"
    if(isset($_GET["thank_you"]) && $_GET["thank_you"]==="true") {
      return $configs["thank-you-message"]; 
    }
    if (isset($_REQUEST['error'])) {
      return getFormWithError($form,$_REQUEST['error']);    
    }

    return $form;
}
add_shortcode( 'signup-form-ogt', 'signup_form_ogt' );




//OGV
function signup_form_ogv( $atts ) {
    load_scripts("--gv-color");

    //This part is legacy, better leave it as is
    $a = shortcode_atts( array(
        'program' => '',
    ), $atts );

    $configs = include('config.php');
    $form = getForm("ogv",$configs);

    //Legacy: Better leave it "as is"
    if(isset($_GET["thank_you"]) && $_GET["thank_you"]==="true") {
      return $configs["thank-you-message"]; 
    }
    if (isset($_REQUEST['error'])) {
      return getFormWithError($form,$_REQUEST['error']);    
    }

    return $form;
}
add_shortcode( 'signup-form-ogv', 'signup_form_ogv' );

//OGE
function signup_form_oge( $atts ) {
    load_scripts("--ge-color");

    //This part is legacy, better leave it as is
    $a = shortcode_atts( array(
        'program' => '',
    ), $atts );

    $configs = include('config.php');
    $form = getForm("oge",$configs);
    
    //Legacy: Better leave it "as is"
    if(isset($_GET["thank_you"]) && $_GET["thank_you"]==="true") {
      return $configs["thank-you-message"]; 
    }
    if (isset($_REQUEST['error'])) {
      return getFormWithError($form,$_REQUEST['error']);    
    }

    return $form;
}
add_shortcode( 'signup-form-oge', 'signup_form_oge' );
