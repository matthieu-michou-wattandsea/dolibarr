<?php
use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/chargesociales.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/paymentsocialcontribution.class.php';

/**
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class SocialContributions extends DolibarrApi
{
	public static $FIELDS = array('type', 'label', 'amount', 'date_ech', 'period');

	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Create social/fiscal charge (unpaid)
	 *
	 * @param array $request_data
	 * @return int
	 * @url POST
	 */
	public function post($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('tax', 'charges', 'creer')) {
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

		$obj = new ChargeSociales($this->db);
		foreach ($request_data as $field => $value) {
			if ($field === 'caller') {
				continue;
			}
			$obj->$field = $value;
		}
		if (empty($obj->periode) && !empty($obj->period)) {
			$obj->periode = $obj->period;
		}

		$id = $obj->create(DolibarrApiAccess::$user);
		if ($id <= 0) {
			throw new RestException(500, 'Error creating social contribution: '.$obj->error);
		}
		return $id;
	}

	/**
	 * Pay a social contribution and write the bank line
	 *
	 * @param int   $id
	 * @param array $request_data  datepaye, paiementtype, amount, accountid, num_payment
	 * @return int
	 * @url POST {id}/payments
	 */
	public function addPayment($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('tax', 'charges', 'creer')) {
			throw new RestException(403);
		}
		if (empty($request_data) || !is_array($request_data)) {
			throw new RestException(400, 'No data');
		}
		foreach (array('datepaye', 'paiementtype', 'amount', 'accountid') as $f) {
			if (!isset($request_data[$f]) || $request_data[$f] === '') {
				throw new RestException(400, $f.' is required');
			}
		}

		$charge = new ChargeSociales($this->db);
		if ($charge->fetch($id) <= 0) {
			throw new RestException(404, 'Social contribution not found');
		}

		$pay = new PaymentSocialContribution($this->db);
		$pay->chid = $id;
		$pay->fk_charge = $id;
		$pay->datepaye = $request_data['datepaye'];
		$pay->paiementtype = $request_data['paiementtype'];
		$pay->fk_typepaiement = $request_data['paiementtype'];
		$pay->num_payment = isset($request_data['num_payment']) ? $request_data['num_payment'] : '';
		$pay->note = isset($request_data['note']) ? $request_data['note'] : '';
		$pay->amounts = array($id => $request_data['amount']);

		$pid = $pay->create(DolibarrApiAccess::$user, 1);
		if ($pid <= 0) {
			throw new RestException(500, 'Error creating payment: '.$pay->error);
		}

		$res = $pay->addPaymentToBank(
			DolibarrApiAccess::$user,
			'payment_sc',
			'(SocialContributionPayment)',
			(int) $request_data['accountid'],
			'',
			''
		);
		if ($res <= 0) {
			throw new RestException(500, 'Error bank line: '.$pay->error);
		}
		return $pid;
	}

		/**
	 * List social/fiscal charge types (dictionary c_chargesociales, admin dict id=7)
	 *
	 * @param int $active 1=actifs seulement, 0=tous, -1=inactifs
	 * @param int $fk_pays filtre pays (1=FR), 0=tous
	 * @return array
	 * @url GET types
	 */
	public function getTypes($active = 1, $fk_pays = 1)
	{
		if (!DolibarrApiAccess::$user->hasRight('tax', 'charges', 'lire')
			&& !DolibarrApiAccess::$user->admin) {
			throw new RestException(403);
		}

		$sql = "SELECT id, libelle as label, code, deductible, active, fk_pays, accountancy_code, module";
		$sql .= " FROM ".$this->db->prefix()."c_chargesociales";
		$sql .= " WHERE 1=1";
		if ((int) $active >= 0) {
			$sql .= " AND active = ".((int) $active);
		}
		if ((int) $fk_pays > 0) {
			$sql .= " AND fk_pays = ".((int) $fk_pays);
		}
		$sql .= " ORDER BY libelle";

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(500, $this->db->lasterror());
		}
		$out = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$out[] = array(
				'id' => (int) $obj->id,
				'label' => $obj->label,
				'code' => $obj->code,
				'deductible' => (int) $obj->deductible,
				'active' => (int) $obj->active,
				'fk_pays' => (int) $obj->fk_pays,
				'accountancy_code' => $obj->accountancy_code,
				'module' => $obj->module,
			);
		}
		return $out;
	}

	/**
	 * Create a social/fiscal charge type (same fields as dict.php id=7)
	 *
	 * @param array $request_data label (ou libelle), code, deductible, active, fk_pays, accountancy_code
	 * @return int id
	 * @url POST types
	 */
	public function postType($request_data = null)
	{
		if (!DolibarrApiAccess::$user->admin
			&& !DolibarrApiAccess::$user->hasRight('tax', 'charges', 'creer')) {
			throw new RestException(403);
		}
		if (empty($request_data) || !is_array($request_data)) {
			throw new RestException(400, 'No data');
		}

		require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/cchargesociales.class.php';

		$label = '';
		if (!empty($request_data['label'])) {
			$label = $request_data['label'];
		} elseif (!empty($request_data['libelle'])) {
			$label = $request_data['libelle'];
		}
		if ($label === '') {
			throw new RestException(400, 'label is required');
		}

		$obj = new Cchargesociales($this->db);
		$obj->libelle = $label;
		$obj->label = $label;
		$obj->code = isset($request_data['code']) ? $request_data['code'] : '';
		$obj->deductible = isset($request_data['deductible']) ? (int) $request_data['deductible'] : 1;
		$obj->active = isset($request_data['active']) ? (int) $request_data['active'] : 1;
		$obj->fk_pays = isset($request_data['fk_pays']) ? (int) $request_data['fk_pays'] : 1;
		$obj->accountancy_code = isset($request_data['accountancy_code']) ? $request_data['accountancy_code'] : '';
		$obj->module = isset($request_data['module']) ? $request_data['module'] : '';

		$id = $obj->create(DolibarrApiAccess::$user);
		if ($id <= 0) {
			throw new RestException(500, 'Error creating charge type: '.$obj->error.' '.implode(',', $obj->errors));
		}
		return $id;
	}
}

