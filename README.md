# Padstone Mason Neo

Companion Craft plugin that registers a **Neo** structure adapter for [Padstone Mason](https://github.com/imarc/padstone-mason). Matrix-only sites do not need this package.

## Requirements

- Craft CMS ^5.3
- `imarc/craft-padstone-mason` ^0.1.0
- `spicyweb/craft-neo` **^5.2** (covers 5.2–5.x; pre-5.2 create semantics are out of scope)

## Install order

1. Install and enable **Neo**
2. Install and enable **Padstone Mason**
3. Install and enable **Padstone Mason Neo** (this plugin)

In this monorepo, both Mason packages are path repositories. From the project root:

```bash
composer require imarc/craft-padstone-mason-neo
php craft plugin/install padstone-mason-neo
```

## What it does

- Detects Neo fields by field class (not a site-wide setting)
- Supports the same Mason block tools for **top-level** Neo blocks: describe, read, summarize, add, update, remove, reorder, `apply_blocks`
- Reuses Mason `FieldSupport` for writable scalars, relations, and links on Neo blocks

## v1 limits

- **Top-level only.** Creating or moving nested Neo levels is refused with a clear error
- Neo groups are not first-class tools
- No local Neo Content Designer fixture is required for Matrix-only Padstone; verify on a Neo site (see checklist below)

## Client verification checklist (no Neo fixture)

On a site where `contentDesigner` (or another layout field) is Neo and this plugin is enabled:

- [ ] `describe_schema` / Ask Mason schema lists Neo block types
- [ ] Add a registered top-level block, then update it by returned id
- [ ] Reorder top-level siblings
- [ ] Remove a top-level block (respecting min top-level counts)
- [ ] `summarize_page` / `apply_blocks` for top-level ops
- [ ] Nested level add/move is refused
- [ ] With this plugin disabled, Neo structure mutations are refused and Matrix fields still work

## Disable

Disable or uninstall `padstone-mason-neo`. Mason’s Matrix adapter remains; Neo fields become unsupported structure fields again.
