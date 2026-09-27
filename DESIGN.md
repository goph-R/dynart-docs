# Docs - a documentation site from a folder of Markdown

**Status: the build and the site work** (plugin 0.4.0, on Dpress 0.80.0 and dynart-micro 0.20.3).
`dpress docs:build` builds all 54 pages `docs-public`'s toctrees reach, with **the heading ids of
the Sphinx build on every one** - checked against its `_build/html` - and `/docs/...` serves them,
with the old `.html` addresses answered by a 301, and the admin has a Documentation screen. The
Pascal in it is highlighted since Dpress 0.80.0. All five steps are done.

A Dpress plugin that does what `sphinx-build` does for
[docs-public](https://github.com/DynartInteractive/docs-public) - reads a folder of MyST Markdown,
follows its `toctree`s into a tree, and publishes it - but through Dpress's own renderer, so
shortcodes, callouts, code highlighting and internal links all work, and the pages are drawn by
the site's theme with the rest of the blog.

## Decisions

| | |
|---|---|
| Where the pages live | **The plugin's own table**, rebuilt from the files. The files are the only source; nothing is edited in the admin, and the blog's Pages are not touched. |
| Their address | **The files' own case**: `/docs/dos-game-engine/BASICS/VGA`. The base (`docs`) is a setting. |
| The tree | **A block**, for the sidebar, drawn **only on a documentation page** - the blog's own pages keep the sidebar they had. |

## What the source is

`docs-public` today: **59 pages, ~9,200 lines**, in three git submodules (`lisa-engine`,
`dos-game-engine`, `legal`), built with Sphinx + MyST + `sphinx_rtd_theme`. Beyond CommonMark it
uses, and the build has to understand:

| Syntax | Where | Becomes |
|---|---|---|
| ```` ```{toctree} ```` with `:maxdepth:`, `:caption:` | 11 `index.md` files | the tree - see below |
| ```` ```{note} ```` | 1 page | a Dpress callout, `> [!NOTE]` |
| `{#id}` on a line before a block, and `{#id .class}` | `legal/terms-of-use.md` | an `id` on the heading that follows |
| ``{ref}`id` `` | `legal/terms-of-use.md` | a link to that `id`, its text the heading's |
| `[text](../ENGINE/BASEGAME.md)`, relative | 16 pages | a link to that page's address |
| `<br>` in a table cell | tables in `dos-game-engine` | `{{ br() }}` - raw HTML is stripped by the renderer |
| bare URLs (`linkify`) | throughout | Dpress's autolinks already |

**Not yet**, and worth saying: **Pascal is not a language the code highlighter knows** (EnlighterJS
has no Pascal), and 338 of the code blocks are Pascal - they render as plain code until a Pascal
definition is added to the highlighter. No page has an image yet, so images - which would have to
be copied or served from the source folder - are left for when one does.

## The build

A pass like `sphinx-build`'s, run:

- when the **source folder** is saved on the plugin's settings (its own section, *Documentation*);
- from a **Rebuild** button on the plugin's admin screen - in the navigation through
  `adminSections()` (Dpress 0.77.0);
- from **`dpress docs:build`**, for a deploy or a cron job - which needs one thing the core does
  not have yet: **plugins cannot add CLI commands** (`DpressCliApp::COMMANDS` is a constant). A
  `commands()` on the plugin interface, the same shape of change as `adminSections()`, comes first.

What it does:

1. **Start at `index.md`** in the source folder and follow each `toctree` in order: an entry is a
   path relative to the file it is in, without `.md`. What no `toctree` reaches is not published -
   Sphinx's rule, which is what keeps `README.md`, `CLAUDE.md` and `_build/` out.
2. **Each page**: read it, run the **MyST pre-pass** (the table above), render it through
   `MarkdownRenderer`, keep its **title** (the first `#` heading) and its **headings** (for an
   "on this page" list), and its place: parent, position among its siblings.
3. **A `toctree` in a page** is replaced by the list of its entries - the links Sphinx draws in
   that place - with `:caption:` as the list's heading.
4. **Write the whole tree in one go**, replacing the last build, so a page removed from the source
   is removed from the site and a half-finished build never shows.
5. **Report**: pages built, links that pointed at nothing, `toctree` entries with no file, syntax
   left unconverted - on the admin screen after a Rebuild, and on the console after `docs:build`.

## What is stored

`dp_docs_page`: `path` (the address below the base, original case, unique), `title`, `parent_id`,
`position`, `html` (rendered), `headings` (JSON: level, text, id), `source` (the file, relative),
`source_hash` (so an unchanged file need not be re-rendered), `built_at`.

## What a visitor gets

- **`/docs/<path>`** - the page, drawn with the theme's page layout and a template of the plugin's
  (`docs:page`, which a theme may override): **breadcrumbs** up the tree, the page, and
  **previous / next** in the tree's reading order, as Sphinx's theme has - except on the root,
  which is the contents: a reader chooses there, and "Next" would choose for them.
- **`/docs`** - the root `index.md`.
- **The *Documentation tree* block**, placed in the sidebar: the root's chapters - not the root,
  which the block's title stands for - the branch of the current
  page open, the page itself marked; draws nothing on a page that is not documentation. **On a
  documentation page it is alone in its place** - the tag cloud, the categories and the rest are
  the blog's way around, not the manual's. Through `block:before_render` (Dpress 0.79.0), so the
  footer and every other page keep their blocks.
- **Internal links**: `docs#12`-style references are not needed - the source links by path, and
  the build turns those into addresses.

## The old addresses

Sphinx published at **docs.dynart.net**, every page as `.html`, and those addresses are in
bookmarks, search results and other people's pages. They map one to one, and each is answered
with a **301 Moved Permanently**:

| Sphinx | Docs plugin |
|---|---|
| `/dos-game-engine/BASICS/VGA.html` | `/docs/dos-game-engine/BASICS/VGA` |
| `/dos-game-engine/index.html` | `/docs/dos-game-engine` |
| `/index.html`, `/` | `/docs` |

- **On docs.dynart.net**, which is another host than the blog, three lines of `.htaccess` do it and
  nothing of Dpress runs there:

  ```apache
  RewriteEngine On
  RewriteRule ^$                       https://gopherlab.net/docs [R=301,L]
  RewriteRule ^(?:(.*)/)?index\.html$  https://gopherlab.net/docs/$1 [R=301,L]
  RewriteRule ^(.*)\.html$             https://gopherlab.net/docs/$1 [R=301,L]
  ```

- **On the blog**, the plugin also accepts `.html` on its own addresses and answers it with a 301 to
  the address without - so `gopherlab.net/docs/.../VGA.html`, which is what somebody rewriting an
  old link by hand types, lands too. Were docs.dynart.net ever pointed at this install instead, the
  same route answers the bare old paths.
- **The `#fragment` of an old link survives** a 301 - the browser keeps it - but only lands if the
  heading has the id Sphinx gave it. Dpress's renderer gives headings no ids, so the build adds them
  **by Sphinx's rule**: lowercase, every run of characters that is not a letter or a digit one `-`,
  none at either end - `## VGA Graphics` is `#vga-graphics`. A title repeated on one page is named
  the way the existing `_build/html` shows, checked against it. `{#id}` in the source wins over the
  generated one, as it does in MyST.

## What the admin gets

A **Documentation** section in the navigation, after Pages, with: the source folder, the address,
the time and result of the last build - its problems listed - a **Build now** button, and the tree
of built pages (read-only, each with a View). The settings are in their own *Documentation* section
of the Site tab, which has a Build button too; each goes back to the screen it was pressed on.

The last build is kept in a setting, `docs_last_build`, written by every build - the button's and
`dpress docs:build`'s alike. Not the pages' `built_at`: a build that makes nothing leaves the last
good pages up, and the screen has to say both.

## On production

The source folder has to be on the server: `docs-public` cloned with its submodules
(`git submodule update --init --recursive`), then `dpress docs:build` after each pull - or the
Rebuild button. The build reads only inside the folder it is given.

**Or the build pulls it** (0.4.0): with *Update* on in the Documentation settings, a build - the
button's, or `dpress docs:build` from a cron job - first runs `git pull --ff-only
--recurse-submodules` and `git submodule update --init --recursive` in the source folder
(`SourceUpdater`). What it did is the first line of the build's report: "updated from a to b",
"up to date", or - as a problem, with the build going ahead on what is there - git's own
`fatal:` line.

- **The web server's user has to own the folder**, since that is who runs git from the button:
  `chown -R www-data: /var/www/docs-public`.
- **The remotes have to need no password**: `https://` for public repositories - the submodules
  too, whose `.gitmodules` addresses are `git@github.com:` and are overridden in the clone's own
  config. Git is told there is nobody to ask, so a missing credential fails at once rather than
  hanging the request.
- **`--ff-only`**, because the server's copy is a mirror: an edit made there stops the pull with
  "Not possible to fast-forward" instead of becoming a merge commit on the server.

## The order of work

1. **Core:** `PluginInterface::commands()` - CLI commands from a plugin. **Done, Dpress 0.78.0.**
2. **Plugin, the build:** the entity and migration, the tree walk, the MyST pre-pass, the render,
   `docs:build`. Tested against `docs-public` itself. **Done** - and one construct the table above
   missed, found by that: `{.numbered-header}`, classes with no id, which go on the heading.
   Later, from reading the pages: a fence with no language is built as `text`, so it is drawn in
   the highlighter's box as Sphinx draws every literal block - not as the blog's plain `<pre>`.
3. **Plugin, the site:** the route, the page template, breadcrumbs and previous/next, the tree block.
   **Done** - and one change to the framework it needed: the router tried catch-alls in the order
   they were added, so the site's `/*` took `/docs/*` too. A longer catch-all is tried first now
   (dynart-micro 0.20.3). A page's own `# Title` is taken out of its body and drawn as the
   theme's `entry-title`, keeping its id. A path in another case is a 301 to the real one.
4. **Plugin, the admin:** the section, the settings, Rebuild and its report. **Done.**
5. **Highlighter:** a Pascal language for EnlighterJS - separate, and it helps the blog's posts too.
   **Done, in Dpress 0.80.0** - in the core, not here: `assets/enlighter/pascal.js`.
