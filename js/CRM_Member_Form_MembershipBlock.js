(function(ts) {
  CRM.$(function($) {

    // On-change handler for 'is_cppt_membership' checkbox.
    var isCpptMembershipChange = function isCpptMembershipChange() {
      if($('input#is_cppt_membership').is(':checked')) {
        $('input#member_is_active').prop('checked', 1).click();
        $('input#member_is_active').closest('tr').hide();
      }
      else {  
        $('input#member_is_active').closest('tr').show();
      }
    };

    // Give the bhfe elements table an id so we can handle it later.
    $('input#is_cppt_membership').closest('table').attr('id', 'bhfe_table');

    var trMemberIsActive = $('input#member_is_active').closest('tr');
    // remove the 'nowrap' class because it breaks the layout.
    $('table#bhfe_table td').removeClass('nowrap');
    // Move all bhfe table rows into the main table before 'member_is_active'
    $('table#bhfe_table tr').insertBefore(trMemberIsActive);
    $('label[for="is_cppt_membership"').insertAfter('input#is_cppt_membership');

    // Set change hanler for 'is_cppt_membership', and go ahead and run it to start with.
    $('input#is_cppt_membership').change(isCpptMembershipChange);
    isCpptMembershipChange();

    // Remove the bhfe table, which should be empty by now.
    $('table#bhfe_table').remove();

  });
}(CRM.ts('com.joineryhq.cpptmembership')));