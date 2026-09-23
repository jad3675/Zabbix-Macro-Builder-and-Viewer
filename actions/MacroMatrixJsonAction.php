<?php declare(strict_types = 0);

namespace Modules\MacroMatrix\Actions;

use CController,
	CControllerResponseData,
	CRoleHelper;

/**
 * Base for the module's AJAX endpoints: JSON in, JSON out, CSRF token checked by CController against the full action
 * name (module controllers are checked that way).
 */
abstract class MacroMatrixJsonAction extends CController {

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN
			&& $this->checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS);
	}

	/**
	 * Validates input; on failure sets an error response.
	 */
	protected function validateJson(array $fields): bool {
		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->respondError(_('Invalid request.'));
		}

		return $ret;
	}

	protected function respond(array $data): void {
		$messages = array_column(get_and_clear_messages(), 'message');

		if ($messages) {
			$data['warnings'] = array_merge($data['warnings'] ?? [], $messages);
		}

		$this->setResponse(
			(new CControllerResponseData(['main_block' => json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE)]))
				->disableView()
		);
	}

	protected function respondError(string $title, array $messages = []): void {
		$messages = array_merge($messages, array_column(get_and_clear_messages(), 'message'));

		$this->setResponse(
			(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['title' => $title, 'messages' => $messages]
			], JSON_INVALID_UTF8_SUBSTITUTE)]))->disableView()
		);
	}

	/**
	 * Keeps only ID-shaped strings.
	 */
	protected static function ids($value): array {
		if (!is_array($value)) {
			return [];
		}

		return array_values(array_unique(array_filter(array_map('strval', $value),
			static fn(string $id): bool => ctype_digit($id) && $id !== '0'
		)));
	}
}
