#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * consolidate_movies.php
 *
 * Consolidate episodic video files spread across multiple /Volumes/Recorded X/recorded directories,
 * moving files group-by-group (base title), minimizing moves, handling duplicates,
 * logging all actions, and optionally balancing free space by moving whole groups to Recorded 4.
 *
 * macOS-friendly (BSD tools). Uses /bin/mv for cross-volume moves.
 *
 * IMPORTANT: Run --dry-run first.
 */

date_default_timezone_set('America/New_York');

// -----------------------
// CONFIG (edit if needed)
// -----------------------
$DRIVES = [
  // driveKey => absolute path to the directory
  'Recorded 1' => '/Volumes/Recorded 1/recorded',
  'Recorded 2' => '/Volumes/Recorded 2/recorded',
  'Recorded 3' => '/Volumes/Recorded 3/recorded',
  'Recorded 4' => '/Volumes/Recorded 4/recorded',
];

// Drive used for evacuations / balancing (big free space sink)
$BALANCE_TO_DRIVE_KEY = 'Recorded 4';

// Default: try to end non-balance-to drives with ~100 GB free
$DEFAULT_TARGET_FREE_GB = 100;

// Safety reserve (extra headroom) required on a destination drive before pulling in a group
$DEFAULT_RESERVE_GB = 20;

// Video extensions to include (case-insensitive)
$DEFAULT_EXTENSIONS = ['mp4', 'mkv', 'avi', 'mov', 'm4v', 'mpg', 'mpeg', 'ts', 'wmv', 'flv', 'webm', 'vob'];

// -----------------------
// CLI Options
// -----------------------
$opts = getopt('', [
  'dry-run',
  'execute',
  'recursive',
  'target-free-gb::',
  'reserve-gb::',
  'no-balance',
  'only::',          // substring filter on base title (case-insensitive)
  'limit-groups::',  // stop after N groups (for testing)
  'log::',           // custom log filename
]);

$DRY_RUN  = isset($opts['dry-run']) || !isset($opts['execute']);
$RECURSIVE = isset($opts['recursive']);
$DO_BALANCE = !isset($opts['no-balance']);

$targetFreeGb = isset($opts['target-free-gb']) ? (int)$opts['target-free-gb'] : $DEFAULT_TARGET_FREE_GB;
$reserveGb    = isset($opts['reserve-gb']) ? (int)$opts['reserve-gb'] : $DEFAULT_RESERVE_GB;

$onlyFilter = isset($opts['only']) && is_string($opts['only']) && trim($opts['only']) !== ''
  ? mb_strtolower(trim((string)$opts['only']))
  : null;

$limitGroups = isset($opts['limit-groups']) ? max(1, (int)$opts['limit-groups']) : null;

$logFile = isset($opts['log']) && is_string($opts['log']) && trim($opts['log']) !== ''
  ? trim((string)$opts['log'])
  : ('move_status_' . date('Ymd_His') . '.tsv');

$balanceToDriveKey = $BALANCE_TO_DRIVE_KEY;

if (!array_key_exists($balanceToDriveKey, $DRIVES)) {
  fwrite(STDERR, "ERROR: balance-to drive key '$balanceToDriveKey' not found in DRIVES config.\n");
  exit(1);
}

function usageAndExit(): void
{
  $u = <<<TXT
Usage:
  php consolidate_movies.php --dry-run [options]
  php consolidate_movies.php --execute [options]

Options:
  --dry-run                  Plan only (default if --execute not provided)
  --execute                  Perform moves
  --recursive                Scan subdirectories too
  --target-free-gb=100        Final target free space for drives except balance-to
  --reserve-gb=20             Extra headroom required before pulling a group onto a destination drive
  --no-balance               Skip final balancing pass to reach target-free-gb
  --only="On the Beach"       Only process groups whose base title contains this substring
  --limit-groups=50           Stop after processing N groups (testing)
  --log="mylog.tsv"           Custom log filename (TSV)

TXT;
  fwrite(STDERR, $u);
  exit(1);
}

if (!$DRY_RUN && !isset($opts['execute'])) {
  usageAndExit();
}

// -----------------------
// Logger
// -----------------------
$logFp = fopen($logFile, 'wb');
if (!$logFp) {
  fwrite(STDERR, "ERROR: Cannot open log file for writing: $logFile\n");
  exit(1);
}

$header = [
  'ts',
  'mode',
  'group',
  'action',
  'src',
  'dest',
  'bytes',
  'status',
  'message',
];
fwrite($logFp, implode("\t", $header) . "\n");

function logLine($fp, array $cols): void
{
  fwrite($fp, implode("\t", array_map(static function ($v) {
    $s = (string)$v;
    // Keep TSV sane
    $s = str_replace(["\t", "\r", "\n"], ['\\t', '\\r', '\\n'], $s);
    return $s;
  }, $cols)) . "\n");
}

function out(string $msg): void
{
  fwrite(STDOUT, $msg . "\n");
}

function err(string $msg): void
{
  fwrite(STDERR, $msg . "\n");
}

