<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

$this->includeJsFile('macromatrix.view.js.php');

$filter = $data['filter'];

$multiselect = static function (string $name, string $object, string $srctbl, string $srcfld, array $selected,
		array $extra = []): CMultiSelect {
	return (new CMultiSelect([
		'name' => $name.'[]',
		'object_name' => $object,
		'data' => $selected,
		'popup' => [
			'parameters' => [
				'srctbl' => $srctbl,
				'srcfld1' => $srcfld,
				'dstfrm' => 'mm_filter',
				'dstfld1' => $name.'_'
			] + $extra
		]
	]))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
};

$rows_choice = (new CRadioButtonList('rows', $filter['rows']))
	->addValue(_('Hosts'), 'hosts')
	->addValue(_('Templates'), 'templates')
	->addValue(_('Both'), 'both')
	->setModern(true);

$left = (new CFormGrid())
	->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
	->addItem([
		(new CLabel(_('Show')))->addClass('js-mm-grid-only'),
		(new CFormField($rows_choice))->addClass('js-mm-grid-only')
	])
	->addItem([
		(new CLabel(_('Host groups'), 'groupids__ms'))->addClass('js-mm-grid-only')->addClass('js-mm-hosts'),
		(new CFormField(
			$multiselect('groupids', 'hostGroup', 'host_groups', 'groupid', $filter['groups'], [
				'with_hosts' => true,
				'enrich_parent_groups' => true
			])
		))->addClass('js-mm-grid-only')->addClass('js-mm-hosts')
	])
	->addItem([
		(new CLabel(_('Hosts'), 'hostids__ms'))->addClass('js-mm-grid-only')->addClass('js-mm-hosts'),
		(new CFormField(
			$multiselect('hostids', 'hosts', 'hosts', 'hostid', $filter['hosts'])
		))->addClass('js-mm-grid-only')->addClass('js-mm-hosts')
	])
	->addItem([
		(new CLabel(_('Template groups'), 'tpl_groupids__ms'))
			->addClass('js-mm-grid-only')
			->addClass('js-mm-templates')
			->addClass('js-mm-tpl-tab'),
		(new CFormField(
			$multiselect('tpl_groupids', 'templateGroup', 'template_groups', 'groupid', $filter['tpl_groups'], [
				'with_templates' => true,
				'enrich_parent_groups' => true
			])
		))
			->addClass('js-mm-grid-only')
			->addClass('js-mm-templates')
			->addClass('js-mm-tpl-tab')
	])
	->addItem([
		(new CLabel(_('Templates'), 'templateids__ms'))->addClass('js-mm-grid-only')->addClass('js-mm-templates'),
		(new CFormField(
			$multiselect('templateids', 'templates', 'templates', 'hostid', $filter['templates'], [
				'srcfld2' => 'host'
			])
		))->addClass('js-mm-grid-only')->addClass('js-mm-templates')
	])
	->addItem([
		(new CLabel(''))->addClass('js-mm-grid-only')->addClass('js-mm-templates'),
		(new CFormField([
			(new CDiv(
				(new CCheckBox('tpl_used_only'))
					->setLabel(_('Only templates used by hosts'))
					->setChecked($filter['tpl_used_only'] === '1')
			)),
			(new CDiv(
				(new CCheckBox('with_hosts'))
					->setLabel(_('Also load the hosts that use them'))
					->setChecked($filter['with_hosts'] === '1')
			))->addClass('mm-subgroups')
		]))->addClass('js-mm-grid-only')->addClass('js-mm-templates')
	])
	->addItem([
		(new CLabel(''))->addClass('js-mm-grid-only')->addClass('js-mm-tpl-tab'),
		(new CFormField(
			(new CCheckBox('subgroups'))
				->setLabel(_('Include subgroups'))
				->setChecked($filter['subgroups'] === '1')
		))->addClass('js-mm-grid-only')->addClass('js-mm-tpl-tab')
	]);

$right = (new CFormGrid())
	->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
	->addItem([
		new CLabel(_('Macros'), 'mm_pattern'),
		new CFormField([
			(new CTextBox('pattern', $filter['pattern'], false, 1024))
				->setId('mm_pattern')
				->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
				->setAttribute('placeholder', '{$SNMP_*}, {$LOW_SPACE_LIMIT}')
				->disableAutocomplete()
				->setAttribute('spellcheck', 'false'),
			(new CDiv(_('Comma-separated names; * matches anything.')))->addClass('mm-hint')
		])
	])
	->addItem([
		(new CLabel(_('Context'), 'mm_context'))->addClass('js-mm-grid-only'),
		(new CFormField([
			(new CTextBox('context', $filter['context'], false, 1024))
				->setId('mm_context')
				->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
				->setAttribute('placeholder', _('optional, e.g. /var'))
				->disableAutocomplete()
				->setAttribute('spellcheck', 'false'),
			(new CDiv(_('Adds a column per macro, resolved as {$NAME:"context"} would be.')))->addClass('mm-hint')
		]))->addClass('js-mm-grid-only')
	]);

$form = (new CForm('get'))
	->setName('mm_filter')
	->setId('mm_filter')
	->addClass('mm-filter')
	->addItem([
		(new CDiv([$left, $right]))->addClass('mm-filter-columns'),
		(new CDiv([
			(new CSubmit('mm_load', _('Load')))->setId('mm_load'),
			(new CButton('mm_reset', _('Reset')))->setId('mm_reset')->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('mm-filter-buttons')
	]);

$tabs = (new CDiv([
	(new CButton('mm_tab_grid', _('Grid')))
		->addClass('mm-tab')
		->setAttribute('data-tab', 'grid')
		->setAttribute('role', 'tab'),
	(new CButton('mm_tab_templates', _('Templates in use')))
		->addClass('mm-tab')
		->setAttribute('data-tab', 'templates')
		->setAttribute('role', 'tab'),
	(new CButton('mm_tab_find', _('Find everywhere')))
		->addClass('mm-tab')
		->setAttribute('data-tab', 'find')
		->setAttribute('role', 'tab')
]))
	->addClass('mm-tabs')
	->setAttribute('role', 'tablist');

(new CHtmlPage())
	->setTitle(_('Macro matrix'))
	->addItem(
		(new CDiv([
			(new CDiv($form))->addClass(ZBX_STYLE_FILTER_CONTAINER)->addClass('mm-filter-container'),
			$tabs,
			(new CDiv())->setId('mm_messages'),
			(new CDiv())->setId('mm_grid_panel')->addClass('mm-panel'),
			(new CDiv())->setId('mm_templates_panel')->addClass('mm-panel'),
			(new CDiv())->setId('mm_find_panel')->addClass('mm-panel')
		]))->setId('mm_root')->addClass('mm-root')
	)
	->show();

(new CScriptTag('macromatrix.init('.json_encode([
	'filter' => [
		'rows' => $filter['rows'],
		'layout' => $filter['layout'],
		'subgroups' => $filter['subgroups'],
		'pattern' => $filter['pattern'],
		'context' => $filter['context'],
		'tab' => $filter['tab']
	],
	'csrf' => $data['csrf'],
	'csrf_field' => CSRF_TOKEN_NAME,
	'can_edit_templates' => $data['can_edit_templates']
]).');'))
	->setOnDocumentReady()
	->show();
