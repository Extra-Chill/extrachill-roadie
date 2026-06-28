# Changelog

All notable changes to Extra Chill Roadie will be documented in this file.

## [0.15.0] - 2026-06-28

### Added
- add read-only search_content tool so Roadie grounds music/editorial answers in the published catalog with citations
- ship Roadie as a committed Data Machine agent bundle
- file_feature_request returns structured {question,choices} for repo disambiguation + dedupe
- infer GitHub repo from the page the user had open (page_url)

### Fixed
- stop Roadie looping present_question — positive framing + structural follow-up

## [0.14.0] - 2026-06-19

### Added
- add inspect_page DOM-read tool so Roadie sees the rendered page
- read-only inspect_code tool + data-machine-events repo map so Roadie can ground events-calendar feedback (#54, #57)

### Changed
- tighten Roadie filing guidance, drop hardcoded specifics

### Fixed
- stop Roadie's present_question loop and ground its UI feedback

## [0.13.0] - 2026-06-17

### Added
- instrument Roadie usage as team-experience analytics events

### Changed
- reference canonical analytics event-name constants (users#129)

## [0.12.0] - 2026-06-17

### Added
- writing assistant tool for editing + submitting your own Studio draft

## [0.11.1] - 2026-06-16

### Changed
- fix host-smoke harness deps so the release preflight runs clean

## [0.11.0] - 2026-06-05

### Added
- register agent-stack components for the wp-codebox browser runtime

## [0.10.1] - 2026-06-01

### Fixed
- use propose-code cap constant in onboarding capability gate
- infer GitHub repo from subsite context and steer issue requests to file_feature_request

## [0.10.0] - 2026-05-31

### Added
- add present_question tool for multiple-choice question cards
- role-aware roadie chat surface (public/team/admin)
- activate roadie mode + real page context in frontend chat
- map chat 0.9-0.13 status tokens in roadie token bridge

### Fixed
- use host-smoke test backend for standalone smoke tests

## [0.9.0] - 2026-05-27

### Added
- grant extra_chill_team the propose-code cap by default

## [0.8.0] - 2026-05-27

### Added
- file_feature_request chat tool for filing GitHub issues from Roadie
- feat(agent-mode): adopt AgentModeRegistry for roadie mode + calling_user_id propagation
- sandbox-backed code contribution flow

### Changed
- refactor(apply-code-change): use GitHubCredentialResolver for git push + gh pr create

### Fixed
- fix(token-bridge): bridge FAB chrome to --accent for visibility

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