function bytesToHuman(int $bytes): string
{
  $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  $i = 0;
  $v = (float)$bytes;
  while ($v >= 1024 && $i < count($units) - 1) {
    $v /= 1024;
    $i++;
  }
  return sprintf('%.2f %s', $v, $units[$i]);
}

function gbToBytes(int $gb): int
{
  return $gb * 1024 * 1024 * 1024;
}

function safeDirName(string $s): string
{
  // Make a folder-friendly name; keep it readable
  $s = trim($s);
  $s = preg_replace('/[\/:]/', '-', $s) ?? $s;
  $s = preg_replace('/\s+/', ' ', $s) ?? $s;
  $s = preg_replace('/[^\p{L}\p{N}\s\-\#\(\)\[\]\.\&\']+/u', '', $s) ?? $s;
  $s = trim($s);
  return $s !== '' ? $s : 'unknown';
}

function diskFree(string $path): int
{
  $v = @disk_free_space($path);
  return $v === false ? 0 : (int)$v;
}

// -----------------------
// File scanning
// -----------------------
function scanDriveFiles(string $root, bool $recursive, array $extensions): array
{
  $files = [];

  $extSet = [];
  foreach ($extensions as $e) $extSet[mb_strtolower($e)] = true;

  if (!is_dir($root)) return $files;

  if ($recursive) {
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $fi) {
      /** @var SplFileInfo $fi */
      if (!$fi->isFile()) continue;
      $path = $fi->getPathname();
      // Skip anything inside a duplicates folder
      if (preg_match('#/duplicates(/|$)#i', $path)) continue;

      $ext = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
      if ($ext === '' || !isset($extSet[$ext])) continue;

      $files[] = $path;
    }
  } else {
    $it = new DirectoryIterator($root);
    foreach ($it as $fi) {
      /** @var DirectoryIterator $fi */
      if ($fi->isDot() || !$fi->isFile()) continue;
      $path = $fi->getPathname();
      if (preg_match('#/duplicates(/|$)#i', $path)) continue;

      $ext = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
      if ($ext === '' || !isset($extSet[$ext])) continue;

      $files[] = $path;
    }
  }

  return $files;
}

function parseBaseAndEpisode(string $nameNoExt): array
{
  $s = trim($nameNoExt);

  // 1) Episodic: "<base> # <digits> <anything...>"
  if (preg_match('/^(.*?)(?:\s*#\s*(\d+))\b(.*)$/u', $s, $m)) {
    $base = trim($m[1]);
    $ep   = (int)$m[2];
    $tail = trim($m[3]); // could be "", or "(Studio) - Scene_2 ...", "- CD1", etc

    // NEW: if the tail begins with one or more "(...)" tags, treat them as part of the base.
    // Example: "Some Movie # 01 (Paramount) - Scene_1"
    //   => base: "Anal Angels (Paramount)"
    //   => tail: "- Scene_1"
    if (preg_match('/^\s*-?\s*((?:\([^)]*\)\s*)+)(.*)$/u', $tail, $mm)) {
      $parenBlock = trim($mm[1]);           // "(Paramount)" or "(Paramount) (Something)"
      $rest       = trim($mm[2]);           // "- Scene_1" or ""

      // Normalize whitespace inside the parenthetical block
      $parenBlock = preg_replace('/\s+/', ' ', $parenBlock) ?? $parenBlock;

      $base = trim($base . ' ' . $parenBlock);
      $tail = $rest;
    }

    return [$base !== '' ? $base : $s, $ep, $tail];
  }

  // 2) Multi-part discs/parts without episode: "<base> - CD1 ..." / "Disc 2" / "Part 1" / "DVD 3"
  if (preg_match('/^(.*?)(?:\s*-\s*)((?:cd|disc|dvd|part)\s*\d+)\b(.*)$/iu', $s, $m)) {
    $base = trim($m[1]);
    $var  = trim('- ' . preg_replace('/\s+/', ' ', $m[2]) . $m[3]);
    return [$base !== '' ? $base : $s, null, $var];
  }

  // 3) Scene markers without episode: "<base> - Scene_2 ..." (and allow extra stuff after)
  if (preg_match('/^(.*?)(?:\s*-\s*)((?:scene)[_\s]*\d+)\b(.*)$/iu', $s, $m)) {
    $base = trim($m[1]);
    $var  = trim('- ' . preg_replace('/\s+/', ' ', $m[2]) . $m[3]);
    return [$base !== '' ? $base : $s, null, $var];
  }

  return [$s, null, ''];
}

/**
 * Sort paths in "episode / scene / disc" order based on filename parsing.
 *
 * Order:
 *   - Episode number (# 01, # 02...) ascending; no-episode treated as 0
 *   - Variant type order: (none) -> scene -> cd/disc/dvd/part -> other
 *   - Variant number ascending (scene_2 before scene_10)
 *   - Then variant text / basename / full path as tie-breakers
 */
