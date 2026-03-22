# CiviCRM: FPPTA: CPPT Recertification Memberships
## com.joineryhq.cpptmembership

Provides special handling of a properly configured contribution page, for specific
features relevant to FPPTA, to wit: to allow for multiple CPPT re-certifications 
to be submitted (and paid) at once.

Also, provides these specific customizations in CiviCRM behavior:

* On the CiviCRM user dashboard (https://drupal.example.org/civicrm/user), under
the Memberships section:
  * Rows displaying data for memberships of type "CPPT" will be hidden.

## Requirements

* PHP v7.0+
* CiviCRM 5.x

## Installation (Web UI)

This extension has not yet been published for installation via the web UI. Please 
see the official CiviCRM documentation for instructions on [Manual installation of native extensions](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/#installing-a-new-extension).

## Usage

* Once installed, configuration is at Administer > CiviMember > CPPT Recertification Page. 

## Support

Support for this package is handled under Joinery's ["As-Is Support" policy](https://joineryhq.com/software-support-levels#as-is-support).

Public issue queue for this package: https://github.com/twomice/com.joineryhq.cpptmembership/issues
