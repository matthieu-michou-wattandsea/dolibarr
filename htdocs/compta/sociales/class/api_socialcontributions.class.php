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
}