<?php

require_once 'cpptmembership.civix.php';
use CRM_Cpptmembership_ExtensionUtil as E;

/**
 * Implements hook_civicrm_post().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_post/
 */
function cpptmembership_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if ($objectName == 'Contribution' && $op == 'edit') {
    // We need to check contribution_page_id, but that may not be in $objectRef,
    // so fetch the full contribution via BAO.
    $contributionBao = new CRM_Contribute_BAO_Contribution();
    $contributionBao->id = $objectId;
    $contributionBao->find();
    $contributionBao->fetch();
    $contribution = $contributionBao->toArray();
    // Don't boter unless there's a contribution_page_id, and the contribution is completed.
    $completedContributionStatusID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    if (
      ($contributionPageId = CRM_Utils_Array::value('contribution_page_id', $contribution))
      && (CRM_Utils_Array::value('contribution_status_id', $contribution) == $completedContributionStatusID)
    ) {
      // Don't bother unless the related contribution page is our configured CPPT page.
      if ($contributionPageId == _cpptmembership_getSetting('cpptmembership_cpptContributionPageId')) {
        // Figure out which CPPT memberships, if any, are associated with this payment.
        $membershipPayment = civicrm_api3('MembershipPayment', 'get', [
          'contribution_id' => $objectId,
          'membership_id.membership_type_id' => _cpptmembership_getSetting('cpptmembership_cpptMembershipTypeId'),
          'return' => ['membership_id.end_date', 'membership_id'],
          'options' => ['limit' => 0],
        ]);
        // For each of these memberships, if the end_date is the last day of this year,
        // that would only be because CiviCRM has set it that way automatically,
        // according to its way of handling completed membership payments, which
        // is to set the end date to the current membership period. No staff member
        // should be setting CPPT membership end date to that value.
        // So in that case, we know we should set it to the corret value, which
        // is the last day in the period for which this payment was due. And we can
        // know that based on the date of the contribution, because the payment
        // page will only accept payments for contributions that need payment
        // for the currently due period.
        $lastDayOfThisYearTime = strtotime(date('Y') . '-12-31');
        $membershipIdsToFix = [];
        foreach ($membershipPayment['values'] as $value) {
          $endDateTime = strtotime(CRM_Utils_Array::value('membership_id.end_date', $value));
          if ($endDateTime == $lastDayOfThisYearTime) {
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

        // TODO: also, we should block the "renew" action in the UI as much as possible on cppt memberships.
      }
    }
  }
}

/**
 * Implements hook_civicrm_pre().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_pre/
 */
function cpptmembership_civicrm_pre($op, $objectName, $id, &$params) {
  // For cppt memberships, we'll ensure the status is always overridden and
  // always set to the status called for in settings.
  if ($objectName == 'Membership' && $op == 'edit') {
    $membership = civicrm_api3('Membership', 'getSingle', ['id' => $id]);
    $cpptMembershipTypeId = _cpptmembership_getSetting('cpptmembership_cpptMembershipTypeId');
    if ($membership['membership_type_id'] == $cpptMembershipTypeId) {
      if ($statusId = _cpptmembership_getSetting('cpptmembership_statusId')) {
        // If the status id is set, override status and force to that status id.
        $params['is_override'] = 1;
        $params['status_override_end_date'] = '';
        $params['status_id'] = $statusId;
      }
      // Force member start date to member since, if available
      $params['start_date'] = CRM_Utils_Array::value('join_date', $params, CRM_Utils_Array::value('join_date', $membership));
    }
  }
}

/**
 * Implements hook_civicrm_buildAmount().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_buildAmount/
 */
function cpptmembership_civicrm_buildAmount($pageType, &$form, &$amounts) {
  // Only do this for cppt contribution page.
  $contributionPageId = $form->getVar('_id');
  if ($contributionPageId != _cpptmembership_getSetting('cpptmembership_cpptContributionPageId')) {
    return;
  }
  if (empty($form->_submitValues)) {
    // This form has not been submitted, so there's nothing to do.
    return;
  }
  // Define the label.
  $label = E::ts('CPPT Recertification for: ');
  $paidMemberships = _cpptmembership_getPaidMembershipsFromFormValues($form->_submitValues);
  $priceFieldId = _cpptmembership_getSetting('cpptmembership_priceFieldId');
  if (!empty($paidMemberships)) {
    $memberNames = [];
    foreach ($paidMemberships as $member) {
      $memberNames[] = $member['contact_id.display_name'];
    }
    $label .= implode(', ', $memberNames);
    foreach ($amounts[$priceFieldId]['options'] as &$option) {
      $option['label'] = $label;
      // There should be only one, so break here.
      break;
    }
  }
}

/**
 * Implements hook_civicrm_postProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postProcess/
 */
function cpptmembership_civicrm_postProcess($formName, $form) {
  if ($formName == 'CRM_Contribute_Form_Contribution_Confirm') {
    $contributionId = $form->_contributionID;
    $cpptPrice = _cpptmembership_getCpptPrice();
    $paidMemberships = _cpptmembership_getPaidMembershipsFromFormValues($form->_params);
    foreach ($paidMemberships as $paidMembershipId => $paidMembership) {
      // Create soft credit to contact
      $contributionSoft = civicrm_api3('ContributionSoft', 'create', [
        'contribution_id' => $contributionId,
        'contact_id' => $paidMembership['contact_id.id'],
        'amount' => $cpptPrice,
        'soft_credit_type_id' => 1,
      ]);
      // Create membership payment
      $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
        'contribution_id' => $contributionId,
        'membership_id' => $paidMembershipId,
      ]);
    }
  }
}

