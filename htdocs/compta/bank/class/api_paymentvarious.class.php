<?php
/*
 * Copyright (C) 2016 Xebax Christy <xebax@wanadoo.fr>
 * Copyright (C) 2024		MDW							<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024       Frédéric France             <frederic.france@free.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/paymentvarious.class.php';


/**
 * API class for accounts
 *
 * @property DoliDB $db
 * @access protected
 * @class DolibarrApiAccess {@requires user,external}
 */
class PaymentvariousApi extends DolibarrApi
{
	/**
	 * array $FIELDS Mandatory fields, checked when creating an object
	 */
	public static $FIELDS = array(
		'id',
		'ref',
		'label',
		'type',
		'currency_code',
		'country_id'
	);

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

		/**
	 * Add a line to an account
	 *
	 * @param int    $id               ID of account
	 * @param string $date             Payment date (timestamp) {@from body} {@type timestamp}
	 * @param string $type             Payment mode (TYP,VIR,PRE,LIQ,VAD,CB,CHQ...) {@from body}
	 * @param string $label            Label {@from body}
	 * @param float  $amount           Amount (may be 0) {@from body}
	 * @param int    $category         Category
	 * @param string $cheque_number    Cheque numero {@from body}
	 * @param string $cheque_writer    Name of cheque writer {@from body}
	 * @param string $cheque_bank      Bank of cheque writer {@from body}
	 * @param string $accountancycode  Accountancy code {@from body}
	 * @param string $datev            Payment date value (timestamp) {@from body} {@type timestamp}
	 * @param string $num_releve       Bank statement numero {@from body}
	 * @return int					   ID of line
	 *
	 * @url POST variouspayment
	 */
	public function addVariousPayment($id, $date, $type, $label, $amount, $category = 0, $cheque_number = '', $cheque_writer = '', $cheque_bank = '', $accountancycode = '', $datev = null, $num_releve = '')
	{
		if (!DolibarrApiAccess::$user->hasRight('banque', 'modifier')) {
			throw new RestException(403);
		}

		$account = new Account($this->db);
		$result = $account->fetch($id);
		if (!$result) {
			throw new RestException(404, 'account not found');
		}

		$type = sanitizeVal($type);
		$label = sanitizeVal($label);
		$cheque_number = sanitizeVal($cheque_number);
		$cheque_writer = sanitizeVal($cheque_writer);
		$cheque_bank = sanitizeVal($cheque_bank);
		$accountancycode = sanitizeVal($accountancycode);
		$num_releve = sanitizeVal($num_releve);

		$payment = new PaymentVarious($this->db);
		$result = $payment->create(
			$date,
			$type,
			$label,
			$amount,
			$cheque_number,
			$category,
			DolibarrApiAccess::$user,
			$cheque_writer,
			$cheque_bank,
			$accountancycode,
			$datev,
			$num_releve
		);
		if ($result < 0) {
			throw new RestException(503, 'Error when adding various payment to account: ' . $payment->error);
		}
		return $result;
	}

	/**
	 * Create various payment object
	 *
	 * @param	array $request_data		Request data
	 * @return	int						ID of account
	 * @url POST /
	 */
	public function post($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('banque', 'configurer')) {
			throw new RestException(403);
		}
		// Check mandatory fields
		$result = $this->_validate($request_data);

		$payment = new PaymentVarious($this->db);
		// Date of the initial balance (required to create an account).
		//$payment->date_solde = time();
		foreach ($request_data as $field => $value) {
			if ($field === 'caller') {
				// Add a mention of caller so on trigger called after action, we can filter to avoid a loop if we try to sync back again with the caller
				$payment->context['caller'] = sanitizeVal($request_data['caller'], 'aZ09');
				continue;
			}

			$payment->$field = $this->_checkValForAPI($field, $value, $account);
		}
		// courant and type are the same thing but the one used when
		// creating an account is courant
		//$account->courant = $account->type; // deprecated

		if ($payment->create(DolibarrApiAccess::$user) < 0) {
			throw new RestException(500, 'Error creating various payment', array_merge(array($payment->error), $payment->errors));
		}
		return $payment->id;
	}


}