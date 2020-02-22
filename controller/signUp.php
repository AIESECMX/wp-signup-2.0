<?php

//Include Composer Autoloader (for CAPTCHA, Podio & Redis PHP APIs)
require '../vendor/autoload.php';
require_once './errors/ExpaExceptions.php';

//|--------------|
//| DEFINITIONS  |
//|--------------|

//Is this debug mode?
// * If yes(1), set captcha sandbox & test Podio Workspace;
// * else(0), set production keys
define('DEBUG',0);

//Load Private Keys from Configuration File
//Do not keep on local folder. Keep outside of public-web to ensure non authorized people won't enter. Also, chmod this accordingly :)
if(DEBUG == 1) {
  $configs_external = include('../signup_config.php'); //Local Test environment (.gitignore doesn't commit this file, but be careful anyways)
}
else {
  $configs_external = include('/home/webmaster/wp-config-files/signup_config.php'); //Set a location of your choosign
}

//Config the <form> fields name to be retrieved from $_POST
define('FIRST_NAME',"firstName");
define('LAST_NAME',"lastName");
define('EMAIL',"email");
define('MOBILE_PHONE',"mobilePhone");
define('SOURCE_SELECT',"sourceSelect");
define('STATE_SELECT',"stateSelect");
define('UNIVERSITY_SELECT',"universitySelect");

define('COLLEGE_SELECT',"collegeCareerSelect");
define('ENGLISH_SELECT',"englishSelect");
define('FLIGHT_SELECT',"flightSelect");
define('SEMESTER_SELECT',"semesterSelect");

