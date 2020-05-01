<?php

use CRM_Cpptmembership_ExtensionUtil as E;

/**
 * Utility methods for cpptmembership
 */
class CRM_Cpptmembership_Utils {

  /**
   * Function to return list of permissioned contacts for a given contact and relationship type.
   * Copied and modified from CRM_Contact_BAO_Relationship::getPermissionedContacts(), with
   * improvements made to support both a=>b and b=>a relationship types.
   *
   * @param int $contactID
   *   contact id whose permissioned contacts are to be found.
   * @param int $relTypeId
   *   one or more relationship type id's.
   * @param string $name
   * @param string $contactType
   *
   * @return array
   *   Array of contacts
   */
  public static function getPermissionedContacts($contactID, $relTypeId = NULL, $name = NULL, $contactType = NULL) {
    $contacts = [];
    $args = [1 => [$contactID, 'Integer']];
    $relationshipTypeClause = $contactTypeClause = '';

    if ($relTypeId) {
      // @to-do relTypeId is only ever passed in as an int. Change this to reflect that -
      // probably being overly conservative by not doing so but working on stable release.
      $relationshipTypeClause = 'AND cr.relationship_type_id IN (%2) ';
      $args[2] = [$relTypeId, 'String'];
    }

    if ($contactType) {
      $contactTypeClause = ' AND cr.relationship_type_id = crt.id AND  if(cr.contact_id_a = %1, crt.contact_type_b, crt.contact_type_a) = %3 ';
      $args[3] = [$contactType, 'String'];
    }

    $query = "
SELECT cc.id as id, cc.sort_name as name
FROM civicrm_relationship cr, civicrm_contact cc, civicrm_relationship_type crt
WHERE
  (
    (
      cr.contact_id_a         = %1 AND
      cr.is_permission_a_b    = 1
    )
  OR
    (
      cr.contact_id_b         = %1 AND
      cr.is_permission_b_a    = 1
    )
  ) AND
  cc.id = if(cr.contact_id_a = %1, cr.contact_id_b, cr.contact_id_a) AND
  IF(cr.end_date IS NULL, 1, (DATEDIFF( CURDATE( ), cr.end_date ) <= 0)) AND
  cr.is_active = 1 AND
  cc.is_deleted = 0
  $relationshipTypeClause
  $contactTypeClause
";

    if (!empty($name)) {
      $name = CRM_Utils_Type::escape($name, 'String');
      $query .= "
AND cc.sort_name LIKE '%$name%'";
    }

    $dao = CRM_Core_DAO::executeQuery($query, $args);
    while ($dao->fetch()) {
      $contacts[$dao->id] = [
        'name' => $dao->name,
        'value' => $dao->id,
      ];
    }

    return $contacts;
  }

  public static function getContributionPageConfigWarnings($contributionPageId) {
    $warnings = [];
    $contributionPageGet = civicrm_api3('contributionPage', 'get', [
      'id' => $contributionPageId,
      'sequential' => 1,
    ]);
    if ($contributionPageGet['count']) {
      $contributionPage = $contributionPageGet['values'][0];
      if ($contributionPage['is_recur']) {
        $warnings[] = E::ts('This Contribution Page is selected under "CPPT Recertification Page", but is also configured with "Recurring Contributions"; you should resolve this conflict before continuing.');
      }
      $ufJoinGet = civicrm_api3('ufJoin', 'get', [
        'entity_table' => 'civicrm_contribution_page',
        'is_active' => 1,
        'entity_id' => $contributionPageId,
        'module' => ['IN' => ['soft_credit', 'on_behalf']],
        'sequential' => 1,
      ]);
      foreach ($ufJoinGet['values'] as $value) {
        if ($value['module'] == 'soft_credit') {
          $warnings[] = E::ts('This Contribution Page is selected under "CPPT Recertification Page", but is also configured with "Honoree Section Enabled"; you should resolve this conflict before continuing.');
        }
        if ($value['module'] == 'on_behalf') {
          $warnings[] = E::ts('This Contribution Page is selected under "CPPT Recertification Page", but is also configured with "Allow individuals to contribute and / or signup for membership on behalf of an organization?"; you should resolve this conflict before continuing.');
        }
      }

      // Membership should  not be enabled.
      $membershipBlockCount = civicrm_api3('MembershipBlock', 'getCount', [
        'sequential' => 1,
        'is_active' => 1,
        'entity_table' => "civicrm_contribution_page",
        'entity_id' => $contributionPageId,
      ]);
      if ($membershipBlockCount) {
        $warnings[] = E::ts('This Contribution Page is selected under "CPPT Recertification Page", but is also configured with "Membership Section Enabled?"; you should resolve this conflict before continuing.');
      }

      // Price set should contain the configured price field.
      $priceSetField = civicrm_api3('PriceField', 'get', [
        'sequential' => 1,
        'is_active' => 1,
        'is_enter_qty' => 1,
        'return' => ['price_set_id'],
        'id' => _cpptmembership_getSetting('cpptmembership_priceFieldId'),
      ]);
      $hasPriceField = FALSE;
      if ($priceSetField['count']) {
        $priceSetId = CRM_Utils_Array::value('price_set_id', $priceSetField['values'][0]);
        $query = "SELECT * FROM civicrm_price_set_entity WHERE entity_table = 'civicrm_contribution_page' AND entity_id = %1 AND price_set_id = %2";
        $queryParams = [
          '1' => [$contributionPageId, 'Int'],
          '2' => [$priceSetId, 'Int'],
        ];
        $dao = CRM_Core_DAO::executeQuery($query, $queryParams);
        if ($dao->N) {
          $hasPriceField = TRUE;
        }
      }
      if (!$hasPriceField) {
        $warnings[] = E::ts('This Contribution Page is selected under "CPPT Recertification Page", but it does not use a Price Set containing the configured CPPT Price Field; you should resolve this conflict before continuing.');
      }

    }
    return $warnings;
  }

