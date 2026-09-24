<?php declare(strict_types = 0);

/**
 * @var CView $this
 */
?>
<script>
window.macromatrix = new class {

	TYPE_TEXT = 0;
	TYPE_SECRET = 1;
	TYPE_VAULT = 2;

	PAGE_SIZE = 100;
	TRANSPOSED_PAGE = 10;
	AUTO_MACROS_MAX = 12;
	LAYOUTS = ['auto', 'macros', 'hosts', 'list'];

	init({filter, csrf, csrf_field, can_edit_templates}) {
		this.csrf = csrf;
		this.csrf_field = csrf_field;
		this.can_edit_templates = can_edit_templates;

		this.form = document.getElementById('mm_filter');
		this.root = document.getElementById('mm_root');
		this.messages = document.getElementById('mm_messages');
		this.grid_panel = document.getElementById('mm_grid_panel');
		this.find_panel = document.getElementById('mm_find_panel');
		this.templates_panel = document.getElementById('mm_templates_panel');
		this.tpl_data = null;
		this.tpl_filter = '';
		this.tpl_sort = {key: 'total', desc: true};
		this.tpl_include_unused = false;
		this.tpl_checked = new Set();

		this.staged_bar = document.createElement('div');
		this.staged_bar.className = 'mm-staged-bar';
		this.messages.after(this.staged_bar);

		this.grid = null;
		this.find = null;
		this.loaded_pattern = null;

		// Staged changes, keyed: h|hostid|macro (host cell), s|defid (edit a definition in place), d|defid (delete).
		this.staged = new Map();
		this.checked = new Set();
		this.reach_cache = new Map();

		this.page = 0;
		this.row_filter = '';
		this.show = 'all';
		this.find_filter = '';

		this.setThemeBackground();
		this.loadPrefs();

		if (this.LAYOUTS.includes(filter.layout)) {
			this.layout = filter.layout;
		}

		for (const radio of this.form.querySelectorAll('[name="rows"]')) {
			radio.addEventListener('change', () => this.applyRowsChoice());
		}

		this.form.addEventListener('submit', e => {
			e.preventDefault();
			this.load();
		});

		document.getElementById('mm_reset').addEventListener('click', () => this.resetFilter());

		for (const button of this.root.querySelectorAll('.mm-tab')) {
			button.addEventListener('click', () => this.setTab(button.dataset.tab));
		}

		window.addEventListener('beforeunload', e => {
			if (this.staged.size > 0) {
				e.preventDefault();
				e.returnValue = '';
			}
		});

		this.setTab(filter.tab, false);
		this.renderStagedBar();

		if (filter.pattern.trim() !== '' || this.tab === 'templates') {
			this.load();
		}
		else {
			this.renderEmpty(this.grid_panel, 'Enter one or more macro names above and press Load.');
			this.renderEmpty(this.find_panel, 'Enter one or more macro names above and press Load.');
			this.renderEmpty(this.templates_panel, 'Press Load to list the templates hosts use.');
		}
	}

	/* ------------------------------------------------------------------ helpers */

	esc(value) {
		return String(value ?? '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	el(html) {
		const template = document.createElement('template');
		template.innerHTML = html.trim();

		return template.content.firstElementChild;
	}

	typeName(type) {
		return ['Text', 'Secret', 'Vault'][type] ?? String(type);
	}

	displayValue(type, value) {
		if (type === this.TYPE_SECRET) {
			return '******';
		}

		return value ?? '';
	}

	valueHtml(type, value) {
		if (type === this.TYPE_SECRET) {
			return '<span class="mm-secret">******</span>';
		}

		if (value === '' || value === null || value === undefined) {
			return '<span class="mm-muted">(empty)</span>';
		}

		return this.esc(value);
	}

	/**
	 * Zabbix themes do not expose their colors as CSS variables; sticky table cells need an opaque background, so the
	 * page background is read once and handed to the stylesheet.
	 */
	setThemeBackground() {
		let node = this.root;

		while (node && node !== document.documentElement) {
			const color = getComputedStyle(node).backgroundColor;

			if (color && color !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(color)) {
				this.root.style.setProperty('--mm-bg', color);

				return;
			}

			node = node.parentElement;
		}
	}

	async post(action, body) {
		const url = new Curl('zabbix.php');
		url.setArgument('action', 'macromatrix.' + action);

		let response;

		try {
			response = await fetch(url.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify({...body, [this.csrf_field]: this.csrf[action]})
			});
		}
		catch (e) {
			throw {title: 'Cannot reach the Zabbix frontend.', messages: [String(e)]};
		}

		const text = await response.text();
		let data;

		try {
			data = JSON.parse(text);
		}
		catch (e) {
			throw {
				title: 'Unexpected response from the server.',
				messages: ['Your session may have expired. Reload the page and try again.']
			};
		}

		if (data.error) {
			throw data.error;
		}

		return data;
	}

	/**
	 * Replaces the message area. Called with no type (or no title) it only clears it.
	 */
	showMessage(type = null, title = null, messages = []) {
		this.messages.innerHTML = '';

		if (type === null || title === null || title === undefined) {
			return;
		}

		jQuery(this.messages).append(makeMessageBox(type, messages, title, true));
	}

	showError(error) {
		this.showMessage('bad', error.title ?? 'Error', error.messages ?? []);
	}

	showWarnings(warnings) {
		if (warnings && warnings.length) {
			this.showMessage('warning', warnings.length === 1 ? warnings[0] : 'Some results are incomplete.',
				warnings.length === 1 ? [] : warnings
			);
		}
	}

	renderEmpty(panel, text) {
		panel.innerHTML = `<div class="mm-empty">${this.esc(text)}</div>`;
	}

	filterValues() {
		const fd = new FormData(this.form);

		return {
			rows: fd.get('rows') ?? 'hosts',
			groupids: fd.getAll('groupids[]'),
			hostids: fd.getAll('hostids[]'),
			tpl_groupids: fd.getAll('tpl_groupids[]'),
			templateids: fd.getAll('templateids[]'),
			tpl_used_only: fd.get('tpl_used_only') === '1' ? '1' : '0',
			with_hosts: fd.get('with_hosts') === '1' ? '1' : '0',
			subgroups: fd.get('subgroups') === '1' ? '1' : '0',
			pattern: (fd.get('pattern') ?? '').trim(),
			context: fd.get('context') ?? ''
		};
	}

	updateUrl() {
		const values = this.filterValues();
		const url = new Curl('zabbix.php');

		url.setArgument('action', 'macromatrix.view');
		url.setArgument('tab', this.tab);
		url.setArgument('rows', values.rows);

		if (this.layout) {
			url.setArgument('layout', this.layout);
		}

		if (values.pattern !== '') {
			url.setArgument('pattern', values.pattern);
		}

		if (values.context !== '') {
			url.setArgument('context', values.context);
		}

		url.setArgument('subgroups', values.subgroups);

		if (values.tpl_used_only === '1') {
			url.setArgument('tpl_used_only', '1');
		}

		if (values.with_hosts === '1') {
			url.setArgument('with_hosts', '1');
		}

		values.groupids.forEach((id, i) => url.setArgument(`groupids[${i}]`, id));
		values.hostids.forEach((id, i) => url.setArgument(`hostids[${i}]`, id));
		values.tpl_groupids.forEach((id, i) => url.setArgument(`tpl_groupids[${i}]`, id));
		values.templateids.forEach((id, i) => url.setArgument(`templateids[${i}]`, id));

		history.replaceState(null, '', url.getUrl());
	}

	resetFilter() {
		jQuery('#groupids_').multiSelect('clean');
		jQuery('#hostids_').multiSelect('clean');
		jQuery('#tpl_groupids_').multiSelect('clean');
		jQuery('#templateids_').multiSelect('clean');
		this.form.querySelector('[name="rows"][value="hosts"]').checked = true;
		this.form.querySelector('[name="tpl_used_only"]').checked = false;
		this.form.querySelector('[name="with_hosts"]').checked = false;
		this.applyRowsChoice();
		this.form.querySelector('[name="pattern"]').value = '';
		this.form.querySelector('[name="context"]').value = '';
		this.form.querySelector('[name="subgroups"]').checked = true;
		this.updateUrl();
	}

	/**
	 * Shows only the filter fields that matter for the chosen rows (hosts, templates or both).
	 */
	applyRowsChoice(update_url = true) {
		const rows = this.form.querySelector('[name="rows"]:checked')?.value ?? 'hosts';

		for (const node of this.form.querySelectorAll('.js-mm-hosts')) {
			node.hidden = this.tab !== 'grid' || rows === 'templates';
		}

		for (const node of this.form.querySelectorAll('.js-mm-templates')) {
			node.hidden = this.tab !== 'grid' || rows === 'hosts';
		}

		// Templates in use: filtered by template groups only.
		if (this.tab === 'templates') {
			for (const node of this.form.querySelectorAll('.js-mm-tpl-tab')) {
				node.hidden = false;
			}
		}

		if (update_url) {
			this.updateUrl();
		}
	}

	setTab(tab, update_url = true) {
		this.tab = ['find', 'templates'].includes(tab) ? tab : 'grid';

		for (const button of this.root.querySelectorAll('.mm-tab')) {
			const active = button.dataset.tab === this.tab;
			button.classList.toggle('mm-tab-active', active);
			button.setAttribute('aria-selected', active ? 'true' : 'false');
		}

		this.grid_panel.hidden = this.tab !== 'grid';
		this.find_panel.hidden = this.tab !== 'find';
		this.templates_panel.hidden = this.tab !== 'templates';

		for (const node of this.form.querySelectorAll('.js-mm-grid-only')) {
			node.hidden = this.tab !== 'grid';
		}

		this.applyRowsChoice(false);

		if (update_url) {
			this.updateUrl();
		}

		if (update_url && this.tab === 'templates' && this.tpl_data === null) {
			this.loadTemplatesTab();
		}

		// Load the other view on first switch, with the same pattern.
		if (update_url && this.filterValues().pattern !== '') {
			if (this.tab === 'find' && this.find === null) {
				this.loadFind();
			}
			else if (this.tab === 'grid' && this.grid === null) {
				this.loadGrid();
			}
		}
	}

	/* ------------------------------------------------------------------ loading */

	async load() {
		if (this.staged.size > 0 && this.loaded_pattern !== null
				&& !confirm(`Reloading keeps your ${this.staged.size} staged change(s), but cells may no longer `
					+ 'show them if the filter changed. Continue?')) {
			return;
		}

		this.updateUrl();
		this.showMessage(null);

		if (this.tab === 'templates') {
			await this.loadTemplatesTab();
		}
		else if (this.tab === 'grid') {
			await this.loadGrid();
			this.find = null;
		}
		else {
			await this.loadFind();
			this.grid = null;
		}
	}

	async loadGrid() {
		const values = this.filterValues();

		if (values.pattern === '') {
			this.showMessage('bad', 'Enter at least one macro name or pattern, e.g. {$SNMP_*}.');

			return;
		}

		this.setBusy(this.grid_panel, 'Loading hosts and macros...');

		try {
			const data = await this.post('resolve', values);

			this.grid = data;
			this.hosts_by_id = new Map(data.hosts.map(h => [h.hostid, h]));
			this.loaded_pattern = values.pattern;
			this.checked.clear();
			this.page = 0;
			this.showWarnings(data.warnings);
			this.renderGrid();
		}
		catch (error) {
			this.showError(error);
			this.renderEmpty(this.grid_panel, 'Nothing loaded.');
		}
	}

	async loadFind() {
		const values = this.filterValues();

		if (values.pattern === '') {
			this.showMessage('bad', 'Enter at least one macro name or pattern, e.g. {$SNMP_*}.');

			return;
		}

		this.setBusy(this.find_panel, 'Searching all hosts, templates and global macros...');

		try {
			const data = await this.post('find', {pattern: values.pattern});

			this.find = data;
			this.loaded_pattern = values.pattern;
			this.showWarnings(data.warnings);
			this.renderFind();
		}
		catch (error) {
			this.showError(error);
			this.renderEmpty(this.find_panel, 'Nothing loaded.');
		}
	}

	setBusy(panel, text) {
		panel.innerHTML = `<div class="mm-empty mm-busy">${this.esc(text)}</div>`;
	}

	async reach(hostmacroids) {
		const missing = hostmacroids.filter(id => !this.reach_cache.has(id));

		if (missing.length > 0) {
			const data = await this.post('reach', {hostmacroids: missing});

			for (const result of data.results) {
				this.reach_cache.set(result.hostmacroid, result);
			}

			if (data.warnings && data.warnings.length) {
				this.showWarnings(data.warnings);
			}
		}

		return hostmacroids.map(id => this.reach_cache.get(id)).filter(Boolean);
	}

	/* ------------------------------------------------------------------ grid model */

	sourceName(def) {
		if (def.level === 'global') {
			return 'Global';
		}

		if (def.level === 'template') {
			return this.grid?.templates[def.oid]?.name ?? def.object_name ?? `Template ${def.oid}`;
		}

		return this.hosts_by_id?.get(def.oid)?.name ?? def.object_name ?? 'Host';
	}

	cellState(host, ci) {
		const column = this.grid.columns[ci];
		const raw = this.grid.cells[host.hostid]?.[ci] ?? null;
		const chain = raw ? raw[2].map(id => this.grid.defs[id]) : [];
		const winner = raw && raw[0] !== null ? this.grid.defs[raw[0]] : null;

		return {
			column,
			chain,
			winner,
			fallback: raw ? raw[1] === 1 : false,
			// The row's own definition of exactly this macro (host-level on a host, template-level on a template).
			host_def: chain.find(d => d.oid === host.hostid && d.macro === column.macro) ?? null,
			staged: this.staged.get(this.hostKey(host.hostid, column.macro)) ?? null,
			source_staged: winner ? this.staged.get('s|' + winner.id) ?? null : null
		};
	}

	hostKey(hostid, macro) {
		return `h|${hostid}|${macro}`;
	}

	visibleHosts() {
		const needle = this.row_filter.toLowerCase();
		const columns = this.grid.columns;

		return this.grid.hosts.filter(host => {
			if (needle !== '' && !host.name.toLowerCase().includes(needle)
					&& !host.host.toLowerCase().includes(needle)) {
				return false;
			}

			switch (this.show) {
				case 'kind-template':
					return host.kind === 'template';

				case 'kind-host':
					return host.kind === 'host';

				case 'overrides':
					return columns.some((c, ci) => this.cellState(host, ci).winner?.oid === host.hostid);

				case 'undefined':
					return columns.some((c, ci) => this.cellState(host, ci).winner === null);

				case 'staged':
					return columns.some(c => this.staged.has(this.hostKey(host.hostid, c.macro)));

				case 'readonly':
					return !host.editable;
			}

			return true;
		});
	}

	/* ------------------------------------------------------------------ grid rendering */

	loadPrefs() {
		const prefs = {layout: 'auto', hidden: [], hide_undefined: true, show_sources: true, only_diff: false};

		try {
			Object.assign(prefs, JSON.parse(localStorage.getItem('macromatrix.prefs') ?? '{}'));
		}
		catch (e) {
			// Private mode or corrupt value: defaults.
		}

		// 1.1 stored "matrix" plus a density; both map to automatic orientation now.
		this.layout = this.LAYOUTS.includes(prefs.layout) ? prefs.layout : 'auto';
		this.hidden_cols = new Set(Array.isArray(prefs.hidden) ? prefs.hidden : []);
		this.hide_undefined = prefs.hide_undefined !== false;
		this.show_sources = prefs.show_sources !== false;
		this.only_diff = prefs.only_diff === true;
		this.collapsed_groups = new Set();
		this.macro_filter = '';
	}

	savePrefs() {
		try {
			localStorage.setItem('macromatrix.prefs', JSON.stringify({
				layout: this.layout,
				hidden: [...this.hidden_cols],
				hide_undefined: this.hide_undefined,
				show_sources: this.show_sources,
				only_diff: this.only_diff
			}));
		}
		catch (e) {
			// Not fatal.
		}
	}

	/**
	 * "auto" puts macros down the side while few hosts or templates are loaded, and hosts down the side beyond that.
	 * Based on what was loaded, not on the row filter, so typing in the filter never flips the table.
	 */
	effectiveLayout() {
		if (this.layout !== 'auto') {
			return this.layout;
		}

		return this.grid.hosts.length <= this.AUTO_MACROS_MAX ? 'macros' : 'hosts';
	}

	shownColumns() {
		const needle = this.macro_filter.trim().toLowerCase();
		const shown = [];

		this.grid.columns.forEach((column, ci) => {
			if (this.hidden_cols.has(column.macro)) {
				return;
			}

			if (needle !== '' && !column.macro.toLowerCase().includes(needle)) {
				return;
			}

			shown.push(ci);
		});

		return shown;
	}

	segmented(name, value, options) {
		return `<span class="mm-seg" role="group" data-seg="${name}">`
			+ options.map(([v, label, title]) => `<button type="button" data-value="${v}"
				class="${v === value ? 'mm-seg-on' : ''}" aria-pressed="${v === value}"
				title="${this.esc(title)}">${this.esc(label)}</button>`).join('')
			+ '</span>';
	}

	renderGrid() {
		const data = this.grid;

		if (data.hosts.length === 0) {
			this.renderEmpty(this.grid_panel, 'Nothing matches the host and template filter.');

			return;
		}

		if (data.columns.length === 0) {
			this.renderEmpty(this.grid_panel,
				'No macro matching the pattern is defined on these hosts or templates, the templates they link, '
					+ 'or globally. Type an exact name (no *) to get a column you can set values in.'
			);

			return;
		}

		const has_templates = data.hosts.some(h => h.kind === 'template');
		const has_hosts = data.hosts.some(h => h.kind === 'host');
		const layout = this.effectiveLayout();
		const auto_note = this.layout === 'auto'
			? `Automatic: macros as rows for up to ${this.AUTO_MACROS_MAX} hosts or templates, hosts as rows beyond.`
			: '';

		this.grid_panel.innerHTML = `
			<div class="mm-toolbar">
				<input type="search" class="mm-row-filter" placeholder="Filter hosts and templates"
					value="${this.esc(this.row_filter)}" aria-label="Filter hosts and templates by name">
				<input type="search" class="mm-macro-filter" placeholder="Filter macros"
					value="${this.esc(this.macro_filter)}" aria-label="Filter macros by name">
				<select class="mm-show" aria-label="Which hosts and templates to show">
					<option value="all">All hosts and templates</option>
					${has_templates && has_hosts ? '<option value="kind-template">Templates only</option>' : ''}
					${has_templates && has_hosts ? '<option value="kind-host">Hosts only</option>' : ''}
					<option value="overrides">With a macro set on themselves</option>
					<option value="undefined">With an undefined macro</option>
					<option value="staged">With staged changes</option>
					<option value="readonly">Read-only</option>
				</select>
				<span class="mm-colpick-wrap">
					<button type="button" class="btn-alt mm-colpick-btn" aria-expanded="false"></button>
					<div class="mm-colpick" hidden></div>
				</span>
				<span class="mm-spacer"></span>
				<button type="button" class="btn-alt mm-csv-export" title="Download the visible hosts, templates and macros">Export CSV</button>
				<button type="button" class="btn-alt mm-csv-import" title="Stage values from a CSV file">Import CSV</button>
				<input type="file" class="mm-csv-file" accept=".csv,text/csv" hidden>
			</div>
			<div class="mm-toolbar">
				${this.segmented('layout', this.layout, [
					['auto', 'Auto', auto_note || 'Pick the orientation from how many hosts and templates are loaded'],
					['macros', 'Macros as rows', 'Macros down the side, one column per host or template'],
					['hosts', 'Hosts as rows', 'Hosts and templates down the side, one column per macro'],
					['list', 'List', 'One line per host or template and macro']
				])}
				${this.layout === 'auto'
					? `<span class="mm-muted mm-small">${layout === 'macros' ? 'Macros as rows' : 'Hosts as rows'}</span>`
					: ''}
				${layout === 'macros' ? `
					<label class="mm-inline"><input type="checkbox" class="mm-opt" data-opt="show_sources"
						${this.show_sources ? 'checked' : ''}> Show where values come from</label>
					${data.hosts.length > 1 ? `<label class="mm-inline"><input type="checkbox" class="mm-opt" data-opt="only_diff"
						${this.only_diff ? 'checked' : ''}> Only macros that differ</label>` : ''}
					<button type="button" class="btn-link mm-groups" data-act="expand">Expand all</button>
					<button type="button" class="btn-link mm-groups" data-act="collapse">Collapse all</button>
				` : ''}
				${layout === 'list' ? `<label class="mm-inline"><input type="checkbox" class="mm-opt" data-opt="hide_undefined"
					${this.hide_undefined ? 'checked' : ''}> Hide undefined</label>` : ''}
			</div>
			<div class="mm-bulk"></div>
			<div class="mm-table-wrap"></div>
			<div class="mm-pager"></div>
			<div class="mm-legend">${this.legendHtml(layout)}</div>
		`;

		const panel = this.grid_panel;
		const show = panel.querySelector('.mm-show');

		show.value = this.show;

		if (show.value !== this.show) {
			this.show = 'all';
			show.value = 'all';
		}

		panel.querySelector('.mm-row-filter').addEventListener('input', e => {
			this.row_filter = e.target.value;
			this.page = 0;
			this.renderGridBody();
		});

		panel.querySelector('.mm-macro-filter').addEventListener('input', e => {
			this.macro_filter = e.target.value;
			this.renderGridBody();
		});

		show.addEventListener('change', e => {
			this.show = e.target.value;
			this.page = 0;
			this.renderGridBody();
		});

		panel.querySelector('.mm-seg').addEventListener('click', e => {
			const button = e.target.closest('button[data-value]');

			if (!button || button.dataset.value === this.layout) {
				return;
			}

			this.layout = button.dataset.value;
			this.page = 0;
			this.savePrefs();
			this.updateUrl();
			this.renderGrid();
		});

		for (const box of panel.querySelectorAll('.mm-opt')) {
			box.addEventListener('change', () => {
				this[box.dataset.opt] = box.checked;
				this.page = 0;
				this.savePrefs();
				this.renderGridBody();
			});
		}

		for (const button of panel.querySelectorAll('.mm-groups')) {
			button.addEventListener('click', () => {
				if (button.dataset.act === 'expand') {
					this.collapsed_groups.clear();
				}
				else {
					for (const column of this.grid.columns) {
						this.collapsed_groups.add(this.macroGroup(column));
					}
				}

				this.renderGridBody();
			});
		}

		this.initColumnPicker(panel);

		panel.querySelector('.mm-csv-export').addEventListener('click', () => this.exportCsv());
		panel.querySelector('.mm-csv-import').addEventListener('click', () => panel.querySelector('.mm-csv-file').click());
		panel.querySelector('.mm-csv-file').addEventListener('change', e => {
			const file = e.target.files[0];
			e.target.value = '';

			if (file) {
				this.importCsv(file);
			}
		});

		const wrap = panel.querySelector('.mm-table-wrap');

		wrap.addEventListener('click', e => {
			const checkbox = e.target.closest('input.mm-check');

			if (checkbox) {
				this.onCheck(checkbox);

				return;
			}

			const toggle = e.target.closest('.mm-group-toggle');

			if (toggle) {
				const group = toggle.dataset.group;
				this.collapsed_groups.has(group) ? this.collapsed_groups.delete(group) : this.collapsed_groups.add(group);
				this.renderGridBody();

				return;
			}

			if (e.target.closest('a')) {
				return;
			}

			const cell = e.target.closest('td.mm-cell');

			if (cell) {
				this.openCellDialog(cell.dataset.hostid, Number(cell.dataset.ci));
			}
		});

		wrap.addEventListener('keydown', e => {
			const cell = e.target.closest('td.mm-cell');

			if (cell && (e.key === 'Enter' || e.key === ' ')) {
				e.preventDefault();
				this.openCellDialog(cell.dataset.hostid, Number(cell.dataset.ci));
			}
		});

		this.renderGridBody();
	}

	legendHtml(layout) {
		if (layout === 'macros') {
			return '<span class="mm-pill-own">set here</span> the macro is set on that host or template itself. '
				+ 'Grey text says where an inherited value comes from. '
				+ '<span class="mm-legend-diff">Tinted</span> values differ from the most common value in their row. '
				+ '<span class="mm-undef">&ndash;</span> undefined. Hover a value for the full lookup; click it to change it.';
		}

		return '<span class="mm-badge mm-src-host">Host</span> set on the host or template itself '
			+ '<span class="mm-badge mm-src-template">Template</span> inherited '
			+ '<span class="mm-badge mm-src-global">Global</span> global macro '
			+ '<span class="mm-undef">&ndash;</span> undefined. Hover a value for the full lookup; click it to change it.';
	}

	initColumnPicker(panel) {
		const button = panel.querySelector('.mm-colpick-btn');
		const menu = panel.querySelector('.mm-colpick');

		const label = () => {
			const shown = this.grid.columns.filter(c => !this.hidden_cols.has(c.macro)).length;
			const total = this.grid.columns.length;

			button.textContent = shown === total ? `Macros (${total})` : `Macros (${shown} of ${total})`;
		};

		const build = () => {
			menu.innerHTML = `
				<div class="mm-colpick-actions">
					<button type="button" class="btn-link" data-act="all">Show all</button>
					<button type="button" class="btn-link" data-act="none">Hide all</button>
					<button type="button" class="btn-link" data-act="empty"
						title="Hide macros that are undefined on every row loaded">Hide unused</button>
				</div>
				<div class="mm-colpick-list">
					${this.grid.columns.map(c => `<label><input type="checkbox" value="${this.esc(c.macro)}"
						${this.hidden_cols.has(c.macro) ? '' : 'checked'}> <span class="mm-macro">${this.esc(c.macro)}</span>
						</label>`).join('')}
				</div>
			`;
		};

		const apply = () => {
			label();
			this.savePrefs();
			this.renderGridBody();
		};

		button.addEventListener('click', () => {
			const open = menu.hidden;

			if (open) {
				build();
			}

			menu.hidden = !open;
			button.setAttribute('aria-expanded', open ? 'true' : 'false');
		});

		menu.addEventListener('change', e => {
			if (e.target.type === 'checkbox') {
				e.target.checked ? this.hidden_cols.delete(e.target.value) : this.hidden_cols.add(e.target.value);
				apply();
			}
		});

		menu.addEventListener('click', e => {
			const act = e.target.closest('button[data-act]')?.dataset.act;

			if (!act) {
				return;
			}

			this.grid.columns.forEach((column, ci) => {
				if (act === 'all') {
					this.hidden_cols.delete(column.macro);
				}
				else if (act === 'none') {
					this.hidden_cols.add(column.macro);
				}
				else if (act === 'empty' && this.grid.hosts.every(h => this.cellState(h, ci).winner === null)) {
					this.hidden_cols.add(column.macro);
				}
			});

			build();
			apply();
		});

		document.addEventListener('click', e => {
			if (!menu.hidden && !e.target.closest('.mm-colpick-wrap')) {
				menu.hidden = true;
				button.setAttribute('aria-expanded', 'false');
			}
		});

		label();
	}

	renderGridBody() {
		const wrap = this.grid ? this.grid_panel.querySelector('.mm-table-wrap') : null;

		if (!wrap) {
			return;
		}

		switch (this.effectiveLayout()) {
			case 'macros':
				this.renderTransposedBody(wrap);
				break;

			case 'list':
				this.renderListBody(wrap);
				break;

			default:
				this.renderMatrixBody(wrap);
		}

		this.renderBulkBar();
	}

	rowHeaderHtml(row) {
		const url = row.kind === 'template' ? this.templateUrl(row.hostid) : this.hostUrl(row.hostid);

		return `<a href="${url}" class="mm-host-link" title="${this.esc(row.name)}">${this.esc(row.name)}</a>`
			+ (row.kind === 'template' ? ' <span class="mm-kind">template</span>' : '')
			+ (row.kind === 'host' && row.name !== row.host
				? `<div class="mm-muted mm-small mm-ellipsis" title="${this.esc(row.host)}">${this.esc(row.host)}</div>`
				: '')
			+ (!row.editable ? ' <span class="mm-kind" title="You cannot change macros on this row">read-only</span>' : '')
			+ (row.status === 1 ? ' <span class="mm-kind">disabled</span>' : '');
	}

	checkboxHtml(row) {
		return `<input type="checkbox" class="mm-check" data-hostid="${row.hostid}"
			aria-label="Select ${this.esc(row.name)}" ${this.checked.has(row.hostid) ? 'checked' : ''}>`;
	}

	headerCheckboxHtml(rowids) {
		const all = rowids.length > 0 && rowids.every(id => this.checked.has(id));

		return `<input type="checkbox" class="mm-check mm-check-page" aria-label="Select all rows on this page"
			${all ? 'checked' : ''}>`;
	}

	renderMatrixBody(wrap) {
		const rows = this.visibleHosts();
		const pages = Math.max(1, Math.ceil(rows.length / this.PAGE_SIZE));

		this.page = Math.min(this.page, pages - 1);

		const page_rows = rows.slice(this.page * this.PAGE_SIZE, (this.page + 1) * this.PAGE_SIZE);
		const shown = this.shownColumns();

		this.page_rowids = page_rows.map(r => r.hostid);

		if (shown.length === 0) {
			wrap.innerHTML = '<div class="mm-empty">No macros to show. Clear the macro filter or pick some under Macros.</div>';
			this.renderPager(rows.length, pages, 'host(s) and template(s)');

			return;
		}

		let html = '<table class="list-table mm-grid"><thead><tr>'
			+ `<th class="mm-sticky mm-col-check">${this.headerCheckboxHtml(this.page_rowids)}</th>`
			+ '<th class="mm-sticky mm-col-host">Host or template</th>';

		for (const ci of shown) {
			const column = this.grid.columns[ci];

			html += `<th class="mm-col-macro" title="${this.esc(this.columnTitle(column))}">`
				+ `<span class="mm-macro">${this.macroHtml(column.macro)}</span>`
				+ (column.mode === 'context' ? '<span class="mm-colmode">context</span>' : '')
				+ (column.mode === 'literal' ? '<span class="mm-colmode">regex</span>' : '')
				+ '</th>';
		}

		html += '</tr></thead><tbody>';

		if (page_rows.length === 0) {
			html += `<tr><td colspan="${shown.length + 2}" class="mm-empty">Nothing matches the filter.</td></tr>`;
		}

		for (const row of page_rows) {
			html += `<tr class="${row.editable ? '' : 'mm-readonly'} ${row.kind === 'template' ? 'mm-row-template' : ''}">`
				+ `<td class="mm-sticky mm-col-check">${this.checkboxHtml(row)}</td>`
				+ `<td class="mm-sticky mm-col-host">${this.rowHeaderHtml(row)}</td>`;

			for (const ci of shown) {
				html += this.cellHtml(row, ci);
			}

			html += '</tr>';
		}

		html += '</tbody></table>';
		wrap.innerHTML = html;

		// The name column sticks right of the checkbox column, whose width depends on the theme's cell padding.
		const check_th = wrap.querySelector('th.mm-col-check');

		if (check_th) {
			wrap.style.setProperty('--mm-check-w', check_th.offsetWidth + 'px');
		}

		this.renderPager(rows.length, pages, 'host(s) and template(s)');
	}

	/**
	 * Macro text with line-break opportunities after dots, underscores and colons, so long names wrap at word
	 * boundaries instead of between arbitrary characters.
	 */
	macroHtml(macro) {
		return this.esc(macro).replace(/([._:])/g, '$1<wbr>');
	}

	/**
	 * Group for the macros-as-rows view: the name up to the first dot or underscore ({$NET.IF.X} -> NET,
	 * {$ICMP_LOSS_WARN} -> ICMP).
	 */
	macroGroup(column) {
		return column.name.split(/[._]/)[0] || column.name;
	}

	/**
	 * What a cell shows, reduced to a comparable key: staged changes count, secrets compare by definition.
	 */
	cellKey(row, ci) {
		const state = this.cellState(row, ci);

		if (state.staged) {
			if (state.staged.kind === 'revert') {
				return 'revert';
			}

			return state.staged.value === null
				? 'secret-kept:' + row.hostid
				: `${state.staged.type}:${state.staged.value}`;
		}

		if (state.winner === null) {
			return 'undefined';
		}

		const source = state.source_staged?.kind === 'source' ? state.source_staged : state.winner;

		if (source.type === this.TYPE_SECRET) {
			return 'secret:' + state.winner.id + (state.source_staged ? ':staged' : '');
		}

		return `${source.type}:${source.value}`;
	}

	/**
	 * For one macro across the given hosts/templates: whether values differ, and which cells differ from the most
	 * common value (ties go to the leftmost column, so templates, listed first, act as the baseline).
	 */
	diffInfo(objects, ci) {
		const keys = objects.map(o => this.cellKey(o, ci));
		const counts = new Map();

		for (const key of keys) {
			counts.set(key, (counts.get(key) ?? 0) + 1);
		}

		let majority = keys[0];

		for (const key of keys) {
			if (counts.get(key) > counts.get(majority)) {
				majority = key;
			}
		}

		const odd = new Set();

		objects.forEach((o, i) => {
			if (keys[i] !== majority) {
				odd.add(o.hostid);
			}
		});

		return {differs: counts.size > 1, odd};
	}

	renderTransposedBody(wrap) {
		const objects = this.visibleHosts();
		const size = this.TRANSPOSED_PAGE;
		const pages = Math.max(1, Math.ceil(objects.length / size));

		this.page = Math.min(this.page, pages - 1);

		const cols = objects.slice(this.page * size, (this.page + 1) * size);
		const shown = this.shownColumns();
		const compare = cols.length > 1;

		this.page_rowids = cols.map(o => o.hostid);

		if (cols.length === 0) {
			wrap.innerHTML = '<div class="mm-empty">No hosts or templates match the filter.</div>';
			this.renderPager(0, 1, 'host(s) and template(s)', size);

			return;
		}

		if (shown.length === 0) {
			wrap.innerHTML = '<div class="mm-empty">No macros to show. Clear the macro filter or pick some under Macros.</div>';
			this.renderPager(objects.length, pages, 'host(s) and template(s)', size);

			return;
		}

		const groups = new Map();

		for (const ci of shown) {
			const group = this.macroGroup(this.grid.columns[ci]);

			if (!groups.has(group)) {
				groups.set(group, []);
			}

			groups.get(group).push(ci);
		}

		let html = '<table class="list-table mm-trans"><colgroup><col class="mm-trans-namecol">'
			+ cols.map(() => '<col>').join('')
			+ '</colgroup><thead><tr>'
			+ `<th class="mm-trans-corner">${compare ? this.headerCheckboxHtml(this.page_rowids) : ''} Macro</th>`;

		for (const o of cols) {
			html += `<th class="mm-trans-obj ${o.kind === 'template' ? 'mm-row-template' : ''}">`
				+ `${this.checkboxHtml(o)} ${this.rowHeaderHtml(o)}`
				+ `<div class="mm-muted mm-small">${o.kind}</div></th>`;
		}

		html += '</tr></thead><tbody>';

		let rows_shown = 0;
		let differing = 0;

		for (const [group, cis] of groups) {
			const rows = [];

			for (const ci of cis) {
				const diff = compare ? this.diffInfo(cols, ci) : null;

				if (diff?.differs) {
					differing++;
				}

				if (this.only_diff && compare && !diff.differs) {
					continue;
				}

				rows.push({ci, diff});
			}

			if (rows.length === 0) {
				continue;
			}

			const collapsed = this.collapsed_groups.has(group);
			const group_diff = rows.filter(r => r.diff?.differs).length;

			html += `<tr class="mm-group"><td colspan="${cols.length + 1}">`
				+ `<button type="button" class="btn-link mm-group-toggle" data-group="${this.esc(group)}"
					aria-expanded="${collapsed ? 'false' : 'true'}">`
				+ `<span class="mm-chevron">${collapsed ? '&#9656;' : '&#9662;'}</span> ${this.esc(group)}</button>`
				+ ` <span class="mm-muted mm-small">${rows.length}</span>`
				+ (group_diff > 0 ? ` <span class="mm-legend-diff mm-small">${group_diff} differ</span>` : '')
				+ '</td></tr>';

			if (collapsed) {
				continue;
			}

			for (const {ci, diff} of rows) {
				const column = this.grid.columns[ci];
				rows_shown++;

				html += `<tr><td class="mm-trans-name" title="${this.esc(this.columnTitle(column))}">`
					+ `<span class="mm-macro">${this.macroHtml(column.macro)}</span>`
					+ (column.mode === 'context' ? ' <span class="mm-colmode">context</span>' : '')
					+ (column.mode === 'literal' ? ' <span class="mm-colmode">regex, where defined</span>' : '')
					+ '</td>'
					+ cols.map(o => this.transposedCellHtml(o, ci, diff)).join('')
					+ '</tr>';
			}
		}

		if (rows_shown === 0 && ![...groups.keys()].some(g => this.collapsed_groups.has(g))) {
			html += `<tr><td colspan="${cols.length + 1}" class="mm-empty">`
				+ (this.only_diff ? 'Every macro has the same value on all columns shown.' : 'No macros to show.')
				+ '</td></tr>';
		}

		html += '</tbody></table>';
		wrap.innerHTML = html;

		this.renderPager(objects.length, pages, 'host(s) and template(s)', size,
			compare ? `${differing} of ${shown.length} macro(s) differ across the columns shown` : ''
		);
	}

	transposedCellHtml(row, ci, diff) {
		const state = this.cellState(row, ci);
		const classes = ['mm-cell', 'mm-tcell'];
		let title = this.chainText(row, state);
		let value = '';
		let note = '';

		if (diff?.odd.has(row.hostid)) {
			classes.push('mm-differs');
		}

		if (state.staged) {
			classes.push('mm-staged');
			title = 'Staged change, not applied yet.\n\n' + title;

			if (state.staged.kind === 'revert') {
				value = '<span class="mm-muted">removed, will inherit</span>';
			}
			else {
				value = state.staged.value === null
					? '<span class="mm-secret">******</span>'
					: this.valueHtml(state.staged.type, state.staged.value);
				note = '<span class="mm-pill-own">set here</span>';
			}

			note += ' <span class="mm-badge mm-pending">staged</span>';
		}
		else if (state.winner === null) {
			classes.push('mm-is-undef');
			value = '<span class="mm-undef">&ndash;</span>';
			note = this.show_sources ? '<span class="mm-muted mm-small">undefined</span>' : '';
		}
		else {
			const def = state.winner;
			const own = def.oid === row.hostid;
			const source = state.source_staged?.kind === 'source' ? state.source_staged : def;
			const extras = [];

			value = source.value === null && source.type === this.TYPE_SECRET
				? '<span class="mm-secret">******</span>'
				: this.valueHtml(source.type, source.value);

			if (def.type === this.TYPE_SECRET) {
				extras.push('secret');
			}
			else if (def.type === this.TYPE_VAULT) {
				extras.push('vault');
			}

			if (def.automatic) {
				extras.push('discovered');
			}

			if (state.fallback) {
				extras.push('no macro for this context, plain {$' + def.name + '} used');
			}

			if (own) {
				note = '<span class="mm-pill-own">set here</span>'
					+ (extras.length && this.show_sources ? ` <span class="mm-muted mm-small">${this.esc(extras.join(', '))}</span>` : '');
			}
			else if (this.show_sources) {
				const from = def.level === 'global' ? 'global' : this.sourceName(def);
				note = `<span class="mm-muted mm-small">${this.esc([from, ...extras].join(', '))}</span>`;
			}

			if (state.source_staged?.kind === 'source') {
				classes.push('mm-staged-src');
				note += ' <span class="mm-badge mm-pending">source staged</span>';
				title = 'The source of this value has a staged change.\n\n' + title;
			}
		}

		return `<td class="${classes.join(' ')}" tabindex="0" data-hostid="${row.hostid}" data-ci="${ci}"
			title="${this.esc(title)}"><div class="mm-tval">${value}</div>${note ? `<div class="mm-tnote">${note}</div>` : ''}</td>`;
	}

	renderListBody(wrap) {
		const shown = this.shownColumns();
		const pairs = [];

		for (const row of this.visibleHosts()) {
			for (const ci of shown) {
				if (this.hide_undefined) {
					const state = this.cellState(row, ci);

					if (state.winner === null && !state.staged) {
						continue;
					}
				}

				pairs.push([row, ci]);
			}
		}

		const size = this.PAGE_SIZE * 2;
		const pages = Math.max(1, Math.ceil(pairs.length / size));

		this.page = Math.min(this.page, pages - 1);

		const page_pairs = pairs.slice(this.page * size, (this.page + 1) * size);

		this.page_rowids = [...new Set(page_pairs.map(([row]) => row.hostid))];

		let html = '<table class="list-table mm-list"><thead><tr>'
			+ `<th class="mm-col-check">${this.headerCheckboxHtml(this.page_rowids)}</th>`
			+ '<th>Host / template</th><th>Macro</th><th>Value and source</th></tr></thead><tbody>';

		if (page_pairs.length === 0) {
			html += '<tr><td colspan="4" class="mm-empty">No values match.'
				+ (this.hide_undefined ? ' Untick "Hide undefined" to see macros that are not set anywhere.' : '')
				+ '</td></tr>';
		}

		let previous = null;

		for (const [row, ci] of page_pairs) {
			const first = previous !== row.hostid;
			previous = row.hostid;

			html += `<tr class="${first ? 'mm-list-first' : ''} ${row.editable ? '' : 'mm-readonly'}">`
				+ `<td class="mm-col-check">${first ? this.checkboxHtml(row) : ''}</td>`
				+ `<td class="mm-list-row">${first ? this.rowHeaderHtml(row) : ''}</td>`
				+ `<td class="mm-macro">${this.esc(this.grid.columns[ci].macro)}</td>`
				+ this.cellHtml(row, ci)
				+ '</tr>';
		}

		html += '</tbody></table>';
		wrap.innerHTML = html;

		this.renderPager(pairs.length, pages, 'value(s)', size);
	}

	columnTitle(column) {
		switch (column.mode) {
			case 'context':
				return `${column.macro}: resolved for context "${column.context}", the way an item using `
					+ `this macro would see it (exact context, then regex contexts, then ${'{$' + column.name + '}'}).`;

			case 'literal':
				return `${column.macro}: shows where this regex-context macro is defined. `
					+ 'It is not resolved, since that depends on the context an item passes.';
		}

		return `${column.macro}: resolved without context.`;
	}

	cellHtml(host, ci) {
		const state = this.cellState(host, ci);
		const classes = ['mm-cell'];
		let body = '';
		let title = this.chainText(host, state);

		if (state.staged) {
			classes.push('mm-staged');

			if (state.staged.kind === 'revert') {
				body = '<span class="mm-muted">remove, inherit instead</span> <span class="mm-badge mm-pending">staged</span>';
			}
			else {
				body = (state.staged.value === null
						? '<span class="mm-secret">******</span>'
						: this.valueHtml(state.staged.type, state.staged.value))
					+ ` <span class="mm-badge mm-src-host">${host.kind === 'template' ? 'this template' : 'Host'}</span>`
					+ ' <span class="mm-badge mm-pending">staged</span>';
			}

			title = 'Staged change, not applied yet.\n\n' + title;
		}
		else if (state.winner === null) {
			classes.push('mm-is-undef');
			body = '<span class="mm-undef">&ndash;</span> <span class="mm-muted mm-small">undefined</span>';
		}
		else {
			const def = state.winner;
			const source_staged = state.source_staged;
			let value_html = this.valueHtml(def.type, def.value);

			if (source_staged && source_staged.kind === 'source') {
				classes.push('mm-staged-src');
				value_html = source_staged.value === null
					? '<span class="mm-secret">******</span>'
					: this.valueHtml(source_staged.type, source_staged.value);
				value_html += ' <span class="mm-badge mm-pending">staged</span>';
				title = 'The source of this value has a staged change.\n\n' + title;
			}

			body = `<span class="mm-value">${value_html}</span> ` + this.sourceBadge(def, host)
				+ (state.fallback ? ' <span class="mm-badge mm-flag" title="No macro for this context; {$NAME} used">no context</span>' : '')
				+ (def.type === this.TYPE_VAULT ? ' <span class="mm-badge mm-flag">vault</span>' : '')
				+ (def.automatic ? ' <span class="mm-badge mm-flag" title="Written by discovery">LLD</span>' : '');
		}

		return `<td class="${classes.join(' ')}" tabindex="0" data-hostid="${host.hostid}" data-ci="${ci}"
			title="${this.esc(title)}">${body}</td>`;
	}

	sourceBadge(def, row = null) {
		if (row !== null && def.oid === row.hostid) {
			return `<span class="mm-badge mm-src-host">${row.kind === 'template' ? 'this template' : 'Host'}</span>`;
		}

		if (def.level === 'host') {
			return '<span class="mm-badge mm-src-host">Host</span>';
		}

		if (def.level === 'global') {
			return '<span class="mm-badge mm-src-global">Global</span>';
		}

		return `<span class="mm-badge mm-src-template">${this.esc(this.sourceName(def))}</span>`;
	}

	chainText(host, state) {
		const lines = [`${state.column.macro} on ${host.kind === 'template' ? 'template ' : ''}${host.name}`];

		if (state.chain.length === 0) {
			lines.push(host.kind === 'template'
				? 'Not defined on this template, the templates it links or globally.'
				: 'Not defined on the host, its templates or globally.');

			return lines.join('\n');
		}

		lines.push('Where it is defined, in lookup order (first match wins):');

		for (const def of state.chain) {
			const where = def.oid === host.hostid
				? (host.kind === 'template' ? 'This template' : 'Host')
				: def.level === 'global' ? 'Global' : this.sourceName(def);
			const mark = state.winner && def.id === state.winner.id ? '   <- used' : '';

			lines.push(`  ${where}: ${def.macro} = ${this.displayValue(def.type, def.value)}${mark}`);
		}

		if (state.fallback) {
			lines.push('No macro exists for this context, so the plain {$NAME} value applies.');
		}

		return lines.join('\n');
	}

	renderPager(total, pages, noun, size = this.PAGE_SIZE, extra = '') {
		const pager = this.grid_panel.querySelector('.mm-pager');
		const from = total === 0 ? 0 : this.page * size + 1;
		const to = Math.min(total, (this.page + 1) * size);

		pager.innerHTML = `
			${extra ? `<span class="mm-muted">${this.esc(extra)}</span><span class="mm-spacer"></span>` : ''}
			<span>${from}-${to} of ${total} ${noun}</span>
			${pages > 1 ? `
				<button type="button" class="btn-alt mm-prev" ${this.page === 0 ? 'disabled' : ''}>Previous</button>
				<span>Page ${this.page + 1} of ${pages}</span>
				<button type="button" class="btn-alt mm-next" ${this.page >= pages - 1 ? 'disabled' : ''}>Next</button>
			` : ''}
		`;

		pager.querySelector('.mm-prev')?.addEventListener('click', () => {
			this.page--;
			this.renderGridBody();
		});

		pager.querySelector('.mm-next')?.addEventListener('click', () => {
			this.page++;
			this.renderGridBody();
		});
	}

	onCheck(checkbox) {
		if (checkbox.classList.contains('mm-check-page')) {
			for (const id of this.page_rowids ?? []) {
				checkbox.checked ? this.checked.add(id) : this.checked.delete(id);
			}

			this.renderGridBody();

			return;
		}

		const hostid = checkbox.dataset.hostid;
		checkbox.checked ? this.checked.add(hostid) : this.checked.delete(hostid);
		this.renderBulkBar();
	}

	renderBulkBar() {
		const bar = this.grid_panel.querySelector('.mm-bulk');

		if (!bar) {
			return;
		}

		const visible = this.visibleHosts();
		const count = this.checked.size;

		if (count === 0) {
			bar.innerHTML = `<span class="mm-muted">Tick rows to change many at once${visible.length > 0
				? `, or <button type="button" class="btn-link mm-select-all">select all ${visible.length} shown</button>`
				: ''}.</span>`;
		}
		else {
			const options = this.grid.columns
				.map((c, ci) => `<option value="${ci}">${this.esc(c.macro)}</option>`)
				.join('');

			bar.innerHTML = `
				<strong>${count} selected</strong>
				<label class="mm-inline">Macro <select class="mm-bulk-col">${options}</select></label>
				<button type="button" class="btn-alt mm-bulk-set" title="Write the same value on every selected row">Set value...</button>
				<button type="button" class="btn-alt mm-bulk-pin"
					title="Copy each row's current value onto the row itself, keeping its type">Pin current value</button>
				<button type="button" class="btn-alt mm-bulk-revert"
					title="Remove the macro from the selected rows so they inherit it instead">Remove from rows</button>
				<button type="button" class="btn-link mm-bulk-clear">Clear selection</button>
				${visible.length > count
					? `<button type="button" class="btn-link mm-select-all">Select all ${visible.length} shown</button>`
					: ''}
			`;

			if (this.bulk_ci !== undefined && this.bulk_ci < this.grid.columns.length) {
				bar.querySelector('.mm-bulk-col').value = String(this.bulk_ci);
			}

			const col = () => {
				this.bulk_ci = Number(bar.querySelector('.mm-bulk-col').value);

				return this.bulk_ci;
			};

			bar.querySelector('.mm-bulk-col').addEventListener('change', col);
			bar.querySelector('.mm-bulk-set').addEventListener('click', () => this.bulkSet(col()));
			bar.querySelector('.mm-bulk-pin').addEventListener('click', () => this.bulkPin(col()));
			bar.querySelector('.mm-bulk-revert').addEventListener('click', () => this.bulkRevert(col()));
			bar.querySelector('.mm-bulk-clear').addEventListener('click', () => {
				this.checked.clear();
				this.renderGridBody();
			});
		}

		bar.querySelector('.mm-select-all')?.addEventListener('click', () => {
			for (const host of visible) {
				this.checked.add(host.hostid);
			}

			this.renderGridBody();
		});
	}

	/* ------------------------------------------------------------------ lookup and reach diagrams */

	/**
	 * Lookup order for a loaded host or template, mirroring MacroResolver::getLookupOrder(): levels of linked
	 * templates, each sorted by numeric ID, objects never visited twice. Global is not included.
	 */
	lookupLevels(rowid) {
		const parentsOf = id => this.hosts_by_id.get(id)?.parents ?? this.grid.templates[id]?.parents ?? [];
		const cmp = (a, b) => (a.length - b.length) || (a < b ? -1 : a > b ? 1 : 0);
		const visited = new Set([rowid]);
		const levels = [];
		let current = [rowid];

		while (current.length > 0) {
			const next = [];

			for (const id of current) {
				for (const parent of parentsOf(id)) {
					const pid = String(parent);

					if (!visited.has(pid)) {
						visited.add(pid);
						next.push(pid);
					}
				}
			}

			if (next.length > 0) {
				next.sort(cmp);
				levels.push(next);
			}

			current = next;
		}

		return levels;
	}

	objectName(oid) {
		if (oid === 'global') {
			return 'Global macros';
		}

		return this.hosts_by_id?.get(oid)?.name ?? this.grid?.templates[oid]?.name ?? `Template ${oid} (no access)`;
	}

	/**
	 * Which definition would win if one definition were gone: used to say what a removal leads to. Works on the
	 * candidate chain, which is already in server lookup order.
	 */
	resolveWithout(state, removed_id) {
		const chain = state.chain.filter(d => d.id !== removed_id);

		if (state.column.mode !== 'context') {
			return chain[0] ?? null;
		}

		return chain.find(d => d.context !== null || d.regex !== null) ?? chain.find(d => d.context === null && d.regex === null)
			?? null;
	}

	defKindLabel(def) {
		if (def.regex !== null && def.regex !== undefined) {
			return 'regex context';
		}

		return def.context !== null && def.context !== undefined ? 'context' : 'plain';
	}

	/**
	 * The lookup path for one value, drawn top to bottom: the row itself, each template level, global.
	 */
	renderLookupFlow(row, state) {
		const column = state.column;
		const winner = state.winner;
		const levels = [[row.hostid], ...this.lookupLevels(row.hostid), ['global']];
		const by_oid = new Map();
		const order = new Map(state.chain.map((d, i) => [d.id, i]));
		const winner_pos = winner ? order.get(winner.id) : Infinity;

		for (const def of state.chain) {
			if (!by_oid.has(def.oid)) {
				by_oid.set(def.oid, []);
			}

			by_oid.get(def.oid).push(def);
		}

		const node = this.el('<div class="mm-flow"></div>');

		if (column.mode === 'context') {
			node.append(this.el(`<p class="mm-flow-note">Looks for <span class="mm-macro">${this.esc(column.macro)}</span>
				first (exact context, then regex contexts) at every level, global included. Only if none matches does the
				first plain <span class="mm-macro">{$${this.esc(column.name)}}</span> apply.</p>`));
		}
		else if (column.mode === 'literal') {
			node.append(this.el(`<p class="mm-flow-note">Shows where this exact regex-context macro is defined. Which
				value an item gets depends on the context the item passes.</p>`));
		}

		let reached_winner = false;

		levels.forEach((level, li) => {
			const label = li === 0 ? (row.kind === 'template' ? 'This template' : 'Host')
				: level[0] === 'global' ? 'Global' : `Level ${li}`;
			const with_defs = level.filter(oid => by_oid.has(oid));
			const without = level.filter(oid => !by_oid.has(oid));
			const shown_without = with_defs.length > 0 ? without.slice(0, 2) : without.slice(0, 4);
			const hidden = without.length - shown_without.length;

			const row_el = this.el(`<div class="mm-flow-level"><div class="mm-flow-label">${label}</div>
				<div class="mm-flow-boxes"></div></div>`);
			const boxes = row_el.querySelector('.mm-flow-boxes');

			for (const oid of [...with_defs, ...shown_without]) {
				boxes.append(this.flowBox(oid, li, level, levels, by_oid.get(oid) ?? [], winner, order, winner_pos));
			}

			if (hidden > 0) {
				const more = this.el(`<button type="button" class="btn-link mm-flow-more">${hidden} more without it</button>`);

				more.addEventListener('click', () => {
					for (const oid of without.slice(shown_without.length)) {
						boxes.insertBefore(this.flowBox(oid, li, level, levels, [], winner, order, winner_pos), more);
					}

					more.remove();
				});

				boxes.append(more);
			}

			node.append(row_el);

			const here = winner && level.includes(winner.oid);

			if (li < levels.length - 1) {
				node.append(this.el(`<div class="mm-flow-arrow"><span aria-hidden="true">&darr;</span>${here && !reached_winner
					? ' <span class="mm-small">first match wins; lower levels only matter if this one goes away</span>'
					: ''}</div>`));
			}

			reached_winner = reached_winner || here;
		});

		if (!winner) {
			node.append(this.el('<p class="mm-flow-note mm-undef">Not defined anywhere on this path, so items using it get the macro text unresolved.</p>'));
		}
		else if (state.fallback) {
			node.append(this.el(`<p class="mm-flow-note">No macro for this context exists on the path, so the first plain
				value is used.</p>`));
		}

		return node;
	}

	flowBox(oid, li, level, levels, defs, winner, order, winner_pos) {
		const is_global = oid === 'global';
		const name = is_global ? 'Global macros' : this.objectName(oid);
		let linked_by = '';

		// Level 2 and below: say which template brought this one in.
		if (li >= 2 && !is_global) {
			const parent = levels[li - 1].find(p => (this.grid.templates[p]?.parents ?? []).map(String).includes(oid));

			if (parent) {
				linked_by = `<div class="mm-small mm-muted">linked by ${this.esc(this.objectName(parent))}</div>`;
			}
		}

		if (defs.length === 0) {
			return this.el(`<div class="mm-flow-box mm-flow-empty"><div>${this.esc(name)}</div>${linked_by}
				<div class="mm-small">not defined here</div></div>`);
		}

		const used = winner && defs.some(d => d.id === winner.id);
		const box = this.el(`<div class="mm-flow-box ${used ? 'mm-flow-used' : 'mm-flow-other'}">
			<div class="mm-flow-name">${this.esc(name)}</div>${linked_by}</div>`);

		for (const def of defs) {
			const pos = order.get(def.id);
			let status;

			if (winner && def.id === winner.id) {
				status = 'used';
			}
			else if (pos > winner_pos) {
				status = 'shadowed: an earlier match wins';
			}
			else if (def.context === null && def.regex === null && winner && (winner.context !== null || winner.regex !== null)) {
				status = 'plain value, passed over: a context match exists further down';
			}
			else {
				status = 'not used';
			}

			const value = def.type === this.TYPE_SECRET ? '******' : (def.value === '' ? '(empty)' : def.value);

			box.append(this.el(`<div class="mm-flow-def ${status === 'used' ? '' : 'mm-flow-def-off'}">
				<span class="mm-macro">${this.esc(def.macro)}</span> = <span class="mm-flow-val">${this.esc(value)}</span>
				<span class="mm-small ${status === 'used' ? 'mm-flow-yes' : 'mm-muted'}">${status === 'used' ? '&#10003; used' : this.esc(status)}</span>
			</div>`));
		}

		return box;
	}

	/**
	 * Where a template macro goes: the template, the templates it reaches hosts through, and the hosts sorted by
	 * what the change does to them.
	 *
	 * @param {object} result  Reach result from the server.
	 * @param {object} change  Optional staged change: {type, value, reverts: Set}.
	 */
	renderReachFlow(result, change = null) {
		const node = this.el('<div class="mm-flow mm-reach-flow"></div>');
		const reverting = change?.reverts ? result.overridden.filter(e => change.reverts.has(e.hostmacroid)).length : 0;
		const change_html = change
			? ` <span class="mm-flow-val">${change.value === null && change.type === this.TYPE_SECRET ? '******'
				: this.esc(change.type === this.TYPE_SECRET ? '******' : change.value)}</span> <span class="mm-muted">(staged)</span>`
			: '';

		node.append(this.el(`<div class="mm-flow-level"><div class="mm-flow-label">Template</div><div class="mm-flow-boxes">
			<div class="mm-flow-box mm-flow-used"><div class="mm-flow-name">${this.esc(result.template_name)}</div>
			<div><span class="mm-macro">${this.esc(result.macro)}</span>${change_html}</div></div></div></div>`));

		if (result.through.length > 0) {
			node.append(this.el('<div class="mm-flow-arrow"><span aria-hidden="true">&darr;</span> <span class="mm-small">directly, and through templates that link it</span></div>'));

			const tier = this.el(`<div class="mm-flow-level"><div class="mm-flow-label">Through</div>
				<div class="mm-flow-boxes"></div></div>`);

			for (const t of result.through.slice(0, 8)) {
				tier.querySelector('.mm-flow-boxes').append(this.el(`<div class="mm-flow-box mm-flow-other">
					<div>${this.esc(t.name)}</div><div class="mm-small mm-muted">${t.hosts} host(s)</div></div>`));
			}

			if (result.through.length > 8) {
				tier.querySelector('.mm-flow-boxes').append(this.el(`<div class="mm-flow-box mm-flow-empty">
					${result.through.length - 8} more templates</div>`));
			}

			node.append(tier);
		}

		node.append(this.el('<div class="mm-flow-arrow"><span aria-hidden="true">&darr;</span></div>'));

		const bucket = (title, css, entries, count, note) => {
			const b = this.el(`<details class="mm-flow-box mm-flow-bucket ${css}">
				<summary><strong>${count}</strong> ${this.esc(title)}${note ? ` <span class="mm-small mm-muted">${this.esc(note)}</span>` : ''}</summary>
				<ul class="mm-small"></ul></details>`);

			for (const e of entries.slice(0, 200)) {
				const via = e.via && e.via !== result.templateid
					? ` <span class="mm-muted">via ${this.esc(this.grid?.templates[e.via]?.name
						?? result.through.find(t => t.templateid === e.via)?.name ?? e.via)}</span>`
					: '';
				const src = e.source ? ` <span class="mm-muted">from ${this.esc(e.source)}</span>` : '';
				const rev = change?.reverts?.has(e.hostmacroid) ? ' <span class="mm-badge mm-pending">will revert</span>' : '';

				b.querySelector('ul').append(this.el(`<li>${this.esc(e.name)}${via}${src}${rev}</li>`));
			}

			if (entries.length > 200 || result.truncated) {
				b.querySelector('ul').append(this.el('<li class="mm-muted">list shortened</li>'));
			}

			if (count === 0) {
				b.removeAttribute('open');
				b.classList.add('mm-flow-empty');
			}

			return b;
		};

		const tier = this.el('<div class="mm-flow-level"><div class="mm-flow-label">Hosts</div><div class="mm-flow-boxes"></div></div>');
		const boxes = tier.querySelector('.mm-flow-boxes');

		boxes.append(
			bucket(change ? 'get the new value' : 'use this value', 'mm-flow-used', result.affected,
				result.affected_count + reverting, reverting ? `incl. ${reverting} reverted override(s)` : ''),
			bucket('keep their own override', 'mm-flow-other', change?.reverts
				? result.overridden.filter(e => !change.reverts.has(e.hostmacroid)) : result.overridden,
				result.overridden.length - reverting, ''),
			bucket('get it from elsewhere', 'mm-flow-empty', result.other, result.other.length, 'not affected')
		);

		node.append(tier);

		return node;
	}

	async openReachDialog(hostmacroid) {
		const body = this.el('<div><p class="mm-muted">Counting the hosts that inherit this template...</p></div>');

		this.openDialog({title: 'Where this template macro applies', body, wide: true, buttons: [{label: 'Close'}]});

		try {
			const [result] = await this.reach([hostmacroid]);

			body.innerHTML = '';
			body.append(result ? this.renderReachFlow(result) : this.el('<p>No hosts inherit this template.</p>'));
		}
		catch (error) {
			body.innerHTML = '';
			body.append(this.el(`<p class="mm-undef">Could not count affected hosts: ${this.esc(error.title ?? '')}</p>`));
		}
	}

	/* ------------------------------------------------------------------ dialogs */

	/**
	 * Minimal modal built on Zabbix's overlay classes, so it follows the active theme. Buttons: {label, primary,
	 * action: async () => boolean (true closes)}.
	 */
	openDialog({title, body, buttons, wide = false}) {
		const bg = this.el('<div class="overlay-bg mm-dialog-bg"></div>');
		const dialog = this.el(`
			<div class="overlay-dialogue modal mm-dialog ${wide ? 'mm-dialog-wide' : ''}" role="dialog" aria-modal="true">
				<div class="overlay-dialogue-header">
					<h4>${this.esc(title)}</h4>
					<button type="button" class="btn-overlay-close" title="Close"></button>
				</div>
				<div class="overlay-dialogue-body mm-dialog-body"></div>
				<div class="overlay-dialogue-footer mm-dialog-footer"></div>
			</div>
		`);

		dialog.querySelector('.mm-dialog-body').append(body);

		const returnFocus = document.activeElement;

		const close = () => {
			bg.remove();
			dialog.remove();
			document.removeEventListener('keydown', onKey, true);

			if (returnFocus && document.contains(returnFocus)) {
				returnFocus.focus();
			}
		};

		const onKey = e => {
			if (e.key === 'Escape') {
				e.stopPropagation();
				close();
			}
		};

		const footer = dialog.querySelector('.mm-dialog-footer');
		const api = {close, dialog, footer, setButtons: null};

		const setButtons = list => {
			footer.innerHTML = '';

			for (const spec of list) {
				const button = this.el(`<button type="button" class="${spec.primary ? '' : 'btn-alt'}">`
					+ `${this.esc(spec.label)}</button>`);

				if (spec.disabled) {
					button.disabled = true;
				}

				button.addEventListener('click', async () => {
					if (!spec.action) {
						close();

						return;
					}

					const all = [...footer.querySelectorAll('button')];
					all.forEach(b => b.disabled = true);
					button.classList.add('is-loading');

					let done = false;

					try {
						done = await spec.action(api);
					}
					finally {
						button.classList.remove('is-loading');

						if (document.contains(button)) {
							all.forEach(b => b.disabled = b.dataset.disabled === '1');
						}
					}

					if (done) {
						close();
					}
				});

				if (spec.disabled) {
					button.dataset.disabled = '1';
				}

				footer.append(button);
			}
		};

		api.setButtons = setButtons;
		setButtons(buttons);

		dialog.querySelector('.btn-overlay-close').addEventListener('click', close);
		document.addEventListener('keydown', onKey, true);
		document.body.append(bg, dialog);

		(dialog.querySelector('.mm-dialog-body input:not([type=hidden]):not([disabled]), .mm-dialog-body select')
			?? dialog.querySelector('.btn-overlay-close')).focus();

		return api;
	}

	dialogError(api, error) {
		const box = api.dialog.querySelector('.mm-dialog-error') ?? this.el('<div class="mm-dialog-error"></div>');

		box.innerHTML = '';
		jQuery(box).append(makeMessageBox('bad', (error.messages ?? []), error.title, false));
		api.dialog.querySelector('.mm-dialog-body').prepend(box);
	}

	/**
	 * Type / value / description fields.
	 *
	 * @param {object}  initial         {type, value, description}; value null = existing secret, unknown.
	 * @param {boolean} secret_keepable Existing secret that may be kept by leaving the value empty.
	 */
	valueFields(initial, secret_keepable) {
		const node = this.el(`
			<div class="mm-fields">
				<label>Type
					<select class="mm-f-type">
						<option value="0">Text</option>
						<option value="1">Secret text</option>
						<option value="2">Vault secret</option>
					</select>
				</label>
				<label>Value
					<input type="text" class="mm-f-value" autocomplete="off" spellcheck="false">
				</label>
				<div class="mm-hint mm-f-hint"></div>
				<label>Description
					<textarea class="mm-f-desc" rows="2" spellcheck="false"></textarea>
				</label>
			</div>
		`);

		const type = node.querySelector('.mm-f-type');
		const value = node.querySelector('.mm-f-value');
		const hint = node.querySelector('.mm-f-hint');

		type.value = String(initial.type ?? this.TYPE_TEXT);
		value.value = initial.value ?? '';
		node.querySelector('.mm-f-desc').value = initial.description ?? '';

		const update = () => {
			const t = Number(type.value);

			if (t === this.TYPE_SECRET && secret_keepable && initial.type === this.TYPE_SECRET) {
				value.placeholder = 'leave empty to keep the current secret';
				hint.textContent = 'The current secret value is never sent to the browser.';
			}
			else if (t === this.TYPE_SECRET) {
				value.placeholder = 'secret value';
				hint.textContent = '';
			}
			else if (t === this.TYPE_VAULT) {
				value.placeholder = 'path/to/secret:key';
				hint.textContent = 'Vault path, as configured under Administration > General > Other.';
			}
			else {
				value.placeholder = '';
				hint.textContent = '';
			}
		};

		type.addEventListener('change', update);
		update();

		node.read = () => {
			const t = Number(type.value);
			let v = value.value;
			const keep = t === this.TYPE_SECRET && secret_keepable && initial.type === this.TYPE_SECRET && v === '';

			if (t === this.TYPE_VAULT && v.trim() === '') {
				return {error: 'A vault secret needs a path.'};
			}

			if (t === this.TYPE_SECRET && v === '' && !keep) {
				return {error: 'Enter the secret value. Existing secret values cannot be read back or copied.'};
			}

			return {type: t, value: keep ? null : v, description: node.querySelector('.mm-f-desc').value};
		};

		return node;
	}

	/* ------------------------------------------------------------------ cell dialog */

	/**
	 * What the value becomes if the row's own macro is deleted.
	 */
	afterRemovalText(state) {
		const next = state.host_def ? this.resolveWithout(state, state.host_def.id) : state.winner;

		if (!next) {
			return 'it becomes undefined';
		}

		const value = next.type === this.TYPE_SECRET ? 'a secret value' : (next.value === '' ? 'an empty value' : next.value);

		return `then ${value} from ${next.level === 'global' ? 'global macros' : this.sourceName(next)}`;
	}

	openCellDialog(hostid, ci) {
		const host = this.hosts_by_id.get(hostid);
		const state = this.cellState(host, ci);
		const column = state.column;
		const winner = state.winner;
		const key = this.hostKey(hostid, column.macro);

		const noun = host.kind === 'template' ? 'template' : 'host';
		const can_host = host.editable;
		const readonly_why = `You cannot change macros on this ${noun}.`;
		const source_is_template = winner !== null && winner.level === 'template' && winner.oid !== hostid;

		// A template row's own macro is edited in place, so its staged change lives under the definition's key.
		const own_key = state.host_def && host.kind === 'template' ? 's|' + state.host_def.id : null;
		const staged_key = state.staged ? key : (own_key && this.staged.has(own_key) ? own_key : null);
		const can_source = source_is_template && this.can_edit_templates
			&& this.grid.templates[winner.oid]?.editable === true;

		const body = this.el('<div class="mm-cell-dialog"></div>');

		// Current state.
		body.append(this.el(`
			<div class="mm-current">
				<div class="mm-muted mm-small">Effective now</div>
				<div>${winner
					? `${this.valueHtml(winner.type, winner.value)} ${this.sourceBadge(winner, host)}`
						+ (state.fallback ? ' <span class="mm-badge mm-flag">no context</span>' : '')
					: '<span class="mm-undef">&ndash;</span> undefined'}</div>
			</div>
		`));

		const flow_details = this.el(`<details class="mm-flow-wrap" open>
			<summary>How this value is found</summary></details>`);
		flow_details.append(this.renderLookupFlow(host, state));
		body.append(flow_details);

		const modes = [];

		if (staged_key) {
			modes.push({id: 'unstage', label: 'Discard the staged change', enabled: true});
		}

		modes.push({
			id: 'host',
			label: state.host_def
				? `Change the value on this ${noun}`
				: (winner ? `Override it on this ${noun}` : `Set it on this ${noun}`),
			enabled: can_host,
			why: can_host ? '' : readonly_why
		});

		if (source_is_template) {
			modes.push({
				id: 'source',
				label: `Change it where it comes from (template ${this.sourceName(winner)}, affects every host inheriting it)`,
				enabled: can_source,
				why: can_source ? '' : 'You cannot edit this template.'
			});
		}

		if (state.host_def) {
			modes.push({
				id: 'revert',
				label: `Remove it from this ${noun} (${this.afterRemovalText(state)})`,
				enabled: can_host,
				why: can_host ? '' : readonly_why
			});
		}

		const radios = this.el('<fieldset class="mm-modes"><legend class="mm-muted mm-small">Change</legend></fieldset>');

		for (const mode of modes) {
			radios.append(this.el(`
				<label class="${mode.enabled ? '' : 'mm-disabled'}" title="${this.esc(mode.why ?? '')}">
					<input type="radio" name="mm_mode" value="${mode.id}" ${mode.enabled ? '' : 'disabled'}>
					${this.esc(mode.label)}${mode.why ? ` <span class="mm-muted mm-small">(${this.esc(mode.why)})</span>` : ''}
				</label>
			`));
		}

		body.append(radios);

		const fields_slot = this.el('<div class="mm-fields-slot"></div>');
		const reach_slot = this.el('<div class="mm-reach-slot"></div>');
		body.append(fields_slot, reach_slot);

		let fields = null;

		const showMode = mode => {
			fields_slot.innerHTML = '';
			reach_slot.innerHTML = '';
			fields = null;

			if (mode === 'host') {
				let initial;
				let keepable = false;

				if (own_key && this.staged.has(own_key)) {
					initial = this.staged.get(own_key);
					keepable = true;
				}
				else if (state.staged && state.staged.kind === 'set') {
					initial = state.staged;
					keepable = state.staged.existing?.type === this.TYPE_SECRET;
				}
				else if (state.host_def) {
					initial = state.host_def;
					keepable = true;
				}
				else if (winner) {
					initial = {type: winner.type, value: winner.value, description: winner.description};
				}
				else {
					initial = {type: this.TYPE_TEXT, value: '', description: ''};
				}

				fields = this.valueFields(initial, keepable);
				fields_slot.append(fields);

				// Changing a template's own macro reaches every host that inherits it.
				if (host.kind === 'template' && state.host_def) {
					this.renderReachSummary(reach_slot, state.host_def);
				}
				else if (host.kind === 'template') {
					fields_slot.append(this.el(`<p class="mm-hint">Hosts linked to this template inherit the new macro
						unless they, or a template nearer to them, define it themselves.</p>`));
				}
			}
			else if (mode === 'source') {
				const staged = this.staged.get('s|' + winner.id);

				fields = this.valueFields(staged ?? winner, true);
				fields_slot.append(fields);
				this.renderReachSummary(reach_slot, winner);
			}
			else if (mode === 'revert') {
				fields_slot.append(this.el(host.kind === 'template'
					? `<p>${this.esc(column.macro)} is deleted from this template. Hosts that got it from here fall back
						to other templates or global macros, or end up with it undefined.</p>`
					: `<p>The host-level ${this.esc(column.macro)} is deleted; the host then uses whatever its templates
						or the global macros define.</p>`));

				if (host.kind === 'template') {
					this.renderReachSummary(reach_slot, state.host_def);
				}
			}
			else if (mode === 'unstage') {
				fields_slot.append(this.el('<p>The staged change for this cell is dropped. Nothing is written.</p>'));
			}
		};

		radios.addEventListener('change', e => showMode(e.target.value));

		const first = radios.querySelector('input:not([disabled])');

		if (first) {
			first.checked = true;
			showMode(first.value);
		}

		this.openDialog({
			title: `${column.macro} on ${noun} ${host.name}`,
			body,
			buttons: [
				{
					label: 'Stage change',
					primary: true,
					disabled: first === null,
					action: async api => {
						const mode = radios.querySelector('input:checked')?.value;

						if (mode === 'unstage') {
							this.staged.delete(staged_key);
						}
						else if (mode === 'revert') {
							if (own_key) {
								this.staged.delete(own_key);
							}

							this.staged.set(key, {
								kind: 'revert', hostid, host_name: host.name, macro: column.macro,
								row_kind: host.kind, existing: state.host_def
							});
						}
						else if (mode === 'host' || mode === 'source') {
							const read = fields.read();

							if (read.error) {
								this.dialogError(api, {title: read.error});

								return false;
							}

							if (mode === 'host') {
								this.stageHostSet(host, column, state.host_def, read);
							}
							else {
								this.stageSourceEdit(winner, this.sourceName(winner), read);
							}
						}

						this.afterStage();

						return true;
					}
				},
				{label: 'Cancel'}
			]
		});
	}

	async renderReachSummary(slot, def) {
		slot.innerHTML = '<p class="mm-muted">Counting the hosts this template value reaches...</p>';

		try {
			const [result] = await this.reach([def.hostmacroid]);

			slot.innerHTML = '';

			if (!result) {
				return;
			}

			const details = this.el(`<details class="mm-flow-wrap" open><summary>Where this template value goes:
				${result.affected_count} host(s) use it, across every host that inherits the template</summary></details>`);
			details.append(this.renderReachFlow(result, this.staged.get('s|' + def.id) ?? null));
			slot.append(details);
		}
		catch (error) {
			slot.innerHTML = '';
			slot.append(this.el(`<p class="mm-undef">Could not count affected hosts: ${this.esc(error.title ?? '')}</p>`));
		}
	}

	/* ------------------------------------------------------------------ staging */

	sameAsDef(def, change) {
		return def !== null && def !== undefined
			&& def.type === change.type
			&& def.description === change.description
			&& (change.value === null || (def.type !== this.TYPE_SECRET && def.value === change.value));
	}

	stageHostSet(host, column, host_def, read) {
		const key = this.hostKey(host.hostid, column.macro);

		// An existing template macro is edited in place: that path shows its reach and the override choices.
		if (host.kind === 'template' && host_def) {
			this.staged.delete(key);
			const before = this.staged.get('s|' + host_def.id);
			this.stageSourceEdit(host_def, host.name, read);

			return this.staged.get('s|' + host_def.id) !== before;
		}

		if (this.sameAsDef(host_def, read)) {
			this.staged.delete(key);

			return false;
		}

		this.staged.set(key, {
			kind: 'set', hostid: host.hostid, host_name: host.name, macro: column.macro, row_kind: host.kind,
			type: read.type, value: read.value, description: read.description,
			existing: host_def
		});

		return true;
	}

	stageSourceEdit(def, object_name, read) {
		const key = 's|' + def.id;

		if (this.sameAsDef(def, read)) {
			this.staged.delete(key);

			return;
		}

		const previous = this.staged.get(key);

		this.staged.set(key, {
			kind: 'source', def, object_name,
			type: read.type, value: read.value, description: read.description,
			reverts: previous?.reverts ?? new Set()
		});
	}

	afterStage() {
		this.renderStagedBar();

		if (this.grid) {
			this.renderGridBody();
		}

		if (this.find) {
			this.renderFindBody();
		}
	}

	renderStagedBar() {
		const count = this.staged.size;

		if (count === 0) {
			this.staged_bar.innerHTML = '';
			this.staged_bar.hidden = true;

			return;
		}

		this.staged_bar.hidden = false;
		this.staged_bar.innerHTML = `
			<strong>${count} staged change(s)</strong>
			<span class="mm-muted">Nothing is written until you review and apply.</span>
			<span class="mm-spacer"></span>
			<button type="button" class="mm-review">Review and apply</button>
			<button type="button" class="btn-alt mm-discard">Discard all</button>
		`;

		this.staged_bar.querySelector('.mm-review').addEventListener('click', () => this.openReview());
		this.staged_bar.querySelector('.mm-discard').addEventListener('click', () => {
			if (confirm(`Discard ${count} staged change(s)?`)) {
				this.staged.clear();
				this.afterStage();
			}
		});
	}

	/* ------------------------------------------------------------------ bulk actions */

	selectedHosts() {
		return [...this.checked].map(id => this.hosts_by_id.get(id)).filter(Boolean);
	}

	bulkSet(ci) {
		const column = this.grid.columns[ci];
		const hosts = this.selectedHosts();
		const fields = this.valueFields({type: this.TYPE_TEXT, value: '', description: ''}, false);

		const body = this.el('<div></div>');
		body.append(
			this.el(`<p>Writes ${this.esc(column.macro)} directly on each of the ${hosts.length} selected row(s):
				created where it is missing, updated where the row already has it.</p>`),
			fields
		);

		this.openDialog({
			title: `Set ${column.macro}`,
			body,
			buttons: [
				{
					label: 'Stage change',
					primary: true,
					action: async api => {
						const read = fields.read();

						if (read.error) {
							this.dialogError(api, {title: read.error});

							return false;
						}

						let staged = 0;
						let readonly = 0;

						for (const host of hosts) {
							if (!host.editable) {
								readonly++;
								continue;
							}

							const state = this.cellState(host, ci);

							// A new value never "keeps" an existing secret.
							if (this.stageHostSet(host, column, state.host_def, read)) {
								staged++;
							}
						}

						this.afterStage();
						this.bulkReport(staged, {'read-only': readonly});

						return true;
					}
				},
				{label: 'Cancel'}
			]
		});
	}

	bulkPin(ci) {
		const column = this.grid.columns[ci];
		const hosts = this.selectedHosts();
		const plan = [];
		const skipped = {'read-only': 0, 'undefined': 0, 'already set on the row': 0};

		for (const host of hosts) {
			if (!host.editable) {
				skipped['read-only']++;
				continue;
			}

			const state = this.cellState(host, ci);

			if (state.winner === null) {
				skipped['undefined']++;
				continue;
			}

			if (state.winner.oid === host.hostid && state.winner.macro === column.macro) {
				skipped['already set on the row']++;
				continue;
			}

			plan.push({host, state});
		}

		const secrets = plan.filter(p => p.state.winner.type === this.TYPE_SECRET);

		const commit = secret_value => {
			let staged = 0;

			for (const {host, state} of plan) {
				const w = state.winner;
				const read = {
					type: w.type,
					value: w.type === this.TYPE_SECRET ? secret_value : w.value,
					description: w.description
				};

				if (this.stageHostSet(host, column, state.host_def, read)) {
					staged++;
				}
			}

			this.afterStage();
			this.bulkReport(staged, skipped);
		};

		if (plan.length === 0) {
			this.bulkReport(0, skipped);

			return;
		}

		if (secrets.length === 0) {
			commit(null);

			return;
		}

		// Secret values cannot be read back through the API, so the value to pin is asked once.
		const body = this.el(`
			<div>
				<p>${secrets.length} of the ${plan.length} row(s) inherit ${this.esc(column.macro)} as a secret.
					Secret values are never returned by the API, so they cannot be copied. Enter the value to write as a
					secret macro on those rows. Non-secret values are copied as they are.</p>
				<label class="mm-fields">Secret value <input type="password" class="mm-pin-secret" autocomplete="new-password"></label>
			</div>
		`);

		this.openDialog({
			title: `Pin ${column.macro}`,
			body,
			buttons: [
				{
					label: 'Stage change',
					primary: true,
					action: async api => {
						const value = body.querySelector('.mm-pin-secret').value;

						if (value === '') {
							this.dialogError(api, {title: 'Enter the secret value.'});

							return false;
						}

						commit(value);

						return true;
					}
				},
				{label: 'Cancel'}
			]
		});
	}

	bulkRevert(ci) {
		const column = this.grid.columns[ci];
		let staged = 0;
		const skipped = {'read-only': 0, 'not set on the row itself': 0};

		for (const host of this.selectedHosts()) {
			if (!host.editable) {
				skipped['read-only']++;
				continue;
			}

			const state = this.cellState(host, ci);

			if (!state.host_def) {
				skipped['not set on the row itself']++;
				continue;
			}

			this.staged.delete('s|' + state.host_def.id);
			this.staged.set(this.hostKey(host.hostid, column.macro), {
				kind: 'revert', hostid: host.hostid, host_name: host.name, macro: column.macro, row_kind: host.kind,
				existing: state.host_def
			});
			staged++;
		}

		this.afterStage();
		this.bulkReport(staged, skipped);
	}

	bulkReport(staged, skipped) {
		const parts = Object.entries(skipped).filter(([, n]) => n > 0).map(([why, n]) => `${n} ${why}`);

		this.showMessage(staged > 0 ? 'good' : 'warning',
			`Staged ${staged} change(s).` + (parts.length ? ` Skipped: ${parts.join(', ')}.` : '')
		);
	}

	/* ------------------------------------------------------------------ review and apply */

	expectOf(def) {
		const expect = {macro: def.macro, type: def.type, description: def.description};

		if (def.type !== this.TYPE_SECRET) {
			expect.value = def.value ?? '';
		}

		return expect;
	}

	/**
	 * Staged changes to API operations. Each op carries a key that maps its result back to the staged entry.
	 */
	buildOps() {
		const ops = [];
		const rows = [];
		const deleted = new Set();
		const updated = new Set();

		const push = (op, row) => {
			ops.push(op);
			rows.push({...row, key: op.key});
		};

		for (const [key, change] of this.staged) {
			if (change.kind === 'set') {
				if (change.existing) {
					const op = {
						key, action: 'update', hostmacroid: change.existing.hostmacroid,
						type: change.type, description: change.description, expect: this.expectOf(change.existing)
					};

					if (change.value !== null) {
						op.value = change.value;
					}

					updated.add(change.existing.hostmacroid);
					push(op, {object: change.host_name, level: change.row_kind ?? 'host', macro: change.macro, what: 'Update',
						before: change.existing, after: change});
				}
				else {
					push({key, action: 'create', hostid: change.hostid, macro: change.macro, type: change.type,
						value: change.value ?? '', description: change.description},
						{object: change.host_name, level: change.row_kind ?? 'host', macro: change.macro, what: 'Create', before: null,
							after: change});
				}
			}
			else if (change.kind === 'revert' || change.kind === 'delete') {
				const def = change.existing ?? change.def;

				if (deleted.has(def.hostmacroid)) {
					continue;
				}

				deleted.add(def.hostmacroid);
				push({key, action: 'delete', hostmacroid: def.hostmacroid, expect: this.expectOf(def)},
					{object: change.host_name ?? change.object_name, level: def.level, macro: def.macro,
						what: 'Delete', before: def, after: null});
			}
			else if (change.kind === 'source') {
				const op = {
					key, action: 'update', hostmacroid: change.def.hostmacroid,
					type: change.type, description: change.description, expect: this.expectOf(change.def)
				};

				if (change.value !== null) {
					op.value = change.value;
				}

				updated.add(change.def.hostmacroid);
				push(op, {object: change.object_name, level: change.def.level, macro: change.def.macro,
					what: 'Update', before: change.def, after: change});
			}
		}

		// Reverts chosen in the review step for host overrides of an edited template macro.
		for (const [key, change] of this.staged) {
			if (change.kind !== 'source' || !change.reverts || change.reverts.size === 0) {
				continue;
			}

			const reach = this.reach_cache.get(change.def.hostmacroid);

			for (const entry of reach?.overridden ?? []) {
				if (!change.reverts.has(entry.hostmacroid) || deleted.has(entry.hostmacroid)
						|| updated.has(entry.hostmacroid)) {
					continue;
				}

				deleted.add(entry.hostmacroid);

				const def = {
					hostmacroid: entry.hostmacroid, macro: entry.macro, type: entry.type, value: entry.value,
					description: entry.description, level: 'host'
				};

				push({key: `${key}#r${entry.hostmacroid}`, action: 'delete', hostmacroid: entry.hostmacroid,
					expect: this.expectOf(def)},
					{object: entry.name, level: 'host', macro: entry.macro, what: 'Delete (revert override)',
						before: def, after: null});
			}
		}

		return {ops, rows};
	}

	changeText(change) {
		if (change === null) {
			return '<span class="mm-muted">(none)</span>';
		}

		const value = change.value === null && change.type === this.TYPE_SECRET
			? '<span class="mm-secret">******</span> <span class="mm-muted mm-small">(unchanged)</span>'
			: this.valueHtml(change.type, change.value);

		return `${value} <span class="mm-muted mm-small">${this.typeName(change.type)}</span>`
			+ (change.description ? `<div class="mm-muted mm-small">${this.esc(change.description)}</div>` : '');
	}

	async openReview() {
		const body = this.el('<div class="mm-review-body"><p class="mm-muted">Checking what the changes reach...</p></div>');

		const api = this.openDialog({
			title: 'Review staged changes',
			body,
			wide: true,
			buttons: [{label: 'Apply', primary: true, disabled: true}, {label: 'Back'}]
		});

		const sources = [...this.staged.values()].filter(c => c.kind === 'source' && c.def.level === 'template');

		try {
			if (sources.length > 0) {
				await this.reach(sources.map(c => c.def.hostmacroid));
			}
		}
		catch (error) {
			this.dialogError(api, error);
		}

		this.renderReview(api, body, sources, null);
	}

	renderReview(api, body, sources, check) {
		body.innerHTML = '';

		// Template edits first: how far they reach and which host overrides to keep.
		for (const change of sources) {
			const reach = this.reach_cache.get(change.def.hostmacroid);

			if (!reach) {
				continue;
			}

			const section = this.el(`
				<div class="mm-review-source">
					<h5>${this.esc(change.def.macro)} on template ${this.esc(change.object_name)}</h5>
					<div class="mm-review-flow"></div>
				</div>
			`);

			const drawFlow = () => {
				const slot = section.querySelector('.mm-review-flow');
				slot.innerHTML = '';
				slot.append(this.renderReachFlow(reach, change));
			};

			drawFlow();

			if (reach.overridden.length > 0) {
				const list = this.el(`
					<div class="mm-overrides">
						<p>${reach.overridden.length} host(s) override this macro and keep their own value, unless you tick
							them to revert the override in the same apply.</p>
						<label class="mm-small"><input type="checkbox" class="mm-rev-all"> Revert all editable overrides</label>
						<table class="list-table mm-review-table">
							<thead><tr><th></th><th>Host</th><th>Host value</th></tr></thead>
							<tbody></tbody>
						</table>
					</div>
				`);

				const tbody = list.querySelector('tbody');

				for (const entry of reach.overridden) {
					const has_own_change = this.staged.has(this.hostKey(entry.hostid, entry.macro));
					const disabled = !entry.editable || has_own_change;
					const why = !entry.editable ? 'read-only' : has_own_change ? 'has its own staged change' : '';

					tbody.append(this.el(`
						<tr>
							<td><input type="checkbox" class="mm-rev" value="${entry.hostmacroid}"
								${change.reverts.has(entry.hostmacroid) && !disabled ? 'checked' : ''}
								${disabled ? 'disabled' : ''} aria-label="Also revert ${this.esc(entry.name)}"></td>
							<td>${this.esc(entry.name)} <span class="mm-muted mm-small">unaffected (host override)</span>
								${why ? `<span class="mm-badge mm-flag">${why}</span>` : ''}</td>
							<td>${this.changeText({type: entry.type, value: entry.value, description: ''})}</td>
						</tr>
					`));
				}

				const sync = () => {
					change.reverts = new Set([...list.querySelectorAll('.mm-rev:checked')].map(c => c.value));
					drawFlow();
					this.renderReviewOps(api, body, sources, null);
				};

				list.addEventListener('change', e => {
					if (e.target.classList.contains('mm-rev-all')) {
						for (const box of list.querySelectorAll('.mm-rev:not([disabled])')) {
							box.checked = e.target.checked;
						}
					}

					sync();
				});

				section.append(list);
			}

			if (reach.other.length > 0) {
				const details = this.el(`
					<details class="mm-small">
						<summary>${reach.other.length} host(s) get this macro from elsewhere and are not affected</summary>
						<ul></ul>
					</details>
				`);

				for (const entry of reach.other) {
					details.querySelector('ul').append(this.el(
						`<li>${this.esc(entry.name)} <span class="mm-muted">via ${this.esc(entry.source)}</span></li>`
					));
				}

				section.append(details);
			}

			body.append(section);
		}

		body.append(this.el('<div class="mm-review-ops"></div>'));
		this.renderReviewOps(api, body, sources, check);
	}

	renderReviewOps(api, body, sources, check) {
		const {ops, rows} = this.buildOps();
		const slot = body.querySelector('.mm-review-ops');
		const status = check ? new Map(check.results.map(r => [r.key, r])) : new Map();

		let html = `<h5>${ops.length} change(s) to write</h5>
			<div class="mm-review-scroll"><table class="list-table mm-review-table">
			<thead><tr><th>Object</th><th>Macro</th><th>Change</th><th>Before</th><th>After</th><th>Check</th></tr></thead><tbody>`;

		for (const row of rows) {
			const result = status.get(row.key);
			let badge = '<span class="mm-muted">not checked</span>';

			if (result?.status === 'ok') {
				badge = '<span class="mm-badge mm-ok">ok</span>';
			}
			else if (result?.status === 'conflict') {
				badge = `<span class="mm-badge mm-bad">conflict</span><div class="mm-small">${this.esc(result.message)}</div>`;
			}

			html += `<tr>
				<td>${this.esc(row.object)} <span class="mm-muted mm-small">${this.esc(row.level)}</span></td>
				<td class="mm-macro">${this.esc(row.macro)}</td>
				<td>${this.esc(row.what)}</td>
				<td>${this.changeText(row.before)}</td>
				<td>${this.changeText(row.after)}</td>
				<td>${badge}</td>
			</tr>`;
		}

		html += '</tbody></table></div>';
		slot.innerHTML = html;

		const conflicts = check ? check.counts.conflict : 0;

		api.setButtons([
			{
				label: conflicts > 0 ? `Apply ${ops.length - conflicts} change(s), skip conflicts` : `Apply ${ops.length} change(s)`,
				primary: true,
				disabled: ops.length === 0 || ops.length === conflicts,
				action: async () => {
					try {
						if (check === null) {
							const dry = await this.post('apply', {ops, dry_run: '1'});

							if (dry.counts.conflict > 0) {
								// Show what changed underneath before writing anything.
								this.renderReviewOps(api, body, sources, dry);

								return false;
							}
						}

						const result = await this.post('apply', {ops, dry_run: '0'});

						this.showApplyResult(api, body, rows, result);
					}
					catch (error) {
						this.dialogError(api, error);
					}

					return false;
				}
			},
			{label: 'Back'}
		]);
	}

	showApplyResult(api, body, rows, result) {
		const by_key = new Map(result.results.map(r => [r.key, r]));
		const failed = [];
		const conflicts = [];

		// Staged entries: drop what was written or is stale, keep what failed so it can be fixed and retried.
		for (const key of [...this.staged.keys()]) {
			const related = [...by_key.values()].filter(r => r.key === key || r.key.startsWith(key + '#'));

			if (related.length === 0) {
				continue;
			}

			if (related.some(r => r.status === 'failed')) {
				continue;
			}

			this.staged.delete(key);
		}

		for (const row of rows) {
			const r = by_key.get(row.key);

			if (r?.status === 'failed') {
				failed.push(`${row.object} ${row.macro}: ${r.message}`);
			}
			else if (r?.status === 'conflict') {
				conflicts.push(`${row.object} ${row.macro}: ${r.message}`);
			}
		}

		this.reach_cache.clear();

		const applied = result.counts.applied;

		body.innerHTML = '';
		body.append(this.el(`<p><strong>${applied}</strong> change(s) applied.</p>`));

		if (conflicts.length > 0) {
			jQuery(body).append(makeMessageBox('warning', conflicts,
				`${conflicts.length} change(s) skipped: changed by someone else. They were dropped; reload to see the current state.`,
				false
			));
		}

		if (failed.length > 0) {
			jQuery(body).append(makeMessageBox('bad', failed,
				`${failed.length} change(s) failed and stay staged.`, false
			));
		}

		api.setButtons([{
			label: 'Close and reload',
			primary: true,
			action: async () => {
				this.renderStagedBar();

				if (this.grid) {
					await this.loadGrid();
				}

				if (this.find) {
					await this.loadFind();
				}

				if (applied > 0) {
					this.showMessage('good', `${applied} change(s) applied.`);
				}

				return true;
			}
		}]);
	}

	/* ------------------------------------------------------------------ templates in use */

	async loadTemplatesTab() {
		const values = this.filterValues();

		this.setBusy(this.templates_panel, 'Counting which templates are in use...');

		try {
			this.tpl_data = await this.post('templates', {
				tpl_groupids: values.tpl_groupids,
				subgroups: values.subgroups,
				pattern: values.pattern,
				include_unused: this.tpl_include_unused ? '1' : '0'
			});
			this.tpl_checked = new Set();
			this.renderTemplatesTab();
		}
		catch (error) {
			this.showError(error);
			this.renderEmpty(this.templates_panel, 'Nothing loaded.');
		}
	}

	renderTemplatesTab() {
		const data = this.tpl_data;

		this.templates_panel.innerHTML = `
			<div class="mm-toolbar">
				<input type="search" class="mm-tpl-filter" placeholder="Filter templates"
					value="${this.esc(this.tpl_filter)}" aria-label="Filter templates by name">
				<label class="mm-inline"><input type="checkbox" class="mm-tpl-unused"
					${this.tpl_include_unused ? 'checked' : ''}> Include templates no host uses</label>
				<span class="mm-spacer"></span>
				<span class="mm-muted mm-small">${data.filtered
					? 'Macro counts: macros matching the Macros filter.'
					: 'Macro counts: all user macros on the template. Fill in Macros to count only those.'}</span>
			</div>
			<div class="mm-bulk mm-tpl-bulk"></div>
			<div class="mm-table-wrap mm-tpl-wrap"></div>
			<div class="mm-pager mm-tpl-count"></div>
		`;

		const panel = this.templates_panel;

		panel.querySelector('.mm-tpl-filter').addEventListener('input', e => {
			this.tpl_filter = e.target.value;
			this.renderTemplatesBody();
		});

		panel.querySelector('.mm-tpl-unused').addEventListener('change', e => {
			this.tpl_include_unused = e.target.checked;
			this.loadTemplatesTab();
		});

		panel.querySelector('.mm-tpl-wrap').addEventListener('click', e => {
			const sort = e.target.closest('button[data-sort]');

			if (sort) {
				const key = sort.dataset.sort;
				this.tpl_sort = {key, desc: this.tpl_sort.key === key ? !this.tpl_sort.desc : key !== 'name'};
				this.renderTemplatesBody();

				return;
			}

			const check = e.target.closest('input.mm-tpl-check');

			if (check) {
				if (check.dataset.all) {
					for (const row of this.visibleTemplates()) {
						check.checked ? this.tpl_checked.add(row.templateid) : this.tpl_checked.delete(row.templateid);
					}

					this.renderTemplatesBody();
				}
				else {
					check.checked ? this.tpl_checked.add(check.value) : this.tpl_checked.delete(check.value);
					this.renderTemplatesBulk();
				}

				return;
			}

			const detail = e.target.closest('button[data-detail]');

			if (detail) {
				const row = data.rows.find(r => r.templateid === detail.dataset.id);

				if (detail.dataset.detail === 'macros') {
					this.openTemplateMacros(row);
				}
				else {
					this.openTemplateHosts(row, detail.dataset.detail === 'direct');
				}

				return;
			}

			const open = e.target.closest('button[data-open]');

			if (open) {
				const row = data.rows.find(r => r.templateid === open.dataset.id);
				this.openTemplatesInGrid([row], open.dataset.open === 'hosts');
			}
		});

		this.renderTemplatesBody();
	}

	/**
	 * A count on the Templates in use tab as a link; zero stays plain text.
	 */
	countLink(row, what, value, title) {
		if (value === 0) {
			return `<span class="mm-muted">${what === 'macros' ? '0' : 'none'}</span>`;
		}

		return `<button type="button" class="btn-link mm-count" data-detail="${what}" data-id="${row.templateid}"
			title="${this.esc(title)}">${value}</button>`;
	}

	async openTemplateMacros(row) {
		const body = this.el('<div class="mm-detail"><p class="mm-muted">Loading macros...</p></div>');
		const api = this.openDialog({
			title: `Macros on ${row.name}`,
			body,
			wide: true,
			buttons: [
				{label: 'Open in grid', primary: true, action: async () => {
					this.openTemplatesInGrid([row], false);

					return true;
				}},
				{label: 'Compare with its hosts', disabled: row.total === 0, action: async () => {
					this.openTemplatesInGrid([row], true);

					return true;
				}},
				{label: 'Close'}
			]
		});

		try {
			const data = await this.post('tpldetail', {
				templateid: row.templateid,
				what: 'macros',
				pattern: this.filterValues().pattern
			});

			body.innerHTML = '';
			body.append(this.el(`<p class="mm-muted mm-small">Defined on the template itself${data.filtered
				? ', matching the Macros filter' : ''}. Inherited macros from templates it links are not listed.</p>`));

			if (data.macros.length === 0) {
				body.append(this.el('<div class="mm-empty">No macros.</div>'));

				return;
			}

			const filter = this.el(`<input type="search" class="mm-detail-filter" placeholder="Filter macros"
				aria-label="Filter macros">`);
			const wrap = this.el('<div class="mm-detail-scroll"></div>');

			const draw = () => {
				const needle = filter.value.toLowerCase();
				const rows = data.macros.filter(m => needle === ''
					|| m.macro.toLowerCase().includes(needle)
					|| (m.type !== this.TYPE_SECRET && (m.value ?? '').toLowerCase().includes(needle))
					|| m.description.toLowerCase().includes(needle));

				wrap.innerHTML = `<table class="list-table"><thead><tr><th>Macro</th><th>Value</th><th>Type</th>
					<th>Description</th></tr></thead><tbody>${rows.map(m => `<tr>
						<td class="mm-macro">${this.esc(m.macro)}</td>
						<td class="mm-tval">${this.valueHtml(m.type, m.value)}</td>
						<td class="mm-small">${this.typeName(m.type)}</td>
						<td class="mm-small">${this.esc(m.description)}</td>
					</tr>`).join('')}</tbody></table>`;
			};

			filter.addEventListener('input', draw);
			body.append(filter, wrap);
			draw();
			filter.focus();
		}
		catch (error) {
			this.dialogError(api, error);
			body.querySelector('p')?.remove();
		}
	}

	async openTemplateHosts(row, direct_only) {
		const body = this.el('<div class="mm-detail"><p class="mm-muted">Loading hosts...</p></div>');
		const selected = new Map();

		const buttons = () => [
			{
				label: selected.size > 0 ? `Open ${selected.size} selected in grid` : 'Open selected in grid',
				primary: true,
				disabled: selected.size === 0,
				action: async () => {
					this.openHostsInGrid(row, [...selected.values()]);

					return true;
				}
			},
			{label: 'Compare with all its hosts', action: async () => {
				this.openTemplatesInGrid([row], true);

				return true;
			}},
			{label: 'Close'}
		];

		const api = this.openDialog({
			title: `${direct_only ? 'Hosts linked directly to' : 'Hosts using'} ${row.name}`,
			body,
			wide: true,
			buttons: buttons()
		});

		try {
			const data = await this.post('tpldetail', {templateid: row.templateid, what: 'hosts'});
			const direct = data.hosts.filter(h => h.direct).length;
			const by_id = new Map(data.hosts.map(h => [h.hostid, h]));

			body.innerHTML = '';
			body.append(this.el(`<p class="mm-muted mm-small">${data.total} host(s) use this template: ${direct} linked
				directly, ${data.total - direct} only through templates that link it.${data.truncated ? ' List shortened.' : ''}
				Click a host to load it into the grid next to the template, or tick several.</p>`));

			const toolbar = this.el(`<div class="mm-toolbar">
				<input type="search" class="mm-detail-filter" placeholder="Filter hosts" aria-label="Filter hosts">
				<label class="mm-inline mm-small"><input type="checkbox" class="mm-direct-only" ${direct_only ? 'checked' : ''}>
					Linked directly only</label>
			</div>`);
			const wrap = this.el('<div class="mm-detail-scroll"></div>');
			const count = this.el('<div class="mm-pager"></div>');
			let shown = [];

			const draw = () => {
				const needle = toolbar.querySelector('.mm-detail-filter').value.toLowerCase();
				const only = toolbar.querySelector('.mm-direct-only').checked;

				shown = data.hosts.filter(h => (!only || h.direct)
					&& (needle === '' || h.name.toLowerCase().includes(needle) || h.host.toLowerCase().includes(needle)));

				const all = shown.length > 0 && shown.every(h => selected.has(h.hostid));

				wrap.innerHTML = `<table class="list-table"><thead><tr>
					<th class="mm-col-check"><input type="checkbox" class="mm-h-all" aria-label="Select all shown hosts"
						${all ? 'checked' : ''}></th>
					<th>Host</th><th>How it gets the template</th><th></th></tr></thead><tbody>${shown.map(h => `<tr>
						<td class="mm-col-check"><input type="checkbox" class="mm-h-check" value="${h.hostid}"
							aria-label="Select ${this.esc(h.name)}" ${selected.has(h.hostid) ? 'checked' : ''}></td>
						<td><button type="button" class="btn-link mm-h-open" data-id="${h.hostid}"
							title="Load ${this.esc(h.name)} into the grid next to ${this.esc(row.name)}">${this.esc(h.name)}</button>${h.name !== h.host
							? ` <span class="mm-muted mm-small">${this.esc(h.host)}</span>` : ''}${h.status === 1
							? ' <span class="mm-kind">disabled</span>' : ''}</td>
						<td class="mm-small">${[
							h.direct ? 'linked directly' : '',
							...h.via.map(v => `through ${this.esc(v)}`)
						].filter(Boolean).join(', ')}</td>
						<td class="mm-nowrap"><a href="${this.hostUrl(h.hostid)}" class="mm-small"
							title="Open the Zabbix host editor instead">host editor</a></td>
					</tr>`).join('')}</tbody></table>`;

				count.textContent = `${shown.length} of ${data.hosts.length} host(s) shown, ${selected.size} selected`;
			};

			const refreshButtons = () => {
				api.setButtons(buttons());
				count.textContent = `${shown.length} of ${data.hosts.length} host(s) shown, ${selected.size} selected`;
			};

			toolbar.addEventListener('input', draw);
			toolbar.addEventListener('change', draw);

			wrap.addEventListener('change', e => {
				if (e.target.classList.contains('mm-h-all')) {
					for (const h of shown) {
						e.target.checked ? selected.set(h.hostid, h) : selected.delete(h.hostid);
					}

					draw();
				}
				else if (e.target.classList.contains('mm-h-check')) {
					const h = by_id.get(e.target.value);
					e.target.checked ? selected.set(h.hostid, h) : selected.delete(h.hostid);
				}

				refreshButtons();
			});

			wrap.addEventListener('click', e => {
				const open = e.target.closest('.mm-h-open');

				if (open) {
					api.close();
					this.openHostsInGrid(row, [by_id.get(open.dataset.id)]);
				}
			});

			body.append(toolbar, wrap, count);
			draw();
			toolbar.querySelector('.mm-detail-filter').focus();
		}
		catch (error) {
			this.dialogError(api, error);
			body.querySelector('p')?.remove();
		}
	}

	/**
	 * Loads hosts into the grid together with the template they came from, so the template's values sit next to
	 * theirs as the baseline and the drift tint shows where each host deviates.
	 */
	openHostsInGrid(template, hosts) {
		for (const id of ['#groupids_', '#hostids_', '#tpl_groupids_', '#templateids_']) {
			jQuery(id).multiSelect('clean');
		}

		jQuery('#hostids_').multiSelect('addData', hosts.map(h => ({id: h.hostid, name: h.name})), false);
		jQuery('#templateids_').multiSelect('addData', [{id: template.templateid, name: template.name}], false);

		this.form.querySelector('[name="rows"][value="both"]').checked = true;
		this.form.querySelector('[name="with_hosts"]').checked = false;
		this.form.querySelector('[name="tpl_used_only"]').checked = false;

		const pattern = this.form.querySelector('[name="pattern"]');

		if (pattern.value.trim() === '') {
			pattern.value = '*';
		}

		this.setTab('grid', false);
		this.applyRowsChoice(false);
		this.updateUrl();
		this.showMessage(null);
		this.loadGrid();
	}

	visibleTemplates() {
		const needle = this.tpl_filter.toLowerCase();
		const {key, desc} = this.tpl_sort;

		return this.tpl_data.rows
			.filter(r => needle === '' || r.name.toLowerCase().includes(needle))
			.sort((a, b) => {
				const by_name = a.name.localeCompare(b.name, undefined, {sensitivity: 'base'});

				if (key === 'name') {
					return desc ? -by_name : by_name;
				}

				// Ties on a count stay alphabetical in both directions.
				return ((a[key] - b[key]) * (desc ? -1 : 1)) || by_name;
			});
	}

	renderTemplatesBody() {
		const wrap = this.templates_panel.querySelector('.mm-tpl-wrap');
		const rows = this.visibleTemplates();
		const all = rows.length > 0 && rows.every(r => this.tpl_checked.has(r.templateid));
		const head = (key, label, title) => {
			const on = this.tpl_sort.key === key;

			return `<th><button type="button" class="btn-link mm-sort" data-sort="${key}" title="${this.esc(title)}"
				aria-sort="${on ? (this.tpl_sort.desc ? 'descending' : 'ascending') : 'none'}">${label}${on
					? (this.tpl_sort.desc ? ' &darr;' : ' &uarr;') : ''}</button></th>`;
		};

		if (rows.length === 0) {
			wrap.innerHTML = `<div class="mm-empty">${this.tpl_data.rows.length === 0
				? 'No template in these groups is used by a host.'
				: 'No template matches the filter.'}</div>`;
		}
		else {
			let html = '<table class="list-table mm-tpl-table"><thead><tr>'
				+ `<th class="mm-col-check"><input type="checkbox" class="mm-tpl-check" data-all="1"
					aria-label="Select all shown templates" ${all ? 'checked' : ''}></th>`
				+ head('name', 'Template', 'Sort by name')
				+ head('total', 'Hosts using it', 'Hosts that inherit it, directly or through other templates')
				+ head('direct', 'Linked directly', 'Hosts linked to this template itself')
				+ head('macros', 'Macros', 'User macros defined on the template')
				+ '<th></th></tr></thead><tbody>';

			for (const row of rows) {
				html += `<tr>
					<td class="mm-col-check"><input type="checkbox" class="mm-tpl-check" value="${row.templateid}"
						aria-label="Select ${this.esc(row.name)}" ${this.tpl_checked.has(row.templateid) ? 'checked' : ''}></td>
					<td><a href="${this.templateUrl(row.templateid)}">${this.esc(row.name)}</a>${row.editable
						? '' : ' <span class="mm-kind">read-only</span>'}</td>
					<td>${this.countLink(row, 'hosts', row.total, 'List every host using this template')}</td>
					<td>${this.countLink(row, 'direct', row.direct, 'List the hosts linked to this template itself')}</td>
					<td>${this.countLink(row, 'macros', row.macros, 'List the macros on this template')}</td>
					<td class="mm-nowrap">
						<button type="button" class="btn-link" data-open="hosts" data-id="${row.templateid}"
							title="Macros as rows: the template next to every host that uses it"
							${row.total === 0 ? 'disabled' : ''}>Compare with its hosts</button>
						<button type="button" class="btn-link" data-open="template" data-id="${row.templateid}">Macros</button>
					</td>
				</tr>`;
			}

			wrap.innerHTML = html + '</tbody></table>';
		}

		const used = this.tpl_data.rows.filter(r => r.total > 0).length;

		this.templates_panel.querySelector('.mm-tpl-count').textContent =
			`${rows.length} of ${this.tpl_data.rows.length} template(s), ${used} used by hosts. `
				+ 'Counts cover the hosts and templates you can read.';

		this.renderTemplatesBulk();
	}

	renderTemplatesBulk() {
		const bar = this.templates_panel.querySelector('.mm-tpl-bulk');
		const count = this.tpl_checked.size;

		if (count === 0) {
			bar.innerHTML = '<span class="mm-muted">Tick templates to open several at once.</span>';

			return;
		}

		bar.innerHTML = `
			<strong>${count} selected</strong>
			<button type="button" class="btn-alt" data-bulk="hosts">Open with the hosts that use them</button>
			<button type="button" class="btn-alt" data-bulk="template">Open templates only</button>
			<button type="button" class="btn-link" data-bulk="clear">Clear selection</button>
		`;

		bar.onclick = e => {
			const act = e.target.closest('button[data-bulk]')?.dataset.bulk;

			if (act === 'clear') {
				this.tpl_checked.clear();
				this.renderTemplatesBody();
			}
			else if (act) {
				const rows = this.tpl_data.rows.filter(r => this.tpl_checked.has(r.templateid));
				this.openTemplatesInGrid(rows, act === 'hosts');
			}
		};
	}

	/**
	 * Switches to the grid with these templates as rows, optionally with every host that uses them. Reuses the
	 * filter form, so the URL, bookmarks and the Load button behave as if the user had filled it in.
	 */
	openTemplatesInGrid(rows, with_hosts) {
		jQuery('#templateids_').multiSelect('clean');
		jQuery('#templateids_').multiSelect('addData', rows.map(r => ({id: r.templateid, name: r.name})), false);
		jQuery('#tpl_groupids_').multiSelect('clean');

		this.form.querySelector('[name="rows"][value="templates"]').checked = true;
		this.form.querySelector('[name="with_hosts"]').checked = with_hosts;
		this.form.querySelector('[name="tpl_used_only"]').checked = false;

		const pattern = this.form.querySelector('[name="pattern"]');

		if (pattern.value.trim() === '') {
			pattern.value = '*';
		}

		if (this.layout !== 'auto') {
			this.layout = 'auto';
			this.savePrefs();
		}

		this.setTab('grid', false);
		this.updateUrl();
		this.showMessage(null);
		this.loadGrid();
	}

	/* ------------------------------------------------------------------ find mode */

	renderFind() {
		const rows = this.find.rows;

		if (rows.length === 0) {
			this.renderEmpty(this.find_panel, 'No host, template or global macro matches the pattern.');

			return;
		}

		this.find_panel.innerHTML = `
			<div class="mm-toolbar">
				<input type="search" class="mm-find-filter" placeholder="Filter by object, macro or value"
					value="${this.esc(this.find_filter)}" aria-label="Filter definitions">
				<span class="mm-spacer"></span>
				<button type="button" class="btn-alt mm-reach-all"
					title="Count, for every template macro listed, the hosts that resolve to it">Calculate reach</button>
			</div>
			<div class="mm-table-wrap mm-find-wrap"></div>
			<div class="mm-pager mm-find-count"></div>
		`;

		this.find_panel.querySelector('.mm-find-filter').addEventListener('input', e => {
			this.find_filter = e.target.value;
			this.renderFindBody();
		});

		this.find_panel.querySelector('.mm-reach-all').addEventListener('click', async e => {
			const ids = this.find.rows.filter(r => r.level === 'template').map(r => r.hostmacroid);

			if (ids.length === 0) {
				return;
			}

			e.target.disabled = true;
			e.target.classList.add('is-loading');

			try {
				for (let i = 0; i < ids.length; i += 100) {
					await this.reach(ids.slice(i, i + 100));
					this.renderFindBody();
				}
			}
			catch (error) {
				this.showError(error);
			}
			finally {
				e.target.disabled = false;
				e.target.classList.remove('is-loading');
			}
		});

		this.find_panel.querySelector('.mm-find-wrap').addEventListener('click', e => {
			const button = e.target.closest('button[data-act]');

			if (!button) {
				return;
			}

			const row = this.find.rows[Number(button.dataset.row)];

			if (button.dataset.act === 'reach') {
				this.openReachDialog(row.hostmacroid).then(() => this.renderFindBody());
			}
			else if (button.dataset.act === 'edit') {
				this.openFindEdit(row);
			}
			else if (button.dataset.act === 'delete') {
				const key = 'd|' + row.id;

				if (this.staged.has(key)) {
					this.staged.delete(key);
				}
				else {
					this.staged.set(key, {kind: 'delete', def: row, object_name: row.object.name});
				}

				this.afterStage();
			}
			else if (button.dataset.act === 'unstage') {
				this.staged.delete('s|' + row.id);
				this.staged.delete('d|' + row.id);
				this.afterStage();
			}
		});

		this.renderFindBody();
	}

	renderFindBody() {
		const wrap = this.find_panel.querySelector('.mm-find-wrap');

		if (!this.find || !wrap) {
			return;
		}

		const needle = this.find_filter.toLowerCase();
		const level_label = {global: 'Global', template: 'Template', host: 'Host'};

		let shown = 0;
		let html = `<table class="list-table mm-find">
			<thead><tr><th>Macro</th><th>Level</th><th>Object</th><th>Value</th><th>Description</th>
			<th title="Hosts that resolve to this template macro">Reach</th><th></th></tr></thead><tbody>`;

		this.find.rows.forEach((row, i) => {
			const haystack = `${row.macro} ${row.object.name} ${row.type === this.TYPE_SECRET ? '' : row.value}`
				+ ` ${row.description}`;

			if (needle !== '' && !haystack.toLowerCase().includes(needle)) {
				return;
			}

			shown++;

			const edit = this.staged.get('s|' + row.id);
			const del = this.staged.get('d|' + row.id);
			const reach = row.level === 'template' ? this.reach_cache.get(row.hostmacroid) : null;

			let value = this.valueHtml(row.type, row.value);

			if (edit) {
				value = `<span class="mm-strike">${value}</span> ${edit.value === null
					? '<span class="mm-secret">******</span>' : this.valueHtml(edit.type, edit.value)}
					<span class="mm-badge mm-pending">staged</span>`;
			}

			if (del) {
				value = `<span class="mm-strike">${value}</span> <span class="mm-badge mm-pending">delete</span>`;
			}

			const object_link = row.level === 'global'
				? `<a href="zabbix.php?action=macros.edit">Global macros</a>`
				: `<a href="${row.level === 'host' ? this.hostUrl(row.oid) : this.templateUrl(row.oid)}">`
					+ `${this.esc(row.object.name)}</a>`;

			let actions = '';

			if (edit || del) {
				actions = `<button type="button" class="btn-link" data-act="unstage" data-row="${i}">Discard change</button>`;
			}
			else if (row.object.editable && (row.level === 'host' || this.can_edit_templates)) {
				actions = `<button type="button" class="btn-link" data-act="edit" data-row="${i}">Edit</button>`
					+ (row.level === 'host'
						? ` <button type="button" class="btn-link" data-act="delete" data-row="${i}">Delete</button>`
						: '');
			}
			else if (row.level !== 'global') {
				actions = '<span class="mm-muted mm-small">read-only</span>';
			}

			html += `<tr class="${edit || del ? 'mm-staged' : ''}">
				<td class="mm-macro">${this.esc(row.macro)}${row.automatic
					? ' <span class="mm-badge mm-flag" title="Written by discovery">LLD</span>' : ''}</td>
				<td>${level_label[row.level]}</td>
				<td>${object_link}${row.object.status === 1 ? ' <span class="mm-badge mm-flag">disabled</span>' : ''}</td>
				<td>${value}${row.type === this.TYPE_VAULT ? ' <span class="mm-badge mm-flag">vault</span>' : ''}</td>
				<td class="mm-small">${this.esc(row.description)}</td>
				<td>${row.level === 'template'
					? `<button type="button" class="btn-link" data-act="reach" data-row="${i}"
						title="Show which hosts this template macro reaches">${reach
							? `${reach.affected_count} host(s)${reach.overridden.length
								? ` <span class="mm-muted mm-small">(${reach.overridden.length} override)</span>` : ''}`
							: 'Show'}</button>`
					: ''}</td>
				<td class="mm-nowrap">${actions}</td>
			</tr>`;
		});

		html += '</tbody></table>';
		wrap.innerHTML = html;

		this.find_panel.querySelector('.mm-find-count').textContent =
			`${shown} of ${this.find.rows.length} definition(s)`;
	}

	openFindEdit(row) {
		const staged = this.staged.get('s|' + row.id);
		const fields = this.valueFields(staged ?? row, true);
		const reach_slot = this.el('<div class="mm-reach-slot"></div>');

		const body = this.el('<div></div>');
		body.append(
			this.el(`<p>${this.esc(row.macro)} on ${row.level === 'template' ? 'template' : 'host'}
				<strong>${this.esc(row.object.name)}</strong>.</p>`),
			fields,
			reach_slot
		);

		if (row.level === 'template') {
			this.renderReachSummary(reach_slot, row);
		}

		this.openDialog({
			title: `Edit ${row.macro}`,
			body,
			buttons: [
				{
					label: 'Stage change',
					primary: true,
					action: async api => {
						const read = fields.read();

						if (read.error) {
							this.dialogError(api, {title: read.error});

							return false;
						}

						this.stageSourceEdit(row, row.object.name, read);
						this.afterStage();

						return true;
					}
				},
				{label: 'Cancel'}
			]
		});
	}

	hostUrl(hostid) {
		return `zabbix.php?action=popup&popup=host.edit&hostid=${encodeURIComponent(hostid)}`;
	}

	templateUrl(templateid) {
		return `zabbix.php?action=popup&popup=template.edit&templateid=${encodeURIComponent(templateid)}`;
	}

	/* ------------------------------------------------------------------ CSV */

	csvField(value) {
		const s = String(value ?? '');

		return /[",\r\n]/.test(s) || /^\s|\s$/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
	}

	exportCsv() {
		const lines = [['host', 'row_type', 'name', 'macro', 'effective_value', 'type', 'source', 'source_object',
			'own_value'].join(',')];
		const shown_cols = this.shownColumns();

		for (const host of this.visibleHosts()) {
			shown_cols.forEach(ci => {
				const column = this.grid.columns[ci];
				const state = this.cellState(host, ci);
				const w = state.winner;
				const hd = state.host_def;
				const shown = def => def === null ? '' : def.type === this.TYPE_SECRET ? '<secret>' : def.value;

				lines.push([
					host.host,
					host.kind,
					host.name,
					column.macro,
					shown(w),
					w ? ['text', 'secret', 'vault'][w.type] : '',
					w ? (w.oid === host.hostid ? 'own' : w.level) : 'undefined',
					w ? this.sourceName(w) : '',
					shown(hd)
				].map(v => this.csvField(v)).join(','));
			});
		}

		const blob = new Blob([lines.join('\r\n') + '\r\n'], {type: 'text/csv;charset=utf-8'});
		const link = document.createElement('a');
		const stamp = new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '');

		link.href = URL.createObjectURL(blob);
		link.download = `macromatrix-${stamp}.csv`;
		document.body.append(link);
		link.click();
		link.remove();
		setTimeout(() => URL.revokeObjectURL(link.href), 1000);
	}

	parseCsv(text) {
		const rows = [];
		let row = [];
		let field = '';
		let quoted = false;

		text = text.replace(/^\uFEFF/, '');

		for (let i = 0; i < text.length; i++) {
			const c = text[i];

			if (quoted) {
				if (c === '"' && text[i + 1] === '"') {
					field += '"';
					i++;
				}
				else if (c === '"') {
					quoted = false;
				}
				else {
					field += c;
				}
			}
			else if (c === '"' && field === '') {
				quoted = true;
			}
			else if (c === ',') {
				row.push(field);
				field = '';
			}
			else if (c === '\n' || c === '\r') {
				if (c === '\r' && text[i + 1] === '\n') {
					i++;
				}

				row.push(field);
				rows.push(row);
				row = [];
				field = '';
			}
			else {
				field += c;
			}
		}

		if (field !== '' || row.length > 0) {
			row.push(field);
			rows.push(row);
		}

		return rows.filter(r => r.some(f => f.trim() !== ''));
	}

	async importCsv(file) {
		const rows = this.parseCsv(await file.text());

		if (rows.length < 2) {
			this.showMessage('bad', 'The CSV file has no data rows.');

			return;
		}

		const header = rows[0].map(h => h.trim().toLowerCase());
		const col = name => header.indexOf(name);
		const [ih, im, iv, it, id, ik] = ['host', 'macro', 'value', 'type', 'description', 'row_type'].map(col);

		if (ih < 0 || im < 0 || iv < 0) {
			this.showMessage('bad', 'The CSV header must include host, macro and value columns.',
				['Optional columns: type (text, secret, vault) and description.']
			);

			return;
		}

		// Keyed by kind so a host and a template with the same name stay apart when row_type is given.
		const by_host = new Map();
		const by_name = new Map();

		for (const host of this.grid.hosts) {
			for (const prefix of [host.kind + '|', '|']) {
				if (!by_host.has(prefix + host.host)) {
					by_host.set(prefix + host.host, host);
				}

				if (!by_name.has(prefix + host.name)) {
					by_name.set(prefix + host.name, host);
				}
			}
		}

		const columns = new Map(this.grid.columns.map((c, ci) => [c.macro, ci]));
		const problems = [];
		let staged = 0;

		rows.slice(1).forEach((row, i) => {
			const line = i + 2;
			const host_key = (row[ih] ?? '').trim();
			const kind_raw = ik >= 0 ? (row[ik] ?? '').trim().toLowerCase() : '';
			const prefix = kind_raw === 'host' || kind_raw === 'template' ? kind_raw + '|' : '|';
			const host = by_host.get(prefix + host_key) ?? by_name.get(prefix + host_key);
			let macro = (row[im] ?? '').trim();

			if (!columns.has(macro) && /^\{\$[A-Za-z0-9_.]+\}$/.test(macro)) {
				macro = macro.toUpperCase();
			}

			if (!host) {
				problems.push(`Line ${line}: "${host_key}" is not a loaded host or template.`);

				return;
			}

			if (!columns.has(macro)) {
				problems.push(`Line ${line}: ${macro} is not a column. Add it to the macro filter and load again.`);

				return;
			}

			if (!host.editable) {
				problems.push(`Line ${line}: ${host.name} is read-only for you.`);

				return;
			}

			const type_raw = it >= 0 ? (row[it] ?? '').trim().toLowerCase() : '';
			const type = {'': 0, 'text': 0, '0': 0, 'secret': 1, '1': 1, 'vault': 2, '2': 2}[type_raw];

			if (type === undefined) {
				problems.push(`Line ${line}: unknown type "${row[it]}".`);

				return;
			}

			const value = row[iv] ?? '';

			if (type !== this.TYPE_TEXT && value === '') {
				problems.push(`Line ${line}: a ${this.typeName(type).toLowerCase()} macro needs a value.`);

				return;
			}

			if (type === this.TYPE_SECRET && value === '<secret>') {
				problems.push(`Line ${line}: "<secret>" is the export placeholder, not a value.`);

				return;
			}

			const ci = columns.get(macro);
			const state = this.cellState(host, ci);
			const description = id >= 0 ? (row[id] ?? '') : (state.host_def?.description ?? '');

			if (this.stageHostSet(host, this.grid.columns[ci], state.host_def, {type, value, description})) {
				staged++;
			}
		});

		this.afterStage();

		this.showMessage(problems.length ? 'warning' : 'good',
			`Staged ${staged} change(s) from ${file.name}.` + (problems.length ? ` ${problems.length} line(s) skipped.` : ''),
			problems.slice(0, 50).concat(problems.length > 50 ? [`...and ${problems.length - 50} more.`] : [])
		);
	}
};
</script>