define('EY_SELECT',"eySelect");
define('PRODUCT_SELECT',"product");
define('REFERRAL',"sourceSelect");

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
const REFERRAL_DATA = array(
  "1" => "Facebook",
  "2" => "Facebook",
  "3" => "Search engine",
  "4" => "Twitter",
  "5" => "Instagram",
  "6" => "LinkedIn",
  "8" => "Friend",
  "9" => "Other",
  "10" => "Information booth on campus",
  "11" => "Information booth on campus",
  "12" => "Event",
  "13" => "Media (magazine, TV, newspaper or radio)",
  "14" => "Friend",
  "15" => "Media (magazine, TV, newspaper or radio)",
  "16" => "Event",
  "18" => "Media (magazine, TV, newspaper or radio)",
  "19" => "Classroom presentation",
  "20" => "Other",
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
  //                    REMOVE THIS PART AS SOON AS WE HAVE RANDOM PASSWORDS
  if( validate_post($_POST) && isset($_POST['password']) ) {
    //Retrieves the product depending on the source form.
    //If there's no valid product input selects GV as default 
    $product = getProduct($_POST[PRODUCT_SELECT]);

    //Gets EY ID for the lead. If the EY doesn't run a product, it selects VAM as the default EY.
    $ids = getEyIds($product);
    
    //Step 1: Add EP to EXPA [TO-DO]: or send to pending queue if EXPA is not available
    try {
      $ep_id = sendToExpa($ids['expa']); //Signs up EP on EXPA with a randomly generated (not yet) password. Sends that password to the user's email (not yet)
    } catch(EXPA\EmailException $e) {
      $ep_id = null;
      error_log("signup_error: Email already exists on EXPA");
      echo "I had an email exception <br>";

      // Do not allow for duplicate resgistations, not even on Podio, to make DB cleaning faster
      header("Location: http://aiesec.org.mx/registro_no/?error=email_exists");
      die($e->getMessage());
    } catch(Exception $e) {
      error_log("signup_error: ".$e->getMessage());
      echo "I had a random exception: ".$e->getMessage()."<br>";
      $ep_id = null;

      //This is supposed to be replaced with thankyou-gv-podio to distinguish only Podio was created after the second try
      header("Location: http://aiesec.org.mx/registro_no/?error=expa");
      die($e->getMessage());
      //If EP was not added to EXPA, then, set redirection script to pending expa sign-up
      //Also, send to Pub/Sub queue to trigger regular functions
    }

    //Step 2: Add EP to Podio [TO-DO]: or send to pending queue if Podio is not available
    try {
      addToPodio($product,$ids['podio'],isset($ep_id)?$ep_id:null); //EP ID for future feature of PDY anonymization
    } catch (PodioError $e) {
      //This needs an extra redirection
      header("Location: http://aiesec.org.mx/registro_no/?error=podio");
      die($e->getMessage());
    } catch(Exception $e) {
      error_log($e->getTrace());
      header("Location: http://aiesec.org.mx/registro_no");
      die($e->getMessage());
    }

    header(getRedirection($product));
  }
  else {
    //We need some way to log this has happened
    header("Location: http://aiesec.org.mx/registro_no/?error=validation");
    die("Hubo un error al completar los campos. Por favor asegúrese de que todos los datos son correctos e intente de nuevo.");
  }
}
else {
  //Log this has happened just to verify we have humans trying to acces our resources
  header("Location: http://aiesec.org.mx/registro_no/?error=captcha");
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

  //$tmp_pass = "Aiesec123"; //getRandPasswd();

  //Set fields from the data sent by the user
  $fields = array(
    'utf' => '✓',
    'authenticity_token' => $gis_token,
    'user' => array(
      'first_name' => htmlspecialchars($_POST[FIRST_NAME]),
      'last_name' => htmlspecialchars($_POST[LAST_NAME]),
      'email' => htmlspecialchars($_POST[EMAIL]),
      'password' => htmlspecialchars($_POST['password']), //This is going to be changed for a random passsword!
      'country_code' => MC_CODE,
      'country' => MC_NAME,
      'mc' => MC_ID,
      'phone' => htmlspecialchars($_POST[MOBILE_PHONE]),
      'college_career' => htmlspecialchars($_POST[COLLEGE_SELECT]),
      
      'lc_input' => $lc_id, //Put here EY code
      'lc' => $lc_id,  //Put here EY code
      //'alignment_id' => '', //Put here alignment ID
      'referral_type' => REFERRAL_DATA[$_POST[REFERRAL]],
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

function validate_post($data) { return 
  isset($data[FIRST_NAME]) && 
  isset($data[LAST_NAME]) && 
  isset($data[EMAIL]) && 
  isset($data[MOBILE_PHONE]) && 
  isset($data[SOURCE_SELECT]) && 
  isset($data[STATE_SELECT]) && 
  isset($data[UNIVERSITY_SELECT]) &&
  isset($data[COLLEGE_SELECT]) && 
  isset($data[ENGLISH_SELECT]) && 
  isset($data[FLIGHT_SELECT]) && 
  isset($data[SEMESTER_SELECT]) ; }

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
        // NOTE: Hardcoding 'limit' (in line above) can introduce scalability issues
        // (Not likely due to the size of AIESEC operations, most likely to fail is university allocations limit)

        foreach ($items as $item) {
          $redis->hset(STATES,$item->title,$item->id);
        }
      }

      //Same logic than states, just for AIESEC EYs
      if(!$redis->exists(EYS)) {
        $app = $configs_external[EYS."-app"];

        Podio::authenticate_with_app($app["id"],$app["key"]);
        $items = PodioItem::filter($app["id"],array('limit' => 100,'sort_by' => 'title'));
        // NOTE: Hardcoding 'limit' (in line above) can introduce scalability issues
        // (Not likely due to the size of AIESEC operations, most likely to fail is university allocations limit)

        foreach ($items as $item) {
          if($item->title !== "VAM") {
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
        
      }

      //Same logic than states, just for Universities Allocation
      if(!$redis->exists(UNIVERSITIES)) {
        $app = $configs_external[UNIVERSITIES."-app"];

        Podio::authenticate_with_app($app["id"],$app["key"]);
        //This hardcoded limit will fail if there are more than 500 allocations at some point
        $items = PodioItem::filter($app["id"],array(
          'limit' => 500,
          'sort_by' => 'title',
          'filters' => array(
            $app["fields"]["date"] => array(
              'from' => $configs_external['ur_last_updated'],
              'to' => $configs_external['ur_last_updated']
            )
          )
        ));

        foreach ($items as $item) {
          $full_name = $item->fields[$app["fields"]["fullName"]]->values;
          $redis->sadd(UNIVERSITIES,$full_name);

          // Unset product allocations from previous iterations
          unset($ogv, $ogt, $oge);

          // Get the entities to be assigned for each product
          if(isset($item->fields[$app["fields"]["gv"]]->values)) {
            $ogv = $item->fields[$app["fields"]["gv"]]->values[0]->id;
          }
          if(isset($item->fields[$app["fields"]["gt"]]->values)) {
            $ogt = $item->fields[$app["fields"]["gt"]]->values[0]->id;
          }
          if(isset($item->fields[$app["fields"]["ge"]]->values)) {
            $oge = $item->fields[$app["fields"]["ge"]]->values[0]->id;
          }

          // Afterwards, get the default fallback entity in case there is not any present
          // If there is no fallback entity, then the allocation is not added into redis
          if(isset($item->fields[$app["fields"]["ey"]]->values)) {
            $ey = $item->fields[$app["fields"]["ey"]]->values[0];
            $redis->hmset(UNIVERSITIES.":$full_name",array(
              EYS => $ey->id,
              OGV => isset($ogv) ? $ogv : $ey->id,
              OGT => isset($ogt) ? $ogt : $ey->id,
              OGE => isset($oge) ? $oge : $ey->id,
              "id" => $item->id
            ));
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
  
  echo "Got University: ".$_POST[UNIVERSITY_SELECT]." and product is ".$product."<br>";
  $ey_podio = $redis->hget(UNIVERSITIES.":".$_POST[UNIVERSITY_SELECT],$product);

  $ey_expa = $redis->hget(EYS.":$ey_podio","expa");
  echo "'Should be' EY: ".$redis->hget(EYS.":$ey_podio","title").", expa id: $ey_expa"."<br>";
  echo "EY is running $product? ".($redis->hexists(EYS.":$ey_podio",$product))."<br>";

  //Reallocate in case EY doesn't run product
  if(!$redis->hexists(EYS.":$ey_podio",$product)) {
    echo "Error! selected entity does not run ".$product."<br>";
    $ey_podio = $redis->hget(UNIVERSITIES.":".$_POST[UNIVERSITY_SELECT],EYS);
    $ey_expa = $redis->hget(EYS.":$ey_podio","expa");
    echo "Reallocating lead to fallback entity: ".$redis->hget(EYS.":$ey_podio","title")."<br>";
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
        new PodioTextItemField(
          array("external_id" => $app["fields"][COLLEGE_SELECT], "values" => $_POST[COLLEGE_SELECT])
        ),
        new PodioTextItemField(
          array("external_id" => $app["fields"][ENGLISH_SELECT], "values" => $_POST[ENGLISH_SELECT])
        ),
        new PodioTextItemField(
          array("external_id" => $app["fields"][FLIGHT_SELECT], "values" => $_POST[FLIGHT_SELECT])
        ),
        new PodioTextItemField(
          array("external_id" => $app["fields"][SEMESTER_SELECT], "values" => $_POST[SEMESTER_SELECT])
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
    print_r($e->body);
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
