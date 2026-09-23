<?php declare(strict_types = 0);

namespace Modules\MacroMatrix;

use APP,
	CMenuItem,
	CWebUser,
	Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		if (!CWebUser::isLoggedIn() || CWebUser::getType() < USER_TYPE_ZABBIX_ADMIN
				|| !APP::Component()->has('menu.main')) {
			return;
		}

		$data_collection = APP::Component()->get('menu.main')->find(_('Data collection'));

		// Absent when the user role hides the whole section.
		if ($data_collection === null) {
			return;
		}

		$data_collection->getSubMenu()->insertAfter(_('Hosts'),
			(new CMenuItem(_('Macro matrix')))->setAction('macromatrix.view')
		);
	}
}