  public static function membershipPaymentNeedsResolution($membership) {
    // Needs resolution UNLESS they are IN AT LEAST ONE of these categories:
    // - membership "end date" is for the period prior to the currently due period; OR
    // - membership start and end dates are identical, and the end date is within the currently due period.
    //

    // We will return FALSE as soon as we meet one of these criteria, or TRUE if none are met.

    $currentlyDueEndDate = self::getCurrentlyDueEndDate();
    $previouslyDueEndDate = date('Y-m-d', strtotime('-1 year', strtotime($currentlyDueEndDate)));
    // Criteria: membership "end date" is for the period prior to the currently due period;
    if ($membership["end_date"] == $previouslyDueEndDate) {
      return FALSE;
    }

    // Criteria: membership start and end dates are identical, and the end date is within the currently due period.
    if ($membership['start_date'] == $membership['end_date']) {
      $endTime = strtotime($membership['end_date']);
      $currentlyDueEndTime = strtotime($currentlyDueEndDate);
      $previouslyDueEndTime = strtotime($previouslyDueEndDate);
      if ($endTime > $previouslyDueEndTime && $endTime <= $currentlyDueEndDate) {
        return FALSE;
      }
    }

    // No criteria were met; return TRUE.
    return TRUE;
  }

  public static function membershipHasPendingCurrentPayment($membership) {
    // Has current pending payment, if they meet ALL of these criteria:
    // - Is not membershipPaymentNeedsResolution(); AND
    // - Has a payment with 'pending' status.

    // We will return FALSE as soon as we FAIL one of these criteria, or TRUE if none are FAILED.

    // Criteria: Is not membershipPaymentNeedsResolution();
    if (self::membershipPaymentNeedsResolution($membership)) {
      return FALSE;
    }

    // Criteria: Has a payment with 'pending' status.
    $count = civicrm_api3('MembershipPayment', 'getCount', [
      'sequential' => 1,
      'membership_id' => $membership['id'],
      'return' => 'contribution_id.contribution_status_id',
      'contribution_id.contribution_status_id' => [
        'IN' => [
          2, //'Pending',
          5, //'In Progress',
          6, //'Overdue',
          8, //'Partially paid',
        ],
      ],
    ]);
    if (!$count) {
      return FALSE;
    }

    // No criteria were FAILED; return TRUE.
    return TRUE;
  }

  public static function membershipHasCompletedCurrentPayment($membership) {
    // Has completed current payment if they meet ALL of these criteria:
    // - Membership start and end dates are not identical; AND
    // - Membership end date is equal to the currently due period.

    // We will return FALSE as soon as we FAIL one of these criteria, or TRUE if none are FAILED.

    // Criteria: Membership start and end dates are not identical
    if ($membership['start_date'] == $membership['end_date']) {
      return FALSE;
    }

    // Criteria: Membership end date is equal to the currently due period.
    if ($membership['end_date'] != self::getCurrentlyDueEndDate()) {
      return FALSE;
    }

    // No criteria were FAILED; return TRUE.
    return TRUE;
  }

  /**
   * Get end date for currently due period in format 'Y-m-d'
   * (this format is suitable for mysql and strtotime().)
   *
   * @param Int $now time() value for the relevant date. If omitted, the current time() is used.
   * @return String date in format 'Y-m-d'
   */
  public static function getCurrentlyDueEndDate($now = FALSE) {
    // Determine payent $cutoff as most recent cutoff day.
    if (!$now) {
      $now = time();
    }
    $cutoffThisYearFormatted = date('Y') . '-' . _cpptmembership_getSetting('cpptmembership_cutoffMonthDayEnglish');
    $cutoffThisYearTime = strtotime($cutoffThisYearFormatted);
    if ($now >= $cutoffThisYearTime) {
      return date('Y') . '-12-31';
    }
    else {
      return (date('Y') - 1) . '-12-31';
    }
  }

