<?php
/**
 * Validate the plugin and marketplace manifests.
 *
 *   php deploy/validate-manifests.php
 *
 * `claude plugin validate` does this and more, but the CLI is not on a CI
 * runner and the build must not depend on a tool that may not be there. So
 * everything the build itself relies on — the manifests parse, the fields
 * the plugin loader reads are present and the right shape, every source a
 * marketplace entry names is really in the tree, and the two manifests
 * agree about the plugin's name — is checked here, with nothing but PHP.
 *
 * Prints one line per check and exits 1 if any of them failed.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;

function ok(string $what): void
{
    printf("  ok    %s\n", $what);
}

function bad(string $what): void
{
    global $failures;
    printf("  FAIL  %s\n", $what);
    $failures++;
}

/** Read a manifest, or null with the reason already reported. */
function manifest(string $path, string $label): ?array
{
    if (!is_file($path)) {
        bad("$label — missing");
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        bad("$label — not valid JSON: " . json_last_error_msg());
        return null;
    }
    return $data;
}

function is_name(mixed $v): bool
{
    return is_string($v) && preg_match('/^[a-z0-9][a-z0-9._-]*$/', $v) === 1;
}

function has_text(array $d, string $key): bool
{
    return isset($d[$key]) && is_string($d[$key]) && trim($d[$key]) !== '';
}

/**
 * One scalar out of a frontmatter block.
 *
 * Enough YAML for what a SKILL.md actually holds: a plain value on the same
 * line, or a `>` / `|` block folded over the indented lines beneath it, which
 * is how every long description in this pack is written.
 */
function front_value(string $front, string $key): ?string
{
    $lines = preg_split('/\R/', $front) ?: [];
    $count = count($lines);

    foreach ($lines as $i => $line) {
        if (preg_match('/^' . preg_quote($key, '/') . ':[ \t]*(.*)$/', $line, $m) !== 1) {
            continue;
        }
        $head = trim($m[1]);
        if ($head !== '' && preg_match('/^[|>][-+]?\d*$/', $head) !== 1) {
            return trim($head, " \t\"'");
        }

        $body = [];
        for ($j = $i + 1; $j < $count; $j++) {
            if (trim($lines[$j]) === '') {
                continue;
            }
            if (preg_match('/^[ \t]/', $lines[$j]) !== 1) {
                break;
            }
            $body[] = trim($lines[$j]);
        }
        return trim(implode(' ', $body));
    }

    return null;
}

// ---- the plugin ----

$pluginName = null;
$plugin = manifest("$root/.claude-plugin/plugin.json", '.claude-plugin/plugin.json');
if ($plugin !== null) {
    $problems = [];

    if (!is_name($plugin['name'] ?? null)) {
        $problems[] = "name must be lower-case letters, digits, '-', '_' or '.'";
    } else {
        $pluginName = $plugin['name'];
    }
    if (!has_text($plugin, 'description')) {
        $problems[] = 'description is required — it is what a person reads before installing';
    }
    if (!isset($plugin['version']) || !is_string($plugin['version'])
        || preg_match('/^\d+\.\d+\.\d+/', $plugin['version']) !== 1) {
        $problems[] = 'version must be semver, e.g. 1.0.0';
    }
    foreach (['displayName', 'homepage', 'repository', 'license'] as $key) {
        if (isset($plugin[$key]) && !is_string($plugin[$key])) {
            $problems[] = "$key must be a string";
        }
    }
    if (isset($plugin['author'])) {
        if (!is_array($plugin['author']) || !has_text($plugin['author'], 'name')) {
            $problems[] = 'author must be an object with a name';
        }
    }
    if (isset($plugin['keywords'])) {
        if (!is_array($plugin['keywords']) || $plugin['keywords'] !== array_values($plugin['keywords'])
            || array_filter($plugin['keywords'], fn ($k) => !is_string($k) || $k === '')) {
            $problems[] = 'keywords must be a list of non-empty strings';
        }
    }

    if ($problems === []) {
        ok('.claude-plugin/plugin.json');
    } else {
        foreach ($problems as $p) {
            bad(".claude-plugin/plugin.json — $p");
        }
    }
}

// ---- the marketplace ----

