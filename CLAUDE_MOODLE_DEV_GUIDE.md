# Moodle Plugin Development — Claude Instructions Reference

Compiled from real development and PHPCS enforcement on `block_servermon`.
Use this as the base instruction set for any new Moodle block or plugin project.

---

## 1. Branch and Git Workflow

- **Always develop on a `claude/` branch** — the CI gate rejects pushes to any
  other pattern. Branch names must start with `claude/`.
- **Push command:** always use `git push -u origin <branch-name>`.
- **Sync before opening a PR:** if GitHub reports "N commits behind main", run
  `git fetch origin main && git merge origin/main --no-edit && git push` before
  raising the PR. Do this proactively after every merge on main.
- **Version bump on every feature commit** — Moodle will refuse a ZIP install if
  the version number in `version.php` is not strictly higher than the installed
  version. Bump `$plugin->version` (format `YYYYMMDDNN`) and `$plugin->release`
  for every substantive change.
- **Commit messages** — include a one-line summary, a blank line, then bullet
  points for each file changed. End with the session URL on its own line.

---

## 2. version.php

```php
$plugin->component = 'block_yourplugin';
$plugin->version   = 2026040300;          // YYYYMMDDNN — bump for every release
$plugin->requires  = 2024100700;          // Moodle 4.5.0 — not 5.0 (common mistake)
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.4.0';
```

- `2024100700` is **Moodle 4.5**, not 5.0. Moodle 5.0 is `2025041400`.
  Double-check the comment matches the actual version number.

---

## 3. PHP Coding Style (PHPCS — Moodle ruleset)

### 3a. Variable naming — two rules that interact

| Context | Rule | Example |
|---|---|---|
| **Class methods** | camelCase, no underscores | `$instanceId`, `$cpuPct` |
| **Global / procedural functions** | all-lowercase, no underscores, no camelCase | `$cpupct`, `$memtotalkb`, `$rsskb` |

Both rules are enforced by separate sniffs:
- `moodle.NamingConventions.ValidVariableName.VariableNameUnderscore`
- `moodle.NamingConventions.ValidVariableName.VariableNameLowerCase`

Array **key strings** (`'cpu_pct'`, `'rss_kb'`) are unaffected — only variable
names are checked.

When renaming, use `replace_all: true` and then grep for any remaining instances
— at least once a missed occurrence in an array value assignment slipped through.

### 3b. Forbidden globals in block classes

```php
// WRONG — triggers moodle.PHP.ForbiddenGlobalUse.BadGlobal
global $PAGE;
$PAGE->requires->js_init_code($js);

// CORRECT — block_base exposes $this->page
$this->page->requires->js_init_code($js);
```

`$DB` and `$CFG` globals are permitted inside block class methods.

### 3c. Anonymous functions

```php
// WRONG — no space after 'function'
usort($arr, function($a, $b) { ... });

// CORRECT
usort($arr, function ($a, $b) { ... });
```

### 3d. Inline comments

```php
// wrong — starts with lowercase
// wrong — no terminal punctuation

// Correct — capital first letter, ends with full stop.
// Also acceptable with exclamation mark or question mark.
```

Comments that start with a path (`/proc/...`) or a flag (`--sort`) will fail.
Rewrite them to start with a capital word:
```php
// WRONG:  // /proc/[pid]/stat format: ...
// WRONG:  // --sort=-%cpu to get ...
// CORRECT: // Format of /proc/[pid]/stat: ...
// CORRECT: // Sort by CPU descending; limit output to 5 processes.
```

### 3e. Line length

| Limit | Severity |
|---|---|
| > 180 chars | ERROR (`other.toolong`) |
| > 132 chars | WARNING (`other.ratherlong`) |

Applies to all files including `.html` and `.php`. Long PHP string literals
(lang strings, SQL) are generally exempt in practice but long lines in HTML
files are always flagged. Wrap HTML content lines at ~120 chars.

---

## 4. Lang File (`lang/en/block_pluginname.php`)

- Strings **must be in strict alphabetical order** by key.
  The sniff is `moodle.Files.LangFilesOrdering.IncorrectOrder`.
