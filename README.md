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
This is a custom extension for CiviCRM, written for a specific site; it will have 
no relevant functionality on other sites.

Please contact the developer at allen@joineryhq.com to request help with similar 
custom functionality for your own CiviCRM site.
