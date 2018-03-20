<?php

//Include Composer Autoloader (for CAPTCHA, Podio & Redis PHP APIs)
require '../vendor/autoload.php';
require_once './errors/ExpaExceptions.php';

//Load Private Keys from Configuration File
//Do not keep on local folder. Keep outside of public-web to ensure non authorized people won't enter. Also, chmod this accordingly :)
//$configs_external = include('/home/webmaster/wp-config-files/signup_config.php');
$configs_external = include('../wp_login_config.php'); //Test environment

//|--------------|
//| DEFINITIONS  |
//|--------------|

//Is this debug mode?
// * If yes(1), set captcha sandbox & test Podio Workspace;
// * else(0), set production keys
define('DEBUG',1);

//Config the <form> fields name to be retrieved from $_POST
define('FIRST_NAME',"firstName");
define('LAST_NAME',"lastName");
define('EMAIL',"email");
define('MOBILE_PHONE',"mobilePhone");
define('SOURCE_SELECT',"sourceSelect");
define('STATE_SELECT',"stateSelect");
define('UNIVERSITY_SELECT',"universitySelect");
define('EY_SELECT',"eySelect");
define('PRODUCT_SELECT',"product");

//Redis Hashes & Sets for in-memory search instead of Podio
define('STATES',"states"); //Hash to save the Podio IDs of States
define('EYS',"ey"); //Set to save the Podio IDs of MX's EYs, this will also be the prefix for the EY hashes to be created to hold EY info
define('UNIVERSITIES',"universities"); //Set to save the Podio IDs of Universities, this will also be the prefix for 'University' hashes to be created to hold EY info

//Define EXPA Information for Sign up
define('MC_ID',"1589");
define('VAM_ID','67'); //EXPA ID of VAM
define('VAM_REDIS','other'); //university code for VAM in Redis
define('MC_NAME',"Mexico");
define('MC_CODE',"+52");
define('OGV',"ogv");
define('OGT',"ogt");
define('OGE',"oge");
const EXPA_PRODS = array(
  OGV => array('program' => 1,'type' => 'person'),
  OGT => array('program' => 2,'type' => 'person'),
  OGE => array('program' => 5,'type' => 'person'),
);

//|--------------|
//| MAIN SCRIPT  |
//|--------------|

//Method can be accessed through GET in order to update redis databases only
$redis = get_redis();

//Main script will only execute if we have a POST request
$_SERVER['REQUEST_METHOD'] === 'POST' or die( 'Plugin can only be accessed through POST.' );

//First check that CAPTCHA is correctly filled
if( check_captcha() ) {
  if( validate_post($_POST) ) {
    //Retrieves the product depending on the source form.
    //If there's no valid product input selects GV as default 
    $product = getProduct($_POST[PRODUCT_SELECT]);

    //Gets EY ID for the lead. If the EY doesn't run a product, it selects VAM as the default EY.
    $ids = getEyIds($product);

    //Step 1: Add EP to EXPA or send to pending queue if EXPA is not available
    try {
      $ep_id = sendToExpa($ids['expa']); //Signs up EP on EXPA with a randomly generated (not yet) password. Sends that password to the user's email (not yet)

    } catch(EXPA\EmailException $e) {
      $ep_id = null;
      error_log("signup_error: Email already exists on EXPA");
      echo "I had an email exception <br>";
    } catch(Exception $e) {
      error_log("signup_error: ".$e->getMessage());
      echo "I had a random exception: ".$e->getMessage()."<br>";
      $ep_id = null;

      //This is supposed to be replaced with thankyou-gv-podio to distinguish only Podio was created after the second try
      header("Location: http://aiesec.org.mx/registro_no");
      //If EP was not added to EXPA, then, set redirection script to pending expa sign-up
      //Also, send to Pub/Sub queue to trigger regular functions
    }
 
    //Step 2: Add EP to Podio or send to pending queue if Podio is not available
    try {
      addToPodio($product,$ids['podio'],isset($ep_id)?$ep_id:null); //EP ID for future feature of PDY anonymization
    } catch (PodioError $e) {
      die($e->getMessage());
    } catch(Exception $e) {
      error_log($e->getTrace());
      die($e->getMessage());
    }
  }
  else {
    //We need some way to log this has happened
    die("Hubo un error al completar los campos. Por favor asegúrese de que todos los datos son correctos e intente de nuevo.");
  }
}
else {
  //Log this has happened just to verify we have humans trying to acces our resources
  die("La verificación CAPTCHA falló. Por favor intente de nuevo");
}