- `privacy:metadata` sorts **before** `proc_*` (`i` < `o`). This is a common
  mistake — `privacy:` looks like it should be near the bottom but it sorts
  before all `pr[o-z]*` keys.
- Standard keys to always include:

```php
$string['pluginname']              = 'Your Plugin Name';
$string['privacy:metadata']        = 'This block does not store any personal data.';
$string['yourplugin:addinstance']  = 'Add a Your Plugin block';
$string['yourplugin:myaddinstance']= 'Add a Your Plugin block to Dashboard';
```

---

## 5. Block Class Patterns

### Restricting visibility

```php
public function get_content(): stdClass {
    if ($this->content !== null) {
        return $this->content;
    }
    $this->content = new stdClass();
    $this->content->footer = '';
    $this->content->text   = '';

    if (!is_siteadmin()) {
        return $this->content;   // Silent empty return for non-admins.
    }
    // ... build content
}
```

### Applicable formats — dashboard only

```php
public function applicable_formats(): array {
    return ['my' => true, 'site' => false, 'course' => false];
}
```

### Injecting JavaScript

```php
// Inside a class method — use $this->page, never global $PAGE.
$this->page->requires->js_init_code($js, true); // true = place in footer
```

Use `json_encode()` to safely pass PHP values into the JS string:
```php
$url = json_encode($url->out(false), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$str = json_encode($str, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
```

Mark inline JS blocks to suppress the PHPCS inline-JS sniff:
```php
// phpcs:disable moodle.Files.InlineJavaScript.Found
$js = <<<JSEOF
...
JSEOF;
// phpcs:enable
```

---

## 6. AJAX Endpoints

Standard bootstrap for an admin-only AJAX file:

```php
define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

require_once('../../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

echo json_encode(['key' => get_data()]);
```

- Always use `require_capability()` — never rely on `is_siteadmin()` alone for
  endpoints that return data.
- Function names in procedural AJAX files must be prefixed (e.g. `bsm_`) to
  avoid collisions with Moodle core.

---

## 7. Reading OS Metrics from `/proc`

### CPU percentage (two-sample delta)

Sample `/proc/stat` twice with `usleep(500000)` (500 ms) between reads.
Calculate: `(delta_active / delta_total) * 100`.
Fields: `user nice system idle iowait irq softirq steal` (indices 0–7).
Idle = index 3 + index 4 (idle + iowait).

### Per-process CPU and core

From `/proc/[pid]/stat`:
- Field 1 (0-based): `(comm)` — process name in parentheses
- Fields 13–14: `utime`, `stime` — CPU ticks
- Field 38: `processor` — last CPU core used

To extract field 38 safely (comm may contain spaces):
```php
$rest   = substr($stat, (int) strrpos($stat, ')') + 1);
$fields = preg_split('/\s+/', trim($rest));
// $fields[0] = state, $fields[36] = processor (field 38 globally)
$cpucore = (isset($fields[36]) && ctype_digit((string)$fields[36]))
    ? (int)$fields[36] : null;
```

### Fallback to `ps`

```php
$out = @shell_exec('ps -A --no-headers --sort=-%cpu -o pid,pcpu,pmem,psr,comm 2>/dev/null | head -6');
// Columns (split max 5): PID(0) %CPU(1) %MEM(2) PSR(3) COMM(4)
```

Check `disable_functions` before calling `shell_exec`:
```php
$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
if (in_array('shell_exec', $disabled)) { return []; }
```

---

## 8. Moodle Universal Cache (MUC) — `cache_helper::get_stats()`

The real return structure (do not assume flat array):

```php
$raw = cache_helper::get_stats();
// $raw['definition_key'] = [
//     'mode'   => int,          // cache_store::MODE_APPLICATION (1), SESSION (2), REQUEST (4)
//     'stores' => [
//         'store_name' => [
//             'hits'    => int,
//             'misses'  => int,
//             'sets'    => int,
//             'iobytes' => int,  // -1 means unsupported, not zero
//             'class'   => string,
//         ],
//     ],
// ]
foreach ($raw as $data) {
    if (!is_array($data) || empty($data['stores'])) { continue; }
    $mode = (int)($data['mode'] ?? cache_store::MODE_APPLICATION);
    foreach ($data['stores'] as $storename => $entry) {
        $hits    = (int)($entry['hits']    ?? 0);
        $iobytes = (int)($entry['iobytes'] ?? -1);
        $bytes   = $iobytes > 0 ? $iobytes : 0;  // -1 = not supported
    }
}
```

