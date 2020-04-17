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
      // @todo relTypeId is only ever passed in as an int. Change this to reflect that -
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
      $priceSetId = civicrm_api3('PriceField', 'getValue', [
        'sequential' => 1,
        'return' => 'price_set_id',
        'id' => _cpptmembership_getSetting('cpptmembership_priceFieldId'),
      ]);
      $query = "SELECT * FROM civicrm_price_set_entity WHERE entity_table = 'civicrm_contribution_page' AND entity_id = %1 AND price_set_id = %2";
      $queryParams = [
        '1' => [$contributionPageId, 'Int'],
        '2' => [$priceSetId, 'Int'],
      ];
      $dao = CRM_Core_DAO::executeQuery($query, $queryParams);
      if (!$dao->N) {
        $warnings[] = E::ts('This Contribution Page is selected under "CPPT Recertification Page", but it does not use a Price Set containing the configured CPPT Price Field; you should resolve this conflict before continuing.');
      }

    }
    return $warnings;
  }
}
