<?php

class CRM_Cpptmembership_APIWrappers_Membership {

  /**
   * Change parameters so that output is limited to relationship-permissioned contacts.
   */
  public function fromApiInput($apiRequest) {
    $userCid = CRM_Core_Session::singleton()->getLoggedInContactID();
    if ($userCid && $orgId = CRM_Utils_Array::value('cpptLimitRelatedMembersOrgId', $apiRequest['params'])) {
      $organizations = CRM_Cpptmembership_Utils::getPermissionedContacts($userCid, NULL, NULL, 'Organization');
      if (array_key_exists($orgId, $organizations)) {
        $related = CRM_Cpptmembership_Utils::getPermissionedContacts($orgId, NULL, NULL, 'Individual');
        $relatedCids = array_keys($related);
        $apiRequest['params']['contact_id'] = ['IN' => $relatedCids];
        // We're limiting to related contacts, but in fact the api will have its
        // own limitations, most notably blocking access to contacts if I don't
        // have 'view all contacts'. So we skip permissions checks.
        $apiRequest['params']['check_permissions'] = FALSE;
        $apiRequest['params']['membership_type_id'] = 'CPPT';
        $apiRequest['params']['return'] = ["contact_id.display_name", "contact_id.sort_name", "contact_id.id"];
        // Default to 0 limit.
        $apiRequest['params']['options']['limit'] = CRM_Utils_Array::value('limit', $apiRequest['params']['options']['limit'], 0);
        $apiRequest['params']['options']['sort'] = 'contact_id.sort_name';
      }
    }
    return $apiRequest;
  }

  /**
   * Munges the result before returning it to the caller.
   */
  public function toApiOutput($apiRequest, $result) {
    // Determine $cutoff as most recent Oct 1.
    $now = time();
    $monthDay = 'october 1';
    $cutoffThisYear = strtotime($monthDay);
    if ($now > $cutoffThisYear) {
      $cutoff = $cutoffThisYear;
    }
    else {
      $cutoff = strtotime("$monthDay -1 year");
    }
    $cutoffDateString = date('Y-m-d', $cutoff);
    // Test each membership to see if it has a payment after the cutoff date.
    foreach ($result['values'] as &$value) {
      $value['paymentCount'] = civicrm_api3('MembershipPayment', 'getCount', [
        'sequential' => 1,
        'membership_id' => $value['id'],
        'contribution_id.receive_date' => ['>=' => $cutoffDateString],
        'contribution_id.contribution_status_id' => "Completed",
      ]);
    }
    return $result;
  }

}
