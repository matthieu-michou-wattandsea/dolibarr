<?php
use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';

/**
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class VariousPayments extends DolibarrApi
{
	public static $FIELDS = array('datep', 'amount', 'sens', 'type_payment', 'fk_account', 'label');

	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Create a various payment (OD)
	 *
	 * @param array $request_data
	 * @return int
	 * @url POST
	 */
	public function post($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('banque', 'modifier')) {
			throw new RestException(403);
		}
		if (empty($request_data) || !is_array($request_data)) {
			throw new RestException(400, 'No data');
		}
		foreach (self::$FIELDS as $f) {
			if (!isset($request_data[$f]) || $request_data[$f] === '') {
				throw new RestException(400, $f.' is required');
			}
		}

		$obj = new PaymentVarious($this->db);
		foreach ($request_data as $field => $value) {
			if ($field === 'caller') {
				continue;
			}
			$obj->$field = $value;
		}
		if (empty($obj->datev)) {
			$obj->datev = $obj->datep;
		}
		if (empty($obj->accountid) && !empty($obj->fk_account)) {
			$obj->accountid = $obj->fk_account;
		}

		$id = $obj->create(DolibarrApiAccess::$user);
		if ($id <= 0) {
			throw new RestException(500, 'Error creating various payment: '.$obj->error);
		}
		return $id;
	}
}