<?php

require_once 'cpptmembership.civix.php';
use CRM_Cpptmembership_ExtensionUtil as E;

/**
 * Implements hook_civicrm_pre().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_pre/
 */
function cpptmembership_civicrm_pre($op, $objectName, $id, &$params) {
  // Don't bother with anything here if CPPT end dates are not locked.
  $lockEndDates = _cpptmembership_getSetting('cpptmembership_lockMembershipEndDate');
  if (!$lockEndDates) {
    return;
  }
  $cpptMembershipTypeId = _cpptmembership_getSetting('cpptmembership_cpptMembershipTypeId');
  if ($objectName == 'Membership' && $op == 'edit') {
    $membership = civicrm_api3('Membership', 'getSingle', ['id' => $id]);
    if ($membership['membership_type_id'] == $cpptMembershipTypeId) {
      // For cppt memberships, lock date to current year, under certain conditions.
      // If they're trying to  set the end date...
      if ($endDate = CRM_Utils_Array::value('end_date', $params)) {
        // If the end date is Dec 31 of some year.
        $endDateMonthDay = date('m-d', strtotime($endDate));
        if ($endDateMonthDay == '12-31') {
          // If the end date is in some future year ...
          $currentYear = date('Y');
          if (date('Y', strtotime($endDate)) > $currentYear) {
            // Then set the end date to Dec 31 in the current year.
            $params['end_date'] = date('Ymd', strtotime("$currentYear-12-31"));
          }
        }
      }
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
  // Define the label.
  $label = E::ts('CPPT Recertification for: ');
  if (empty($form->_submitValues)) {
    // This form has not been submitted, so there's nothing to do.
    return;
  }
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
    $cpptPrice =  _cpptmembership_getCpptPrice();
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
  if ($formName  == 'CRM_Contribute_Form_Contribution_Main') {
    // Only do this for cppt contribution page.
    $contributionPageId = $form->getVar('_id');
    if ($contributionPageId != _cpptmembership_getSetting('cpptmembership_cpptContributionPageId')) {
      return;
    }
    
    //  Define array to store variables for passing to JS.
    $jsVars = [];

    $contactId = CRM_Core_Session::singleton()->getLoggedInContactID();
    $organizations = CRM_Cpptmembership_Utils::getPermissionedContacts($contactId, null, null, 'Organization');

    $organizationOptions =  ['' => '- ' . E::ts('select') . ' -'] + CRM_Utils_Array::collect('name', $organizations);
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
        $hasPaymentMarker = '';
        if (CRM_Utils_Array::value('hasCompletedPayment', $membership)) {
          $hasPaymentMarker = ' *';
          $attributes['disabled'] = 'disabled';
        }
        elseif (CRM_Utils_Array::value('hasPayment', $membership)) {
          $hasPaymentMarker = ' &dagger;';
        }
        $cppt_mid_checkboxes[] = $form->createElement('checkbox', "{$orgId}_{$membershipId}", NULL, "{$membership['contact_id.display_name']}$hasPaymentMarker", $attributes);
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
}

function _cpptmembership_getOrganizationMemberships($orgIds) {
  $organizationMemberships = [];

  // Determine payent $cutoff as most recent Oct 1.
  $now = time();
  $monthDay = _cpptmembership_getSetting('cpptmembership_cutoffMonthDayEnglish');
  $cutoffThisYear = strtotime($monthDay);
  if ($now > $cutoffThisYear) {
    $cutoff = $cutoffThisYear;
  }
  else {
    $cutoff = strtotime("$monthDay -1 year");
  }
  $cutoffDateString = date('Y-m-d', $cutoff);

  // Fetch relevant memberships for each given org.
  foreach ($orgIds as $orgId) {
    $related = CRM_Cpptmembership_Utils::getPermissionedContacts($orgId, NULL, NULL, 'Individual');
    $relatedCids = array_keys($related);
    $apiParams = [];
    $apiParams['contact_id'] = ['IN' => $relatedCids];
    // We're limiting to related contacts, but in fact the api will have its
    // own limitations, most notably blocking access to contacts if I don't
    // have 'view all contacts'. So we skip permissions checks.
    $apiParams['check_permissions'] = FALSE;
    $apiParams['membership_type_id'] = _cpptmembership_getSetting('cpptmembership_cpptMembershipTypeId');
    $apiParams['return'] = ["contact_id.display_name", "contact_id.sort_name", "contact_id.id"];
    // Default to 0 limit.
    $apiParams['sequential'] = 1;
    $apiParams['options']['limit'] = 0;
    $apiParams['options']['sort'] = 'contact_id.sort_name';
    $memberships = civicrm_api3('Membership', 'get', $apiParams);

    // Note whether each membership has a payment after the cutoff date.
    foreach ($memberships['values'] as &$value) {
      $payments = civicrm_api3('MembershipPayment', 'get', [
        'sequential' => 1,
        'membership_id' => $value['id'],
        'return' => 'contribution_id.contribution_status_id',
        'contribution_id.receive_date' => ['>=' => $cutoffDateString],
        'contribution_id.contribution_status_id' => ['IN' => [
          1, //'Completed',
          2, //'Pending',
          5, //'In Progress',
          6, //'Overdue',
          8, //'Partially paid',
        ]],
      ]);
      if ($payments['count']) {
        $value['hasPayment'] = TRUE;
        foreach ($payments['values'] as $payment) {
          if ($payment['contribution_id.contribution_status_id'] == 1) {
            $value['hasCompletedPayment'] = TRUE;
            break;
          }
        }
      }
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
    foreach (array_keys($formValues['cppt_mid']) as $mid) {
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
    'price_field_id' => _cpptmembership_getSetting('cpptmembership_priceFieldId')
  ]);
  return CRM_Utils_Array::value('amount', $priceFieldValue);
}
