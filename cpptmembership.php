<?php

require_once 'cpptmembership.civix.php';
use CRM_Cpptmembership_ExtensionUtil as E;

function cpptmembership_civicrm_buildForm($formName, &$form) {
  if ($formName  == 'CRM_Contribute_Form_Contribution_Main') {
    //  fixme: only do this on pages where is_cppt_membership is true
    $contactId = CRM_Core_Session::singleton()->getLoggedInContactID();
    $organizations = CRM_Contact_BAO_Relationship::getPermissionedContacts($contactId, NULL, NULL, 'Organization');
    $form->add('select', 'ccpt_organization', E::ts('Organization'), CRM_Utils_Array::collect('name', $organizations));
    if (!$bhfe) {
      $bhfe = [];
    }
    $bhfe[] = 'ccpt_organization';
    $form->assign('beginHookFormElements', $bhfe);
    CRM_Core_Resources::singleton()->addScriptFile('com.joineryhq.cpptmembership', 'js/CRM_Contribute_Form_Contribution_Main.js');
  }
  elseif ($formName  == 'CRM_Member_Form_MembershipBlock') {
    $form->addElement('checkbox', 'is_cppt_membership', E::ts('Special handling for CPPT memberships?'));
    $bhfe = $form->get_template_vars('beginHookFormElements');
    if (!$bhfe) {
      $bhfe = [];
    }
    $bhfe[] = 'is_cppt_membership';
    $form->assign('beginHookFormElements', $bhfe);
    CRM_Core_Resources::singleton()->addScriptFile('com.joineryhq.cpptmembership', 'js/CRM_Member_Form_MembershipBlock.js');
// fixme: create postProcess hook to save this setting, and accordingly disable recurring, on-behalf, honoree
  }
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

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 *
function cpptmembership_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 *
function cpptmembership_civicrm_navigationMenu(&$menu) {
  _cpptmembership_civix_insert_navigation_menu($menu, 'Mailings', array(
    'label' => E::ts('New subliminal message'),
    'name' => 'mailing_subliminal_message',
    'url' => 'civicrm/mailing/subliminal',
    'permission' => 'access CiviMail',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _cpptmembership_civix_navigationMenu($menu);
} // */
