# Macro Matrix

Version 1.4.1

A Zabbix frontend module that shows the effective value of user macros across many hosts, where each value comes from, and lets you change them in bulk: host overrides, template macros at the source, pins, reverts, CSV import and export.

Built for Zabbix 7.4. Nothing 7.4-specific is used knowingly, but 7.0 is untested.

## Install

1. Copy the `macromatrix` directory to `ui/modules/` in the Zabbix frontend (for packages usually `/usr/share/zabbix/ui/modules/`).
2. Administration > General > Modules > Scan directory, then enable **Macro Matrix**.
3. The page appears as **Data collection > Macro matrix**, right after Hosts.

Who sees it: Admin and Super admin user types whose role allows Data collection > Hosts. Editing template macros also needs the Templates UI element and write permission on the template. Everything runs through the API as the logged-in user, so Zabbix permissions apply as usual.

## Using it

**Rows.** Choose what the grid lists:

- **Hosts**: pick host groups and/or hosts. An empty filter means all hosts, as in the native host list.
- **Templates**: pick template groups and/or templates. Each template row shows what a host linked only to that template would get: the template's own macro, then the templates it links, then global.
- **Both**: templates first, then hosts. Each kind loads only if you picked something for it.

With template rows, **Only templates used by hosts** drops templates nothing inherits, and **Also load the hosts that use them** adds every host that inherits a template row, directly or through other templates. Combined with macros as rows, that puts a template next to all its hosts and tints where they drift from it.

**Templates in use.** A tab listing templates that at least one host inherits (optionally narrowed by template groups), with the number of hosts using each (total, and linked directly) and how many macros it defines, counting only those matching the Macros field when it is filled in. Sort by any column; **Include templates no host uses** shows the rest. **Compare with its hosts** opens the grid with the template and every host using it; **Macros** opens the template alone. Tick several to open them together. Counts cover the hosts and templates you can read.

**Macros.** Type macro names: `{$SNMP_*}, {$LOW_SPACE_LIMIT}`. The braces and `$` are optional, `*` matches anything, and names are case-insensitive. Type an exact name (no `*`) to get a column even where the macro is not defined anywhere yet.

**Context.** Leave it empty to see context macros as their own columns. Enter a value such as `/var` to add, for every macro name, a column resolved exactly as an item using `{$NAME:"/var"}` would see it.

**Layouts.** One switch, remembered in your browser:

- **Auto** (default): macros as rows while up to 12 hosts or templates are loaded, hosts as rows beyond that.
- **Macros as rows**: macros down the side, grouped by the first part of their name (`NET`, `ICMP`, `CPU`...), each group collapsible; one column per host or template, 10 per page. Long macro names and values wrap in full. A cell says **set here** when the macro is on that host or template itself, and otherwise names where the value comes from in grey (**Show where values come from** turns that off). With two or more columns, values that differ from the most common value in their row are tinted, and **Only macros that differ** filters down to those. Ties go to the leftmost column, so a template listed first acts as the baseline.
- **Hosts as rows**: the classic matrix, one column per macro. Best for many hosts and a few macros.
- **List**: one line per host or template and macro. **Hide undefined** drops empty pairs.

**Filter macros** narrows by name in every layout, and **Macros** hides ones you don't need right now (**Hide unused** hides macros undefined everywhere loaded).

**Changing a value.** Click a value (or focus it and press Enter):

- **Set it / Change the value on this host or template**: writes the macro on the row itself. On a template row that already has the macro, this edits the template macro in place, and the dialog shows how many hosts it reaches.
- **Change it where it comes from (template X)**: edits the template the value is inherited from. The dialog counts how many hosts the value reaches, across every host that inherits the template, not just the loaded ones.
- **Remove it from this host or template**: deletes the row's own macro so it inherits instead.

**How a value is found.** Every value's dialog opens with a diagram of its lookup path: the host or template itself, each level of linked templates (level 2 and below say which template linked them), then global macros. The box that wins is highlighted, lower definitions show as shadowed, templates on the path without the macro show dashed (wide levels collapse to "N more without it"). For context macros it spells out the two passes: context matches at every level first, then the first plain value. The change options state their consequence, for example "Remove it from this host (then 90 from Linux by Zabbix agent)".

**Where a template value goes.** Editing a template macro, the Reach column in Find everywhere, and each template change in the review show the reverse diagram: the template, the templates it reaches hosts through, and the hosts in three buckets (use the value, keep their own override, get it from elsewhere), each expandable to names. In the review the buckets update as you tick overrides to revert, so you see the blast radius before applying.

**Bulk.** Tick rows (hosts, templates or both), pick a macro, then choose one of:

- **Set value** writes the same value on every selected row.
- **Pin current value** copies each row's effective value onto the row itself, keeping its type. For secret sources it asks for the value once, because secrets cannot be read back.
- **Remove from rows** deletes the rows' own macros so they inherit instead.

**Find.** A second tab lists every definition of the matching macros on any host, template or global level you can read, with no host filter. Host and template rows can be edited in place; host rows can be deleted. **Calculate reach** counts how many hosts actually resolve to each template macro.

