/*global VuFind */
/*exported setUpHoldRequestForm, setupHoldEditForm */
function setUpHoldRequestForm(recordId) {
  $('#requestGroupId').change(function requestGroupChange() {
    var $emptyOption = $("#pickUpLocation option[value='']");
    $("#pickUpLocation option[value!='']").remove();
    if ($('#requestGroupId').val() === '') {
      $('#pickUpLocation').attr('disabled', 'disabled');
      return;
    }
    $('#pickUpLocationLabel i').addClass("fa fa-spinner icon-spin");
    var params = {
      method: 'getRequestGroupPickupLocations',
      id: recordId,
      requestGroupId: $('#requestGroupId').val()
    };
    $.ajax({
      data: params,
      dataType: 'json',
      cache: false,
      url: VuFind.path + '/AJAX/JSON'
    })
      .done(function holdPickupLocationsDone(response) {
        var defaultValue = $('#pickUpLocation').data('default');
        $.each(response.data.locations, function holdPickupLocationEach() {
          var option = $('<option></option>').attr('value', this.locationID).text(this.locationDisplay);
          // Make sure to compare locationID and defaultValue as Strings since locationID may be an integer
          if (String(this.locationID) === String(defaultValue) || (defaultValue === '' && this.isDefault && $emptyOption.length === 0)) {
            option.attr('selected', 'selected');
          }
          $('#pickUpLocation').append(option);
        });

        $('#pickUpLocationLabel i').removeClass("fa fa-spinner icon-spin");
        $('#pickUpLocation').removeAttr('disabled');
      })
      .fail(function holdPickupLocationsFail(/*response*/) {
        $('#pickUpLocationLabel i').removeClass("fa fa-spinner icon-spin");
        $('#pickUpLocation').removeAttr('disabled');
      });
  });
  $('#requestGroupId').change();
}

function setupHoldEditForm() {
  $('#frozen').on('change', function updateFrozen() {
    var $frozenUntil = $('#frozen_until');
    if ($(this).val() === '1') {
      $frozenUntil.removeAttr('disabled');
    } else {
      $frozenUntil.val('').attr('disabled', 'disabled');
    }
  }).trigger('change');
}
