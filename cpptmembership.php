<?php

require_once 'cpptmembership.civix.php';
use CRM_Cpptmembership_ExtensionUtil as E;

define('CPPTMEMBERSHIP_BILLING_POLICY_ARREARS', 1);
define('CPPTMEMBERSHIP_BILLING_POLICY_CURRENT', 2);

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
    // Don't botHer unless there's a contribution_page_id, and the related
    // contribution page is our configured CPPT page.
    if (
      ($contributionPageId = CRM_Utils_Array::value('contribution_page_id', $contribution))
      && ($contributionPageId == _cpptmembership_getSetting('cpptmembership_cpptContributionPageId'))
    ) {
      CRM_Cpptmembership_Utils::correctMembershipDatesForCpptContribution($contribution, TRUE);
      // TODO: also, we should block the "renew" action in the UI as much as possible on cppt memberships.
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

  $memberNamesLabel = '';
  $paidMemberships = _cpptmembership_getPaidMembershipsFromFormValues($form->_submitValues);
  if (!empty($paidMemberships)) {
    $memberNames = [];
    foreach ($paidMemberships as $member) {
      $memberNames[] = $member['contact_id.display_name'];
    }
    $memberNamesLabel = implode(', ', $memberNames);
  }

  // Define the label for in-arrears price field, if it's in use..
  $inArrearsPriceFieldId = _cpptmembership_getSetting('cpptmembership_priceFieldId');
  if ($inArrearsPriceFieldId && !empty($memberNamesLabel) && !empty($amounts[$inArrearsPriceFieldId])) {
    $label = E::ts('CPPT Recertification (in arrears for %1) for: %2', [
      '1' => CRM_Utils_Date::customFormat(CRM_Cpptmembership_Utils::getCurrentlyDueEndDate(FALSE, CPPTMEMBERSHIP_BILLING_POLICY_ARREARS), '%Y'),
      '2' => $memberNamesLabel,
    ]);
    foreach ($amounts[$inArrearsPriceFieldId]['options'] as &$option) {
      $option['label'] = $label;
      // There should be only one, so break here.
      break;
    }
  }

  // Define the label for current-period price field, if it's in use..
  $currentPriceFieldId = _cpptmembership_getSetting('cpptmembership_currentPriceFieldId');
  if ($currentPriceFieldId && !empty($memberNamesLabel) && !empty($amounts[$currentPriceFieldId])) {
    $label = E::ts('CPPT Recertification (current period for %1) for: %2', [
      '1' => CRM_Utils_Date::customFormat(CRM_Cpptmembership_Utils::getCurrentlyDueEndDate(FALSE, CPPTMEMBERSHIP_BILLING_POLICY_CURRENT), '%Y'),
      '2' => $memberNamesLabel,
    ]);
    foreach ($amounts[$currentPriceFieldId]['options'] as &$option) {
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
    // Only do this for cppt contribution page.
    $contributionPageId = $form->getVar('_id');
    if ($contributionPageId != _cpptmembership_getSetting('cpptmembership_cpptContributionPageId')) {
      return;
    }
    $contributionId = $form->_contributionID;
    $paidMemberships = _cpptmembership_getPaidMembershipsFromFormValues($form->_params);
    // Create soft credits for in-arrears billing, if in-arrears is in use.
    if ($cpptPrice = _cpptmembership_getCpptPrice(CPPTMEMBERSHIP_BILLING_POLICY_ARREARS)) {
      _cpptmembership_createSoftCredits($paidMemberships, $contributionId, $cpptPrice, CPPTMEMBERSHIP_BILLING_POLICY_ARREARS);
    }
    // Create soft credits for curent-period billing, if current-period is in use.
    if ($cpptPrice = _cpptmembership_getCpptPrice(CPPTMEMBERSHIP_BILLING_POLICY_CURRENT)) {
      _cpptmembership_createSoftCredits($paidMemberships, $contributionId, $cpptPrice, CPPTMEMBERSHIP_BILLING_POLICY_CURRENT);
    }

    // Create membership payment links to each membership
    foreach ($paidMemberships as $paidMembershipId => $paidMembership) {
      // Create membership payment
      $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
        'contribution_id' => $contributionId,
        'membership_id' => $paidMembershipId,
      ]);
    }

    // If fpptaqb exension is enabled and configured, update the aopropriate
    // custom field as configured in the '"Custom "Donating Organization" field for contributions'
    // setting.
    if (CRM_Extension_System::singleton()->getManager()->getStatus('com.joineryhq.fpptaqb') == CRM_Extension_Manager::STATUS_INSTALLED) {
      $customFieldId = Civi::settings()->get('fpptaqb_cf_id_contribution');
      if ($customFieldId && ($form->_params['cppt_organization'] ?? NULL)) {
        civicrm_api3('Contribution', 'create', [
          'id' => $contributionId,
          'custom_' . $customFieldId => $form->_params['cppt_organization'],
        ]);
      }
    }

    // FIXME: we don't know if we should correct for in-arrears dates or current-period dates. Need to add logic for this.
    CRM_Cpptmembership_Utils::correctMembershipDatesForCpptContribution($contributionId);
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
    $warnings = CRM_Cpptmembership_Utils::getContributionPageConfigWarnings($contributionPageId);
    if (!empty($warnings)) {
      throw new CRM_Core_Exception(
        E::ts('This Contribution Page is selected under "CPPT Recertification Page", but it is not properly configured. Please notify the site administrators that the contribution page configuration should be modified.'),
        'cpptmembership_form_misconfiguration',
        [
          'contribution_page_id' => $contributionPageId,
          'Warnings from CRM_Cpptmembership_Utils::getContributionPageConfigWarnings' => $warnings,
        ]
      );
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

/**
 * Implements hook_civicrm_alterContent().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterContent
 */
function cpptmembership_civicrm_pageRun($page) {
  $pageName = $page->getVar('_name');
  if ($pageName == 'CRM_Contact_Page_View_UserDashBoard') {
    // Strip Active Memberships of any CPPT-type memberships.
    $activeMembers = _cpptmembership_strip_cppt_memberships($page->get_template_vars('activeMembers'));
    $page->assign('activeMembers', $activeMembers);
    // Strip Inactive Memberships of any CPPT-type memberships.
    $inactiveMembers = _cpptmembership_strip_cppt_memberships($page->get_template_vars('inactiveMembers'));
    $page->assign('inactiveMembers', $inactiveMembers);
  }
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

function _cpptmembership_getCpptPrice($billingPolicy = CPPTMEMBERSHIP_BILLING_POLICY_ARREARS) {
  $amount = 0;
  $settingName = '';
  if ($billingPolicy == CPPTMEMBERSHIP_BILLING_POLICY_ARREARS) {
    $settingName = 'cpptmembership_priceFieldId';
  }
  elseif ($billingPolicy == CPPTMEMBERSHIP_BILLING_POLICY_CURRENT) {
    $settingName = 'cpptmembership_currentPriceFieldId';
  }

  if ($settingName) {
    $priceFieldId = _cpptmembership_getSetting($settingName);
    if ($priceFieldId) {
      $priceFieldValue = civicrm_api3('priceFieldValue', 'getSingle', [
        'price_field_id' => $priceFieldId,
      ]);
      $amount = CRM_Utils_Array::value('amount', $priceFieldValue);
    }
  }
  return $amount;
}

/**
 * Remove any CPPT-type memberships from a given array of membership records.
 *
 * @param Array $memberships An array of memberships; each membership is expected
 *  to have an element 'membership_type' containing the value of civicrm_membership_type.name
 * @return Array The contents of $memberships with CPPT-type memberships removed.
 */
function _cpptmembership_strip_cppt_memberships($memberships) {
  foreach ($memberships as $id => $membership) {
    if ($membership['membership_type'] == 'CPPT') {
      unset($memberships[$id]);
    }
  }
  return $memberships;
}

function _cpptmembership_createSoftCredits($paidMemberships, $contributionId, $cpptPrice, $billingPolicy) {
  if ($billingPolicy == CPPTMEMBERSHIP_BILLING_POLICY_ARREARS) {
    $softCreditTypeId = _cpptmembership_getSetting('cpptmembership_arrearsSoftCreditType');
  }
  elseif ($billingPolicy == CPPTMEMBERSHIP_BILLING_POLICY_CURRENT) {
    $softCreditTypeId = _cpptmembership_getSetting('cpptmembership_currentSoftCreditType');
  }
  else {
    return;
  }
  foreach ($paidMemberships as $paidMembershipId => $paidMembership) {
    // Create soft credit to contact
    $contributionSoft = civicrm_api3('ContributionSoft', 'create', [
      'contribution_id' => $contributionId,
      'contact_id' => $paidMembership['contact_id.id'],
      'amount' => $cpptPrice,
      'soft_credit_type_id' => $softCreditTypeId,
    ]);
  }
}