function checkEmailExistsPodio($app,$email) {
  $items = PodioSearchResult::app($app["id"],array('query' => $email,'ref_type' => 'item','limit'=>1));
  return count($items) === 0;
}

function getProduct($data) {
  switch($data) {
    case OGT:
      return OGT;
    case OGE:
      return OGE;
    default:
      return OGV;
  }
}

/**
 * this method check the recpatcha form google and if ther's something wrong goes to an error page
 * @return [type] [description]
 */
function check_captcha(){
  global $configs_external;
    
  if(DEBUG === 1) {
    $recaptcha = new \ReCaptcha\ReCaptcha($configs_external['recaptcha_secret_test']); 
  }
  else {
    $recaptcha = new \ReCaptcha\ReCaptcha($configs_external['recaptcha_secret']);
  }
  $resp = $recaptcha->verify($_POST['g-recaptcha-response'], get_client_ip());
    
  if ($resp->isSuccess()) {
    return true;
  }

  error_log("signup_error: CAPTCHA verification failed. POST values & CAPTCHA errors are sent as reference: ");
  error_log(print_r($_POST,true));
  //error_log($resp->getErrorCodes());
  return false;
}

//TO-DO:
//1. Replace $tmp_pass by random password generator instead of fixed string
//2. Obtain alignment id for EXPA and save it in Podio allocation
function sendToExpa($lc_id){
  //
  // AIESEC GIS Form Submission via cURL.
  // 
  // This is a basic form processor to create new users for the Opportunities Portal
  // so you can create and manage a registration form on your country website.
  //

  $curl = curl_init();
  // Set some options - we are passing in a useragent too here
  curl_setopt_array($curl, array(
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_URL => 'https://auth.aiesec.org/users/sign_in',
      CURLOPT_USERAGENT => 'Codular Sample cURL Request'
      ));
  // Send the request & save response to $resp
  $result = curl_exec($curl);

  // Close request to clear up some resources
  curl_close($curl);

  // extract CSRF token from cURL result
  preg_match('/<meta content="(.*)" name="csrf-token" \/>/', $result, $matches);
  $gis_token = $matches[1];
  echo "GIS CSRF Token: ".$gis_token."<br>";

  $tmp_pass = "Aiesec123"; //getRandPasswd();

  //Set fields from the data sent by the user
  $fields = array(
    'utf' => '✓',
    'authenticity_token' => $gis_token,
    'user' => array(
      'first_name' => htmlspecialchars($_POST[FIRST_NAME]),
      'last_name' => htmlspecialchars($_POST[LAST_NAME]),
      'email' => htmlspecialchars($_POST[EMAIL]),
      'password' => htmlspecialchars($tmp_pass),
      'country_code' => MC_CODE,
      'country' => MC_NAME,
      'mc' => MC_ID,
      'phone' => htmlspecialchars($_POST[MOBILE_PHONE]),
      'lc_input' => $lc_id, //Put here EY code
      'lc' => $lc_id,  //Put here EY code
      //'alignment_id' => '', //Put here alignment ID
      'referral_type' => 'Other' //Put here referral
    )
  );

  $fieldsjs = json_encode($fields);

  // POST form with curl
  $url = "https://auth.aiesec.org/users.json";
  $ch2 = curl_init();
  curl_setopt($ch2, CURLOPT_URL, $url);
  curl_setopt($ch2, CURLOPT_POST, count($fieldsjs));
  curl_setopt($ch2, CURLOPT_POSTFIELDS, $fieldsjs);
  curl_setopt($ch2, CURLOPT_HTTPHEADER, array(                                                                          
      'Content-Type: application/json')                                                                       
  );      
  curl_setopt($ch2, CURLOPT_RETURNTRANSFER, TRUE);
  // give cURL the SSL Cert for Salesforce
  curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);   // TODO: FIX SSL - VERIFYPEER must be set to true
  //
  // "without peer certificate verification, the server could use any certificate,
  // including a self-signed one that was guaranteed to have a CN that matched 
  // the server’s host name."
  // http://unitstep.net/blog/2009/05/05/using-curl-in-php-to-access-https-ssltls-protected-sites/
  // 
  // curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 2);
  // curl_setopt($ch2, CURLOPT_CAINFO, getcwd() . "\CACerts\VeriSignClass3PublicPrimaryCertificationAuthority-G5.crt");
  $result = curl_exec($ch2);

  //echo "This is the expa response: <br>";
  //print($result); //uncoment to see expa response

  $expa_res = json_decode($result,true);

  if(isset($expa_res['errors'])) {
    curl_close($ch2);
    if(isset($expa_res['errors']['email'])) {
      throw new EXPA\EmailException(json_encode($expa_res['errors']));
    }
    else {
      throw new Exception(json_encode($expa_res));
    }
    
  }

  // Check if any error occurred
  if (curl_errno($ch2)) {
    echo "There was an error <br>";
    $e = new Exception(curl_error($ch2));
    curl_close($ch2);
    throw $e;
  }

  $ep_id = $expa_res['person_id'];
  return $ep_id;

}

