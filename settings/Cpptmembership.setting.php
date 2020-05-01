<?php

use CRM_Cpptmembership_ExtensionUtil as E;

return array(
  'cpptmembership_cpptMembershipTypeId' => array(
    'group_name' => 'Cpptmembership Settings',
    'group' => 'cpptmembership',
    'name' => 'cpptmembership_cpptMembershipTypeId',
    'type' => 'Int',
    'add' => '5.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Membership type to use in CPPT membership form.'),
    'title' => E::ts('Membership Type'),
    'html_type' => 'Select',
    'quick_form_type' => 'Element',
    'X_options_callback' => 'CRM_Cpptmembership_Form_Settings::getMembershipTypeOptions',
    'X_form_rules_args' => array(
      array(ts('The field "Membership Type" is required'), 'required'),
    ),
  ),
  'cpptmembership_cpptContributionPageId' => array(
    'group_name' => 'Cpptmembership Settings',
    'group' => 'cpptmembership',
    'name' => 'cpptmembership_cpptContributionPageId',
    'type' => 'Int',
    'add' => '5.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Contribution page to be used for CPPT renewals'),
    'title' => E::ts('Contribution Page'),
    'html_type' => 'Select',
    'quick_form_type' => 'Element',
    'X_options_callback' => 'CRM_Cpptmembership_Form_Settings::getContributionPageOptions',
    'X_form_rules_args' => array(
      array(ts('The field "Contribution Page" is required'), 'required'),
    ),
  ),
  'cpptmembership_priceFieldId' => array(
    'group_name' => 'Cpptmembership Settings',
    'group' => 'cpptmembership',
    'name' => 'cpptmembership_priceFieldId',
    'type' => 'Int',
    'add' => '5.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Price field which represents the correct "1 per membership" pricing for CPPT. This must be an active field of type "Text / Numeric Quantity", and must be configured in the price set for the above-named Contribution Page.'),
    'title' => E::ts('CPPT Price Field'),
    'html_type' => 'Select',
    'quick_form_type' => 'Element',
    'X_options_callback' => 'CRM_Cpptmembership_Form_Settings::getPriceFieldOptions',
    'X_form_rules_args' => array(
      array(ts('The field "Price Field" is required'), 'required'),
    ),
  ),
  'cpptmembership_cutoffMonthDayEnglish' => array(
    'group_name' => 'Cpptmembership Settings',
    'group' => 'cpptmembership',
    'name' => 'cpptmembership_cutoffMonthDayEnglish',
    'type' => 'String',
    'add' => '5.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Month and day on which "this year" changes to "next year" for the calculation of currently paid CPPT memberships; must be in the format "MM-DD", e.g., "07-13"'),
    'title' => E::ts('Payment cut-off month and day'),
    'html_type' => 'Text',
    // Omitting this line causes the setting to be omitted from the Settings form:
    'quick_form_type' => 'Element',
    'X_form_rules_args' => array(
      array(ts('The field "Payment cut-off month and day" is required'), 'required'),
    ),
  ),
  'cpptmembership_statusId' => array(
    'group_name' => 'Cpptmembership Settings',
    'group' => 'cpptmembership',
    'name' => 'cpptmembership_statusId',
    'type' => 'Int',
    'add' => '5.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('If set, CPPT memberships will always have their status overridden to this value, any time they are updated. (This will not affect existing memberships until the next time they are updated.)'),
    'title' => E::ts('Force CPPT membership to status'),
    'html_type' => 'Select',
    // Omitting this line causes the setting to be omitted from the Settings form:
    'quick_form_type' => 'Element',
    'X_options_callback' => 'CRM_Cpptmembership_Form_Settings::getStatusOptions',
  ),
);