$market = manifest("$root/.claude-plugin/marketplace.json", '.claude-plugin/marketplace.json');
if ($market !== null) {
    $problems = [];

    if (!is_name($market['name'] ?? null)) {
        $problems[] = "name must be lower-case letters, digits, '-', '_' or '.'";
    }
    if (!isset($market['owner']) || !is_array($market['owner']) || !has_text($market['owner'], 'name')) {
        $problems[] = 'owner must be an object with a name';
    }

    $entries = $market['plugins'] ?? null;
    if (!is_array($entries) || $entries === [] || $entries !== array_values($entries)) {
        $problems[] = 'plugins must be a non-empty list';
        $entries = [];
    }

    $named = false;
    foreach ($entries as $i => $entry) {
        $where = "plugins[$i]";
        if (!is_array($entry)) {
            $problems[] = "$where must be an object";
            continue;
        }
        if (!is_name($entry['name'] ?? null)) {
            $problems[] = "$where.name must be lower-case letters, digits, '-', '_' or '.'";
        }
        if (!has_text($entry, 'source')) {
            $problems[] = "$where.source is required";
            continue;
        }

        // A local source is a path in this repository, and the directory it
        // names has to hold a plugin manifest — otherwise `/plugin install`
        // resolves to nothing and says so only at install time.
        $source = $entry['source'];
        if (!str_contains($source, '://')) {
            $dir = rtrim($root . '/' . ltrim($source, './'), '/');
            if ($dir === '' || $dir === $root) {
                $dir = $root;
            }
            if (!is_dir($dir)) {
                $problems[] = "$where.source '$source' is not a directory in this repository";
            } elseif (!is_file("$dir/.claude-plugin/plugin.json")) {
                $problems[] = "$where.source '$source' has no .claude-plugin/plugin.json";
            } elseif ($dir === $root) {
                // The repository is its own plugin, so the two manifests have
                // to agree about the name that `/plugin install` is given.
                $named = true;
                if ($pluginName !== null && ($entry['name'] ?? null) !== $pluginName) {
                    $problems[] = "$where.name is '{$entry['name']}' but plugin.json says '$pluginName'";
                }
            }
        }
    }

    if ($pluginName !== null && $entries !== [] && !$named) {
        $problems[] = "no entry sources this repository, so plugin.json's '$pluginName' is unreachable";
    }

    if ($problems === []) {
        ok('.claude-plugin/marketplace.json');
    } else {
        foreach ($problems as $p) {
            bad(".claude-plugin/marketplace.json — $p");
        }
    }
}

// ---- every skill's frontmatter ----
//
// The name and the key whitelist are the build script's business, because it
// names the zips. What is checked here is what the loader needs to show the
// skill at all: a frontmatter block, a name in it, and a description, which
// is the only thing Claude reads when deciding whether to load the skill.

$dirs = glob("$root/skills/*", GLOB_ONLYDIR) ?: [];
$skills = 0;
foreach ($dirs as $dir) {
    $name = basename($dir);
    $file = "$dir/SKILL.md";
    if (!is_file($file)) {
        continue;
    }
    $skills++;

    $text = (string) file_get_contents($file);
    if (!str_starts_with($text, "---\n")) {
        bad("$name — SKILL.md must open with a --- frontmatter block on line 1");
        continue;
    }
    $end = strpos($text, "\n---", 3);
    if ($end === false) {
        bad("$name — SKILL.md frontmatter is never closed");
        continue;
    }
    $front = substr($text, 4, $end - 3);

    if ((string) front_value($front, 'name') === '') {
        bad("$name — SKILL.md frontmatter has no name");
    }
    $description = (string) front_value($front, 'description');
    if ($description === '') {
        bad("$name — SKILL.md frontmatter has no description, so nothing will ever load it");
    } elseif (mb_strlen($description) < 40) {
        bad("$name — description is too short to route on");
    }
    if (trim(substr($text, $end + 4)) === '') {
        bad("$name — SKILL.md is frontmatter and nothing else");
    }
}

if ($skills === 0) {
    bad('no skills found under skills/');
} elseif ($failures === 0) {
    ok("$skills skills, frontmatter present and routable");
}

exit($failures === 0 ? 0 : 1);