function sortPathsByEpisodeScene(array &$paths): void
{
  usort($paths, static function (string $a, string $b): int {
    $ka = episodeSceneSortKeyForPath($a);
    $kb = episodeSceneSortKeyForPath($b);

    // Compare tuple elements in order
    $n = min(count($ka), count($kb));
    for ($i = 0; $i < $n; $i++) {
      if ($ka[$i] === $kb[$i]) continue;

      // ints vs strings: use appropriate compare
      if (is_int($ka[$i]) && is_int($kb[$i])) {
        return $ka[$i] <=> $kb[$i];
      }

      return strcmp((string)$ka[$i], (string)$kb[$i]);
    }

    // same key (rare) — stable fallback
    return strnatcasecmp($a, $b);
  });
}

function episodeSceneSortKeyForPath(string $path): array
{
  $nameNoExt = pathinfo($path, PATHINFO_FILENAME);

  // parseBaseAndEpisode() exists above
  [$base, $ep, $variant] = parseBaseAndEpisode($nameNoExt);

  $epNum = $ep ?? 0;

  $variant = trim((string)$variant);
  $variantLower = mb_strtolower($variant);

  // kindOrder: 0 none, 1 scene, 2 cd/disc/dvd/part, 3 other
  $kindOrder = 3;
  $kindNum = 0;

  if ($variantLower === '') {
    $kindOrder = 0;
  } elseif (preg_match('/scene[_\s]*(\d+)/i', $variant, $m)) {
    $kindOrder = 1;
    $kindNum = (int)$m[1];
  } elseif (preg_match('/\b(cd|disc|dvd|part)\s*(\d+)/i', $variant, $m)) {
    $kindOrder = 2;
    $kindNum = (int)$m[2];
  }

  $baseNameLower = mb_strtolower(basename($path));

  return [
    $epNum,           // 0,1,2,3...
    $kindOrder,       // 0..3
    $kindNum,         // 0..N
    $variantLower,    // tie-break
    $baseNameLower,   // tie-break
    $path,            // final stable tie-break
  ];
}

function normKey(string $s): string
{
  $s = mb_strtolower(trim($s));
  $s = preg_replace('/\s+/', ' ', $s) ?? $s;
  return $s;
}

function normVariantKey(string $s): string
{
  $s = mb_strtolower(trim($s));
  $s = preg_replace('/^[\s\-]+/', '', $s) ?? $s;     // strip leading "- "
  $s = preg_replace('/\s+/', '_', $s) ?? $s;         // spaces -> _
  $s = preg_replace('/_+/', '_', $s) ?? $s;          // collapse __
  $s = preg_replace('/[^a-z0-9_]+/i', '', $s) ?? $s; // keep it key-safe
  return trim($s, '_');
}
function sortPathsNaturally(array &$paths): void
{
  usort($paths, static function (string $a, string $b): int {
    $aa = basename($a);
    $bb = basename($b);

    $c = strnatcasecmp($aa, $bb); // natural: 2 < 10
    if ($c !== 0) return $c;

    return strnatcasecmp($a, $b); // tie-breaker
  });
}
function sortFileRecordsNaturally(array &$files): void
{
  usort($files, static function (array $a, array $b): int {
    $aa = basename($a['path']);
    $bb = basename($b['path']);

    $c = strnatcasecmp($aa, $bb); // natural: 2 < 10
    if ($c !== 0) return $c;

    return strnatcasecmp($a['path'], $b['path']); // tie-breaker
  });
}

// -----------------------
// Move helpers
// -----------------------
function ensureDir(string $dir): bool
{
  if (is_dir($dir)) return true;
  return @mkdir($dir, 0775, true);
}

function shellMove(string $src, string $destDir, array &$cmdOut, int &$exitCode): void
{
  // Use /bin/mv for cross-volume moves; destDir must exist
  $cmd = '/bin/mv ' . escapeshellarg($src) . ' ' . escapeshellarg($destDir . DIRECTORY_SEPARATOR);
  $cmdOut = [];
  $exitCode = 0;
  @exec($cmd . ' 2>&1', $cmdOut, $exitCode);
}
function shellMoveToPath(string $src, string $destPath, array &$cmdOut, int &$exitCode): void
{
  $cmd = '/bin/mv ' . escapeshellarg($src) . ' ' . escapeshellarg($destPath);
  $cmdOut = [];
  $exitCode = 0;
  @exec($cmd . ' 2>&1', $cmdOut, $exitCode);
}
function fileSizeSafe(string $path): int
{
  $s = @filesize($path);
  return $s === false ? 0 : (int)$s;
}

function fileMtimeSafe(string $path): int
{
  $t = @filemtime($path);
  return $t === false ? 0 : (int)$t;
}

// -----------------------
// Validate drives
// -----------------------
foreach ($DRIVES as $k => $p) {
  if (!is_dir($p)) {
    err("ERROR: Drive path not found or not a directory: [$k] $p");
    fclose($logFp);
    exit(1);
  }
}

// -----------------------
// Build groups
// -----------------------
out("Mode: " . ($DRY_RUN ? "DRY-RUN" : "EXECUTE"));
out("Recursive: " . ($RECURSIVE ? "yes" : "no"));
out("Target free (non-$balanceToDriveKey): {$targetFreeGb} GB");
out("Reserve headroom: {$reserveGb} GB");
out("Log file: $logFile");

