# Changelog

All notable changes to Extra Chill Roadie will be documented in this file.

## [0.7.0] - 2026-05-23

### Added
- wire events-admin cap + switch roadie bridge to cap check

### Fixed
- fix(frontend-chat): clear default 'AI' fab_icon so FAB shows 'Roadie' alone

## [0.6.0] - 2026-05-21

### Added
- feat(token-bridge): migrate to frontend-agent-chat 0.8.x token surface

## [0.5.2] - 2026-05-19

### Fixed
- satisfy Roadie lint rules
- brand Roadie frontend chat

## [0.5.1] - 2026-05-09

### Fixed
- canonical JSON Schema for chat tools (#5)
- convert roadie tools to canonical JSON Schema for DM 0.106.1
- declare data-machine as required plugin

## [0.5.0] - 2026-04-07

### Added
- add EC-to-DM token bridge CSS for frontend chat widget

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
