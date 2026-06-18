<?php
/**
 * Inspect Code Tool (read-only)
 *
 * Chat tool that lets Roadie READ the source of the current subsite's owning
 * plugin/theme so it can give grounded UI/code feedback to (often
 * non-technical) team members instead of hallucinating UI details.
 *
 * The motivating bug (extrachill-roadie#54): Roadie speaks with confidence
 * about concrete UI elements ("the map takes too much space," "group the
 * tonight/this weekend buttons with the search controls") but has never read
 * the page or its code. Every UI specific is reconstructed from the user's
 * own words plus generic priors. This tool is how Roadie actually reads the
 * source before describing or filing feedback — the read counterpart to the
 * honesty guardrail shipped in PR #55.
 *
 * THREE READ-ONLY ACTIONS, jailed to the current subsite's owning component
 * directory only:
 *   - list_tree — directory tree of the inferred plugin/theme so a
 *                 non-technical "the calendar map" lets the agent find
 *                 templates/, blocks/, the map component.
 *   - read_file — read one located file (optional start_line/end_line).
 *   - grep      — search within the component for a term ("map", "tonight
 *                 button"); return file + line + matched line.
 *
 * SECURITY MODEL (the whole point):
 *   - Read-only, full stop. No write action exists on this tool. Entirely
 *     separate from propose_code_change.
 *   - Path-jailed via realpath() containment: every resolved path MUST
 *     realpath()-contain within the inferred component dir(s) for the current
 *     blog. `../`, symlink escapes, and anything outside the jail are
 *     rejected. It is physically impossible to read wp-config.php, secrets,
 *     another site's uploads, or .git/.
 *   - Binary + size guards: binaries are skipped; file size and grep match
 *     count are capped so one chat turn can't be blown up.
 *   - Dependency-free: plain RecursiveDirectoryIterator / file_get_contents /
 *     line-based search within the jail. No DMC, no wp-codebox, no shell-out,
 *     no exec.
 *   - Capability: TEAM TIER (access_roadie) — a LOWER bar than
 *     extrachill_propose_code. A team member who can read-to-ground does NOT
 *     need code-contribution rights. Reuses extrachill_roadie_user_tier().
 *
 * Component inference reuses extrachill_roadie_detect_subsite_context() — the
 * same page->component mapping file_feature_request already trusts. The jail
 * is the set of on-disk component dirs that detector returns for the current
 * blog (subsite-specific active plugins + the active theme).
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class ECRoadie_InspectCode extends BaseTool {

	protected string $tool_slug = 'inspect_code';

	/**
	 * Maximum bytes of a single file returned by read_file. Larger files are
	 * truncated (with a flag) or, when no line range is given, rejected so a
	 * chat turn can't be blown up.
	 *
	 * @var int
	 */
	protected const MAX_FILE_BYTES = 262144; // 256 KB.

	/**
	 * Maximum number of lines list_tree will descend, and the default depth
	 * cap when none is supplied.
	 *
	 * @var int
	 */
	protected const DEFAULT_TREE_DEPTH = 4;

	/**
	 * Hard ceiling on tree depth regardless of caller request.
	 *
	 * @var int
	 */
	protected const MAX_TREE_DEPTH = 8;

	/**
	 * Hard ceiling on the number of entries list_tree will return.
	 *
	 * @var int
	 */
	protected const MAX_TREE_ENTRIES = 2000;

	/**
	 * Default and maximum number of grep matches returned.
	 *
	 * @var int
	 */
	protected const DEFAULT_GREP_LIMIT = 50;
	protected const MAX_GREP_LIMIT     = 200;

	public function __construct() {
		$this->registerTool(
			$this->tool_slug,
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'access_level' => 'authenticated' )
		);
	}

	/**
	 * Tool definition.
	 *
	 * @return array<string,mixed>
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Read-only inspector for the SOURCE CODE of the current subsite\'s owning plugin or theme. Use this to GROUND UI/UX feedback in real source before describing or filing it — when a user says "the calendar map is too big" or "move the tonight button," call inspect_code to actually locate and read the relevant template/component instead of inventing element details you have not verified. This tool is strictly READ-ONLY: it can list directories, read files, and grep within the current subsite\'s owning component only. It cannot write, edit, or propose changes (use propose_code_change for that), and it cannot read anything outside the inferred plugin/theme directory (no wp-config, no secrets, no other site\'s files). Three actions: action="list_tree" returns the directory tree of the inferred component (optional subpath + depth to focus, e.g. subpath="templates"); action="read_file" reads one file you located (path relative to the component root, optional start_line/end_line to bound context); action="grep" searches the component for a term ("map", "tonight button", a CSS class) and returns matching file + line number + the matched line. Typical flow: grep for a term the user mentioned, then list_tree or read_file to read the file the grep pointed at, then describe the REAL markup. The owning component is inferred from the subsite the user is chatting on (e.g. events.extrachill.com -> extrachill-events); you do not specify it.',
			'parameters'  => array(
				'type'       => 'object',
				'required'   => array( 'action' ),
				'properties' => array(
					'action'     => array(
						'type'        => 'string',
						'enum'        => array( 'list_tree', 'read_file', 'grep' ),
						'description' => 'Which read-only sub-action to run.',
					),
					'path'       => array(
						'type'        => 'string',
						'description' => 'For action=read_file: the file to read, as a path RELATIVE to the component root (e.g. "templates/calendar.php" or "blocks/event-map/render.php"). Located via list_tree or grep first. Absolute paths and "../" escapes are rejected.',
					),
					'subpath'    => array(
						'type'        => 'string',
						'description' => 'For action=list_tree: optional subdirectory (relative to the component root) to scope the tree to, e.g. "templates" or "blocks". Omit to list from the component root. "../" escapes are rejected.',
					),
					'depth'      => array(
						'type'        => 'integer',
						'description' => 'For action=list_tree: how many directory levels deep to descend. Defaults to 4, capped at 8. Use a small depth first to get oriented, then a focused subpath.',
					),
					'query'      => array(
						'type'        => 'string',
						'description' => 'For action=grep: the term to search for within the component (plain substring, case-insensitive). Use words the user mentioned — "map", "tonight", a button label, a CSS class.',
					),
					'start_line' => array(
						'type'        => 'integer',
						'description' => 'For action=read_file: optional 1-based first line to return. Use with end_line to bound a large file to the relevant region.',
					),
					'end_line'   => array(
						'type'        => 'integer',
						'description' => 'For action=read_file: optional 1-based last line to return (inclusive).',
					),
					'limit'      => array(
						'type'        => 'integer',
						'description' => 'For action=grep: maximum number of matches to return. Defaults to 50, capped at 200.',
					),
				),
			),
		);
	}

	/**
	 * Tool callback.
	 *
	 * @param array<string,mixed> $parameters Tool parameters.
	 * @param array<string,mixed> $tool_def   Resolved tool definition (unused).
	 * @return array<string,mixed>
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		unset( $tool_def );

		// 1. Capability check — TEAM TIER (access_roadie), the same lower bar
		// that lets a non-technical member into Roadie at all. Reading-to-ground
		// is far lower risk than proposing code, so this deliberately does NOT
		// gate on extrachill_propose_code: a member with access_roadie but
		// without propose-code rights MUST be able to inspect_code.
		$cap_check = $this->check_team_capability();
		if ( true !== $cap_check ) {
			return $cap_check;
		}

		// 2. Validate action.
		$action = trim( (string) ( $parameters['action'] ?? '' ) );
		if ( ! in_array( $action, array( 'list_tree', 'read_file', 'grep' ), true ) ) {
			return $this->buildErrorResponse(
				'action is required and must be one of: list_tree, read_file, grep.',
				$this->tool_slug
			);
		}

		// 3. Resolve the jail — the set of on-disk component dirs for the
		// current subsite. Inference failure is a clean error so the model
		// understands there is nothing to read here.
		$jail = $this->resolve_jail_roots();
		if ( array() === $jail ) {
			return $this->buildErrorResponse(
				'No owning plugin or theme could be resolved for this subsite, so there is no source to inspect. This site\'s distinguishing code is not a subsite-specific component on disk.',
				$this->tool_slug
			);
		}

		switch ( $action ) {
			case 'list_tree':
				return $this->handle_list_tree( $parameters, $jail );
			case 'read_file':
				return $this->handle_read_file( $parameters, $jail );
			case 'grep':
				return $this->handle_grep( $parameters, $jail );
		}

		// Unreachable — action validated above. Defensive default.
		return $this->buildErrorResponse(
			'Unhandled action: ' . $action,
			$this->tool_slug
		);
	}

	/**
	 * Gate on team tier via the access_roadie capability.
	 *
	 * Reuses extrachill_roadie_user_tier() when available so the tier boundary
	 * lives in exactly one place; falls back to a direct cap check when the
	 * resolver is somehow unavailable. Either path requires team-or-above.
	 *
	 * @return true|array True when allowed, error response otherwise.
	 */
	protected function check_team_capability() {
		$allowed = false;

		if ( function_exists( 'extrachill_roadie_user_tier' ) ) {
			$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			$tier    = extrachill_roadie_user_tier( $user_id );
			$allowed = in_array(
				$tier,
				array( EXTRACHILL_ROADIE_TIER_TEAM, EXTRACHILL_ROADIE_TIER_ADMIN ),
				true
			);
		} else {
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom cap granted by the extra_chill_team role (extrachill-users#45).
			$allowed = function_exists( 'current_user_can' ) && current_user_can( 'access_roadie' );
		}

		if ( ! $allowed ) {
			return $this->buildErrorResponse(
				'You do not have permission to inspect platform source. This requires Extra Chill team access (the "access_roadie" capability). Ask an administrator to add you to the team role.',
				$this->tool_slug
			);
		}

		return true;
	}

	/**
	 * Resolve the jail: the realpath'd component directories for the current
	 * subsite that the tool is allowed to read inside.
	 *
	 * Reuses extrachill_roadie_detect_subsite_context() — the same detector
	 * file_feature_request trusts for page->component inference. The jail is
	 * the union of:
	 *   - every subsite-specific active plugin's on-disk path (the detector
	 *     already excludes network-wide platform boilerplate), and
	 *   - the active theme's path.
	 *
	 * Each path is run through realpath() so the containment check later
	 * compares canonical (symlink-resolved) absolute paths. Paths that don't
	 * resolve (missing dir) are dropped.
	 *
	 * @return array<string,string> Map of component slug => realpath'd dir.
	 */
	protected function resolve_jail_roots(): array {
		if ( ! function_exists( 'extrachill_roadie_detect_subsite_context' ) ) {
			return array();
		}

		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$context = extrachill_roadie_detect_subsite_context( $blog_id > 0 ? $blog_id : null );

		$roots = array();

		foreach ( (array) ( $context['plugins'] ?? array() ) as $plugin ) {
			$slug = (string) ( $plugin['slug'] ?? '' );
			$path = (string) ( $plugin['path'] ?? '' );
			if ( '' === $slug || '' === $path ) {
				continue;
			}
			$real = realpath( $path );
			if ( false !== $real && is_dir( $real ) ) {
				$roots[ $slug ] = $real;
			}
		}

		$theme_slug = (string) ( $context['theme']['slug'] ?? '' );
		$theme_path = (string) ( $context['theme']['path'] ?? '' );
		if ( '' !== $theme_slug && '' !== $theme_path ) {
			$real = realpath( $theme_path );
			if ( false !== $real && is_dir( $real ) ) {
				$roots[ 'theme:' . $theme_slug ] = $real;
			}
		}

		/**
		 * Filter the realpath'd jail roots inspect_code is allowed to read.
		 *
		 * The default is the current subsite's owning components. This filter
		 * exists for tests and for surgical environment-specific adjustments;
		 * it must only ever NARROW or relocate the jail, never widen it to
		 * shared/secret paths.
		 *
		 * @since 0.12.0
		 *
		 * @param array<string,string> $roots   slug => realpath'd dir.
		 * @param int                  $blog_id Current blog id.
		 */
		$roots = (array) apply_filters( 'extrachill_roadie_inspect_code_jail_roots', $roots, $blog_id );

		// Re-canonicalize anything a filter added, and drop non-dirs.
		$clean = array();
		foreach ( $roots as $key => $dir ) {
			$real = realpath( (string) $dir );
			if ( false !== $real && is_dir( $real ) ) {
				$clean[ (string) $key ] = $real;
			}
		}

		return $clean;
	}

	/**
	 * Resolve a caller-supplied relative path inside the jail, enforcing
	 * realpath() containment. THIS IS THE LOAD-BEARING SAFETY CHECK.
	 *
	 * Algorithm:
	 *   1. Reject absolute paths and any path containing a `..` segment up
	 *      front (defense in depth — realpath would catch escapes anyway, but
	 *      this rejects obvious traversal intent before touching the disk).
	 *   2. For each jail root, join root + relative path and realpath() it.
	 *      realpath() resolves `.`/`..`/symlinks to a canonical absolute path
	 *      (or false if the target doesn't exist).
	 *   3. Accept ONLY when the canonical path is the root itself or sits
	 *      beneath the root with a directory-separator boundary (so a sibling
	 *      dir sharing a name prefix — e.g. `/x/plugin-evil` vs `/x/plugin` —
	 *      can never match). Symlinks pointing outside the jail resolve to an
	 *      out-of-jail canonical path and are therefore rejected here.
	 *
	 * @param string                $relative The caller-supplied relative path ('' = root).
	 * @param array<string,string>  $jail     slug => realpath'd root dirs.
	 * @param bool                  $want_dir When true, require the resolved path to be a directory.
	 * @return array{path:string,root:string,slug:string}|array Error response on failure.
	 */
	protected function resolve_in_jail( string $relative, array $jail, bool $want_dir ) {
		$relative = trim( $relative );

		// Normalize separators and strip a leading slash so "templates" and
		// "/templates" behave identically without being treated as absolute.
		$relative = str_replace( '\\', '/', $relative );
		$relative = ltrim( $relative, '/' );

		// Reject obvious traversal intent before hitting the filesystem.
		$segments = explode( '/', $relative );
		foreach ( $segments as $segment ) {
			if ( '..' === $segment ) {
				return $this->buildErrorResponse(
					'Path traversal ("..") is not allowed. Paths must stay inside the current subsite\'s owning component.',
					$this->tool_slug
				);
			}
		}

		foreach ( $jail as $slug => $root ) {
			$candidate = ( '' === $relative ) ? $root : $root . '/' . $relative;
			$real      = realpath( $candidate );

			if ( false === $real ) {
				continue; // Doesn't exist under this root — try the next.
			}

			// Canonical containment: equal to the root, or beneath it with a
			// real separator boundary. Prevents the prefix-sibling escape.
			$is_contained = ( $real === $root ) || ( 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) );
			if ( ! $is_contained ) {
				continue;
			}

			if ( $want_dir && ! is_dir( $real ) ) {
				return $this->buildErrorResponse(
					sprintf( 'Path "%s" is not a directory.', $relative ),
					$this->tool_slug
				);
			}

			if ( ! $want_dir && ! is_file( $real ) ) {
				return $this->buildErrorResponse(
					sprintf( 'Path "%s" is not a readable file.', $relative ),
					$this->tool_slug
				);
			}

			return array(
				'path' => $real,
				'root' => $root,
				'slug' => (string) $slug,
			);
		}

		return $this->buildErrorResponse(
			sprintf(
				'Path "%s" was not found inside this subsite\'s owning component, or resolves outside the readable area. List the tree first to find valid paths.',
				$relative
			),
			$this->tool_slug
		);
	}

	/**
	 * list_tree handler.
	 *
	 * @param array<string,mixed>  $parameters Tool parameters.
	 * @param array<string,string> $jail       Jail roots.
	 * @return array<string,mixed>
	 */
	protected function handle_list_tree( array $parameters, array $jail ): array {
		$subpath = (string) ( $parameters['subpath'] ?? '' );

		$depth = (int) ( $parameters['depth'] ?? self::DEFAULT_TREE_DEPTH );
		if ( $depth <= 0 ) {
			$depth = self::DEFAULT_TREE_DEPTH;
		}
		$depth = min( $depth, self::MAX_TREE_DEPTH );

		// When a subpath is given, scope to exactly one jail root that
		// contains it. When omitted, list every jail root (usually one).
		$targets = array();

		if ( '' !== trim( $subpath ) ) {
			$resolved = $this->resolve_in_jail( $subpath, $jail, true );
			if ( isset( $resolved['error'] ) ) {
				return $resolved;
			}
			$targets[] = array(
				'slug' => $resolved['slug'],
				'root' => $resolved['root'],
				'base' => $resolved['path'],
			);
		} else {
			foreach ( $jail as $slug => $root ) {
				$targets[] = array(
					'slug' => (string) $slug,
					'root' => $root,
					'base' => $root,
				);
			}
		}

		$entries   = array();
		$truncated = false;

		foreach ( $targets as $target ) {
			$root = $target['root'];
			$base = $target['base'];

			$collected = $this->walk_tree( $base, $root, $depth, $entries );
			if ( true === $collected ) {
				$truncated = true;
				break;
			}
		}

		sort( $entries );

		return array(
			'success'   => true,
			'tool_name' => $this->tool_slug,
			'data'      => array(
				'action'     => 'list_tree',
				'components' => array_keys( $jail ),
				'subpath'    => trim( $subpath ),
				'depth'      => $depth,
				'count'      => count( $entries ),
				'truncated'  => $truncated,
				'entries'    => $entries,
				'next_step'  => 'Use read_file with one of these paths (relative to the component root) to read the source, or grep to search for a term across the component.',
			),
		);
	}

	/**
	 * Recursively collect directory entries beneath $base, recording paths
	 * relative to $root. Appends to $entries by reference.
	 *
	 * Dependency-free: RecursiveDirectoryIterator with depth + entry caps.
	 * `.git` and other VCS/dependency noise dirs are skipped so the tree stays
	 * about the component's own source.
	 *
	 * @param string   $base    Directory to walk.
	 * @param string   $root    Jail root (paths are made relative to this).
	 * @param int      $depth   Max depth below $base.
	 * @param string[] $entries Collected relative paths (by reference).
	 * @return bool True when the entry cap was hit (truncated), false otherwise.
	 */
	protected function walk_tree( string $base, string $root, int $depth, array &$entries ): bool {
		$skip_dirs = $this->skipped_dir_names();

		try {
			$dir_iterator = new RecursiveDirectoryIterator(
				$base,
				FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
			);

			$filter = new RecursiveCallbackFilterIterator(
				$dir_iterator,
				static function ( $current ) use ( $skip_dirs ): bool {
					$name = $current->getFilename();
					if ( $current->isDir() && isset( $skip_dirs[ $name ] ) ) {
						return false;
					}
					return true;
				}
			);

			$iterator = new RecursiveIteratorIterator(
				$filter,
				RecursiveIteratorIterator::SELF_FIRST
			);
			$iterator->setMaxDepth( max( 0, $depth - 1 ) );
		} catch ( \Exception $e ) {
			return false;
		}

		foreach ( $iterator as $file ) {
			$real = $file->getRealPath();
			if ( false === $real ) {
				continue;
			}

			// Re-assert containment for every entry — FOLLOW_SYMLINKS means a
			// symlink could point outside the jail; realpath containment is the
			// backstop that keeps the walk inside the component.
			if ( $real !== $root && 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
				continue;
			}

			$relative = ltrim( substr( $real, strlen( $root ) ), DIRECTORY_SEPARATOR );
			if ( '' === $relative ) {
				continue;
			}

			$relative = str_replace( DIRECTORY_SEPARATOR, '/', $relative );
			if ( $file->isDir() ) {
				$relative .= '/';
			}

			$entries[] = $relative;

			if ( count( $entries ) >= self::MAX_TREE_ENTRIES ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * read_file handler.
	 *
	 * @param array<string,mixed>  $parameters Tool parameters.
	 * @param array<string,string> $jail       Jail roots.
	 * @return array<string,mixed>
	 */
	protected function handle_read_file( array $parameters, array $jail ): array {
		$path = (string) ( $parameters['path'] ?? '' );
		if ( '' === trim( $path ) ) {
			return $this->buildErrorResponse(
				'path is required for action=read_file (relative to the component root, e.g. "templates/calendar.php").',
				$this->tool_slug
			);
		}

		$resolved = $this->resolve_in_jail( $path, $jail, false );
		if ( isset( $resolved['error'] ) ) {
			return $resolved;
		}

		$real = $resolved['path'];

		// Binary guard — skip files we can't sensibly stream into a chat turn.
		if ( $this->is_probably_binary( $real ) ) {
			return $this->buildErrorResponse(
				sprintf( 'File "%s" looks like a binary asset and cannot be read as text.', $path ),
				$this->tool_slug
			);
		}

		$size = (int) filesize( $real );

		$start_line = isset( $parameters['start_line'] ) ? max( 1, (int) $parameters['start_line'] ) : 0;
		$end_line   = isset( $parameters['end_line'] ) ? max( 1, (int) $parameters['end_line'] ) : 0;
		$has_range  = ( $start_line > 0 || $end_line > 0 );

		// Size guard. Without a line range, a too-large file is rejected so a
		// chat turn can't be blown up; with a range we read line-by-line and
		// only return the bounded slice.
		if ( ! $has_range && $size > self::MAX_FILE_BYTES ) {
			return $this->buildErrorResponse(
				sprintf(
					'File "%s" is %d bytes, larger than the %d-byte read cap. Pass start_line and end_line to read a bounded region instead.',
					$path,
					$size,
					self::MAX_FILE_BYTES
				),
				$this->tool_slug
			);
		}

		$lines = @file( $real, FILE_IGNORE_NEW_LINES );
		if ( false === $lines ) {
			return $this->buildErrorResponse(
				sprintf( 'Could not read file "%s".', $path ),
				$this->tool_slug
			);
		}

		$total_lines = count( $lines );
		$truncated   = false;

		if ( $has_range ) {
			$from = $start_line > 0 ? $start_line : 1;
			$to   = $end_line > 0 ? $end_line : $total_lines;
			if ( $to < $from ) {
				$to = $from;
			}
			$slice        = array_slice( $lines, $from - 1, ( $to - $from ) + 1, true );
			$first_line   = $from;
			$last_line    = min( $to, $total_lines );
			$content_body = implode( "\n", $slice );

			// Even a bounded slice gets a hard byte cap.
			if ( strlen( $content_body ) > self::MAX_FILE_BYTES ) {
				$content_body = substr( $content_body, 0, self::MAX_FILE_BYTES );
				$truncated    = true;
			}
		} else {
			$first_line   = 1;
			$last_line    = $total_lines;
			$content_body = implode( "\n", $lines );
		}

		return array(
			'success'   => true,
			'tool_name' => $this->tool_slug,
			'data'      => array(
				'action'      => 'read_file',
				'component'   => $resolved['slug'],
				'path'        => $path,
				'total_lines' => $total_lines,
				'start_line'  => $first_line,
				'end_line'    => $last_line,
				'truncated'   => $truncated,
				'content'     => $content_body,
			),
		);
	}

	/**
	 * grep handler.
	 *
	 * @param array<string,mixed>  $parameters Tool parameters.
	 * @param array<string,string> $jail       Jail roots.
	 * @return array<string,mixed>
	 */
	protected function handle_grep( array $parameters, array $jail ): array {
		$query = (string) ( $parameters['query'] ?? '' );
		if ( '' === trim( $query ) ) {
			return $this->buildErrorResponse(
				'query is required for action=grep (a plain substring, e.g. "map" or "tonight").',
				$this->tool_slug
			);
		}

		$limit = isset( $parameters['limit'] ) ? (int) $parameters['limit'] : self::DEFAULT_GREP_LIMIT;
		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_GREP_LIMIT;
		}
		$limit = min( $limit, self::MAX_GREP_LIMIT );

		$needle    = strtolower( $query );
		$matches   = array();
		$truncated = false;
		$skip_dirs = $this->skipped_dir_names();

		foreach ( $jail as $slug => $root ) {
			try {
				$dir_iterator = new RecursiveDirectoryIterator(
					$root,
					FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
				);
				$filter       = new RecursiveCallbackFilterIterator(
					$dir_iterator,
					static function ( $current ) use ( $skip_dirs ): bool {
						$name = $current->getFilename();
						if ( $current->isDir() && isset( $skip_dirs[ $name ] ) ) {
							return false;
						}
						return true;
					}
				);
				$iterator     = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::LEAVES_ONLY );
			} catch ( \Exception $e ) {
				continue;
			}

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$real = $file->getRealPath();
				if ( false === $real ) {
					continue;
				}

				// Containment backstop (FOLLOW_SYMLINKS could escape).
				if ( $real !== $root && 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
					continue;
				}

				// Skip oversized and binary files so grep can't be blown up.
				if ( (int) $file->getSize() > self::MAX_FILE_BYTES ) {
					continue;
				}
				if ( $this->is_probably_binary( $real ) ) {
					continue;
				}

				$handle = @fopen( $real, 'rb' );
				if ( false === $handle ) {
					continue;
				}

				$relative = str_replace(
					DIRECTORY_SEPARATOR,
					'/',
					ltrim( substr( $real, strlen( $root ) ), DIRECTORY_SEPARATOR )
				);

				$line_no = 0;
				while ( false !== ( $line = fgets( $handle ) ) ) {
					++$line_no;
					if ( false === strpos( strtolower( $line ), $needle ) ) {
						continue;
					}

					$matches[] = array(
						'component' => (string) $slug,
						'path'      => $relative,
						'line'      => $line_no,
						'text'      => $this->trim_match_line( $line ),
					);

					if ( count( $matches ) >= $limit ) {
						$truncated = true;
						break;
					}
				}

				fclose( $handle );

				if ( $truncated ) {
					break;
				}
			}

			if ( $truncated ) {
				break;
			}
		}

		return array(
			'success'   => true,
			'tool_name' => $this->tool_slug,
			'data'      => array(
				'action'     => 'grep',
				'components' => array_keys( $jail ),
				'query'      => $query,
				'limit'      => $limit,
				'count'      => count( $matches ),
				'truncated'  => $truncated,
				'matches'    => $matches,
				'next_step'  => 'Use read_file on a matched path (with start_line/end_line near the matched line) to read the surrounding source before describing or filing feedback.',
			),
		);
	}

	/**
	 * Directory names skipped during tree/grep walks — VCS metadata and
	 * dependency trees that aren't the component's own authored source.
	 *
	 * @return array<string,bool> Map for O(1) lookup.
	 */
	protected function skipped_dir_names(): array {
		$defaults = array(
			'.git'         => true,
			'.svn'         => true,
			'.hg'          => true,
			'node_modules' => true,
			'vendor'       => true,
		);

		/**
		 * Filter the directory names inspect_code skips while walking a
		 * component (VCS metadata, dependency trees).
		 *
		 * @since 0.12.0
		 *
		 * @param array<string,bool> $defaults Skip map.
		 */
		$filtered = apply_filters( 'extrachill_roadie_inspect_code_skip_dirs', $defaults );

		return is_array( $filtered ) ? $filtered : $defaults;
	}

	/**
	 * Heuristic binary detection: read a small head of the file and flag it
	 * binary if it contains a NUL byte or a high ratio of non-text bytes.
	 *
	 * @param string $path Absolute file path (already jail-validated).
	 * @return bool
	 */
	protected function is_probably_binary( string $path ): bool {
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return true; // Unreadable — treat as non-text to be safe.
		}

		$chunk = (string) fread( $handle, 8192 );
		fclose( $handle );

		if ( '' === $chunk ) {
			return false; // Empty file is fine to "read".
		}

		// A NUL byte is the classic binary tell.
		if ( false !== strpos( $chunk, "\0" ) ) {
			return true;
		}

		// High ratio of control/non-printable bytes (excluding tab/newline/CR).
		$non_text = 0;
		$length   = strlen( $chunk );
		for ( $i = 0; $i < $length; $i++ ) {
			$ord = ord( $chunk[ $i ] );
			if ( $ord < 9 || ( $ord > 13 && $ord < 32 ) ) {
				++$non_text;
			}
		}

		return ( $non_text / max( 1, $length ) ) > 0.30;
	}

	/**
	 * Normalize a matched line for return: strip the trailing newline, trim
	 * leading/trailing whitespace, and cap length so one pathological line
	 * can't bloat the response.
	 *
	 * @param string $line Raw line from the file.
	 * @return string
	 */
	protected function trim_match_line( string $line ): string {
		$line = rtrim( $line, "\r\n" );
		$line = trim( $line );
		if ( strlen( $line ) > 400 ) {
			$line = substr( $line, 0, 400 ) . '…';
		}
		return $line;
	}
}