$allGroups = []; // baseKey => ['display'=>..., 'files'=>[...]]
$driveFiles = []; // driveKey => list paths

foreach ($DRIVES as $driveKey => $root) {
  out("Scanning: [$driveKey] $root");
  $paths = scanDriveFiles($root, $RECURSIVE, $DEFAULT_EXTENSIONS);
  $driveFiles[$driveKey] = $paths;

  foreach ($paths as $path) {
    $nameNoExt = pathinfo($path, PATHINFO_FILENAME);
    [$base, $ep, $variant] = parseBaseAndEpisode($nameNoExt);

    $baseKey = normKey($base);
    if (!isset($allGroups[$baseKey])) {
      $allGroups[$baseKey] = [
        'display' => $base,
        'files' => [],
      ];
    }

    $allGroups[$baseKey]['files'][] = [
      'path' => $path,
      'driveKey' => $driveKey,
      'base' => $base,
      'baseKey' => $baseKey,
      'ep' => $ep, // int|null
      'variant' => $variant,
      'size' => fileSizeSafe($path),
      'mtime' => fileMtimeSafe($path),
    ];
  }
}

if ($onlyFilter !== null) {
  $allGroups = array_filter($allGroups, static function ($g) use ($onlyFilter) {
    return mb_strpos(mb_strtolower($g['display']), $onlyFilter) !== false;
  });
}

if (count($allGroups) === 0) {
  out("No groups found (check paths/extensions/filter).");
  fclose($logFp);
  exit(0);
}

// Compute group total size for ordering
foreach ($allGroups as $k => &$g) {
  $sum = 0;
  foreach ($g['files'] as $f) $sum += $f['size'];
  $g['totalBytes'] = $sum;
}
unset($g);

// Sort: largest groups first (helps space planning and reduces repeated evacuations)
// uasort($allGroups, static function ($a, $b) {
//   return $b['totalBytes'] <=> $a['totalBytes'];
// });

// Sort: alphabetical by group display (natural sort: 2 < 10)
uasort($allGroups, static function ($a, $b) {
  $aa = mb_strtolower((string)$a['display']);
  $bb = mb_strtolower((string)$b['display']);

  $c = strnatcasecmp($aa, $bb);
  if ($c !== 0) return $c;

  // tie-breaker (optional): bigger groups first if names identical
  return ($b['totalBytes'] ?? 0) <=> ($a['totalBytes'] ?? 0);
});


// Track where processed groups “live” (after consolidation)
$processedGroups = []; // baseKey => ['homeDriveKey'=>..., 'primaryFiles'=>[paths], 'bytes'=>int]