function curl_errors($ch) {
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_errno= curl_errno($ch);

}

function validate_post($data) { return isset($data[FIRST_NAME]) && isset($data[LAST_NAME]) && isset($data[EMAIL]) && isset($data[MOBILE_PHONE]) && isset($data[SOURCE_SELECT]) && isset($data[STATE_SELECT]); }

//TO-DO: Separate Model construction into "Model" folder
function get_redis() {
  global $configs_external;

  try {
    $redis = new Predis\Client();
    
    if(!$redis->exists(STATES) || !$redis->exists(EYS) || !$redis->exists(UNIVERSITIES) ) {
      
      //If some key is missing, then connect to Podio, we will need it
      Podio::setup($configs_external['podio_id'], $configs_external['podio_key']);
      
      //If "states" key does not exist, then create a hash by retrieving Podio info
      if(!$redis->exists(STATES)) {
        $app = $configs_external[STATES."-app"];

        //Authenticate to app, filter items
        Podio::authenticate_with_app($app["id"],$app["key"]);
        $items = PodioItem::filter($app["id"],array('limit' => 100,'sort_by' => 'title'));
        //NOTE: Hardcoding 'limit' (in line above) can introduce scalability issues (Not likely due to the size of AIESEC operations, most likely to fail is university allocations limit)

        foreach ($items as $item) {
          $redis->hset(STATES,$item->title,$item->id);
        }
      }

      //Same logic than states, just for AIESEC EYs
      if(!$redis->exists(EYS)) {
        $app = $configs_external[EYS."-app"];

        Podio::authenticate_with_app($app["id"],$app["key"]);
        $items = PodioItem::filter($app["id"],array('limit' => 100,'sort_by' => 'title'));

        foreach ($items as $item) {
          $redis->sadd(EYS,$item->title);

          $prods = mapProducts($item->fields[$app["fields"]["products"]]->values);

          $redis->hmset(EYS.":$item->id",array(
            "title" => $item->title,
            "expa" => $item->fields[$app["fields"]["expa"]]->values,
          ));

          foreach ($prods as $prod) {
            $redis->hset(EYS.":$item->id",strtolower($prod),($prod));
          }
        }
        
      }

      //Same logic than states, just for Universities Allocation
      if(!$redis->exists(UNIVERSITIES)) {
        $app = $configs_external[UNIVERSITIES."-app"];

        Podio::authenticate_with_app($app["id"],$app["key"]);
        //This hardcoded limit can fail if there are more than 500 allocations at some point
        //Idea for evolution, add a filter for the semester allocation only
        $items = PodioItem::filter($app["id"],array('limit' => 500,'sort_by' => 'title'));

        foreach ($items as $item) {
          $redis->sadd(UNIVERSITIES,$item->title);

          foreach($item->fields[$app["fields"]["ey"]]->values as $ey) {
            $redis->hmset(UNIVERSITIES.":$item->title",array(
              EYS => $ey->id,
              "id" => $item->id
            ));
            break;
          }
        }
      }

    }

    echo "All Redis Hashes have been updated! <br>\n";

    return $redis;

  }
  catch (Exception $e) {
    throw $e;
  }
}

