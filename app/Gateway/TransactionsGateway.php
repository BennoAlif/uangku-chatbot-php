<?php

namespace App\Gateway;

use Illuminate\Database\ConnectionInterface;

class TransactionsGateway
{
    /**
     * @var ConnectionInterface
     */
    private $db;

    public function __construct()
    {
        $this->db = app('db');
    }


    public function saveTransaction(int $nominal, int $type, int $userId)
    {
        $this->db->table('transactions')
            ->insert([
                'nominal' => $nominal,
                'type' => $type,
                'user_id' => $userId
            ]);
    }

    public function getTransactions(string $userId)
    {
        $transaction = $this->db->table('transactions')
            ->where('user_id', $userId)
            ->sum("nominal");

        if ($transaction) {
            return (array) $transaction;
        }

        return null;
    }
}