/**
 * Implements hook_civicrm_buildForm().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_buildForm/
 */
function cpptmembership_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_Contribution_Main') {
    // Only do this for cppt contribution page.
    $contributionPageId = $form->getVar('_id');
    if ($contributionPageId != _cpptmembership_getSetting('cpptmembership_cpptContributionPageId')) {
      return;
    }

    //  Define array to store variables for passing to JS.
    $jsVars = [];

    $contactId = CRM_Core_Session::singleton()->getLoggedInContactID();
    $organizations = CRM_Cpptmembership_Utils::getPermissionedContacts($contactId, NULL, NULL, 'Organization');

    $organizationOptions = ['' => '- ' . E::ts('select') . ' -'] + CRM_Utils_Array::collect('name', $organizations);
    $form->add('select', 'cppt_organization', E::ts('Renew for Organization'), $organizationOptions);

    $organizationMemberships = _cpptmembership_getOrganizationMemberships(array_keys($organizations));
    $jsVars['organizationMemberships'] = $organizationMemberships;
    $cppt_mid_checkboxes = [];
    foreach ($organizationMemberships as $orgId => $memberships) {
      foreach ($memberships as $membership) {
        $membershipId = $membership['id'];
        $attributes = [
          'class' => "cppt-member cppt-member-org-{$orgId}",
        ];
        $noteMarker = '';
        if (CRM_Utils_Array::value('hasCompletedCurrentPayment', $membership)) {
          $noteMarker = ' *';
          $attributes['disabled'] = 'disabled';
        }
        elseif (CRM_Utils_Array::value('hasPendingCurrentPayment', $membership)) {
          $noteMarker = ' &dagger;';
        }
        elseif (CRM_Utils_Array::value('paymentNeedsResolution', $membership)) {
          $noteMarker = ' &Dagger;';
          $attributes['disabled'] = 'disabled';
        }
        $cppt_mid_checkboxes[] = $form->createElement('checkbox', "{$orgId}_{$membershipId}", NULL, "{$membership['contact_id.display_name']}$noteMarker", $attributes);
      }
    }
    $form->addGroup($cppt_mid_checkboxes, 'cppt_mid', NULL, '<br />');

    // freeze and re-label email field:
    $element = $form->getElement('email-5');
    $element->freeze();
    $element->setLabel(E::ts('Your email address'));

    $template = CRM_Core_Smarty::singleton();
    $bhfe = $template->get_template_vars('beginHookFormElements');
    if (!$bhfe) {
      $bhfe = [];
    }
    $bhfe[] = 'cppt_organization';
    $bhfe[] = 'cppt_mid';
    $form->assign('beginHookFormElements', $bhfe);
    CRM_Core_Resources::singleton()->addScriptFile('com.joineryhq.cpptmembership', 'js/CRM_Contribute_Form_Contribution_Main.js');
    CRM_Core_Resources::singleton()->addVars('cpptmembership', $jsVars);
  }
  elseif (
    $formName == 'CRM_Contribute_Form_ContributionPage_Settings'
    || $formName == 'CRM_Contribute_Form_ContributionPage_Amount'
    || $formName == 'CRM_Member_Form_MembershipBlock'
    || $formName == 'CRM_Contribute_Form_ContributionPage_ThankYou'
    || $formName == 'CRM_Friend_Form_Contribute'
    || $formName == 'CRM_PCP_Form_Contribute'
  ) {
    // Only do this for cppt contribution page.
    $contributionPageId = $form->getVar('_id');
    if ($contributionPageId != _cpptmembership_getSetting('cpptmembership_cpptContributionPageId')) {
      return;
    }
    $warnings = CRM_Cpptmembership_Utils::getContributionPageConfigWarnings($contributionPageId);
    foreach ($warnings as $warning) {
      CRM_Core_Session::setStatus($warning, NULL, NULL, ['expires' => 0]);
    }
  }
}

