<?php declare(strict_types = 0);

namespace Modules\MacroMatrix\Actions;

use API,
	CArrayHelper,
	CController,
	CControllerResponseData,
	CCsrfTokenHelper,
	CRoleHelper;

/**
 * The page. Data is loaded by the browser through the JSON actions; this only renders the shell and the filter,
 * pre-filled from the URL so a view can be bookmarked or linked.
 */
class MacroMatrixView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'groupids' => 'array_id',
			'hostids' => 'array_id',
			'tpl_groupids' => 'array_id',
			'templateids' => 'array_id',
			'rows' => 'in hosts,templates,both',
			'tpl_used_only' => 'in 0,1',
			'with_hosts' => 'in 0,1',
			'layout' => 'in auto,macros,hosts,list,matrix',
			'subgroups' => 'in 0,1',
			'pattern' => 'string',
			'context' => 'string',
			'tab' => 'in grid,find,templates'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => '']));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN
			&& $this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS);
	}

	protected function doAction(): void {
		$groups = [];
		$hosts = [];

		if ($this->hasInput('groupids')) {
			$groups = CArrayHelper::renameObjectsKeys(API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $this->getInput('groupids')
			]) ?: [], ['groupid' => 'id']);
		}

		if ($this->hasInput('hostids')) {
			$hosts = CArrayHelper::renameObjectsKeys(API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => $this->getInput('hostids')
			]) ?: [], ['hostid' => 'id']);
		}

		$tpl_groups = [];
		$templates = [];

		if ($this->hasInput('tpl_groupids')) {
			$tpl_groups = CArrayHelper::renameObjectsKeys(API::TemplateGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $this->getInput('tpl_groupids')
			]) ?: [], ['groupid' => 'id']);
		}

		if ($this->hasInput('templateids')) {
			$templates = CArrayHelper::renameObjectsKeys(API::Template()->get([
				'output' => ['templateid', 'name'],
				'templateids' => $this->getInput('templateids')
			]) ?: [], ['templateid' => 'id']);
		}

		$data = [
			'filter' => [
				'rows' => $this->getInput('rows', 'hosts'),
				'tpl_used_only' => $this->getInput('tpl_used_only', '0'),
				'with_hosts' => $this->getInput('with_hosts', '0'),
				'layout' => $this->getInput('layout', ''),
				'groups' => $groups,
				'hosts' => $hosts,
				'tpl_groups' => $tpl_groups,
				'templates' => $templates,
				'subgroups' => $this->getInput('subgroups', '1'),
				'pattern' => $this->getInput('pattern', ''),
				'context' => $this->getInput('context', ''),
				'tab' => $this->getInput('tab', 'grid')
			],
			'csrf' => [
				'resolve' => CCsrfTokenHelper::get('macromatrix.resolve'),
				'find' => CCsrfTokenHelper::get('macromatrix.find'),
				'templates' => CCsrfTokenHelper::get('macromatrix.templates'),
				'reach' => CCsrfTokenHelper::get('macromatrix.reach'),
				'apply' => CCsrfTokenHelper::get('macromatrix.apply')
			],
			'user_type' => $this->getUserType(),
			'can_edit_templates' => $this->checkAccess(CRoleHelper::UI_CONFIGURATION_TEMPLATES)
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Macro matrix'));
		$this->setResponse($response);
	}
}
