<?php
	/* Ajax calls from the page (see dvruiCall in scripts/hdhomerundvr_ui.js).
	 *
	 * The page POSTs fn=<function>&args[]=... to index.php. The function must
	 * be one index.php exported with dvruiAjaxDispatch, and it returns a
	 * DVRUI_AjaxResponse - a set of element id => html that the page puts
	 * into those elements. The reply is JSON: {"ok":true,"html":{id:html,...}}
	 * or {"ok":false,"error":"..."}. */

	class DVRUI_AjaxResponse {
		private $html = array();

		/* Replace the contents of element $id with $html */
		public function setHtml($id, $html) {
			$this->html[(string)$id] = dvruiAjaxUtf8((string)$html);
		}

		public function getString() {
			return json_encode(array('ok' => true, 'html' => (object)$this->html),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		}
	}

	/* Text that isn't valid UTF-8 (log lines, file names) is taken as Latin-1 */
	function dvruiAjaxUtf8($text) {
		if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
			return $text;
		}
		return mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
	}

	function dvruiAjaxIsCall() {
		return isset($_POST['fn']);
	}

	/* If this request is an ajax call, run it and exit. $exported lists the
	 * functions the page may call - anything else is refused. */
	function dvruiAjaxDispatch($exported) {
		if (!dvruiAjaxIsCall()) {
			return;
		}
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');

		$fn = (string)$_POST['fn'];
		if (!in_array($fn, $exported, true) || !function_exists($fn)) {
			http_response_code(400);
			echo json_encode(array('ok' => false, 'error' => $fn . ' is not available'));
			exit;
		}

		$args = array();
		if (isset($_POST['args']) && is_array($_POST['args'])) {
			foreach ($_POST['args'] as $arg) {
				$args[] = is_array($arg) ? '' : (string)$arg;
			}
		}
		// missing arguments are passed as '' rather than failing the call
		$wanted = (new ReflectionFunction($fn))->getNumberOfParameters();
		while (count($args) < $wanted) {
			$args[] = '';
		}
		$args = array_slice($args, 0, $wanted);

		try {
			$result = call_user_func_array($fn, $args);
		} catch (Throwable $e) {
			error_log('ajax ' . $fn . ' failed: ' . $e->getMessage());
			http_response_code(500);
			echo json_encode(array('ok' => false, 'error' => $fn . ' failed: ' . $e->getMessage()),
				JSON_INVALID_UTF8_SUBSTITUTE);
			exit;
		}
		echo ($result instanceof DVRUI_AjaxResponse) ? $result->getString() : (string)$result;
		exit;
	}