function _cpptmembership_getOrganizationMemberships($orgIds) {
  $organizationMemberships = [];

  // Fetch relevant memberships for each given org.
  foreach ($orgIds as $orgId) {
    $related = CRM_Cpptmembership_Utils::getPermissionedContacts($orgId, NULL, NULL, 'Individual');
    $relatedCids = array_keys($related);
    $relatedCids[] = 0;
    $apiParams = [];
    $apiParams['contact_id'] = ['IN' => $relatedCids];
    // We're limiting to related contacts, but in fact the api will have its
    // own limitations, most notably blocking access to contacts if I don't
    // have 'view all contacts'. So we skip permissions checks.
    $apiParams['check_permissions'] = FALSE;
    $apiParams['membership_type_id'] = _cpptmembership_getSetting('cpptmembership_cpptMembershipTypeId');
    $apiParams['return'] = ["contact_id.display_name", "contact_id.sort_name", "contact_id.id", "end_date", "start_date"];
    // Default to 0 limit.
    $apiParams['sequential'] = 1;
    $apiParams['options']['limit'] = 0;
    $apiParams['options']['sort'] = 'contact_id.sort_name';
    $memberships = civicrm_api3('Membership', 'get', $apiParams);

    // Note whether each membership has a payment after the cutoff date.
    foreach ($memberships['values'] as &$value) {
      $value['hasCompletedCurrentPayment'] = CRM_Cpptmembership_Utils::membershipHasCompletedCurrentPayment($value);
      $value['hasPendingCurrentPayment'] = CRM_Cpptmembership_Utils::membershipHasPendingCurrentPayment($value);
      $value['paymentNeedsResolution'] = CRM_Cpptmembership_Utils::membershipPaymentNeedsResolution($value);
    }
    $organizationMemberships[$orgId] = $memberships['values'];
  }
  return $organizationMemberships;
}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function cpptmembership_civicrm_config(&$config) {
  _cpptmembership_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function cpptmembership_civicrm_xmlMenu(&$files) {
  _cpptmembership_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function cpptmembership_civicrm_install() {
  _cpptmembership_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function cpptmembership_civicrm_postInstall() {
  _cpptmembership_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function cpptmembership_civicrm_uninstall() {
  _cpptmembership_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function cpptmembership_civicrm_enable() {
  _cpptmembership_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function cpptmembership_civicrm_disable() {
  _cpptmembership_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function cpptmembership_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _cpptmembership_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function cpptmembership_civicrm_managed(&$entities) {
  _cpptmembership_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function cpptmembership_civicrm_caseTypes(&$caseTypes) {
  _cpptmembership_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function cpptmembership_civicrm_angularModules(&$angularModules) {
  _cpptmembership_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function cpptmembership_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _cpptmembership_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function cpptmembership_civicrm_entityTypes(&$entityTypes) {
  _cpptmembership_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_thems().
 */
function cpptmembership_civicrm_themes(&$themes) {
  _cpptmembership_civix_civicrm_themes($themes);
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
function cpptmembership_civicrm_navigationMenu(&$menu) {
  _cpptmembership_civix_insert_navigation_menu($menu, 'Administer/CiviMember', array(
    'label' => E::ts('CPPT Recertification Page'),
    'name' => 'CPPT Recertification Page',
    'url' => 'civicrm/admin/cpptmembership/settings?reset=1',
    'permission' => 'administer CiviCRM',
    'operator' => 'AND',
    'separator' => NULL,
  ));
  _cpptmembership_civix_navigationMenu($menu);
}

function _cpptmembership_getSetting($settingName) {
  return Civi::settings()->get($settingName);
}

function _cpptmembership_getPaidMembershipsFromFormValues($formValues) {
  $paidMemberships = [];
  if ($orgId = CRM_Utils_Array::value('cppt_organization', $formValues)) {
    $mids = CRM_Utils_Array::value('cppt_mid', $formValues, []);
    foreach (array_keys($mids) as $mid) {
      list($mid_orgId, $mid_membershipId) = explode('_', $mid);
      if ($mid_orgId == $orgId) {
        $membership = civicrm_api3('Membership', 'get', [
          'sequential' => 1,
          'return' => ['contact_id.display_name', 'contact_id.id'],
          'id' => $mid_membershipId,
        ]);
        if ($membership['id']) {
          $paidMemberships[$mid_membershipId] = $membership['values'][0];
        }
      }
    }
  }
  return $paidMemberships;
}

function _cpptmembership_getCpptPrice() {
  $priceFieldValue = civicrm_api3('priceFieldValue', 'getSingle', [
    'price_field_id' => _cpptmembership_getSetting('cpptmembership_priceFieldId'),
  ]);
  return CRM_Utils_Array::value('amount', $priceFieldValue);
}
