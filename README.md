# WordPress Export to Server

A must-use plugin for WordPress Playground that saves WXR exports
directly to the Playground server filesystem — enabling round-trip
editing of demo data with Playground's "Export to GitHub" feature.

## The Problem

Maintaining demo data for a WordPress project (like GatherPress) means
constantly editing posts, events, and media — then exporting the
changes as a WXR file. In a Playground environment, there is no
persistent filesystem and no "Save to disk" for exports.

## How It Works

1. Adds a **"💾 Save Export to server 🤖"** button to the admin toolbar.
2. When clicked, captures the output of WordPress's core `export_wp()`.
3. Rewrites attachment URLs and the site home URL based on configurable
   options (so the export is portable to any environment).
4. Writes the resulting WXR file to a path on the Playground server.
5. Combined with Playground's "Export to GitHub", the saved file can be
   committed directly to a repository.

## Configuration

All configuration is done via WordPress options, set in your
Playground blueprint's `setSiteOptions` step:

| Option Key | Description | Default |
|---|---|---|
| `wordpress_export_to_server__file` | Export filename | `export.xml` |
| `wordpress_export_to_server__path` | Server directory to save the file | `WP_CONTENT_DIR/uploads` |
| `wordpress_export_to_server__owner_repo_branch` | `owner/repo/branch` for GitHub raw URL rewriting | _(none)_ |
| `wordpress_export_to_server__export_home` | Replacement home URL (without trailing slash) | _(none)_ |
| `wordpress_export_to_server__export_path` | Path appended to the GitHub raw URL | _(empty)_ |

### Why options instead of filters?

Playground blueprints are declarative JSON. The `setSiteOptions` step
sets values before any PHP runs — there is no equivalent for
`add_filter()`. Options are the only configuration mechanism that
works atomically within a blueprint without additional `writeFile` or
`runPHP` steps.

## Blueprint Example

```json
{
    "step": "setSiteOptions",
    "options": {
        "wordpress_export_to_server__file": "demo-data.xml",
        "wordpress_export_to_server__path": "/wordpress/wp-content/uploads",
        "wordpress_export_to_server__owner_repo_branch": "GatherPress/gatherpress-demo-data/main",
        "wordpress_export_to_server__export_home": "https://gatherpress.test",
        "wordpress_export_to_server__export_path": ""
    },
    {
      "step": "writeFile",
      "path": "/wordpress/wp-content/mu-plugins/wordpress-export-to-server.php",
      "data": {
        "resource": "url",
        "url": "https://raw.githubusercontent.com/carstingaxion/wordpress-export-to-server/main/wordpress-export-to-server.php"
      }
    }
}
```


## Requirements

- WordPress Playground environment
- PHP 8.0+
- Must be installed as a must-use plugin (`mu-plugins/`)