**Staging and apply.** Nothing is written until **Review and apply**. The review lists every write with before and after values. For template edits it lists the hosts that override the macro: those keep their own value (host overrides always win) unless you tick them to revert in the same apply.

Before writing, the module re-reads everything it is about to change. Anything someone else changed since you loaded it is shown as a conflict and skipped, never overwritten.

**CSV.**

- **Export** writes the visible rows and columns: host, row_type, name, macro, effective value, type, source, source object and the row's own value. Secrets are written as `<secret>`.
- **Import** takes `host,macro,value` plus optional `type` (`text`, `secret`, `vault`), `description` and `row_type` (`host` or `template`, to tell apart a host and a template with the same name). Rows are matched by technical name, then visible name, among the loaded rows. The macro must be one of the current columns. Imported rows are staged, not applied.

## How values are resolved

The module reproduces the server's lookup from `src/libs/zbxcacheconfig/user_macro.c`. It is not taken from the documentation, which leaves some of this out.

1. The host, then all templates linked to it, sorted by template ID (not linking order), then the templates linked to those, and so on, level by level. Global macros come last.
2. On one object, macros of a name are tried in this order: without context, then static contexts, then regex contexts. The first full match wins.
3. With a context, the first context-less macro met on the way is kept as fallback. It is used only if nothing matches the context anywhere, and that includes global macros. A global `{$M:"/var"}` beats a host-level `{$M}` for context `/var`.

Cells that fell back show a **no context** badge. `tests/ResolverTest.php` covers these rules, including uint64 template ID ordering, diamond linkage and regex ordering:

```
php tests/ResolverTest.php /path/to/zabbix/ui
php tests/UsageTest.php
```

## Limits and known gaps

- Up to 5,000 hosts and templates per grid load, and 150 macros. Narrow the filter beyond that. The reach calculation handles up to 20,000 inheriting hosts.
- Regex-context macros are shown where they are defined, not resolved: the result depends on the context an item passes.
- Template reach counts resolution of the macro as defined. An item asking for a context variant may also fall back to a context-less template macro; that is not counted.
- Linked templates you cannot read are invisible to the API, so values inherited from them cannot be shown. The page warns when that happens.
- Host prototype macros are not shown or edited.
- Discovered (LLD) macros are marked **LLD**. Editing one converts it to a manual macro, which is the only way the API allows it.
- Apply writes deletes, then updates, then creates, in chunks of 500. Each chunk is one API transaction. If a chunk fails, earlier chunks stay applied. Failed changes stay staged so you can fix and retry; the result screen says which is which.
- Staged changes live in the page. Leaving the page warns you when there are unapplied changes.

## Layout

```
macromatrix/
├── manifest.json
├── Module.php                    menu entry
├── actions/
│   ├── MacroMatrixView.php       page
│   ├── MacroMatrixJsonAction.php base for the JSON endpoints
│   ├── MacroMatrixResolve.php    grid data
│   ├── MacroMatrixFind.php       Find mode
│   ├── MacroMatrixReach.php      template macro reach
│   ├── MacroMatrixTemplates.php  Templates in use
│   └── MacroMatrixApply.php      conflict check + writes
├── lib/
│   ├── MacroResolver.php         precedence logic, no API calls
│   ├── MacroKey.php              macro and pattern parsing
│   └── MacroData.php             API access
├── views/
│   ├── macromatrix.view.php
│   └── js/macromatrix.view.js.php
├── assets/css/macromatrix.css
└── tests/
    ├── ResolverTest.php
    └── UsageTest.php
```

## Changes

**1.4.1**
- Fixed an empty, unstyled message box appearing whenever messages were cleared (on every load).
- Fixed the empty staged-changes bar showing as a thin orange strip.
- Context field hint now says it takes a literal value, not a pattern.

**1.4.0**
- **Templates in use** tab: templates with hosts inheriting them, host counts (total and direct), macro counts, sorting, and one-click opening in the grid alone or next to their hosts.
- Grid filter options **Only templates used by hosts** and **Also load the hosts that use them**.
- Macro limit per grid load raised from 40 to 150, since macros as rows handles long lists.

**1.3.0**
- Lookup diagram in every value's dialog, showing each level, which definition wins, what it shadows, and which templates were checked without defining it.
- Consequence text on changes: removing a macro says which value takes over.
- Reach diagram for template macros (edit dialogs, Find everywhere, review step), with hosts bucketed by effect and the templates they inherit through.

**1.2.0**
- New **Macros as rows** layout: grouped, collapsible macro rows with one column per host or template, full-width names and values, "set here" markers, and highlighting of values that differ across columns, with an "only macros that differ" filter.
- **Auto** orientation picks macros as rows for up to 12 loaded hosts or templates, hosts as rows beyond.
- **Filter macros** box in every layout.
- Removed the 1.1 compact view (letter chips and character-broken headers). Macro headers in **Hosts as rows** now wrap only at dots, underscores and colons.

**1.1.0**
- Templates as grid rows (template groups and templates filters, Hosts / Templates / Both).
- Editing a template's own macro in place, with reach and override choices.
- List layout and the macro column picker.

**1.0.0**
- Grid of effective values with sources, Find everywhere, staging with review and conflict-checked apply, bulk set, pin and remove, CSV import and export.