function mapProducts($prods) {
  $res = array();
  foreach ($prods as $prod) {
    $res[] = $prod["text"];
  }
  return $res;
}

function getEyIds($product = OGV) {
  global $redis;
  
  //If there is no university selection, then VAM should be selected straight away
  // because it's a market for expansion
  if(!isset($_POST[UNIVERSITY_SELECT])) {
    $ey_podio = $redis->hget(UNIVERSITIES.":".VAM_REDIS,EYS);
    echo "Lead Reallocated to VAM<br>";
  }
  else {
    echo "Got University: ".$_POST[UNIVERSITY_SELECT]."<br>";
    $ey_podio = $redis->hget(UNIVERSITIES.":".$_POST[UNIVERSITY_SELECT],EYS);
  }

  $ey_expa = $redis->hget(EYS.":$ey_podio","expa");
  echo "'Should be' EY: ".$redis->hget(EYS.":$ey_podio","title").", expa id: $ey_expa"."<br>";
  echo "EY is running $product? ".($redis->hexists(EYS.":$ey_podio",$product))."<br>";

  //Reallocate in case EY doesn't run product
  if(!$redis->hexists(EYS.":$ey_podio",$product)) {
    $ey_expa = VAM_ID;
    $ey_podio = $redis->hget(UNIVERSITIES.":".VAM_REDIS,EYS);
    echo "Lead Reallocated to VAM<br>";
  }
  
  return array('expa'=>$ey_expa,'podio'=>$ey_podio);
}

function addToPodio($product,$ey_id,$ep_expa_id){
  global $redis,$configs_external;

  try {
    // This is to test the conection with the podio API
    Podio::setup($configs_external['podio_id'], $configs_external['podio_key']);
    
    // Select Podio workspace depending on the product the client signed up for (default is GV)
    $app = getPodioApp($product);
    print_r($app);
    echo "<br><br>";

    //Authenticate in order to have insert permissions
    Podio::authenticate_with_app(intval($app["id"]),$app["key"]);

    echo "Authenticated! <br>";

    // Set up all item fields from submitted answers
    $item = new PodioItem(array('app' => new PodioApp(intval($app["id"])),'fields' =>
      new PodioItemFieldCollection(array(
        new PodioTextItemField(
          array("external_id" => $app["fields"][FIRST_NAME], "values" => $_POST[FIRST_NAME])
        ),
        new PodioTextItemField(
          array("external_id" => $app["fields"][LAST_NAME], "values" => $_POST[LAST_NAME])
        ),
        new PodioTextItemField(
          array("external_id" => $app["fields"][EMAIL], "values" => $_POST[EMAIL])
        ),
        new PodioTextItemField(
          array("external_id" => $app["fields"][MOBILE_PHONE], "values" => $_POST[MOBILE_PHONE])
        ),
        new PodioCategoryItemField(
          array("external_id" => $app["fields"][SOURCE_SELECT], "values" => intval($_POST[SOURCE_SELECT]))
        ),
        new PodioAppItemField(array(
          "external_id" => $app["fields"][STATE_SELECT],
          "values" => [ intval($redis->hget(STATES,$_POST[STATE_SELECT])) ]
        )),
        new PodioAppItemField(array(
          "external_id" => $app["fields"][UNIVERSITY_SELECT],
          "values" => [ intval($redis->hget(UNIVERSITIES.":".$_POST[UNIVERSITY_SELECT],"id")) ]
        )),
        new PodioAppItemField(array(
          "external_id" => $app["fields"][EY_SELECT],
          "values" => [ intval($ey_id) ]
        ))
      ))
    ));

    echo "Defined Podio object with all Fields <br>";

    echo "Inserting into Podio <br>";
    // Insert the object into Podio
    $item->save(); //If there is an error, the function throws an error
    echo "Insertion Done! <br>";
  }
  catch (PodioError $e) {
    echo "There was an error while inserting into Podio<br>";
    throw $e;
  }
  catch (Exception $e) {
    echo "There was a general error while inserting into Podio<br>";
    throw $e;
  }
}

