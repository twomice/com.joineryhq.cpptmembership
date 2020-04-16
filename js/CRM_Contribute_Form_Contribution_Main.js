(function(ts) {
  CRM.$(function($) {

    /**
     * JS change handler for "members" checkboxes.
     *
     */
    var cpptUpdateTotal = function cpptUpdateTotal(e) {
      var rate = 31; // FIXME: take this from membership type, not hard-coded like this.
      var countChecked = $('input[type="checkbox"].cppt-member:checked:visible').length;
      var total = rate * countChecked;
      $('div.other_amount-section div.other_amount-content input[type="text"][id^="price_"]').val(total).keyup();
      $('div.cppt_total-section div.content').html(CRM.formatMoney(total));
      $('div.crm-submit-buttons').hide();
      if (total) {
        $('div.crm-submit-buttons').show();
      }
    }

    /**
     * JS change handler for "select a person" entityref field.
     *
     */
    var cpptOrganizationChange = function cpptOrganizationChange(e) {
      var newVal = $('#cppt_organization').val();
      
      $('div.cppt_names-org').hide();
      $('div.cppt_names-section').hide();
      $('div.cppt_total-section').hide();
      $('p#cppt-haspayment-notice').hide();
      
      if (newVal > 0) {
        $('div.cppt_names-org').hide();
        var orgNamesSectionId = 'cppt_names-org-id-' + newVal;
        $('#' + orgNamesSectionId).show();
        $('div.cppt_names-section').show();
        $('div.cppt_total-section').show();
      }
      // Show explanation if any are disabled.
      if ($('input[type="checkbox"].cppt-member:disabled:visible').length) {
        $('p#cppt-haspayment-notice').show();        
      }
      cpptUpdateTotal();
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
    
    // Move membership checkboxes into  main table.
    for (orgId in CRM.vars.cpptmembership.organizationMemberships) {
      var orgNamesSectionId = 'cppt_names-org-id-' + orgId;
      $('div.cppt_names-section div.content').append('<div id="' + orgNamesSectionId + '" class="cppt_names-org"/>')
      for (i in CRM.vars.cpptmembership.organizationMemberships[orgId]) {
        var membership = CRM.vars.cpptmembership.organizationMemberships[orgId][i];
        var checkboxId = 'cppt_mid_' + orgId + '_' + membership.id;
        $('#' + orgNamesSectionId).append($('table#bhfe_table input[type="checkbox"].cppt-member-org-' + orgId + '#' + checkboxId));
        $('#' + orgNamesSectionId).append($('label[for="' + checkboxId + '"]'));
        $('#' + orgNamesSectionId).append($('<br/>'));        
        if (membership.paymentCount) {
          $('label[for="' + checkboxId + '"]').css('opacity', '0.5');
        }
      }
    }
    // Create an explanation for disabled members.
    $('div.cppt_names-section div.content').append('<p style="margin-top: 1em; display:none;" id="cppt-haspayment-notice">* Certificate holder is current and need not be renewed.</p>');

    // Remove the bhfe table, which should be empty by now.
    $('table#bhfe_table').remove();

    //  Set change handler for all cppt-member checkboxes
    $('input[type="checkbox"].cppt-member').change(cpptUpdateTotal);

    //  hide not-you message:
    $('div.crm-not-you-message').hide();
    //  hide other amount section:
    $('div.other_amount-section').hide();
    $('div.cppt_names-section').hide();
    $('div.cppt_total-section').hide();
    // Set change hanler for 'cppt_organization'
    $('select#cppt_organization').change(cpptOrganizationChange);
    cpptOrganizationChange();


  });
}(CRM.ts('com.joineryhq.cpptmembership')));
