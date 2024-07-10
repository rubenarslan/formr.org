# Formr.org Change Log (check previous change logs in CHANGELOG-v1.md)

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## [v0.21.4] - 10.07.2024
- implement JS changes for material design too
- bug fix for default session code regex
- default to exporting items when exporting run JSONs
- all newly created surveys have a default field "iteration" which is simply an auto-increment number from 1 to number of responses to survey

## [v0.21.3] - 21.06.2024
- autoset timezone for timezone inputs
- make user id/session code length flexible/configurable
- webshim number inputs to make the regional number formatting configurable

## [v0.21.2] - 02.06.2024
- bug fix (minify changed JS correctly)

## [v0.21.1] - 01.06.2024
- simplify integration with labjs et al by 
  - not changing file names on upload
  - allowing larger amounts of data to be stored in text fields
  - allowing uploadable file types to be configurable
- add Reply-To option for email accounts
- allow default email accounts to be configured in settings.php, Reply-To defaults to admin email address
- allow superadmins to manually set admin account email addresses as verified


## [v0.21.0] - 07.03.2024
- make it easier to dockerise formr
- track bower_components to make it easier to collaborate on changes in CSS/JS
- improve cookie handling, so that formr works similarly, whether you use study-specific subdomains or not.

## [v0.20.7] - 02.05.2023


## [v0.20.7] - 02.05.2023
### Fixed
* Adding SMTP accounts that do not support password
### Added
* User account deletion

## [v0.20.6] - 02.05.2023
### Fixed
* Display a warning message for orphaned run units and enable deletion.
* Other minor bug fixes

## [v0.20.5] - 20.10.2022
### Added
* User search by email in admin
* User deletion

### Fixed
* Various bug fixes

## [v0.20.4] - 13.09.2022
### Fixed
* Restart database transactions in case of lock wait timeout or deadlock.
* Check for orphan unit sessions before executing
* Deprecation warnings

## [v0.20.1] - 04.09.2022
### Fixed
* Deprecation warnings.

## [v0.20.1] - 03.09.2022
## [v0.20.0] - 03.09.2022
### Added
* *Require PHP 8.1 or greater*
* Page content configuration (some menu pages can now  be hidden and footer links / logo can be changed)
* Branding configurability.

### Changed
* Re-factor queue-ing mechanism (run units should instruct run session on the next steps)
* Bug fixes

