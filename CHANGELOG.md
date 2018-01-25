# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## [0.15.1] - 2018-01-25
### Changed
- Adhere to base database changes

## [0.15.0] - 2017-12-28
### Added
- Added package discovery
- DF-1224 Added ability to set different default limits (max_records_returned) per service
- DF-927 Added support for Amazon IAM authentication
### Changed
- DF-1150 Update copyright and support email
- Separated resources from resource handlers
- Fixed swagger definitions

## [0.14.0] - 2017-11-03
- Upgrade Swagger to OpenAPI 3.0 specification

## [0.13.0] - 2017-09-18
### Added
- DF-1060 Support for data retrieval (GET) caching and configuration
### Fixed
- Cleanup primary and unique key handling

## [0.12.0] - 2017-08-17
### Changed
- Reworking API doc usage and generation
- Rework schema interface for database services in order to better control caching
- Set config-based cache prefix

## [0.11.1] - 2017-07-31
### Fixed
- Correct use of static resources

## [0.11.0] - 2017-07-27
### Added
- Adding new regions
### Changed
- Separating base schema from SQL schema, cleanup config usage
### Fixed
- DF-1115 Fixed S3 file listing when number of files is too large
- DF-1144 Made DELETE behavior consistent across local and all remote file services

## [0.10.0] - 2017-06-05
### Changed
- Cleanup - removal of php-utils dependency

## [0.9.0] - 2017-04-21
### Added
- DF-811 Added support for upsert

## [0.8.0] - 2017-03-03
- Major restructuring to upgrade to Laravel 5.4 and be more dynamically available

### Changed
- Squelched pg_internal schema space
- Upgraded to latest filtering for DynamoDB

## [0.7.0] - 2017-01-16
- Adhere to refactored df-core, see df-database and df-email
- Clean out use of MERGE verb, handled at router/controller level
- Cleanup schema management issues

## [0.6.0] - 2016-11-17
### Changed
- DB base class changes to support field configuration across all database types
- Change base create and update table methods to allow for native settings

### Fixed
- DF-853 Fixed broken SNS APIs
- Trim out parenthesis of single filter
- Type marshalling for boolean

## [0.5.0] - 2016-10-03
### Added
- Beta release of Redshift service support

### Changed
- DF-641 Download files in chunks
- DF-826 Protecting secret key using service config rework from df-core

## [0.4.0] - 2016-08-21
### Changed
- General cleanup from declaration changes in df-core for service doc and providers

## [0.3.1] - 2016-07-08
### Changed
- DF-763 Removed unsupported fields from API DOCs for NoSql services
- Updating test cases

## [0.3.0] - 2016-05-27
### Changed
- Moved seeding functionality to service provider to adhere to df-core changes

## [0.2.2] - 2016-03-11
### Fixed
- Table listing on latest sdk version

## [0.2.1] - 2016-03-08
### Fixed
- Upgrade to latest 3.x sdk version
- Swagger output cleanup

## [0.2.0]
### Changed
- **MAJOR** Updated code base to use OpenAPI (fka Swagger) Specification 2.0 from 1.2

## [0.1.2] - 2015-12-18
### Changed
- Sync up with changes in df-core for schema classes

## [0.1.1] - 2015-11-20
### Fixed
- Fixed internal logic to use ColumnSchema from df-core instead of arrays.
- Fixed reported record creation issue.

## 0.1.0 - 2015-10-24
First official release working with the new [df-core](https://github.com/dreamfactorysoftware/df-core) library.

[Unreleased]: https://github.com/dreamfactorysoftware/df-aws/compare/0.15.1...HEAD
[0.15.1]: https://github.com/dreamfactorysoftware/df-aws/compare/0.15.0...0.15.1
[0.15.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.14.0...0.15.0
[0.14.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.13.0...0.14.0
[0.13.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.12.0...0.13.0
[0.12.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.11.1...0.12.0
[0.11.1]: https://github.com/dreamfactorysoftware/df-aws/compare/0.11.0...0.11.1
[0.11.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.10.0...0.11.0
[0.10.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.9.0...0.10.0
[0.9.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.8.0...0.9.0
[0.8.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.7.0...0.8.0
[0.7.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.6.0...0.7.0
[0.6.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.5.0...0.6.0
[0.5.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.4.0...0.5.0
[0.4.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.3.1...0.4.0
[0.3.1]: https://github.com/dreamfactorysoftware/df-aws/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.2.1...0.3.0
[0.2.2]: https://github.com/dreamfactorysoftware/df-aws/compare/0.2.1...0.2.2
[0.2.1]: https://github.com/dreamfactorysoftware/df-aws/compare/0.2.0...0.2.1
[0.2.0]: https://github.com/dreamfactorysoftware/df-aws/compare/0.1.2...0.2.0
[0.1.2]: https://github.com/dreamfactorysoftware/df-aws/compare/0.1.1...0.1.2
[0.1.1]: https://github.com/dreamfactorysoftware/df-aws/compare/0.1.0...0.1.1
