(function(ts) {
  CRM.$(function($) {
console.log('main.js');

    /**
     * JS change handler for "members" checkboxes.
     *
     */
    var cpptUpdateTotal = function cpptUpdateTotal(e) {
      var rate = 31; // FIXME: take this from membership type, not hard-coded like this.
      var countChecked = $('input.cppt-membership-id:checked').length;
      var total = rate * countChecked;
      $('div.other_amount-section div.other_amount-content input[type="text"][id^="price_"]').val(total).keyup();
      $('div.cppt_total-section div.content').html(CRM.formatMoney(total));

    }

    /**
     * JS change handler for "select a person" entityref field.
     *
     */
    var cpptOrganizationChange = function cpptOrganizationChange(e) {
      var newVal = $('#cppt_organization').val();
      $('div.cppt_names-section div.content').empty();
      $('div.cppt_names-section').hide();
      $('div.cppt_total-section').hide();
      cpptUpdateTotal();
      if (newVal > 0) {
        // Only if we've selected an org. Fetch the related cppt members.
        CRM.api3('Membership', 'get', {
          "sequential": 1,
          "cpptLimitRelatedMembersOrgId": newVal,
        }).then(function(result) {
          console.log('result', result)
          // Upon returning api, display checkboxes.
          $('div.cppt_names-section').show();
          $('div.cppt_total-section').show();
          var hasPaidMembers = false;
          for (i in result.values) {
            // FIXME: if no values were found, say so to the user.
            var value = result.values[i];
            var checkbox_id = 'cppt-individual-' + value.id;
            var label_id = 'label-' + checkbox_id;
            
            $('div.cppt_names-section div.content').append('<input type="checkbox" id="' + checkbox_id +'" class="cppt-membership-id" name="cppt_mid" value="' + value.id + '"> ');
            $('div.cppt_names-section div.content').append('<label id="' + label_id + '" for="' + checkbox_id +'">' + value['contact_id.display_name'] + '</label><BR />');
            if (value.paymentCount * 1) {
              hasPaidMembers = true;
              $('input#' + checkbox_id).attr('disabled', 'disabled');
              $('label#' + label_id).after(' *');
              $('label#' + label_id).css('opacity', '0.5');
            }
            $('#' + checkbox_id).change(cpptUpdateTotal);
          };
          if (hasPaidMembers) {
            $('div.cppt_names-section div.content').append('<p style="margin-top: 1em;">* Certificate holder is current and need not be renewed.</p>');
          }
          cpptUpdateTotal();
        }, function(error) {
        });
      }
    };
    
    $('div.email-5-section').after(`
      <div class="crm-public-form-item crm-section cppt_organization-section">
        <div class="label"></div>
        <div class="content">
        </div>
        <div class="clear"></div>
      </div>
      <div class="crm-public-form-item crm-section cppt_names-section">
        <div class="label">Members to renew</div>
        <div class="content">
        </div>
        <div class="clear"></div>
      </div>
      <div class="crm-public-form-item crm-section cppt_total-section">
        <div class="label">Total Due:</div>
        <div class="content">
        </div>
        <div class="clear"></div>
      </div>
    `);

    // Give the bhfe elements table an id so we can handle it later.
    $('select#cppt_organization').closest('table').attr('id', 'bhfe_table');

    // Move cppt_organization field and label into div structure.
    $('div.cppt_organization-section div.label').append($('table#bhfe_table label[for="cppt_organization"]'));
    $('div.cppt_organization-section div.content').append($('table#bhfe_table select#cppt_organization'));
    // Remove the bhfe table, which should be empty by now.
    $('table#bhfe_table').remove();


    //  hide not-you message:
    $('div.crm-not-you-message').hide();
    // Set change hanler for 'cppt_organization'
    //  hide other amount section:
    $('div.other_amount-section').hide();
    // Set change hanler for 'cppt_organization'
    $('select#cppt_organization').change(cpptOrganizationChange);
    $('div.cppt_names-section').hide();
    $('div.cppt_total-section').hide();


  });
}(CRM.ts('com.joineryhq.cpptmembership')));