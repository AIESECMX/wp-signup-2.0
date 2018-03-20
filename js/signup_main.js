var selectId = '#stateSelect'
var universityId = '#universitySelect'
var stateField = 'state'
var lcField = 'lc_name'
var universityField = 'university'

var recaptchaValidation = false

function selectedCaptcha() {
  recaptchaValidation = true
}

function expiredCaptcha() {
  recaptchaValidation = false
}

function validateForm(e) {
  $(this).prop('disabled', true);
  if(recaptchaValidation) return true;
  e.preventDefault();
  $(this).prop('disabled', false);
  alert('Por favor completa la verificación final del formulario')
}


jQuery(document).ready(function ($){
  //--------------------------------------------------||
  // Hook event listener for captcha check on submit  ||
  //--------------------------------------------------||
  var formId = '#signupForm'
  $(formId).submit(validateForm)

  var palette = wp_data ? wp_data.palette : undefined

  //If palette is defined, then set new colors, otherwise keep AIESEC blue as default palette
  if(palette && palette.indexOf('--') != -1) {
    //document.getElementById('main-wrap').style.backgroundColor = "var("+palette+'-background)'
    document.getElementById('signup-head').style.backgroundColor = "var("+palette+')'
    console.log('Changed palette to '+palette)
  }
});

