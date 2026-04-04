# Changelog

All notable changes to Extra Chill Roadie will be documented in this file.

## [0.4.2] - 2026-04-04

### Changed
- convert manage_user_profile to use ECRoadie_PlatformTool + REST

## [0.4.1] - 2026-04-03

### Changed
- extract shared cross-site REST logic into ECRoadie_PlatformTool base class

### Fixed
- replace switch_to_blog with cross-site REST for all tools

## [0.4.0] - 2026-04-03

### Added
- add bridge onboarding config for Roadie agent
- sync Roadie auth policy for Beeper access

### Fixed
- rely on Data Machine access APIs for Roadie

## [0.3.0] - 2026-03-29

### Added
- add team membership bridge to DM agent access

## [0.2.0] - 2026-03-29

### Added
- initial scaffold with EC platform chat tools

### Changed
- rename to extrachill-roadie (EC platform tooling)

### Fixed
- initialize changelog with v0.1.0 entry
- PHP 7.4 compatibility — replace match expressions and union types
- remove Network: true — per-site activation only

## [0.1.0] - 2026-03-29

### Added

- Initial scaffold with EC platform chat tools (manage_artist_profile, manage_link_page, manage_user_profile, manage_community)