// -----------------------
// Duplicate folder helper
// -----------------------
function duplicatesDirForDrive(string $driveRoot, string $baseDisplay): string
{
  // keep signature so we don't have to change any call sites
  return rtrim($driveRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'duplicates';
}

function pickBestCopy(array $bucket): array
{
  usort($bucket, static function ($a, $b) {
    if ($a['size'] !== $b['size']) return $b['size'] <=> $a['size'];
    if ($a['mtime'] !== $b['mtime']) return $b['mtime'] <=> $a['mtime'];
    return strcmp($a['path'], $b['path']);
  });
  return $bucket[0];
}

function moveToDuplicates(
  $logFp,
  bool $dryRun,
  string $modeStr,
  string $groupDisplay,
  string $srcPath,
  string $driveRoot,
  string $reason
): bool {
  $dupDir = duplicatesDirForDrive($driveRoot, $groupDisplay);

  // In dry-run, don't create anything—just log what would happen.
  if ($dryRun) {
    if (!is_dir($dupDir)) {
      logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MKDIR_DUP', $srcPath, $dupDir, 0, 'DRYRUN', 'would create duplicates dir']);
    }
  } else {
    if (!ensureDir($dupDir)) {
      logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MKDIR_DUP', $srcPath, $dupDir, 0, 'FAIL', 'Could not create duplicates dir']);
      err("FAIL mkdir duplicates: $dupDir");
      return false;
    }
  }

  $destPath = $dupDir . DIRECTORY_SEPARATOR . basename($srcPath);
  $bytes = fileSizeSafe($srcPath);

  // If name collision inside duplicates, append timestamp
  if (file_exists($destPath)) {
    $pi = pathinfo($destPath);
    $ext = (isset($pi['extension']) && $pi['extension'] !== '') ? '.' . $pi['extension'] : '';
    $destPath = $pi['dirname'] . DIRECTORY_SEPARATOR . $pi['filename'] . '__' . date('Ymd_His') . $ext;
  }

  out("  DUPLICATES: $srcPath  ->  $destPath  ($reason)");

  if ($dryRun) {
    logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MOVE_DUP', $srcPath, $destPath, $bytes, 'DRYRUN', $reason]);
    return true;
  }

  // Same drive move; prefer rename
  $ok = @rename($srcPath, $destPath);
  if ($ok) {
    logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MOVE_DUP', $srcPath, $destPath, $bytes, 'OK', $reason]);
    return true;
  }

  logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MOVE_DUP', $srcPath, $destPath, $bytes, 'FAIL', 'rename() failed']);
  err("FAIL moving to duplicates (rename failed): $srcPath");
  return false;
}


// -----------------------
// Evacuation helpers
// -----------------------
function evacuateLargestGroupsFromDrive(
  $logFp,
  bool $dryRun,
  string $modeStr,
  string $fromDriveKey,
  string $fromRoot,
  string $toDriveKey,
  string $toRoot,
  int $needBytes,
  array &$processedGroups
): int {
  // Move already-processed groups off a drive, largest first, until we free at least needBytes.
  $candidates = [];
  foreach ($processedGroups as $baseKey => $pg) {
    if ($pg['homeDriveKey'] !== $fromDriveKey) continue;
    $candidates[$baseKey] = $pg;
  }

  uasort($candidates, static function ($a, $b) {
    return $b['bytes'] <=> $a['bytes'];
  });

  $freed = 0;

  foreach ($candidates as $baseKey => $pg) {
    if ($freed >= $needBytes) break;

    $groupDisplay = $pg['display'];
    out("EVACUATE group: [$groupDisplay] from $fromDriveKey -> $toDriveKey (to free space)");

    $movedPathsForThisGroup = [];

    $srcPaths = $pg['primaryFiles'];
    sortPathsByEpisodeScene($srcPaths);

    foreach ($srcPaths as $srcPath) {
      $bytes = fileSizeSafe($srcPath);

      // If executing and source is missing, it may already have been moved earlier.
      if (!$dryRun && !file_exists($srcPath)) {
        $expectedDest = rtrim($toRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($srcPath);
        if (file_exists($expectedDest)) {
          logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_ALREADY', $srcPath, $expectedDest, fileSizeSafe($expectedDest), 'OK', 'source missing; destination exists']);
          $movedPathsForThisGroup[] = $expectedDest;
          continue;
        }

        logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_SKIP', $srcPath, $toRoot, $bytes, 'SKIP', 'source missing']);
        continue;
      }

      // In dry-run, don't create folders—just log what would happen, and continue planning.
      if ($dryRun) {
        if (!is_dir($toRoot)) {
          logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_MKDIR', $srcPath, $toRoot, $bytes, 'DRYRUN', 'would ensure dest root']);
        }
      } else {
        if (!ensureDir($toRoot)) {
          logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_MKDIR', $srcPath, $toRoot, $bytes, 'FAIL', 'Could not ensure dest root']);
          continue;
        }
      }

      $destPath = rtrim($toRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($srcPath);

      // If collision at destination, choose a unique destination name and still move
      if (file_exists($destPath)) {
        $pi = pathinfo($destPath);
        $ext = (isset($pi['extension']) && $pi['extension'] !== '') ? '.' . $pi['extension'] : '';

        $n = 1;
        do {
          $destPath = $pi['dirname'] . DIRECTORY_SEPARATOR
            . $pi['filename'] . '__EVAC_' . date('Ymd_His') . '_' . $n
            . $ext;
          $n++;
        } while (file_exists($destPath));
      }

      out("  EVAC MOVE: $srcPath  ->  $destPath");

      if ($dryRun) {
        logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_MOVE', $srcPath, $destPath, $bytes, 'DRYRUN', '']);
        $movedPathsForThisGroup[] = $destPath;
        $freed += $bytes;
        continue;
      }

      $cmdOut = [];
      $exit = 0;
      shellMoveToPath($srcPath, $destPath, $cmdOut, $exit);

      if ($exit === 0 && file_exists($destPath) && !file_exists($srcPath)) {
        logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_MOVE', $srcPath, $destPath, $bytes, 'OK', '']);
        $movedPathsForThisGroup[] = $destPath;
        $freed += $bytes;
      } else {
        $msg = 'mv failed: exit=' . $exit . ' out=' . implode(' | ', $cmdOut);
        logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_MOVE', $srcPath, $destPath, $bytes, 'FAIL', $msg]);
        err("  FAIL EVAC: $msg");
      }
    }

    // Conservative: if any old path still exists under fromRoot, consider it still on the source drive.
    $stillOnFrom = false;
    if (!$dryRun) {
      foreach ($pg['primaryFiles'] as $old) {
        if (str_starts_with($old, $fromRoot) && file_exists($old)) {
          $stillOnFrom = true;
          break;
        }
      }
    }

    sortPathsByEpisodeScene($movedPathsForThisGroup);

    $allMoved = (count($movedPathsForThisGroup) === count($pg['primaryFiles']));

    if ($allMoved && ($dryRun || !$stillOnFrom)) {
      $processedGroups[$baseKey]['homeDriveKey'] = $toDriveKey;
      $processedGroups[$baseKey]['primaryFiles'] = $movedPathsForThisGroup;
    }
  }

  return $freed;
}


function ensureDestinationHasSpace(
  $logFp,
  bool $dryRun,
  string $modeStr,
  string $destDriveKey,
  string $destRoot,
  string $balanceToDriveKey,
  string $balanceToRoot,
  int $requiredBytes,
  array &$processedGroups
): int {
  $free = diskFree($destRoot);
  if ($free >= $requiredBytes) return 0;

  $need = $requiredBytes - $free;
  out("Destination [$destDriveKey] needs to free at least " . bytesToHuman($need) . " (free=" . bytesToHuman($free) . ")");

  $freed = evacuateLargestGroupsFromDrive(
    $logFp,
    $dryRun,
    $modeStr,
    $destDriveKey,
    $destRoot,
    $balanceToDriveKey,
    $balanceToRoot,
    $need,
    $processedGroups
  );

  // SAFETY re-check
  $free2 = $dryRun ? ($free + $freed) : diskFree($destRoot);
  out("After evacuation: freed=" . bytesToHuman($freed) . " free=" . bytesToHuman($free2));

  return $freed;
}

// -----------------------
// Process groups
// -----------------------
$modeStr = $DRY_RUN ? 'DRYRUN' : 'EXEC';

$groupCount = 0;

foreach ($allGroups as $baseKey => $g) {
  $groupCount++;
  if ($limitGroups !== null && $groupCount > $limitGroups) {
    out("Stopping due to --limit-groups=$limitGroups");
    break;
  }

  $groupDisplay = $g['display'];
  $files = $g['files'];

  out("");
  out("============================================================");
  out("GROUP: $groupDisplay  (" . count($files) . " files, total " . bytesToHuman((int)$g['totalBytes']) . ")");
  out("============================================================");

  // Build episode buckets and detect duplicates
  $byItem = [];
  foreach ($files as $f) {
    $variantKey = normVariantKey((string)($f['variant'] ?? ''));

    if ($f['ep'] === null) {
      $itemKey = ($variantKey === '') ? 'BASEONLY' : ('VAR__' . $variantKey);
    } else {
      $itemKey = sprintf('EP%05d', (int)$f['ep']) . ($variantKey === '' ? '' : ('__' . $variantKey));
    }

    $byItem[$itemKey][] = $f;
  }

  $hasNumberedEpisodes = false;
  foreach ($files as $f) {
    if ($f['ep'] !== null) {
      $hasNumberedEpisodes = true;
      break;
    }
  }

  $primaryFiles = [];     // files we will consolidate
  $duplicateMoves = [];   // ['file' => <fileRecord>, 'reason' => string]

  foreach ($byItem as $itemKey => $bucket) {

    // BASEONLY bucket (no "# 01")
    if ($itemKey === 'BASEONLY') {

      if ($hasNumberedEpisodes) {
        // base-only files are duplicates if numbered episodes exist
        foreach ($bucket as $f) {
          $duplicateMoves[] = [
            'file' => $f,
            'reason' => 'unnumbered base title file (base + numbered exists)',
          ];
        }
        continue;
      }

      // If multiple BASEONLY copies exist, keep best one and dupe the rest
      if (count($bucket) === 1) {
        $primaryFiles[] = $bucket[0];
      } else {
        $keep = pickBestCopy($bucket);
        $primaryFiles[] = $keep;

        foreach ($bucket as $f) {
          if ($f['path'] === $keep['path']) continue;
          $duplicateMoves[] = [
            'file' => $f,
            'reason' => "duplicate base-only title (kept best copy)",
          ];
        }
      }

      continue;
    }

    // Any non-BASEONLY item (EPxxxxx__variant or VAR__variant):
    if (count($bucket) === 1) {
      $primaryFiles[] = $bucket[0];
    } else {
      $keep = pickBestCopy($bucket);
      $primaryFiles[] = $keep;

      foreach ($bucket as $f) {
        if ($f['path'] === $keep['path']) continue;
        $duplicateMoves[] = [
          'file' => $f,
          'reason' => "duplicate item ($itemKey) (kept best copy)",
        ];
      }
    }
  }
  // Make ordering deterministic
  sortFileRecordsNaturally($primaryFiles);

  // Sort duplicate moves deterministically
  usort($duplicateMoves, static function (array $a, array $b): int {
    $aa = basename($a['file']['path']);
    $bb = basename($b['file']['path']);

    $c = strnatcasecmp($aa, $bb);
    if ($c !== 0) return $c;

    return strnatcasecmp($a['file']['path'], $b['file']['path']);
  });

  // Move duplicates aside first (within their current drive)
  foreach ($duplicateMoves as $d) {
    $f = $d['file'];
    $src = $f['path'];
    if (!file_exists($src)) continue;

    moveToDuplicates(
      $logFp,
      $DRY_RUN,
      $modeStr,
      $groupDisplay,
      $src,
      $DRIVES[$f['driveKey']],
      $d['reason']
    );
  }

  if (count($primaryFiles) === 0) {
    out("No primary files remain after duplicate rules. Skipping consolidation.");
    logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'GROUP_SKIP', '', '', (int)$g['totalBytes'], 'OK', 'no primary files; moved items to duplicates']);
    continue;
  }

  // Determine destination drive with fallback:
  // 1) Rank drives by (primary count already on drive, then bytes already on drive, then free space)
  // 2) Try each drive in order
  //    - First pass: only accept drives that already have enough free (no evacuation)
  //    - Second pass: allow evacuation (except when dest is the balance-to drive)

  $counts = [];
  $bytesByDrive = [];
  foreach ($primaryFiles as $f) {
    $dk = $f['driveKey'];
    $counts[$dk] = ($counts[$dk] ?? 0) + 1;
    $bytesByDrive[$dk] = ($bytesByDrive[$dk] ?? 0) + (int)$f['size'];
  }

  $candidates = [];
  foreach ($DRIVES as $dk => $root) {
    $candidates[] = [
      'driveKey' => $dk,
      'root' => $root,
      'cnt' => (int)($counts[$dk] ?? 0),
      'bytes' => (int)($bytesByDrive[$dk] ?? 0),
      'free' => diskFree($root),
    ];
  }

  // Sort best-first
  usort($candidates, static function (array $a, array $b): int {
    // cnt desc, bytes desc, free desc
    if ($a['cnt'] !== $b['cnt']) return $b['cnt'] <=> $a['cnt'];
    if ($a['bytes'] !== $b['bytes']) return $b['bytes'] <=> $a['bytes'];
    return $b['free'] <=> $a['free'];
  });

  $chosen = null;

  // Two-phase try: first without evacuation, then with evacuation
  foreach ([false, true] as $allowEvac) {
    foreach ($candidates as $cand) {
      $tryDriveKey = $cand['driveKey'];
      $tryRoot = $cand['root'];

      // Compute incoming bytes if this drive is the destination
      $incomingBytesTry = 0;
      foreach ($primaryFiles as $pf) {
        if ($pf['driveKey'] !== $tryDriveKey) {
          $incomingBytesTry += (int)$pf['size'];
        }
      }

      $requiredTry = $incomingBytesTry + gbToBytes($reserveGb);
      $freeNow = diskFree($tryRoot);

      // If it fits without evacuation, accept immediately
      if ($freeNow >= $requiredTry) {
        $chosen = [
          'driveKey' => $tryDriveKey,
          'root' => $tryRoot,
          'incomingBytes' => $incomingBytesTry,
          'required' => $requiredTry,
          'freedBytes' => 0,
        ];
        break 2;
      }

      // Second phase: attempt evacuation (but never evacuate "to itself")
      if ($allowEvac && $tryDriveKey !== $balanceToDriveKey) {
        out("Destination candidate [$tryDriveKey] short by " . bytesToHuman($requiredTry - $freeNow) . " (free=" . bytesToHuman($freeNow) . "). Attempting evacuation...");
        $freedBytes = ensureDestinationHasSpace(
          $logFp,
          $DRY_RUN,
          $modeStr,
          $tryDriveKey,
          $tryRoot,
          $balanceToDriveKey,
          $DRIVES[$balanceToDriveKey],
          $requiredTry,
          $processedGroups
        );

        $freeAfter = $DRY_RUN ? ($freeNow + $freedBytes) : diskFree($tryRoot);

        if ($freeAfter >= $requiredTry) {
          $chosen = [
            'driveKey' => $tryDriveKey,
            'root' => $tryRoot,
            'incomingBytes' => $incomingBytesTry,
            'required' => $requiredTry,
            'freedBytes' => $freedBytes,
          ];
          break 2;
        }


        out("  Still insufficient on [$tryDriveKey] after evacuation (required=" . bytesToHuman($requiredTry) . ", free=" . bytesToHuman($freeAfter) . "). Trying next candidate...");
      }
    }
  }

  if ($chosen === null) {
    $primaryBytes = 0;
    foreach ($primaryFiles as $pf) $primaryBytes += (int)$pf['size'];

    $msg = 'no destination drive could satisfy space requirements'
      . '; incoming+reserve needed varies by destination'
      . '; reserve=' . $reserveGb . ' GB';

    err("  SKIP GROUP: $msg");
    logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'GROUP_SKIP', '', '', $primaryBytes, 'SKIP', $msg]);
    continue;
  }

  // Final chosen destination
  $destDriveKey = $chosen['driveKey'];
  $destRoot     = $chosen['root'];
  $incomingBytes = $chosen['incomingBytes'];
  $required      = $chosen['required'];

  out("Destination drive (with fallback): $destDriveKey ($destRoot)");

  // Compute list of files that must be moved into destination (primary files not already there)
  $toMove = [];
  foreach ($primaryFiles as $f) {
    if ($f['driveKey'] !== $destDriveKey) {
      $toMove[] = $f;
    }
  }

  out("Primary files: " . count($primaryFiles) . " | Need to move into destination: " . count($toMove) . " (" . bytesToHuman($incomingBytes) . ")");

  // SAFETY: if we STILL don't have enough space (should be rare), skip this group
  $freeNow = diskFree($destRoot);
  $effectiveFree = $DRY_RUN ? ($freeNow + (int)($chosen['freedBytes'] ?? 0)) : $freeNow;

  if ($effectiveFree < $required) {
    $primaryBytes = 0;
    foreach ($primaryFiles as $pf) $primaryBytes += (int)$pf['size'];

    $msg = 'insufficient space after destination selection/evacuation'
      . '; required=' . bytesToHuman($required)
      . '; free=' . bytesToHuman($effectiveFree)
      . '; incoming=' . bytesToHuman($incomingBytes)
      . '; reserve=' . $reserveGb . ' GB'
      . '; dest=' . $destDriveKey;

    err("  SKIP GROUP: $msg");
    logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'GROUP_SKIP', '', $destRoot, $primaryBytes, 'SKIP', $msg]);
    continue;
  }

  $primaryFilesSorted = $primaryFiles;
  usort($primaryFilesSorted, static function ($a, $b) {
    $c = strnatcasecmp(basename($a['path']), basename($b['path']));
    if ($c !== 0) return $c;
    return strnatcasecmp($a['path'], $b['path']); // tie-breaker
  });

  // Move each needed file into destination root
  $movedPrimaryPaths = [];
  foreach ($primaryFilesSorted as $f) {
    $src = $f['path'];
    $bytes = $f['size'];

    // If it already lives on destination, keep it
    if ($f['driveKey'] === $destDriveKey) {
      $movedPrimaryPaths[] = $src;
      continue;
    }

    if (!file_exists($src)) {
      logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MOVE', $src, $destRoot, $bytes, 'FAIL', 'source missing']);
      err("  FAIL: source missing $src");
      continue;
    }

    if (!ensureDir($destRoot)) {
      logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MKDIR_DEST', $src, $destRoot, $bytes, 'FAIL', 'could not ensure dest root']);
      err("  FAIL: could not ensure dest root: $destRoot");
      continue;
    }

    $destPath = rtrim($destRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($src);

    // If destination exists, treat source as duplicate and move to duplicates on its current drive
    if (file_exists($destPath)) {
      moveToDuplicates($logFp, $DRY_RUN, $modeStr, $groupDisplay, $src, $DRIVES[$f['driveKey']], 'destination collision');
      continue;
    }

    out("  MOVE: $src  ->  $destPath");

    if ($DRY_RUN) {
      logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MOVE', $src, $destPath, $bytes, 'DRYRUN', '']);
      $movedPrimaryPaths[] = $destPath;
      continue;
    }

    $cmdOut = [];
    $exit = 0;
    shellMoveToPath($src, $destPath, $cmdOut, $exit);

    if ($exit === 0 && file_exists($destPath) && !file_exists($src)) {
      logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MOVE', $src, $destPath, $bytes, 'OK', '']);
      $movedPrimaryPaths[] = $destPath;
    } else {
      $msg = 'mv failed: exit=' . $exit . ' out=' . implode(' | ', $cmdOut);
      logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MOVE', $src, $destPath, $bytes, 'FAIL', $msg]);
      err("  FAIL: $msg");
    }
  }

  // Record processed group "home"
  $groupBytes = 0;

  sortPathsByEpisodeScene($movedPrimaryPaths);

  foreach ($primaryFiles as $f) $groupBytes += $f['size'];

  $processedGroups[$baseKey] = [
    'display' => $groupDisplay,
    'homeDriveKey' => $destDriveKey,
    'primaryFiles' => $movedPrimaryPaths,
    'bytes' => $groupBytes,
  ];

  logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'GROUP_DONE', '', $destRoot, $groupBytes, 'OK', '']);
}

