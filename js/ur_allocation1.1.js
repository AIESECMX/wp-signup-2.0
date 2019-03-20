jQuery(document).ready(function ($){
  var OTHER_TEXT = 'Otra Universidad';
  var ALL_TEXT = 'Todas las Universidades';
  var urAllocation = wp_data.allocationUrl
  //Get LC Allocations from PHP file
  $.get(urAllocation, function (data){

    var lcAllocations = JSON.parse(data)
    //Note: The states of Mexico are hard coded, it would be nice to have them
    //      being able to change dinamically somehow. Not a necessity though
    //      since this data most certainly won't change in the near future
    var states = ['Aguascalientes','Baja California','Baja California Sur','Campeche','CDMX','Chiapas','Chihuahua','Coahuila','Colima','Durango','Estado de México','Guanajuato','Guerrero','Hidalgo','Jalisco','Michoacán','Morelos','Nayarit','Nuevo León','Oaxaca','Puebla','Querétaro','Quintana Roo','San Luis Potosí','Sinaloa','Sonora','Tabasco','Tamaulipas','Tlaxcala','Veracruz','Yucatán','Zacatecas','Otros']

    //--------------------------------------------------------||
    // Task 1: Complete values of options for Mexico's States ||
    //--------------------------------------------------------||
    //Must set variable selectId in main file
    var placeholder = '{{value}}'
    var genericOption = '<option disabled selected value="">Selecciona una opción</option>'
    var optionBase = '<option value="{{value}}">{{value}}</option>'

    var stateSelect = $(selectId);

    //Populate the states from the JSON provided
    $.each(states,function (i,state){
      stateSelect.append(optionBase.replace(new RegExp(placeholder,'g'),state))
    });

    //Delete loading sign, and add a generic option.
    stateSelect.children()[0].remove() //There must be a "loading..." option in main file, otherwise this will cause problems
    stateSelect.prepend(genericOption)

    //-----------------------------------------------------------------------------------------------||
    // Task 2: Attach onChange event to State Selector to display University Allocations accordingly ||
    //-----------------------------------------------------------------------------------------------||
    //Must set variable universityId in main file

    stateSelect.change(function (){
      var universitySelect = $(universityId)
      var wrapper = $(universityId+'-wrapper')
      var stateVal = $(selectId).val()
      universitySelect.empty(); // Here the array length is zero

      var filteredUni = lcAllocations.filter(function (el) {
        return stateVal == el[stateField]
      }).sort(function (a,b){
        if(a[universityField] === OTHER_TEXT || b[universityField] === OTHER_TEXT) {
          return a[universityField] === OTHER_TEXT ? 1 : -1;
        }
        return a[universityField].localeCompare(b[universityField],'la')
      });
      
      // If there is at least one university to select, then append "Select an option" beforehand
      if(filteredUni.length > 1 ) {
        universitySelect.append(genericOption);
      }

      // Append all JSON allocations into the DOM (as options for University Select)
      filteredUni.forEach(function (allocation) {
        universitySelect.append(
          optionBase.replace(placeholder,allocation[stateField]+' - '+allocation[universityField])
          .replace(placeholder,allocation[universityField])
        );
      })

      // Just in case the JSON is empty for that state (for some weird reason) add "All Universities" option
      if(filteredUni.length === 0) {
        universitySelect.append(
          optionBase.replace(placeholder,'other')
          .replace(placeholder,ALL_TEXT)
        )
      }
      
      //If there are not segmented universities (only the default one), do not show "University" field
      if(filteredUni.length === 1) {
        //Hides the university field
        wrapper.css('display', 'none')
        universitySelect.prop("disabled", true);
      }
      else {
        //Shows the university field
        wrapper.css('display', 'flex')
        universitySelect.prop("disabled", false);
      }
    });

  })

});

//Some Array.prototype polyfills (just in case old IE users are around)
if (!Array.prototype.filter) {
  Array.prototype.filter = function(func, thisArg) {
    'use strict';
    if ( ! ((typeof func === 'Function') && this) )
        throw new TypeError();

    var len = this.length >>> 0,
        res = new Array(len), // preallocate array
        c = 0, i = -1;
    if (thisArg === undefined)
      while (++i !== len)
        // checks to see if the key was set
        if (i in this)
          if (func(t[i], i, t))
            res[c++] = t[i];
    else
      while (++i !== len)
        // checks to see if the key was set
        if (i in this)
          if (func.call(thisArg, t[i], i, t))
            res[c++] = t[i];

    res.length = c; // shrink down array to proper size
    return res;
  };
}

// Production steps of ECMA-262, Edition 5, 15.4.4.18
// Reference: http://es5.github.io/#x15.4.4.18
if (!Array.prototype.forEach) {

  Array.prototype.forEach = function(callback/*, thisArg*/) {

    var T, k;

    if (this == null) {
      throw new TypeError('this is null or not defined');
    }

    // 1. Let O be the result of calling toObject() passing the
    // |this| value as the argument.
    var O = Object(this);

    // 2. Let lenValue be the result of calling the Get() internal
    // method of O with the argument "length".
    // 3. Let len be toUint32(lenValue).
    var len = O.length >>> 0;

    // 4. If isCallable(callback) is false, throw a TypeError exception. 
    // See: http://es5.github.com/#x9.11
    if (typeof callback !== 'function') {
      throw new TypeError(callback + ' is not a function');
    }

    // 5. If thisArg was supplied, let T be thisArg; else let
    // T be undefined.
    if (arguments.length > 1) {
      T = arguments[1];
    }

    // 6. Let k be 0.
    k = 0;

    // 7. Repeat while k < len.
    while (k < len) {

      var kValue;

      // a. Let Pk be ToString(k).
      //    This is implicit for LHS operands of the in operator.
      // b. Let kPresent be the result of calling the HasProperty
      //    internal method of O with argument Pk.
      //    This step can be combined with c.
      // c. If kPresent is true, then
      if (k in O) {

        // i. Let kValue be the result of calling the Get internal
        // method of O with argument Pk.
        kValue = O[k];

        // ii. Call the Call internal method of callback with T as
        // the this value and argument list containing kValue, k, and O.
        callback.call(T, kValue, k, O);
      }
      // d. Increase k by 1.
      k++;
    }
    // 8. return undefined.
  };
}
