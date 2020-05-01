(function(ts) {
  CRM.$(function($) {

    /**
     * JS change handler for "members" checkboxes.
     *
     */
    var cpptUpdateTotal = function cpptUpdateTotal(e) {
      var countChecked = $('input[type="checkbox"].cppt-member:checked:visible').length;
      $('div.other_amount-section div.other_amount-content input[type="text"][id^="price_"]').val(countChecked).keyup();
      $('div.crm-submit-buttons').hide();
      if (countChecked) {
        $('div.crm-submit-buttons').show();
      }
    }

    /**
     * JS change handler for "select a person" entityref field.
     *
     */
    var cpptOrganizationChange = function cpptOrganizationChange(e) {
      var orgId = $('#cppt_organization').val();
      
      $('div.cppt_names-org').hide();
      $('div.cppt_names-section').hide();
      $('div#pricesetTotal').hide();
      $('p#cppt-haspayment-notice').hide();
      $('p#cppt-payment-pending-notice').hide();
      $('p#cppt-unpayable-notice').hide();
      $('p#cppt-no-members-notice').hide();
      
      if (orgId > 0) {
        $('div.cppt_names-org').hide();
        var orgNamesSectionId = 'cppt_names-org-id-' + orgId;
        $('#' + orgNamesSectionId).show();
        $('div.cppt_names-section').show();
        $('div#pricesetTotal').show();
        
        // Show explanation if any are disabled.
        if (paymentNotices[orgId].completed) {
          $('p#cppt-haspayment-notice').show();        
        }
        if (paymentNotices[orgId].pending) {
          $('p#cppt-payment-pending-notice').show();        
        }
        if (paymentNotices[orgId].unpayable) {
          $('p#cppt-unpayable-notice').show();        
        }
        
        // Show explanation if ther are none entirely.
        console.log('input[type=checkbox].cppt-member.cppt-member-org-' + orgId);
        if (!$('input[type=checkbox].cppt-member.cppt-member-org-' + orgId).length) {
          $('p#cppt-no-members-notice').show();        
        }
        
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
    `);
    $('div.cppt_names-section').after($('div#pricesetTotal'));

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
        if ($('input#' + checkboxId).attr('disabled')) {
          $('label[for="' + checkboxId + '"]').css('opacity', '0.5');
        }
      }
    }
    
    // Define shorthand object for tracking payment notices.
    var paymentNotices = {};
    for (orgId in CRM.vars.cpptmembership.organizationMemberships) {
      paymentNotices[orgId] = {};
      for (i in CRM.vars.cpptmembership.organizationMemberships[orgId]) {      
        var membership = CRM.vars.cpptmembership.organizationMemberships[orgId][i];
        if (membership.hasCompletedCurrentPayment) {
          paymentNotices[orgId].completed = true;
        }
        else if (membership.hasPendingCurrentPayment) {
          paymentNotices[orgId].pending = true;          
        }
        else if (membership.paymentNeedsResolution) {
          paymentNotices[orgId].unpayable = true;          
        }
        if (
          paymentNotices[orgId].completed 
          && paymentNotices[orgId].pending
          && paymentNotices[orgId].unpayable
        ) {
          break;
        }
      }
    }    
    
    // Create an explanation for currently paid members.
    $('div.cppt_names-section div.content').append('<p style="margin-top: 1em; display:none;" id="cppt-haspayment-notice">* Re-certification payment is current and need not be submitted.</p>');
    // Create an explanation for payment-pending members.
    $('div.cppt_names-section div.content').append('<p style="margin-top: 1em; display:none;" id="cppt-payment-pending-notice">&dagger; A payment already pending; you may wish to contact our office to complete that payment.</p>');
    // Create an explanation for those whose payment situation is too outdated to handle here.
    $('div.cppt_names-section div.content').append('<p style="margin-top: 1em; display:none;" id="cppt-unpayable-notice">&Dagger; Please contact our office to rectify matters with regard to this certificate holder.</p>');
    // Create an explanation for no-members-found.
    $('div.cppt_names-section div.content').append('<p style="margin-top: 1em; display:none;" id="cppt-no-members-notice">This organization has no CPPT certificate holders on record.</p>');

    // Remove the bhfe table, which should be empty by now.
    $('table#bhfe_table').remove();

    //  Set change handler for all cppt-member checkboxes
    $('input[type="checkbox"].cppt-member').change(cpptUpdateTotal);

    //  hide not-you message:
    $('div.crm-not-you-message').hide();
    //  hide other amount section:
    $('div.other_amount-section').hide(); 
    $('div.cppt_names-section').hide();
    $('div#pricesetTotal').hide();
    // Set change hanler for 'cppt_organization'
    $('select#cppt_organization').change(cpptOrganizationChange);
    cpptOrganizationChange();

  });
}(CRM.ts('com.joineryhq.cpptmembership')));