function getPodioApp($product = OGV) {
  global $configs_external;

  if(DEBUG == 1) {
    return $configs_external['test-app'];
  }

  switch($product) {
    case OGT:
      return $configs_external['ogt-app'];
    case OGE:
      return $configs_external['oge-app'];
    default: //Default product will be GV
      return $configs_external['ogv-app'];
  }
}

function getRedirection($product = "") {

  if(DEBUG == 1) {
    return "Location: https://aiesec.org.mx/thankyou-general/";
  }

  switch($product) {
    case OGT:
      return "Location: https://aiesec.org.mx/thankyou-tg/";
    case OGE:
      return "Location: https://aiesec.org.mx/thankyou-ge/";
    case OGV:
      return "Location: https://aiesec.org.mx/thankyou-vg/";
    default:
      return "Location: https://aiesec.org.mx/thankyou-general/";
  }
}

/**
* this method sends check the redirect for the marketing tag manager
*/
function redirect($referer){

    if (strpos($referer,"https://aiesec.org.mx/voluntarioglobal-colombia") !== false  ){
        header("Location: https://aiesec.org.mx/thankyou-col/");
    }elseif(strpos($referer, "https://aiesec.org.mx/voluntarioglobal-brasil") !== false){
        header("Location: https://aiesec.org.mx/thankyou-bra/");
    }elseif(strpos($referer, "https://aiesec.org.mx/voluntarioglobal-peru") !== false ){
        header("Location: https://aiesec.org.mx/thankyou-per/");
    }elseif(strpos($referer, "https://aiesec.org.mx/voluntarioglobal-argentina/")  !== false){
        header("Location: https://aiesec.org.mx/thankyou-arg/");
    }elseif(strpos($referer, "https://aiesec.org.mx/talento-global-ba")  !== false){
        header("Location: https://aiesec.org.mx/thankyou-ba/");
    }elseif(strpos($referer, "https://aiesec.org.mx/talento-global-mkt")   !== false){
        header("Location: https://aiesec.org.mx/thankyou-mkt/");
    }elseif(strpos($referer,"https://aiesec.org.mx/talento-global-it" ) !== false ){
        header("Location: https://aiesec.org.mx/thankyou-it/");
    }elseif(strpos($referer,"https://aiesec.org.mx/talento-global-engineering" )  !== false ){
        header("Location: https://aiesec.org.mx/thankyou-en/");
    }elseif(strpos($referer, "https://aiesec.org.mx/talento-global-teaching") !== false ){
        header("Location: https://aiesec.org.mx/thankyou-tea/");
    }elseif(strpos($referer, "https://aiesec.org.mx/emprendedor")  !== false  or strpos($referer,"https://aiesec.org.mx/jovenes/emprendedor-global/") !== false ){
        header("Location: https://aiesec.org.mx/thankyou-ge/");
    }elseif(strpos($referer, "https://aiesec.org.mx/talento-global") !== false ){
        header("Location: https://aiesec.org.mx/thankyou-tg/");
    }elseif(strpos($referer, "https://aiesec.org.mx/voluntariado")  !== false ){
        header("Location: https://aiesec.org.mx/thankyou-vg/");
    }else{
        header("Location: https://aiesec.org.mx/thankyou-general/");
    }
}

//Legacy function for CAPTCHA, better leave it "as is"
function get_client_ip() {
    $ipaddress = '';
    if (getenv('HTTP_CLIENT_IP'))
        $ipaddress = getenv('HTTP_CLIENT_IP');
    else if(getenv('HTTP_X_FORWARDED_FOR'))
        $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
    else if(getenv('HTTP_X_FORWARDED'))
        $ipaddress = getenv('HTTP_X_FORWARDED');
    else if(getenv('HTTP_FORWARDED_FOR'))
        $ipaddress = getenv('HTTP_FORWARDED_FOR');
    else if(getenv('HTTP_FORWARDED'))
       $ipaddress = getenv('HTTP_FORWARDED');
   else if(getenv('REMOTE_ADDR'))
    $ipaddress = getenv('REMOTE_ADDR');
else
    $ipaddress = 'UNKNOWN';
return $ipaddress;
}