  public static function correctMembershipDatesForCpptContribution($contribution, $checkEndDate = FALSE) {
    // If $contribution is numeric, we assume it's a contribution ID. Load the
    // contribution from the database as an array.
    if (is_numeric($contribution)) {
      $contributionBao = new CRM_Contribute_BAO_Contribution();
      $contributionBao->id = $contribution;
      $contributionBao->find();
      $contributionBao->fetch();
      $contribution = $contributionBao->toArray();
    }
    $completedContributionStatusID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    if (CRM_Utils_Array::value('contribution_status_id', $contribution) != $completedContributionStatusID) {
      // contribution is not completed; there's nothing to do here. just return.
      return;
    }

    $contributionId = $contribution['id'];
    // Figure out which CPPT memberships, if any, are associated with this contribution.
    $membershipPayment = civicrm_api3('MembershipPayment', 'get', [
      'contribution_id' => $contributionId,
      'membership_id.membership_type_id' => _cpptmembership_getSetting('cpptmembership_cpptMembershipTypeId'),
      'return' => ['membership_id.end_date', 'membership_id'],
      'options' => ['limit' => 0],
    ]);
    // At this point, we know which cppt memberships (if any) are associated with
    // this contribution. But we're still not sure if we need to correct the date.
    // It depends on whether or not civicrm has already incremented the date wrongly,
    // and THAT depends on whether civicrm was IN A POSITION to make that change.
    // That's why we need $checkEndDate.
    //
    // $checkEndDate is TRUE when we're coming from the _post hook on 'contribution' 'edit'.
    // At that point in the execution, civicrm MAY have known about the membership, or
    // maybe not. (This is because we create the membershipPayment records in the _postProcess
    // hook for the contribution page, which fires AFTER the _post hook on 'contribution' 'edit'.
    // For a credit card payment, this means the completed contribution already
    // passes through _post hook on 'contribution' 'edit' before the membershipPayment
    // records are created. But on a 'pay later' contribution, the contribution is
    // not set to 'completed' until sometime later by manual editing, which is
    // of course a long time after the CPPT form is submitted, so of course at that
    // point the membershipPayment records were already created.) So when coming
    // from the _post hook on 'contribution' 'edit', we need to check the date,
    // because within that hook we can't tell if we have a credit card contribution
    // on the spot, or a 'pay later' contribution being completed days later.
    // In this case, for each of these memberships, if the end_date is the last day of this year,
    // that would only be because CiviCRM has set it that way automatically,
    // according to its way of handling completed membership payments, which
    // is to set the end date to the current membership period. No staff member
    // should be setting CPPT membership end date to that value.
    // So in that case, we know we should set it to the corret value, which
    // is the last day in the period for which this payment was due. And we can
    // know that based on the date of the contribution, because the payment
    // page will only accept payments for contributions that need payment
    // for the currently due period.
    //
    // $checkEndDate is FALSE when we're coming from the _postProcess hook for
    // the contribution. At that point we know we've already created the membershipPayment
    // records, but we can't tell if we have a credit card contribution on the spot,
    // or a 'pay later' contribution to be completed days later. However, if it was
    // a 'pay later', we won't even get this far, because we already returned (above)
    // if the contribution status is not 'completed'. So if we're here and $checkEndDate
    // is FALSE, then we know we've just completed a credit card payment immediately
    // upon submitting the contribution page; therefore we can be confident that
    // the end date should be set to the last date of the currently due period,
    // regardless of what the current end_date is.
    //
    $lastDayOfThisYearTime = strtotime(date('Y') . '-12-31');
    $membershipIdsToFix = [];
    foreach ($membershipPayment['values'] as $value) {
      $endDateTime = strtotime(CRM_Utils_Array::value('membership_id.end_date', $value));
      if (!$checkEndDate || $endDateTime == $lastDayOfThisYearTime) {
        $membershipIdsToFix[] = CRM_Utils_Array::value('membership_id', $value);
      }
    }
    if (!empty($membershipIdsToFix)) {
      $paymentDuePeriodEndDate = CRM_Cpptmembership_Utils::getCurrentlyDueEndDate(strtotime($contribution['receive_date']));
      foreach ($membershipIdsToFix as $membershipId) {
        $membership = civicrm_api3('Membership', 'create', [
          'id' => $membershipId,
          'end_date' => $paymentDuePeriodEndDate,
        ]);
      }
    }
  }

}