---

## 9. Disk Space — Mount Point Awareness

`disk_total_space('/')` only queries the filesystem that owns `/`. If Moodle
data is on `/data` (a separate mount), the reported value will be wrong.

Make the path configurable via admin settings:
```php
$configured = get_config('block_yourplugin', 'disk_path');
$default    = (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') ? '/' : 'C:\\';
$path       = (!empty($configured) && is_readable($configured)) ? $configured : $default;
$total      = @disk_total_space($path);
```

Apply the same path in any scheduled task that logs disk metrics.

---

## 10. CSS Conventions

### Font scale — use a defined set, not ad-hoc values

Suggested scale for a small block (all in `rem`):

| Token | Value | Used for |
|---|---|---|
| xs | 0.62rem | Badges |
| sm-xs | 0.68rem | Sub-labels, table headers, per-core labels |
| sm | 0.72rem | Detail lines, secondary text, unavailable msg |
| sm-md | 0.74rem | Alerts, process table body |
| md | 0.75rem | Collapsible summaries, cache rows |
| md-lg | 0.78rem | Info tables |
| base | 0.82rem | Block root, section titles, debug summary |
| lg | 0.85rem | Percentage values |
| xl | 0.92rem | Metric card values |

Avoid one-off values like `0.71rem`, `0.73rem`, `0.67rem` — round to the
nearest step. Near-duplicate font sizes that differ by 0.01–0.02rem are
invisible to users but create maintenance noise.

### Specificity trap — table header alignment

When column classes set `text-align`, do **not** also set `text-align` on the
element+class selector — it will win due to higher specificity:

```css
/* WRONG — (0,1,1) specificity overrides .bsm-proc-num (0,1,0) on <th> */
.bsm-proc-table th { text-align: left; }
.bsm-proc-num      { text-align: right; }  /* loses on <th> elements */

/* CORRECT — let column classes control alignment for both th and td */
.bsm-proc-table th { font-size: 0.68rem; font-weight: 600; opacity: 0.55; }
.bsm-proc-num      { text-align: right; width: 3.5rem; }
```

### Consistency checklist

- `border-radius`: pick one value for "pill/card" elements and use it
  everywhere (e.g. `0.375rem`). Do not mix `0.3rem`, `0.375rem`, `3px`.
- Divider colour: use one `rgba(128, 128, 128, X)` opacity throughout.
  Do not use `0.2` in one place and `0.12` everywhere else.
- Remove `opacity: 1` declarations — it is the browser default.

---

## 11. README and Moodle.org Description

- Keep a `README.md` for the GitHub repo (full detail, markdown tables fine).
- Keep a separate `MOODLE_ORG_DESCRIPTION.html` for the plugin directory.
- The HTML file is linted for line length: wrap all lines to **≤ 132 chars**.
  HTML block elements render identically regardless of internal line breaks.
- Do not use markdown in the HTML file — use `<strong>`, `<code>`, `<ul>/<li>`.

---

## 12. Common Mistakes Checklist

Before pushing any new feature, verify:

- [ ] `version.php` bumped (`$plugin->version` and `$plugin->release`)
- [ ] All variables in global functions are all-lowercase, no underscores
- [ ] No `global $PAGE` inside block class methods — use `$this->page`
- [ ] Anonymous functions have a space: `function ($a, $b)`
- [ ] Inline comments start with a capital letter and end with a full stop
- [ ] Lang strings are in alphabetical order (`privacy:` sorts before `proc_`)
- [ ] No lines over 132 chars in HTML files
- [ ] `replace_all` variable renames — grep afterwards for missed occurrences
- [ ] Branch is up to date with main before raising PR (`git merge origin/main`)
