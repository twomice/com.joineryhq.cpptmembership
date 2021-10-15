<?php

require_once 'CRM/Core/Form.php';

use CRM_Cpptmembership_ExtensionUtil as E;

/**
 * Form controller class for extension Settings form.
 * Borrowed heavily from
 * https://github.com/eileenmcnaughton/nz.co.fuzion.civixero/blob/master/CRM/Civixero/Form/XeroSettings.php
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Cpptmembership_Form_Settings extends CRM_Core_Form {

  private static $settingFilter = array('group' => 'cpptmembership');
  private static $extensionName = 'com.joineryhq.cpptmembership';
  private $_submittedValues = array();
  private $_settings = array();

  public function __construct(
  $state = NULL, $action = CRM_Core_Action::NONE, $method = 'post', $name = NULL
  ) {

    $this->setSettings();

    parent::__construct(
      $state = NULL, $action = CRM_Core_Action::NONE, $method = 'post', $name = NULL
    );
  }

  public function buildQuickForm() {
    $settings = $this->_settings;
    foreach ($settings as $name => $setting) {
      if (isset($setting['quick_form_type'])) {
        switch ($setting['html_type']) {
          case 'Select':
            $this->add(
              // field type
              $setting['html_type'],
              // field name
              $setting['name'],
              // field label
              $setting['title'],
              $this->getSettingOptions($setting), NULL, CRM_Utils_Array::value('html_attributes', $setting)
            );
            break;

          case 'CheckBox':
            $this->addCheckBox(
              // field name
              $setting['name'],
              // field label
              $setting['title'],
              array_flip($this->getSettingOptions($setting))
            );
            break;

          case 'Radio':
            $this->addRadio(
              // field name
              $setting['name'],
              // field label
              $setting['title'],
              $this->getSettingOptions($setting)
            );
            break;

          default:
            $add = 'add' . $setting['quick_form_type'];
            if ($add == 'addElement') {
              $this->$add($setting['html_type'], $name, E::ts($setting['title']), CRM_Utils_Array::value('html_attributes', $setting, array()));
            }
            else {
              $this->$add($name, E::ts($setting['title']));
            }
            break;
        }
      }
      $descriptions[$setting['name']] = E::ts($setting['description']);

      if (!empty($setting['X_form_rules_args'])) {
        $rules_args = (array) $setting['X_form_rules_args'];
        foreach ($rules_args as $rule_args) {
          array_unshift($rule_args, $setting['name']);
          call_user_func_array(array($this, 'addRule'), $rule_args);
        }
      }
    }
    $this->assign("descriptions", $descriptions);

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());

    $breadCrumb = array(
      'title' => E::ts('CPPT Membership Settings'),
      'url' => CRM_Utils_System::url('civicrm/admin/cpptmembership/settings', 'reset=1'),
    );
    CRM_Utils_System::appendBreadCrumb(array($breadCrumb));

    // Do some verification on form load
    $this->_verifySettingsOnFormLoad();

    parent::buildQuickForm();
  }

  /**
   * Performs the server side validation.
   * @since     1.0
   * @return bool
   *   true if no error found
   * @throws    HTML_QuickForm_Error
   */
  public function validate() {
    $error = parent::validate();
    $submittedMonthDay = CRM_Utils_Array::value('cpptmembership_cutoffMonthDayEnglish', $this->_submitValues);
    $formattedMonthDay = date('m-d', strtotime("2001-{$submittedMonthDay}"));
    if ($submittedMonthDay != $formattedMonthDay) {
      $this->setElementError('cpptmembership_cutoffMonthDayEnglish', E::ts('"Payment cut-off month and day" must be in the format MM-DD.'));
    }

    // Ensure at least one price field is specified:
    if (
      !CRM_Utils_Array::value('cpptmembership_priceFieldId', $this->_submitValues)
      && !CRM_Utils_Array::value('cpptmembership_currentPriceFieldId', $this->_submitValues)
    ) {
      $requirePriceFieldErrorMessage = E::ts('You must define at least one price field.');
      $this->setElementError('cpptmembership_priceFieldId', $requirePriceFieldErrorMessage);
      $this->setElementError('cpptmembership_currentPriceFieldId', $requirePriceFieldErrorMessage);
    }

    $this->_validatePriceField('cpptmembership_priceFieldId');
    $this->_validatePriceField('cpptmembership_currentPriceFieldId');

    return (0 == count($this->_errors));
  }

  public function postProcess() {
    $this->_submittedValues = $this->exportValues();
    $this->saveSettings();
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/cpptmembership/settings', 'reset=1'));
    parent::postProcess();
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  private function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons". These
    // items don't have labels. We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  /**
   * Define the list of settings we are going to allow to be set on this form.
   */
  private function setSettings() {
    if (empty($this->_settings)) {
      $this->_settings = self::getSettings();
    }
  }

  private static function getSettings() {
    $settings = civicrm_api3('setting', 'getfields', array('filters' => self::$settingFilter));
    return $settings['values'];
  }

  /**
   * Get the settings we are going to allow to be set on this form.
   */
  private function saveSettings() {
    $settings = $this->_settings;
    $values = array_intersect_key($this->_submittedValues, $settings);
    civicrm_api3('setting', 'create', $values);

    // Save any that are not submitted, as well (e.g., checkboxes that aren't checked).
    $unsettings = array_fill_keys(array_keys(array_diff_key($settings, $this->_submittedValues)), NULL);
    civicrm_api3('setting', 'create', $unsettings);

    CRM_Core_Session::setStatus(" ", E::ts('Settings saved.'), "success");
  }

  /**
   * Set defaults for form.
   *
   * @see CRM_Core_Form::setDefaultValues()
   */
  public function setDefaultValues() {
    static $ret;
    if (!isset($ret)) {
      $result = civicrm_api3('setting', 'get', array(
        'return' => array_keys($this->_settings),
        'sequential' => 1,
      ));
      $ret = CRM_Utils_Array::value(0, $result['values']);
    }
    return $ret;
  }

  public function getSettingOptions($setting) {
    if (!empty($setting['X_options_callback']) && is_callable($setting['X_options_callback'])) {
      return call_user_func($setting['X_options_callback']);
    }
    else {
      return CRM_Utils_Array::value('X_options', $setting, array());
    }
  }

  private static function getMembershipTypeOptions() {
    return ['' => '- ' . E::ts('select') . ' -'] + CRM_Member_BAO_Membership::buildOptions('membership_type_id');
  }

  private static function getSoftCreditTypeOptions() {
    return ['' => '- ' . E::ts('select') . ' -'] + CRM_Contribute_BAO_ContributionSoft::buildOptions('soft_credit_type_id');
  }

  private static function getContributionPageOptions() {
    $contributionPages = CRM_Contribute_DAO_Contribution::buildOptions('contribution_page_id');
    $options = ['' => '- ' . E::ts('select') . ' -'];
    foreach ($contributionPages as $contributionPageId => $contributionPageTitle) {
      $options[$contributionPageId] = "{$contributionPageTitle} (ID={$contributionPageId})";
    }
    return $options;
  }

  private static function getPriceFieldOptions() {
    $options = ['' => '- ' . E::ts('select') . ' -'];
    $result = civicrm_api3('PriceField', 'get', [
      'sequential' => 1,
      'is_active' => 1,
      'is_enter_qty' => 1,
      'return' => ["price_set_id.title", "label"],
      'options' => ['sort' => "price_set_id.title, label", 'limit' => 0],
    ]);
    foreach ($result['values'] as $value) {
      $options[$value['id']] = $value['price_set_id.title'] . ' :: ' . $value['label'];
    }
    return $options;
  }

  private static function getStatusOptions() {
    return ['' => '- ' . E::ts('select') . ' -'] + CRM_Member_BAO_Membership::buildOptions('status_id');
  }

  /**
   * Upon displaying the form (i.e., only if it's not being submitted now),
   * perform some checks on the configured Contribution Page to ensure it qualifies.
   */
  private function _verifySettingsOnFormLoad() {
    if (!$this->_flagSubmitted) {
      $defaults = $this->setDefaultValues();
      if ($contributionPageId = CRM_Utils_Array::value('cpptmembership_cpptContributionPageId', $defaults)) {
        $warnings = CRM_Cpptmembership_Utils::getContributionPageConfigWarnings($contributionPageId);
        foreach ($warnings as $warning) {
          CRM_Core_Session::setStatus($warning, NULL, NULL, ['expires' => 0]);
        }
      }
    }
  }

  private function _validatePriceField($priceFieldSettingName) {
    $priceFieldId = CRM_Utils_Array::value($priceFieldSettingName, $this->_submitValues);
    if (empty($priceFieldId)) {
      return;
    }
    $priceSetField = civicrm_api3('PriceField', 'get', [
      'sequential' => 1,
      'return' => ['price_set_id'],
      'id' => $priceFieldId,
    ]);
    if ($priceSetField['count']) {
      $priceSetId = CRM_Utils_Array::value('price_set_id', $priceSetField['values'][0]);
      $submittedContributionPageId = CRM_Utils_Array::value('cpptmembership_cpptContributionPageId', $this->_submitValues);
      $query = "SELECT * FROM civicrm_price_set_entity WHERE entity_table = 'civicrm_contribution_page' AND entity_id = %1 AND price_set_id = %2";
      $queryParams = [
        '1' => [$submittedContributionPageId, 'Int'],
        '2' => [$priceSetId, 'Int'],
      ];
      $dao = CRM_Core_DAO::executeQuery($query, $queryParams);
      if (!$dao->N) {
        $this->setElementError($priceFieldSettingName, E::ts('This Price Field is not part of the Price Set configured for the given Contribution Page.'));
      }
    }
  }

}