// -----------------------
// Final balancing pass
// -----------------------
if ($DO_BALANCE) {
  out("");
  out("============================================================");
  out("FINAL BALANCING PASS (target free per drive: {$targetFreeGb} GB)");
  out("============================================================");

  $targetBytes = gbToBytes($targetFreeGb);

  foreach ($DRIVES as $driveKey => $root) {
    if ($driveKey === $balanceToDriveKey) continue;

    $free = diskFree($root);
    out("Drive [$driveKey] free: " . bytesToHuman($free));

    if ($free >= $targetBytes) continue;

    $need = $targetBytes - $free;
    out("  Needs to free: " . bytesToHuman($need));

    // Evacuate whole processed groups from this drive to balanceTo until free >= target
    $freed = evacuateLargestGroupsFromDrive(
      $logFp,
      $DRY_RUN,
      $modeStr,
      $driveKey,
      $root,
      $balanceToDriveKey,
      $DRIVES[$balanceToDriveKey],
      $need,
      $processedGroups
    );

    $free2 = $DRY_RUN ? ($free + $freed) : diskFree($root);
    out("  Freed: " . bytesToHuman($freed) . " | Now free: " . bytesToHuman($free2));
  }
} else {
  out("Skipping final balancing pass (--no-balance).");
}

fclose($logFp);
out("");
out("Done. Log written to: $logFile");
out("NOTE: Duplicates were placed under each drive's /recorded/duplicates/");
out("IMPORTANT: Review duplicates before deleting anything.");

