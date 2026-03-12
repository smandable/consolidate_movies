# consolidate_movies

A PHP CLI tool that consolidates episodic video files spread across multiple drives into a single location per title. Designed for macOS setups with multiple external volumes (e.g., `/Volumes/Recorded 1/recorded`, `/Volumes/Recorded 2/recorded`, etc.).

## What It Does

1. Scans all configured drives for video files
2. Groups files by base title (parses episode numbers, scene markers, disc/part labels)
3. Detects and moves duplicates to a `duplicates` folder on their current drive (keeping the largest/newest copy)
4. Consolidates each group onto a single drive, choosing the one that already holds the most files from that group
5. Evacuates groups between drives when space is tight
6. Optionally runs a final balancing pass to maintain a target free-space threshold on each drive

## Usage

```bash
# Preview what would happen (default)
php consolidate_movies.php --dry-run

# Actually perform moves
php consolidate_movies.php --execute
```

**Always run `--dry-run` first.**

### Options

| Flag | Description |
|---|---|
| `--dry-run` | Plan only, no moves (default) |
| `--execute` | Perform actual moves |
| `--recursive` | Scan subdirectories within each drive |
| `--target-free-gb=N` | Target free space per drive in GB (default: `100`) |
| `--reserve-gb=N` | Extra headroom required before pulling a group onto a drive (default: `20`) |
| `--no-balance` | Skip the final balancing pass |
| `--only="Title"` | Only process groups whose base title contains this substring |
| `--limit-groups=N` | Stop after N groups (for testing) |
| `--log="file.tsv"` | Custom log filename (default: timestamped TSV) |

## Configuration

Edit the `$DRIVES` array at the top of the script to match your volume paths:

```php
$DRIVES = [
  'Recorded 1' => '/Volumes/Recorded 1/recorded',
  'Recorded 2' => '/Volumes/Recorded 2/recorded',
  'Recorded 3' => '/Volumes/Recorded 3/recorded',
  'Recorded 4' => '/Volumes/Recorded 4/recorded',
];
```

`$BALANCE_TO_DRIVE_KEY` sets which drive absorbs evacuated groups during space balancing (defaults to `Recorded 4`).

## Filename Parsing

The script parses filenames like:

- `Title # 08 - Scene_2 blah` -> base: `Title`, episode: 8, variant: `Scene_2 blah`
- `Title - CD1` -> base: `Title`, variant: `CD1`
- `Title - Disc 2 - Extras` -> base: `Title`, variant: `Disc 2 - Extras`
- `Title (Studio) # 01` -> base: `Title (Studio)`, episode: 1

Duplicates are identified by matching episode + variant combinations. The largest file wins; others are moved to the `duplicates` folder.

## Logging

Every action is logged to a TSV file with columns: timestamp, mode, group, action, source, destination, bytes, status, and message.

## Supported Formats

`mp4`, `mkv`, `avi`, `mov`, `m4v`, `mpg`, `mpeg`, `ts`, `wmv`, `flv`, `webm`, `vob`

## Requirements

- PHP 8.0+ (uses `str_starts_with`)
- macOS (uses `/bin/mv` for cross-volume moves)
